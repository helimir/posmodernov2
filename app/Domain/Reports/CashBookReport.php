<?php

namespace App\Domain\Reports;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;

/**
 * El LIBRO DE CAJA: el dinero de la caja, dia a dia.
 *
 * REGLA que fija esta clase: *el libro de caja sale de los ASIENTOS de la
 * cuenta de efectivo*. El saldo en curso de una caja es la suma de los
 * debitos menos los creditos de su cuenta, y esa cuenta es la del ROL `cash`
 * del plan de la tienda: es DATO del negocio, no un codigo escrito aqui.
 *
 * Y CADA TIENDA TIENE SU CAJA: el libro no es de la tienda activa, es de las
 * tiendas del ambito, y cada una con la suya. Una tienda sin cuenta de
 * efectivo en su plan no tiene libro de caja, y no se le inventa una.
 *
 * Y EL SALDO DE APERTURA es el saldo que se trae: el de la cuenta de
 * efectivo ANTES del primer dia del periodo, mas lo que entro y lo que salio
 * de los dias anteriores. No se inventa un cero: un saldo de partida que se
 * pierde es un descuadre que nadie puede explicar.
 *
 * Y UN DIA SIN MOVIMIENTO NO ES UN DIA DE CERO: el libro enseña los dias que
 * se movieron, y los dias del periodo que NO se movieron salen aparte, con
 * el hecho de si la caja estaba ABIERTA. Un dia que no opero no es una venta
 * de cero, y un dia con la caja abierta y sin movimiento es otra cosa: por
 * eso los dos se distinguen.
 *
 * Y EL SALDO FINAL DE LA TABLA es la SUMA del saldo final de cada caja: el
 * dinero que hay en los cajones al cerrar el periodo. No es la suma de la
 * columna (un saldo es un saldo arrastrado, y sumar saldos no dice nada): es
 * el dinero que hay, contado una vez por caja.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class CashBookReport
{
    public function __construct(private Connection $connection) {}

    /**
     * El LIBRO DE CAJA del periodo.
     *
     * @return array{
     *     rows: list<array{date: string, store_id: int, store_name: string, opening_balance: string, debit: string, credit: string, closing_balance: string}>,
     *     totals: array{debit: string, credit: string, closing_balance: string},
     *     without_movement: list<array{store_id: int, store_name: string, days: list<array{date: string, till_open: bool}>}>
     * }
     */
    public function build(ReportScope $scope, ReportPeriod $period): array
    {
        $desde = $period->from->format('Y-m-d');
        $hasta = $period->to->format('Y-m-d');

        $filas = $this->movimientos($scope, $desde, $hasta);

        // El SALDO que se trae: el de la cuenta de efectivo ANTES del periodo.
        $previos = $this->saldoAnterior($scope, $desde);

        $rows = [];
        $saldos = [];

        foreach ($filas as $fila) {
            $storeId = (int) $fila->store_id;

            if (! array_key_exists($storeId, $saldos)) {
                $saldos[$storeId] = $previos[$storeId] ?? '0.0000';
            }

            $debe = PosAmount::text($fila->debit);
            $haber = PosAmount::text($fila->credit);

            $apertura = $saldos[$storeId];
            // El CIERRE es lo que se trae MAS lo que entro MENOS lo que salio.
            $cierre = $this->resta($this->suma($apertura, $debe), $haber);

            $saldos[$storeId] = $cierre;

            $rows[] = [
                'date' => (string) $fila->date,
                'store_id' => $storeId,
                'store_name' => (string) $fila->store_name,
                'opening_balance' => $apertura,
                'debit' => $debe,
                'credit' => $haber,
                'closing_balance' => $cierre,
            ];
        }

        $totals = [
            'debit' => '0.0000',
            'credit' => '0.0000',
            'closing_balance' => '0.0000',
        ];

        foreach ($rows as $row) {
            $totals['debit'] = $this->suma($totals['debit'], $row['debit']);
            $totals['credit'] = $this->suma($totals['credit'], $row['credit']);
        }

        // El SALDO FINAL es el del ultimo dia de cada tienda, y la suma de
        // esos saldos es el saldo final del libro.
        foreach ($saldos as $saldo) {
            $totals['closing_balance'] = $this->suma($totals['closing_balance'], $saldo);
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'without_movement' => $this->diasSinMovimiento($scope, $period, $rows),
        ];
    }

    /**
     * Los MOVIMIENTOS de las cajas del periodo: una fila por tienda y dia.
     *
     * REGLA que fija este metodo: *la cuenta de efectivo es la del ROL `cash`
     * del plan de la tienda*. El vinculo se une con el asiento, asi que un
     * movimiento de otra cuenta nunca entra en el libro, y una tienda sin
     * plan no tiene lineas y por eso no tiene libro.
     *
     * @return list<object>
     */
    private function movimientos(ReportScope $scope, string $desde, string $hasta): array
    {
        $consulta = $this->connection->table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->join('stores', 'stores.id', '=', 'ledger_entries.store_id')
            ->join('store_accounts', 'store_accounts.store_id', '=', 'ledger_entries.store_id')
            ->where('store_accounts.role', 'cash')
            ->where('store_accounts.status', 'active')
            ->join('accounts', 'accounts.id', '=', 'store_accounts.account_id')
            ->whereRaw('accounts.code = ledger_lines.account_code')
            ->whereBetween('ledger_entries.business_date', [$desde, $hasta]);

        $scope->restrict($consulta, 'ledger_entries.store_id');

        return $consulta
            ->groupBy('ledger_entries.store_id', 'stores.name', 'ledger_entries.business_date')
            ->orderBy('ledger_entries.store_id')
            ->orderBy('ledger_entries.business_date')
            ->selectRaw(
                'ledger_entries.store_id as store_id, '
                .'stores.name as store_name, '
                .'ledger_entries.business_date as date, '
                .'COALESCE(SUM(ledger_lines.debit), 0) as debit, '
                .'COALESCE(SUM(ledger_lines.credit), 0) as credit'
            )
            ->get()
            ->all();
    }

    /**
     * El SALDO de las cajas ANTES del periodo: el que se trae.
     *
     * @return array<int, string>
     */
    private function saldoAnterior(ReportScope $scope, string $desde): array
    {
        $consulta = $this->connection->table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->join('store_accounts', 'store_accounts.store_id', '=', 'ledger_entries.store_id')
            ->where('store_accounts.role', 'cash')
            ->where('store_accounts.status', 'active')
            ->join('accounts', 'accounts.id', '=', 'store_accounts.account_id')
            ->whereRaw('accounts.code = ledger_lines.account_code')
            ->where('ledger_entries.business_date', '<', $desde);

        $scope->restrict($consulta, 'ledger_entries.store_id');

        $filas = $consulta
            ->groupBy('ledger_entries.store_id')
            ->selectRaw(
                'ledger_entries.store_id as store_id, '
                .'COALESCE(SUM(ledger_lines.debit - ledger_lines.credit), 0) as balance'
            )
            ->get();

        $saldos = [];

        foreach ($filas as $fila) {
            $saldos[(int) $fila->store_id] = PosAmount::text($fila->balance);
        }

        return $saldos;
    }

    /**
     * Los DIAS del periodo SIN movimiento, con el hecho de si la caja estaba
     * abierta.
     *
     * REGLA que fija este metodo: *un dia que no opero no es un dia de
     * ventas de cero*. El dia sale con su hecho: la caja ABIERTA sin
     * movimiento (se abrio y no se movio) o la caja CERRADA (el dia no
     * opero). Los dos se ensenan y no se inventan.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{store_id: int, store_name: string, days: list<array{date: string, till_open: bool}>}>
     */
    private function diasSinMovimiento(ReportScope $scope, ReportPeriod $period, array $rows): array
    {
        $movidos = [];

        foreach ($rows as $row) {
            $movidos[(int) $row['store_id'].'|'.$row['date']] = true;
        }

        $tiendas = $this->tiendasConCaja($scope);

        $resultado = [];

        foreach ($tiendas as $tienda) {
            $storeId = (int) $tienda->id;

            $abiertas = $this->cajasAbiertas($storeId, $period);

            $dias = [];

            foreach ($period->days() as $dia) {
                if (isset($movidos[$storeId.'|'.$dia])) {
                    continue;
                }

                $dias[] = [
                    'date' => $dia,
                    'till_open' => isset($abiertas[$dia]),
                ];
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
     * Las TIENDAS del ambito que tienen una cuenta de efectivo en su plan.
     *
     * @return list<object>
     */
    private function tiendasConCaja(ReportScope $scope): array
    {
        $consulta = $this->connection->table('stores')
            ->join('store_accounts', 'store_accounts.store_id', '=', 'stores.id')
            ->where('store_accounts.role', 'cash')
            ->where('store_accounts.status', 'active')
            ->select('stores.id', 'stores.name')
            ->distinct();

        $scope->restrict($consulta, 'stores.id');

        return $consulta->orderBy('stores.id')->get()->all();
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
     * La SUMA de dos importes, a la escala de la columna.
     */
    private function suma(string $a, string $b): string
    {
        return (string) BigDecimal::of($a)
            ->plus(BigDecimal::of($b))
            ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * La RESTA de dos importes, a la escala de la columna.
     */
    private function resta(string $a, string $b): string
    {
        return (string) BigDecimal::of($a)
            ->minus(BigDecimal::of($b))
            ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }
}
