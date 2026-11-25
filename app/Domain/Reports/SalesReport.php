<?php

namespace App\Domain\Reports;

use App\Models\Sale;
use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * El REPORTE DE VENTAS: por periodo, por producto y por cajero.
 *
 * REGLA que fija esta clase: *el reporte de ventas sale de las VENTAS*. Las
 * cifras de una venta son las de su cabecera (`sales`), sus totales
 * (`sale_totals`) y sus lineas (`sale_items`), y no las del libro contable ni
 * las de los documentos fiscales: el reporte FISCAL sale de los documentos y
 * el reporte de VENTAS sale de las ventas, y son dos hechos distintos.
 *
 * Y LA VENTA SIN COMPROBANTE CUENTA AQUI: una venta en `fiscal_failed` tiene
 * el dinero cobrado y el producto entregado, asi que es una venta, y su
 * hueco fiscal se dice en el reporte fiscal (no aqui). Una venta que no
 * cuenta en el reporte de ventas es una venta que nadie puede defender
 * despues.
 *
 * Y LO QUE NO CUENTA: un `draft` no es una venta (es un borrador) y una
 * venta `cancelled` no es una venta (fue anulada, con su nota de credito).
 *
 * Y EL ALCANCE es el de las VENTAS: las consultas salen del modelo `Sale`,
 * que ya lleva su ALCANCE POR DEFECTO (`App\Support\Pos\StoreScope`), que es
 * el ambito del ROL. Por eso este reporte NO lleva un filtro de tienda
 * encima: dos filtros del mismo criterio son dos filtros que se pueden
 * desincronizar. Ver `ReportScope` para el resto de los reportes.
 *
 * Y LOS TOTALES son la SUMA DE LAS FILAS, calculada en memoria: el agregado
 * y las filas tienen que contar lo mismo, y la unica forma de que eso sea
 * cierto es que el agregado salga de las filas.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class SalesReport
{
    public function __construct(private Connection $connection) {}

    /**
     * Los ESTADOS de una venta que CUENTAN en el reporte.
     *
     * @var list<string>
     */
    private const ESTADOS_CUENTAN = ['confirmed', 'paid', 'fiscal_failed'];

    /**
     * Las VENTAS por PERIODO: una fila por dia y tienda.
     *
     * @return array{
     *     rows: list<array{date: string, store_id: int, store_name: string, sales_count: int, subtotal: string, discount: string, tax_total: string, grand_total: string}>,
     *     totals: array{sales_count: int, subtotal: string, discount: string, tax_total: string, grand_total: string}
     * }
     */
    public function byPeriod(ReportPeriod $period): array
    {
        $filas = $this->base()
            ->join('sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->join('stores', 'stores.id', '=', 'sales.store_id')
            ->whereBetween('sales.sale_date', [$period->from->format('Y-m-d'), $period->to->format('Y-m-d')])
            ->groupBy('sales.sale_date', 'sales.store_id', 'stores.name')
            ->orderBy('sales.sale_date')
            ->orderBy('sales.store_id')
            ->selectRaw(
                'sales.sale_date as date, '
                .'sales.store_id as store_id, '
                .'stores.name as store_name, '
                .'COUNT(sales.id) as sales_count, '
                .'COALESCE(SUM(sale_totals.sub_total), 0) as subtotal, '
                .'COALESCE(SUM(sale_totals.discount), 0) as discount, '
                .'COALESCE(SUM(sale_totals.tax_total), 0) as tax_total, '
                .'COALESCE(SUM(sale_totals.grand_total), 0) as grand_total'
            )
            ->get();

        $rows = [];

        foreach ($filas as $fila) {
            $rows[] = [
                'date' => (string) $fila->date,
                'store_id' => (int) $fila->store_id,
                'store_name' => (string) $fila->store_name,
                'sales_count' => (int) $fila->sales_count,
                'subtotal' => PosAmount::text($fila->subtotal),
                'discount' => PosAmount::text($fila->discount),
                'tax_total' => PosAmount::text($fila->tax_total),
                'grand_total' => PosAmount::text($fila->grand_total),
            ];
        }

        return ['rows' => $rows, 'totals' => $this->totals($rows)];
    }

    /**
     * Las VENTAS por PRODUCTO: una fila por producto y tienda.
     *
     * La BASE es la de la linea (`sale_items.base_amount`), que es lo cobrado
     * sin impuesto, y el TOTAL es el de la linea (`sale_items.line_total`),
     * que ya lleva el impuesto y el redondeo de la linea.
     *
     * @return array{
     *     rows: list<array{product_id: int, code: string, name: string, store_id: int, store_name: string, quantity: string, net_amount: string, tax_amount: string, total_amount: string}>,
     *     totals: array{quantity: string, net_amount: string, tax_amount: string, total_amount: string}
     * }
     */
    public function byProduct(ReportPeriod $period): array
    {
        $filas = $this->base()
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('stores', 'stores.id', '=', 'sale_items.store_id')
            ->whereBetween('sales.sale_date', [$period->from->format('Y-m-d'), $period->to->format('Y-m-d')])
            ->groupBy(
                'sale_items.product_id',
                'products.code',
                'products.name',
                'sale_items.store_id',
                'stores.name'
            )
            ->orderByDesc('total_amount')
            ->orderBy('sale_items.product_id')
            ->selectRaw(
                'sale_items.product_id as product_id, '
                .'products.code as code, '
                .'products.name as name, '
                .'sale_items.store_id as store_id, '
                .'stores.name as store_name, '
                .'COALESCE(SUM(sale_items.quantity), 0) as quantity, '
                .'COALESCE(SUM(sale_items.base_amount), 0) as net_amount, '
                .'COALESCE(SUM(sale_items.tax_amount), 0) as tax_amount, '
                .'COALESCE(SUM(sale_items.line_total), 0) as total_amount'
            )
            ->get();

        $rows = [];

        foreach ($filas as $fila) {
            $rows[] = [
                'product_id' => (int) $fila->product_id,
                'code' => (string) $fila->code,
                'name' => (string) $fila->name,
                'store_id' => (int) $fila->store_id,
                'store_name' => (string) $fila->store_name,
                'quantity' => PosAmount::text($fila->quantity),
                'net_amount' => PosAmount::text($fila->net_amount),
                'tax_amount' => PosAmount::text($fila->tax_amount),
                'total_amount' => PosAmount::text($fila->total_amount),
            ];
        }

        $totales = [
            'quantity' => '0.0000',
            'net_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'total_amount' => '0.0000',
        ];

        foreach ($rows as $row) {
            $totales['quantity'] = $this->suma($totales['quantity'], $row['quantity']);
            $totales['net_amount'] = $this->suma($totales['net_amount'], $row['net_amount']);
            $totales['tax_amount'] = $this->suma($totales['tax_amount'], $row['tax_amount']);
            $totales['total_amount'] = $this->suma($totales['total_amount'], $row['total_amount']);
        }

        return ['rows' => $rows, 'totals' => $totales];
    }

    /**
     * Las VENTAS por CAJERO: una fila por quien las hizo.
     *
     * El cajero es `sales.created_by`, y una venta SIN cajero (un importe
     * cargado por el sistema) sale con su hueco a la vista: un cajero que no
     * se sabe es un cajero que nadie puede auditar.
     *
     * @return array{
     *     rows: list<array{user_id: int|null, name: string|null, sales_count: int, subtotal: string, tax_total: string, grand_total: string}>,
     *     totals: array{sales_count: int, subtotal: string, tax_total: string, grand_total: string}
     * }
     */
    public function byCashier(ReportPeriod $period): array
    {
        $filas = $this->base()
            ->join('sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->leftJoin('users', 'users.id', '=', 'sales.created_by')
            ->whereBetween('sales.sale_date', [$period->from->format('Y-m-d'), $period->to->format('Y-m-d')])
            ->groupBy('sales.created_by', 'users.name')
            ->orderByDesc('grand_total')
            ->orderBy('sales.created_by')
            ->selectRaw(
                'sales.created_by as user_id, '
                .'users.name as name, '
                .'COUNT(sales.id) as sales_count, '
                .'COALESCE(SUM(sale_totals.sub_total), 0) as subtotal, '
                .'COALESCE(SUM(sale_totals.tax_total), 0) as tax_total, '
                .'COALESCE(SUM(sale_totals.grand_total), 0) as grand_total'
            )
            ->get();

        $rows = [];

        foreach ($filas as $fila) {
            $rows[] = [
                'user_id' => $fila->user_id === null ? null : (int) $fila->user_id,
                'name' => $fila->name === null ? null : (string) $fila->name,
                'sales_count' => (int) $fila->sales_count,
                'subtotal' => PosAmount::text($fila->subtotal),
                'tax_total' => PosAmount::text($fila->tax_total),
                'grand_total' => PosAmount::text($fila->grand_total),
            ];
        }

        return ['rows' => $rows, 'totals' => $this->totals($rows)];
    }

    /**
     * La CONSULTA base: las ventas que CUENTAN, del modelo.
     *
     * REGLA que fija este metodo: *la consulta sale del MODELO `Sale`*, que
     * lleva su alcance global por tienda. El reporte no anade un filtro de
     * tienda encima: el alcance es UNO, y por eso el reporte de ventas y el
     * de la contabilidad ven exactamente las mismas tiendas.
     *
     * Y EL FILTRO DE ESTADOS es el MISMO en las tres vistas: un reporte que
     * cuenta unas ventas y otro que no es dos verdades sobre el mismo mes.
     */
    private function base(): Builder
    {
        return Sale::query()
            ->whereIn('sales.status', self::ESTADOS_CUENTAN)
            ->toBase();
    }

    /**
     * Los TOTALES de las ventas: la suma de las filas, en memoria.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{sales_count: int, subtotal: string, discount: string, tax_total: string, grand_total: string}
     */
    private function totals(array $rows): array
    {
        $totales = [
            'sales_count' => 0,
            'subtotal' => '0.0000',
            'discount' => '0.0000',
            'tax_total' => '0.0000',
            'grand_total' => '0.0000',
        ];

        foreach ($rows as $row) {
            $totales['sales_count'] += (int) $row['sales_count'];
            $totales['subtotal'] = $this->suma($totales['subtotal'], (string) $row['subtotal']);
            $totales['tax_total'] = $this->suma($totales['tax_total'], (string) $row['tax_total']);
            $totales['grand_total'] = $this->suma($totales['grand_total'], (string) $row['grand_total']);

            if (array_key_exists('discount', $row)) {
                $totales['discount'] = $this->suma($totales['discount'], (string) $row['discount']);
            }
        }

        return $totales;
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
