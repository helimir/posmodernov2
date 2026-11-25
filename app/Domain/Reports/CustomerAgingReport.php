<?php

namespace App\Domain\Reports;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/**
 * El AGING DE CLIENTES: la deuda y su antiguedad.
 *
 * REGLA que fija esta clase: *el aging sale del LIBRO DEL CLIENTE*
 * (`customer_ledger_entries`), no de un contador y no de un cache. El saldo de
 * un cliente es la SUMA de sus movimientos, y el cache (`customer_stores`) es
 * una copia que se puede desfasar.
 *
 * Y EL SIGNO va en el importe: POSITIVO es lo que el cliente DEBE y NEGATIVO
 * es lo que ha pagado de mas. Un saldo NEGATIVO es CREDITO A FAVOR: no se
 * debe nada, y la deuda es cero, no un numero negativo.
 *
 * Y LA ANTIGUEDAD se DERIVA, no se inventa: un movimiento positivo abre una
 * CARGA (una deuda con su fecha) y un movimiento negativo la CUBRE, de la mas
 * ANTIGUA a la mas reciente. Las cargas que quedan SIN CUBRIR son la deuda que
 * existe hoy, y la antiguedad de cada una son los dias desde su fecha hasta
 * el FIN DEL PERIODO.
 *
 * Y NO SE INVENTAN TRAMOS: el reporte dice la antiguedad en DIAS y enseña
 * las cargas abiertas una a una. Un tramo de "0 a 30 dias" es una regla del
 * negocio, y este repositorio no la tiene escrita en ningun sitio: el que
 * quiera agrupar por tramos, los agrupa.
 *
 * Y EL PERIODO: el aging es una FOTO, y la foto se toma el dia que TERMINA el
 * periodo. El principio no cuenta, porque un saldo no empieza a contar desde
 * el primero: los movimientos de antes tambien son deuda.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class CustomerAgingReport
{
    public function __construct(private Connection $connection) {}

    /**
     * El AGING del periodo.
     *
     * @return array{
     *     rows: list<array{customer_id: int, name: string|null, store_id: int, store_name: string, balance: string, due: string, credit: string, oldest_date: string|null, age_days: int|null, items: list<array{date: string, amount: string, age_days: int}>}>,
     *     totals: array{due: string, credit: string}
     * }
     */
    public function build(ReportScope $scope, ReportPeriod $period): array
    {
        $consulta = $this->connection->table('customer_ledger_entries')
            ->join('customers', 'customers.id', '=', 'customer_ledger_entries.customer_id')
            ->join('stores', 'stores.id', '=', 'customer_ledger_entries.store_id')
            ->where('customer_ledger_entries.entry_date', '<=', $period->to->format('Y-m-d'))
            ->orderBy('customer_ledger_entries.customer_id')
            ->orderBy('customer_ledger_entries.store_id')
            ->orderBy('customer_ledger_entries.entry_date')
            ->orderBy('customer_ledger_entries.id')
            ->selectRaw(
                'customer_ledger_entries.customer_id as customer_id, '
                .'customers.name as name, '
                .'customer_ledger_entries.store_id as store_id, '
                .'stores.name as store_name, '
                .'customer_ledger_entries.entry_date as entry_date, '
                .'customer_ledger_entries.amount as amount'
            );

        $scope->restrict($consulta, 'customer_ledger_entries.store_id');

        $filas = $consulta->get()->all();

        // Los MOVIMIENTOS se agrupan por cliente y tienda: el saldo es de la
        // pareja, porque el credito del cliente es de la tienda donde se le
        // vendio.
        $grupos = [];

        foreach ($filas as $fila) {
            $clave = (int) $fila->customer_id.'|'.(int) $fila->store_id;

            if (! isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'customer_id' => (int) $fila->customer_id,
                    'name' => $fila->name === null ? null : (string) $fila->name,
                    'store_id' => (int) $fila->store_id,
                    'store_name' => (string) $fila->store_name,
                    'movimientos' => [],
                ];
            }

            $grupos[$clave]['movimientos'][] = [
                'date' => (string) $fila->entry_date,
                'amount' => PosAmount::text($fila->amount),
            ];
        }

        $rows = [];

        $totales = ['due' => '0.0000', 'credit' => '0.0000'];

        foreach ($grupos as $grupo) {
            $saldo = $this->saldo($grupo['movimientos']);

            if (BigDecimal::of($saldo)->isZero()) {
                continue;
            }

            $abiertas = $this->cargasAbiertas($grupo['movimientos']);

            $items = [];
            $masAntigua = null;

            foreach ($abiertas as $carga) {
                $items[] = [
                    'date' => $carga['date'],
                    'amount' => $carga['remaining'],
                    'age_days' => $this->dias($carga['date'], $period->to->format('Y-m-d')),
                ];

                if ($masAntigua === null || $carga['date'] < $masAntigua) {
                    $masAntigua = $carga['date'];
                }
            }

            $deuda = $this->deuda($saldo);
            $credito = $this->credito($saldo);

            $totales['due'] = $this->suma($totales['due'], $deuda);
            $totales['credit'] = $this->suma($totales['credit'], $credito);

            $rows[] = [
                'customer_id' => $grupo['customer_id'],
                'name' => $grupo['name'],
                'store_id' => $grupo['store_id'],
                'store_name' => $grupo['store_name'],
                'balance' => $saldo,
                'due' => $deuda,
                'credit' => $credito,
                'oldest_date' => $masAntigua,
                'age_days' => $masAntigua === null
                    ? null
                    : $this->dias($masAntigua, $period->to->format('Y-m-d')),
                'items' => $items,
            ];
        }

        // EL ORDEN es por antiguedad: la deuda mas VIEJA primero, que es la
        // que hay que perseguir.
        usort($rows, static function (array $a, array $b): int {
            $edadA = $a['age_days'] ?? -1;
            $edadB = $b['age_days'] ?? -1;

            if ($edadA !== $edadB) {
                return $edadB <=> $edadA;
            }

            return $b['due'] <=> $a['due'];
        });

        return ['rows' => $rows, 'totals' => $totales];
    }

    /**
     * El SALDO del cliente: la suma de sus movimientos, CON SU SIGNO.
     *
     * @param  list<array{date: string, amount: string}>  $movimientos
     */
    private function saldo(array $movimientos): string
    {
        $saldo = BigDecimal::zero()->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);

        foreach ($movimientos as $movimiento) {
            $saldo = $saldo->plus(BigDecimal::of($movimiento['amount']));
        }

        return (string) $saldo->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * Las CARGAS que quedan SIN CUBRIR, con la parte que les queda.
     *
     * REGLA que fija este metodo: *un cobro cubre la deuda MAS ANTIGUA
     * primero*. Es la regla del FIFO, y es la que explica que una deuda
     * reciente este pagada mientras una vieja sigue abierta.
     *
     * @param  list<array{date: string, amount: string}>  $movimientos
     * @return list<array{date: string, remaining: string}>
     */
    private function cargasAbiertas(array $movimientos): array
    {
        $cargas = [];

        foreach ($movimientos as $movimiento) {
            $importe = BigDecimal::of($movimiento['amount']);

            if ($importe->isPositive()) {
                $cargas[] = [
                    'date' => $movimiento['date'],
                    'remaining' => $importe,
                ];

                continue;
            }

            if (! $importe->isNegative()) {
                continue;
            }

            $pendiente = $importe->abs();

            foreach ($cargas as $indice => $carga) {
                if ($pendiente->isZero()) {
                    break;
                }

                $cubierto = BigDecimal::of('0');

                if ($carga['remaining']->isLessThanOrEqualTo($pendiente)) {
                    $cubierto = $carga['remaining'];
                } else {
                    $cubierto = $pendiente;
                }

                $cargas[$indice]['remaining'] = $carga['remaining']->minus($cubierto);
                $pendiente = $pendiente->minus($cubierto);
            }
        }

        $abiertas = [];

        foreach ($cargas as $carga) {
            if ($carga['remaining']->isLessThanOrEqualTo(BigDecimal::zero())) {
                continue;
            }

            $abiertas[] = [
                'date' => $carga['date'],
                'remaining' => (string) $carga['remaining']->toScale(
                    PosAmount::SCALE,
                    RoundingMode::HALF_UP
                ),
            ];
        }

        return $abiertas;
    }

    /**
     * La DEUDA que corresponde a un saldo: el max(0, saldo).
     */
    private function deuda(string $saldo): string
    {
        $balance = BigDecimal::of($saldo);

        return $balance->isNegative()
            ? '0.0000'
            : (string) $balance->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * El CREDITO A FAVOR que corresponde a un saldo negativo.
     */
    private function credito(string $saldo): string
    {
        $balance = BigDecimal::of($saldo);

        return $balance->isNegative()
            ? (string) $balance->abs()->toScale(PosAmount::SCALE, RoundingMode::HALF_UP)
            : '0.0000';
    }

    /**
     * Los DIAS entre dos fechas.
     */
    private function dias(string $desde, string $hasta): int
    {
        $inicio = new DateTimeImmutable($desde);
        $fin = new DateTimeImmutable($hasta);

        return (int) $inicio->diff($fin)->days;
    }

    /**
     * La SUMA de dos importes, a la escala de la columna.
     */
    private function suma(string $a, string $b): string
    {
        return (string) BigDecimal::of($a)
            ->plus(BigDecimal::of($b))
            ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }
}
