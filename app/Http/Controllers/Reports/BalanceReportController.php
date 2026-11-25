<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\CashCloseReport;
use App\Domain\Reports\CustomerAgingReport;
use App\Domain\Reports\ValuedInventoryReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las PANTALLAS de los saldos: el aging, el inventario valorado, el arqueo y
 * el Z por periodo.
 *
 * REGLA que fija esta clase: *el inventario valorado NO tiene periodo*. Es una
 * FOTO: los lotes son los de ahora, y un historial de valoraciones no
 * existe. Inventariarlo con un periodo seria inventar un dato.
 */
class BalanceReportController extends ReportController
{
    /**
     * El AGING DE CLIENTES.
     */
    public function aging(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new CustomerAgingReport($this->connection))->build(
            $this->ambito($request),
            $periodo
        );

        return Inertia::render('reports/customer-aging', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
            ]
        ));
    }

    /**
     * El INVENTARIO VALORADO.
     */
    public function inventory(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new ValuedInventoryReport($this->connection))->build(
            $this->ambito($request)
        );

        return Inertia::render('reports/valued-inventory', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
            ]
        ));
    }

    /**
     * El ARQUEO del periodo: dia por dia, la conciliacion de la caja.
     */
    public function cashCount(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new CashCloseReport($this->connection))->cashCount(
            $this->ambito($request),
            $periodo
        );

        return Inertia::render('reports/cash-count', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
                'without_close' => $reporte['without_close'],
            ]
        ));
    }

    /**
     * El Z POR PERIODO: el resumen fiscal de los dias cerrados.
     */
    public function periodZ(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new CashCloseReport($this->connection))->periodZ(
            $this->ambito($request),
            $periodo
        );

        return Inertia::render('reports/period-z', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
                'without_close' => $reporte['without_close'],
            ]
        ));
    }
}
