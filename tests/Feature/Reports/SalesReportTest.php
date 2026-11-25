<?php

use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\SalesReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ReportFixture;

/**
 * El REPORTE DE VENTAS: por periodo, por producto y por cajero.
 *
 * Reglas que fija:
 *
 *  - el reporte de ventas sale de las VENTAS: una venta del periodo cuenta
 *    con su total, su impuesto y su cantidad, y el total del reporte es la
 *    SUMA de las filas que ensena;
 *  - una venta SIN COMPROBANTE (en `fiscal_failed`) CUENTA: el fallo es del
 *    comprobante, no de la venta, y una venta que no cuenta en el reporte de
 *    ventas es una venta que nadie puede defender despues;
 *  - una venta ANULADA (`cancelled`) y un BORRADOR (`draft`) NO cuentan: una
 *    venta anulada no es una venta, y un borrador tampoco;
 *  - el ALCANCE es el del ROL: una venta de una tienda fuera del ambito del
 *    rol no existe para quien pregunta;
 *  - una venta de una fecha FUERA del periodo no cuenta.
 */

/**
 * La FILA de un dia del reporte de ventas por periodo.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function ventas_fila_de(array $rows, string $date, int $storeId): ?array
{
    foreach ($rows as $row) {
        if ($row['date'] === $date && (int) $row['store_id'] === $storeId) {
            return $row;
        }
    }

    return null;
}

/**
 * Las ventas del periodo salen de las VENTAS, con sus totales.
 */
test('test_el_reporte_de_ventas_por_periodo_sale_de_las_ventas', function () {
    $storeId = ReportFixture::store();

    ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'subtotal' => '100.0000',
        'tax_total' => '16.0000',
        'grand_total' => '116.0000',
    ]);

    ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'subtotal' => '50.0000',
        'tax_total' => '8.0000',
        'grand_total' => '58.0000',
    ]);

    $reporte = new SalesReport(DB::connection());

    $resultado = $reporte->byPeriod(ReportPeriod::between('2026-01-01', '2026-01-31'));

    $fila = ventas_fila_de($resultado['rows'], '2026-01-10', $storeId);

    expect($fila)->not->toBeNull()
        ->and($fila['sales_count'])->toBe(2)
        ->and($fila['subtotal'])->toBe('150.0000')
        ->and($fila['tax_total'])->toBe('24.0000')
        ->and($fila['grand_total'])->toBe('174.0000')
        // Y el TOTAL del reporte es la suma de las filas: el agregado y las
        // filas cuentan lo mismo.
        ->and($resultado['totals']['sales_count'])->toBe(2)
        ->and($resultado['totals']['grand_total'])->toBe('174.0000');
});

/**
 * Una venta SIN COMPROBANTE cuenta en el reporte de ventas.
 */
test('test_el_reporte_de_ventas_cuenta_las_ventas_sin_comprobante', function () {
    $storeId = ReportFixture::store();

    ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'status' => 'fiscal_failed',
        'grand_total' => '58.0000',
    ]);

    $reporte = new SalesReport(DB::connection());

    $resultado = $reporte->byPeriod(ReportPeriod::between('2026-01-01', '2026-01-31'));

    $fila = ventas_fila_de($resultado['rows'], '2026-01-10', $storeId);

    expect($fila)->not->toBeNull()
        ->and($fila['sales_count'])->toBe(1)
        ->and($fila['grand_total'])->toBe('58.0000');
});

/**
 * Una venta ANULADA y un BORRADOR no cuentan.
 */
test('test_el_reporte_de_ventas_no_cuenta_las_ventas_anuladas_ni_los_borradores', function () {
    $storeId = ReportFixture::store();

    ReportFixture::sale(['store_id' => $storeId, 'date' => '2026-01-10', 'status' => 'cancelled']);
    ReportFixture::sale(['store_id' => $storeId, 'date' => '2026-01-10', 'status' => 'draft']);

    $reporte = new SalesReport(DB::connection());

    $resultado = $reporte->byPeriod(ReportPeriod::between('2026-01-01', '2026-01-31'));

    expect($resultado['rows'])->toBe([])
        ->and($resultado['totals']['sales_count'])->toBe(0);
});

/**
 * Una venta de una fecha FUERA del periodo no cuenta.
 */
test('test_el_reporte_de_ventas_solo_cuenta_el_periodo_pedido', function () {
    $storeId = ReportFixture::store();

    ReportFixture::sale(['store_id' => $storeId, 'date' => '2026-01-10']);
    ReportFixture::sale(['store_id' => $storeId, 'date' => '2026-02-10']);

    $reporte = new SalesReport(DB::connection());

    $resultado = $reporte->byPeriod(ReportPeriod::between('2026-01-01', '2026-01-31'));

    expect($resultado['totals']['sales_count'])->toBe(1)
        ->and($resultado['totals']['grand_total'])->toBe('58.0000');
});

/**
 * Las ventas por PRODUCTO: una fila por producto, con su cantidad y su
 * importe.
 */
test('test_el_reporte_de_ventas_por_producto_agrupa_por_producto', function () {
    $storeId = ReportFixture::store();
    $productoId = ReportFixture::producto();

    ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'product_id' => $productoId,
        'quantity' => '2.0000',
        'net_amount' => '50.0000',
        'line_tax' => '8.0000',
        'line_total' => '58.0000',
    ]);

    ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-11',
        'product_id' => $productoId,
        'quantity' => '1.0000',
        'net_amount' => '25.0000',
        'line_tax' => '4.0000',
        'line_total' => '29.0000',
    ]);

    $reporte = new SalesReport(DB::connection());

    $resultado = $reporte->byProduct(ReportPeriod::between('2026-01-01', '2026-01-31'));

    expect($resultado['rows'])->toHaveCount(1)
        ->and($resultado['rows'][0]['product_id'])->toBe($productoId)
        ->and($resultado['rows'][0]['quantity'])->toBe('3.0000')
        ->and($resultado['rows'][0]['net_amount'])->toBe('75.0000')
        ->and($resultado['rows'][0]['tax_amount'])->toBe('12.0000')
        ->and($resultado['rows'][0]['total_amount'])->toBe('87.0000')
        ->and($resultado['totals']['total_amount'])->toBe('87.0000');
});

/**
 * Las ventas por CAJERO: una fila por quien las hizo.
 */
test('test_el_reporte_de_ventas_por_cajero_agrupa_por_cajero', function () {
    $storeId = ReportFixture::store();
    $cajero = User::factory()->create(['name' => 'Cajero del reporte']);

    ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'user_id' => (int) $cajero->id,
        'grand_total' => '58.0000',
    ]);

    $reporte = new SalesReport(DB::connection());

    $resultado = $reporte->byCashier(ReportPeriod::between('2026-01-01', '2026-01-31'));

    expect($resultado['rows'])->toHaveCount(1)
        ->and($resultado['rows'][0]['name'])->toBe('Cajero del reporte')
        ->and($resultado['rows'][0]['sales_count'])->toBe(1)
        ->and($resultado['rows'][0]['grand_total'])->toBe('58.0000');
});
