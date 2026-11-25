<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\CashBookReport;
use App\Domain\Reports\ProfitAndLossReport;
use App\Domain\Reports\VatByRateReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las PANTALLAS de la contabilidad: el IVA, el PyG y el libro de caja.
 *
 * REGLA que fija esta clase: *el IVA y el PyG son DOS reportes distintos y no
 * se mezclan*. El fiscal sale de los COMPROBANTES y el de resultados sale del
 * LIBRO. Y el libro de caja sale de los ASIENTOS de la cuenta de efectivo.
 *
 * Y EL ALCANCE es el del ROL, y aqui se aplica EXPLICITO (`ReportScope`):
 * el libro, los documentos y el aging no son tablas con un alcance global, y
 * el criterio es el MISMO que el de las ventas (`PosContext::scopeIds()`).
 */
class AccountingReportController extends ReportController
{
    /**
     * El IVA POR ALIQUOTA: el reporte fiscal.
     */
    public function vat(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new VatByRateReport($this->connection))->build(
            $this->ambito($request),
            $periodo
        );

        return Inertia::render('reports/vat', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
                'without_document' => $reporte['without_document'],
            ]
        ));
    }

    /**
     * El RESULTADO del periodo: perdidas y ganancias.
     */
    public function profitAndLoss(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new ProfitAndLossReport($this->connection))->build(
            $this->ambito($request),
            $periodo
        );

        return Inertia::render('reports/profit-and-loss', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
            ]
        ));
    }

    /**
     * El LIBRO DE CAJA del periodo.
     */
    public function cashBook(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new CashBookReport($this->connection))->build(
            $this->ambito($request),
            $periodo
        );

        return Inertia::render('reports/cash-book', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
                'without_movement' => $reporte['without_movement'],
            ]
        ));
    }
}
