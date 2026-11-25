<?php

namespace App\Domain\Reports;

use App\Models\Sale;
use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;

/**
 * El REPORTE FISCAL DEL IVA: el impuesto por alicuota.
 *
 * REGLA que fija esta clase: *el IVA sale de los COMPROBANTES, no de las
 * ventas*. El hecho fiscal es el documento (`fiscal_documents`) con sus
 * NODOS de impuesto (`fiscal_document_taxes`), y el nodo lleva la
 * alicuota CONGELADA (`tax_rate_snapshot`), la base y el importe. Una venta
 * sin comprobante no tiene nodo de impuesto, y por eso no aparece aqui.
 *
 * Y EL RETENIDO NO SE MEZCLA CON EL TRASLADADO: los dos son el mismo
 * impuesto en espejo, pero el retenido es el CREDITO del comprador y sale en
 * su PROPIO comprobante. Cada fila dice de que tipo es (`tax_type`), y las
 * dos Sumas se leen por separado.
 *
 * Y LO QUE NO CUENTA: un documento en borrador, un documento anulado (`void`)
 * y un documento con `voided_at` no son hechos fiscales. Un comprobante
 * anulado enseña su IVA en el papel y no lo enseña en la declaracion.
 *
 * Y EL HUECO SE DICE: una venta del periodo SIN comprobante emitido NO esta
 * en este reporte, y por eso el reporte dice CUANTAS son y CUANTO valen. Sin
 * ese bloque, un fiscal compararia su declaracion con el reporte y no
 * encontraria la venta que falta. Las cifras del impuesto siguen viniendo
 * SOLAS de los documentos: el bloque del hueco es una cuenta de lo que NO
 * esta, no una mezcla.
 *
 * Y EL ALCANCE es el del ROL, por los DOS caminos del repositorio: los
 * documentos se acotan con el ambito explicito (`ReportScope`) y las ventas
 * se leen por el MODELO, que ya lleva su alcance global. Los dos criterios
 * son el MISMO (`PosContext::scopeIds()`), y por eso el hueco del reporte
 * cuenta justo las ventas que el reporte fiscal no teach.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class VatByRateReport
{
    public function __construct(private Connection $connection) {}

    /**
     * El IVA del periodo, por alicuota.
     *
     * @return array{
     *     rows: list<array{tax_type: string, tax_rate: string|null, base_amount: string, tax_amount: string, documents_count: int}>,
     *     totals: array{base_amount: string, tax_amount: string, documents_count: int},
     *     without_document: array{sales_count: int, amount: string}
     * }
     */
    public function build(ReportScope $scope, ReportPeriod $period): array
    {
        $consulta = $this->connection->table('fiscal_document_taxes')
            ->join('fiscal_documents', 'fiscal_documents.id', '=', 'fiscal_document_taxes.fiscal_document_id')
            ->where('fiscal_documents.status', 'issued')
            ->whereNull('fiscal_documents.voided_at')
            ->whereBetween('fiscal_documents.issue_date', [
                $period->from->format('Y-m-d'),
                $period->to->format('Y-m-d'),
            ]);

        $scope->restrict($consulta, 'fiscal_documents.store_id');

        $filas = $consulta
            ->groupBy('fiscal_document_taxes.tax_type', 'fiscal_document_taxes.tax_rate_snapshot')
            ->orderBy('fiscal_document_taxes.tax_type')
            ->orderBy('fiscal_document_taxes.tax_rate_snapshot')
            ->selectRaw(
                'fiscal_document_taxes.tax_type as tax_type, '
                .'fiscal_document_taxes.tax_rate_snapshot as tax_rate, '
                .'COALESCE(SUM(fiscal_document_taxes.base_amount), 0) as base_amount, '
                .'COALESCE(SUM(fiscal_document_taxes.tax_amount), 0) as tax_amount, '
                .'COUNT(DISTINCT fiscal_document_taxes.fiscal_document_id) as documents_count'
            )
            ->get();

        $rows = [];

        foreach ($filas as $fila) {
            $rows[] = [
                'tax_type' => (string) $fila->tax_type,
                'tax_rate' => $fila->tax_rate === null ? null : PosAmount::text($fila->tax_rate),
                'base_amount' => PosAmount::text($fila->base_amount),
                'tax_amount' => PosAmount::text($fila->tax_amount),
                'documents_count' => (int) $fila->documents_count,
            ];
        }

        $totals = [
            'base_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'documents_count' => 0,
        ];

        foreach ($rows as $row) {
            $totals['base_amount'] = $this->suma($totals['base_amount'], $row['base_amount']);
            $totals['tax_amount'] = $this->suma($totals['tax_amount'], $row['tax_amount']);
            $totals['documents_count'] += $row['documents_count'];
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'without_document' => $this->ventasSinComprobante($scope, $period),
        ];
    }

    /**
     * Las VENTAS del periodo SIN comprobante emitido: el hueco del reporte
     * fiscal.
     *
     * REGLA que fija este metodo: *el hueco se cuenta con las VENTAS y se
     * declara aparte*. Una venta cuenta cuando su comprobante esta EMITIDO: si
     * no lo tiene, o si lo tiene en borrador, o si su comprobante esta
     * anulado, esa venta no esta en el reporte fiscal, y el fiscal tiene que
     * saber cuantas son.
     *
     * @return array{sales_count: int, amount: string}
     */
    private function ventasSinComprobante(ReportScope $scope, ReportPeriod $period): array
    {
        $consulta = Sale::query()
            ->join('sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->leftJoin('fiscal_documents', 'fiscal_documents.id', '=', 'sales.fiscal_document_id')
            ->whereIn('sales.status', ['confirmed', 'paid', 'fiscal_failed'])
            ->whereBetween('sales.sale_date', [
                $period->from->format('Y-m-d'),
                $period->to->format('Y-m-d'),
            ])
            ->where(function ($consulta): void {
                $consulta->whereNull('sales.fiscal_document_id')
                    ->orWhere(function ($consulta): void {
                        $consulta->whereNotIn('fiscal_documents.status', ['issued'])
                            ->orWhereNotNull('fiscal_documents.voided_at');
                    });
            })
            ->toBase();

        $fila = $consulta
            ->selectRaw(
                'COUNT(sales.id) as sales_count, '
                .'COALESCE(SUM(sale_totals.grand_total), 0) as amount'
            )
            ->first();

        return [
            'sales_count' => (int) ($fila->sales_count ?? 0),
            'amount' => PosAmount::text($fila->amount ?? '0'),
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
}
