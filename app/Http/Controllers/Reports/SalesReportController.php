<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\SalesReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las PANTALLAS de las ventas: por periodo, por producto y por cajero.
 *
 * REGLA que fija esta clase: *la pantalla no decide nada*. Los periodos, los
 * importes y las filas son los del dominio (`SalesReport`), y aqui solo se
 * traducen a los props que el React espera.
 *
 * Y LAS VENTAS SE LEEN CON SU ALCANCE: el reporte sale del modelo `Sale`,
 * que ya lleva el alcance global por tienda del ambito del ROL. Por eso estas
 * pantallas NO pasan un ambito: el mismo criterio, en un solo sitio.
 */
class SalesReportController extends ReportController
{
    /**
     * Las VENTAS POR PERIODO: una fila por dia y tienda.
     */
    public function period(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new SalesReport($this->connection))->byPeriod($periodo);

        return Inertia::render('reports/sales', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
            ]
        ));
    }

    /**
     * Las VENTAS POR PRODUCTO: una fila por producto y tienda.
     */
    public function products(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new SalesReport($this->connection))->byProduct($periodo);

        return Inertia::render('reports/sales-products', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
            ]
        ));
    }

    /**
     * Las VENTAS POR CAJERO: una fila por quien las hizo.
     */
    public function cashiers(Request $request): Response
    {
        $periodo = $this->periodo($request);

        $reporte = (new SalesReport($this->connection))->byCashier($periodo);

        return Inertia::render('reports/sales-cashiers', array_merge(
            $this->props($request, $periodo),
            [
                'rows' => $reporte['rows'],
                'totals' => $reporte['totals'],
            ]
        ));
    }
}
