<?php

namespace App\Domain\Reports;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;

/**
 * El REPORTE DE PERDIDAS Y GANANCIAS: el resultado del periodo.
 *
 * REGLA que fija esta clase: *el PyG sale del LIBRO, por CUENTA*. El libro
 * (`ledger_entries` y `ledger_lines`) es la verdad de lo que ha pasado en la
 * tienda, y el resultado se arma con las cuentas de INGRESO y las de GASTO.
 *
 * Y POR QUE POR CUENTA Y NO POR TIPO DE ASIENTO: *el ingreso comparte tipo
 * con el traspaso*. El catalogo de tipos de asiento es un conjunto CERRADO de
 * siete, y no hay ningun tipo "ingreso": un ingreso se asienta como
 * `bank_movement`, que es el MISMO tipo que el traspaso entre la caja y el
 * banco. Lo que los distingue es la CONTRAPARTIDA: el ingreso se abona en una
 * cuenta de ingresos y el traspaso se mueve entre cuentas de tesoreria. Por
 * eso este reporte separa por el TIPO DE CUENTA, que es un dato del plan de
 * cuentas, y no por el tipo de asiento, que no distingue nada.
 *
 * Y EL IMPUESTO NO ES UN INGRESO: el IVA se abona en la cuenta del impuesto
 * por pagar, que es un PASIVO, y aqui no entra. Un PyG con el IVA de
 * ganancia es un PyG que miente.
 *
 * Y EL RESULTADO es la diferencia: ingresos menos gastos. Un resultado
 * NEGATIVO es una perdida, y sale con su signo: una perdida no es un numero
 * positivo con otro nombre.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class ProfitAndLossReport
{
    public function __construct(private Connection $connection) {}

    /**
     * El RESULTADO del periodo.
     *
     * @return array{
     *     rows: list<array{account_code: string, account_name: string, kind: string, debit: string, credit: string, amount: string}>,
     *     totals: array{income: string, expense: string, result: string}
     * }
     */
    public function build(ReportScope $scope, ReportPeriod $period): array
    {
        $consulta = $this->connection->table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->join('accounts', 'accounts.code', '=', 'ledger_lines.account_code')
            ->whereIn('accounts.type', ['income', 'expense'])
            ->whereBetween('ledger_entries.business_date', [
                $period->from->format('Y-m-d'),
                $period->to->format('Y-m-d'),
            ]);

        $scope->restrict($consulta, 'ledger_entries.store_id');

        $filas = $consulta
            ->groupBy('ledger_lines.account_code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.type')
            ->orderBy('ledger_lines.account_code')
            ->selectRaw(
                'ledger_lines.account_code as account_code, '
                .'accounts.name as account_name, '
                .'accounts.type as kind, '
                .'COALESCE(SUM(ledger_lines.debit), 0) as debit, '
                .'COALESCE(SUM(ledger_lines.credit), 0) as credit'
            )
            ->get();

        $rows = [];

        $ingresos = '0.0000';
        $gastos = '0.0000';

        foreach ($filas as $fila) {
            $debe = PosAmount::text($fila->debit);
            $haber = PosAmount::text($fila->credit);

            $tipo = (string) $fila->kind;

            // El INGRESO se mide por su HABER y el GASTO por su DEBE: una
            // cuenta de ingresos que se ajusta (una devolucion) tiene un debe
            // que RESTA de lo que ingreso.
            $importe = $tipo === 'income'
                ? $this->resta($haber, $debe)
                : $this->resta($debe, $haber);

            if ($tipo === 'income') {
                $ingresos = $this->suma($ingresos, $importe);
            } else {
                $gastos = $this->suma($gastos, $importe);
            }

            $rows[] = [
                'account_code' => (string) $fila->account_code,
                'account_name' => (string) $fila->account_name,
                'kind' => $tipo,
                'debit' => $debe,
                'credit' => $haber,
                'amount' => $importe,
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'income' => $ingresos,
                'expense' => $gastos,
                'result' => $this->resta($ingresos, $gastos),
            ],
        ];
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
