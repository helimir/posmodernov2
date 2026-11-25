<?php

namespace App\Domain\Reports;

use App\Domain\Accounting\CashClose;
use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;

/**
 * El ARQUEO y el Z POR PERIODO: lo que se cerro, y como quedo.
 *
 * REGLA que fija esta clase: *el Z sellado de un dia es un HECHO, y se
 * LEE*. Un dia cerrado no se recalcula: se lee su fila de `cash_closes`, con
 * su contenido y con su FIRMA. Si el hash no cuadra, alguien altero el Z, y
 * eso se dice (`verified`).
 *
 * Y UN Z REABIERTO NO CUENTA: la fila reabierta queda como historial, y la
 * que vale es la VIGENTE (la ultima no reabierta de esa tienda y ese dia). Un
 * Z reabierta es un dia que se todabno ha cerrado de verdad.
 *
 * Y EL ARQUEO es la CONCILIACION de la caja: lo que se conto contra lo que
 * deberia haber. El Z POR PERIODO es el RESUMEN FISCAL del periodo: las
 * ventas, el impuesto y los correlativos de los dias cerrados, sumados. Son
 * dos cosas distintas y por eso son dos reportes.
 *
 * Y UN DIA QUE NO SE CERRO NO ES UN DIA DE CERO: los dias con caja abierta
 * y sin Z salen aparte, con su hecho. Un dia que no se cerro no es un dia
 * que vendio cero.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class CashCloseReport
{
    public function __construct(private Connection $connection) {}

    /**
     * El ARQUEO del periodo: una fila por dia cerrado.
     *
     * @return array{
     *     rows: list<array{date: string, store_id: int, store_name: string, opening_balance: string, expected_balance: string, counted_balance: string, difference: string, verified: bool, closed_by: string|null, closed_at: string|null, close_reason: string|null}>,
     *     totals: array{difference: string},
     *     without_close: list<array{store_id: int, store_name: string, days: list<array{date: string, till_open: bool}>}>
     * }
     */
    public function cashCount(ReportScope $scope, ReportPeriod $period): array
    {
        $cierres = $this->cierres($scope, $period);

        $rows = [];

        $totales = ['difference' => '0.0000'];

        foreach ($cierres as $cierre) {
            $diferencia = PosAmount::text($cierre->difference);

            $totales['difference'] = $this->suma($totales['difference'], $diferencia);

            $rows[] = [
                'date' => (string) $cierre->business_date,
                'store_id' => (int) $cierre->store_id,
                'store_name' => (string) $cierre->store_name,
                'opening_balance' => PosAmount::text($cierre->opening_balance),
                'expected_balance' => PosAmount::text($cierre->expected_balance),
                'counted_balance' => PosAmount::text($cierre->counted_balance),
                'difference' => $diferencia,
                'verified' => CashClose::verify($cierre),
                'closed_by' => $this->nombreDe($cierre->closed_by ?? null),
                'closed_at' => $cierre->closed_at === null ? null : (string) $cierre->closed_at,
                'close_reason' => $cierre->close_reason === null ? null : (string) $cierre->close_reason,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => $totales,
            'without_close' => $this->diasSinCierre($scope, $period, $cierres),
        ];
    }

    /**
     * El Z POR PERIODO: una fila por dia cerrado, con las cifras del Z.
     *
     * @return array{
     *     rows: list<array{date: string, store_id: int, store_name: string, sales_count: int, subtotal: string, tax_total: string, grand_total: string, first_document_number: string|null, last_document_number: string|null, verified: bool}>,
     *     totals: array{sales_count: int, subtotal: string, tax_total: string, grand_total: string},
     *     without_close: list<array{store_id: int, store_name: string, days: list<array{date: string, till_open: bool}>}>
     * }
     */
    public function periodZ(ReportScope $scope, ReportPeriod $period): array
    {
        $cierres = $this->cierres($scope, $period);

        $rows = [];

        $totales = [
            'sales_count' => 0,
            'subtotal' => '0.0000',
            'tax_total' => '0.0000',
            'grand_total' => '0.0000',
        ];

        foreach ($cierres as $cierre) {
            $totales['sales_count'] += (int) $cierre->sales_count;
            $totales['subtotal'] = $this->suma($totales['subtotal'], PosAmount::text($cierre->subtotal));
            $totales['tax_total'] = $this->suma($totales['tax_total'], PosAmount::text($cierre->tax_total));
            $totales['grand_total'] = $this->suma($totales['grand_total'], PosAmount::text($cierre->grand_total));

            $rows[] = [
                'date' => (string) $cierre->business_date,
                'store_id' => (int) $cierre->store_id,
                'store_name' => (string) $cierre->store_name,
                'sales_count' => (int) $cierre->sales_count,
                'subtotal' => PosAmount::text($cierre->subtotal),
                'tax_total' => PosAmount::text($cierre->tax_total),
                'grand_total' => PosAmount::text($cierre->grand_total),
                'first_document_number' => $cierre->first_document_number === null
                    ? null
                    : (string) $cierre->first_document_number,
                'last_document_number' => $cierre->last_document_number === null
                    ? null
                    : (string) $cierre->last_document_number,
                'verified' => CashClose::verify($cierre),
            ];
        }

        return [
            'rows' => $rows,
            'totals' => $totales,
            'without_close' => $this->diasSinCierre($scope, $period, $cierres),
        ];
    }

    /**
     * Los CIERRES VIGENTES del periodo: una fila por tienda y dia.
     *
     * REGLA que fija este metodo: *el cierre que vale es el VIGENTE*. De los
     * cierres de una tienda y un dia, el que cuenta es el ULTIMO que no esta
     * reabierta: un Z reabierta quedo como historial y el dia se volvio a
     * cerrar despues.
     *
     * @return list<object>
     */
    private function cierres(ReportScope $scope, ReportPeriod $period): array
    {
        $consulta = $this->connection->table('cash_closes')
            ->join('stores', 'stores.id', '=', 'cash_closes.store_id')
            ->whereNull('cash_closes.reopened_at')
            ->whereBetween('cash_closes.business_date', [
                $period->from->format('Y-m-d'),
                $period->to->format('Y-m-d'),
            ])
            ->orderBy('cash_closes.store_id')
            ->orderBy('cash_closes.business_date')
            ->orderByDesc('cash_closes.id');

        $scope->restrict($consulta, 'cash_closes.store_id');

        $filas = $consulta
            ->selectRaw(
                'cash_closes.id as id, '
                .'cash_closes.store_id as store_id, '
                .'stores.name as store_name, '
                .'cash_closes.business_date as business_date, '
                .'cash_closes.opening_balance as opening_balance, '
                .'cash_closes.expected_balance as expected_balance, '
                .'cash_closes.counted_balance as counted_balance, '
                .'cash_closes.difference as difference, '
                .'cash_closes.subtotal as subtotal, '
                .'cash_closes.tax_total as tax_total, '
                .'cash_closes.grand_total as grand_total, '
                .'cash_closes.sales_count as sales_count, '
                .'cash_closes.first_document_number as first_document_number, '
                .'cash_closes.last_document_number as last_document_number, '
                .'cash_closes.first_control_number as first_control_number, '
                .'cash_closes.last_control_number as last_control_number, '
                .'cash_closes.closed_by as closed_by, '
                .'cash_closes.closed_at as closed_at, '
                .'cash_closes.close_reason as close_reason, '
                .'cash_closes.payments as payments, '
                .'cash_closes.counted_pieces as counted_pieces, '
                .'cash_closes.content_hash as content_hash'
            )
            ->get()
            ->all();

        // El VIGENTE de cada tienda y dia: el ultimo que no esta reabierta.
        $vigentes = [];

        foreach ($filas as $fila) {
            $clave = (int) $fila->store_id.'|'.(string) $fila->business_date;

            if (isset($vigentes[$clave])) {
                continue;
            }

            $vigentes[$clave] = $fila;
        }

        return array_values($vigentes);
    }

    /**
     * Los DIAS del periodo con caja abierta y SIN cierre.
     *
     * @param  list<object>  $cierres
     * @return list<array{store_id: int, store_name: string, days: list<array{date: string, till_open: bool}>}>
     */
    private function diasSinCierre(ReportScope $scope, ReportPeriod $period, array $cierres): array
    {
        $cerrados = [];

        foreach ($cierres as $cierre) {
            $cerrados[(int) $cierre->store_id.'|'.(string) $cierre->business_date] = true;
        }

        $consulta = $this->connection->table('stores')
            ->join('store_accounts', 'store_accounts.store_id', '=', 'stores.id')
            ->where('store_accounts.role', 'cash')
            ->where('store_accounts.status', 'active')
            ->select('stores.id', 'stores.name')
            ->distinct();
        $scope->restrict($consulta, 'stores.id');

        $tiendas = $consulta->orderBy('stores.id')->get()->all();

        $resultado = [];

        foreach ($tiendas as $tienda) {
            $storeId = (int) $tienda->id;

            $abiertas = $this->cajasAbiertas($storeId, $period);

            $dias = [];

            foreach ($period->days() as $dia) {
                if (! isset($abiertas[$dia])) {
                    continue;
                }

                if (isset($cerrados[$storeId.'|'.$dia])) {
                    continue;
                }

                $dias[] = ['date' => $dia, 'till_open' => true];
            }

            if ($dias === []) {
                continue;
            }

            $resultado[] = [
                'store_id' => $storeId,
                'store_name' => (string) $tienda->name,
                'days' => $dias,
            ];
        }

        return $resultado;
    }

    /**
     * Los DIAS con la CAJA ABIERTA de una tienda.
     *
     * @return array<string, bool>
     */
    private function cajasAbiertas(int $storeId, ReportPeriod $period): array
    {
        $filas = $this->connection->table('cash_registers')
            ->where('store_id', $storeId)
            ->whereBetween('business_date', [
                $period->from->format('Y-m-d'),
                $period->to->format('Y-m-d'),
            ])
            ->pluck('business_date')
            ->all();

        $dias = [];

        foreach ($filas as $dia) {
            $dias[(string) $dia] = true;
        }

        return $dias;
    }

    /**
     * El NOMBRE de quien cerro.
     */
    private function nombreDe(mixed $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $nombre = $this->connection->table('users')->where('id', (int) $userId)->value('name');

        return $nombre === null ? null : (string) $nombre;
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
