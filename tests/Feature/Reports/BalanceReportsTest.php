<?php

use App\Domain\Reports\CashCloseReport;
use App\Domain\Reports\CustomerAgingReport;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\ValuedInventoryReport;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\ReportFixture;

/**
 * Los REPORTES DE SALDOS: el aging, el inventario valorado, el arqueo y el Z
 * por periodo.
 *
 * Reglas que fija:
 *
 *  - el AGING sale del LIBRO DEL CLIENTE, con el saldo DERIVADO y con su
 *    signo: un saldo negativo es credito a favor, no una deuda negativa;
 *  - la antiguedad se DERIVA: un cobro cubre la deuda mas ANTIGUA primero,
 *    y lo que queda sin cubrir es lo que se debe, con los dias que lleva
 *    abierto;
 *  - el INVENTARIO VALORADO sale de los LOTES, nunca del cache: el cache se
 *    puede desfasar, y un reporte sobre el cache miente;
 *  - el ARQUEO y el Z POR PERIODO se LEEN del cierre sellado: un cierre es
 *    un hecho, y su firma se comprueba al leerlo;
 *  - un dia que no se cerro no es un dia de cero: sale aparte.
 */

/**
 * El aging de un cliente del reporte.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function aging_fila_de(array $rows, int $customerId): ?array
{
    foreach ($rows as $row) {
        if ((int) $row['customer_id'] === $customerId) {
            return $row;
        }
    }

    return null;
}

/**
 * El aging sale del LIBRO DEL CLIENTE, con el saldo derivado y con su signo.
 */
test('test_el_aging_de_clientes_sale_del_libro_del_cliente', function () {
    $storeId = ReportFixture::store();
    $customerId = CreditFixture::customer($storeId, 'Cliente que debe');

    ReportFixture::movimientoCliente($customerId, $storeId, '2026-01-05', 'sale', '100.0000');

    $reporte = new CustomerAgingReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    $fila = aging_fila_de($resultado['rows'], $customerId);

    expect($fila)->not->toBeNull()
        ->and($fila['balance'])->toBe('100.0000')
        ->and($fila['due'])->toBe('100.0000')
        ->and($fila['credit'])->toBe('0.0000')
        ->and($fila['oldest_date'])->toBe('2026-01-05')
        ->and($fila['age_days'])->toBe(26)
        ->and($fila['items'])->toHaveCount(1)
        ->and($resultado['totals']['due'])->toBe('100.0000');
});

/**
 * Un saldo NEGATIVO es credito a favor: no se debe nada.
 */
test('test_el_aging_de_clientes_distingue_el_credito_a_favor', function () {
    $storeId = ReportFixture::store();
    $customerId = CreditFixture::customer($storeId, 'Cliente con credito');

    ReportFixture::movimientoCliente($customerId, $storeId, '2026-01-05', 'sale', '100.0000');
    ReportFixture::movimientoCliente($customerId, $storeId, '2026-01-10', 'payment', '-150.0000');

    $reporte = new CustomerAgingReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    $fila = aging_fila_de($resultado['rows'], $customerId);

    expect($fila)->not->toBeNull()
        ->and($fila['balance'])->toBe('-50.0000')
        ->and($fila['due'])->toBe('0.0000')
        ->and($fila['credit'])->toBe('50.0000')
        ->and($fila['items'])->toBe([]);
});

/**
 * Un cobro cubre la deuda MAS ANTIGUA primero: la deuda reciente esta
 * pagada y la vieja sigue abierta.
 */
test('test_el_aging_de_clientes_cubre_la_deuda_mas_antigua_primero', function () {
    $storeId = ReportFixture::store();
    $customerId = CreditFixture::customer($storeId, 'Cliente con dos deudas');

    ReportFixture::movimientoCliente($customerId, $storeId, '2026-01-05', 'sale', '100.0000');
    ReportFixture::movimientoCliente($customerId, $storeId, '2026-01-20', 'sale', '50.0000');
    ReportFixture::movimientoCliente($customerId, $storeId, '2026-01-25', 'payment', '-120.0000');

    $reporte = new CustomerAgingReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    $fila = aging_fila_de($resultado['rows'], $customerId);

    expect($fila)->not->toBeNull()
        ->and($fila['balance'])->toBe('30.0000')
        ->and($fila['oldest_date'])->toBe('2026-01-20')
        ->and($fila['items'])->toHaveCount(1)
        ->and($fila['items'][0]['amount'])->toBe('30.0000');
});

/**
 * El inventario valorado sale de los LOTES, no del cache de la tienda.
 */
test('test_el_inventario_valorado_sale_de_los_lotes_y_no_del_cache', function () {
    $storeId = ReportFixture::store();
    $productId = ReportFixture::producto();

    ReportFixture::lote($productId, $storeId, '10.0000', '4.0000', '2026-01-01 08:00:00+00:00');
    ReportFixture::lote($productId, $storeId, '5.0000', '6.0000', '2026-02-01 08:00:00+00:00');

    // El CACHE de la tienda miente: el reporte enseña los lotes.
    DB::table('product_stores')->insert([
        'product_id' => $productId,
        'store_id' => $storeId,
        'purchase_price' => '4.0000',
        'sell_price' => '10.0000',
        'quantity_in_stock' => '999.0000',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reporte = new ValuedInventoryReport(DB::connection());

    $resultado = $reporte->build(ReportScope::of(null));

    expect($resultado['rows'])->toHaveCount(1)
        ->and($resultado['rows'][0]['quantity'])->toBe('15.0000')
        // El valor es el de cada capa a su propio coste: 10 x 4 + 5 x 6.
        ->and($resultado['rows'][0]['value'])->toBe('70.0000')
        ->and($resultado['totals']['value'])->toBe('70.0000')
        // Y las capas salen en el ORDEN del FIFO.
        ->and($resultado['rows'][0]['layers'][0]['received_at'])->toBe('2026-01-01 08:00:00+00:00')
        ->and($resultado['rows'][0]['layers'][1]['received_at'])->toBe('2026-02-01 08:00:00+00:00');
});

/**
 * El arqueo LEE el cierre sellado, y comprueba su firma.
 */
test('test_el_arqueo_lee_el_cierre_sellado_y_comprueba_su_firma', function () {
    $storeId = ReportFixture::store();
    ReportFixture::plan($storeId);

    ReportFixture::cierre([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'expected_balance' => '100.0000',
        'counted_balance' => '95.0000',
        'difference' => '-5.0000',
    ]);

    $reporte = new CashCloseReport(DB::connection());

    $resultado = $reporte->cashCount(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    expect($resultado['rows'])->toHaveCount(1)
        ->and($resultado['rows'][0]['expected_balance'])->toBe('100.0000')
        ->and($resultado['rows'][0]['counted_balance'])->toBe('95.0000')
        ->and($resultado['rows'][0]['difference'])->toBe('-5.0000')
        ->and($resultado['rows'][0]['verified'])->toBeTrue()
        ->and($resultado['totals']['difference'])->toBe('-5.0000');
});

/**
 * Un Z ALTERADO no es un Z: la firma no cuadra y el arqueo lo dice.
 */
test('test_el_arqueo_avisa_de_un_cierre_alterado', function () {
    $storeId = ReportFixture::store();
    ReportFixture::plan($storeId);

    $cierreId = ReportFixture::cierre([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'expected_balance' => '100.0000',
        'counted_balance' => '100.0000',
        'difference' => '0.0000',
    ]);

    // Alguien TOCA el cierre despues de sellarlo.
    DB::table('cash_closes')->where('id', $cierreId)->update([
        'counted_balance' => '500.0000',
    ]);

    $reporte = new CashCloseReport(DB::connection());

    $resultado = $reporte->cashCount(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    expect($resultado['rows'])->toHaveCount(1)
        ->and($resultado['rows'][0]['verified'])->toBeFalse();
});

/**
 * El Z por periodo se arma con los cierres, no se recalcula.
 */
test('test_el_z_por_periodo_se_arma_con_los_cierres', function () {
    $storeId = ReportFixture::store();
    ReportFixture::plan($storeId);

    ReportFixture::cierre([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'subtotal' => '100.0000',
        'tax_total' => '16.0000',
        'grand_total' => '116.0000',
        'sales_count' => 4,
        'first_document_number' => '0001',
        'last_document_number' => '0004',
    ]);

    ReportFixture::cierre([
        'store_id' => $storeId,
        'date' => '2026-01-11',
        'subtotal' => '50.0000',
        'tax_total' => '8.0000',
        'grand_total' => '58.0000',
        'sales_count' => 2,
        'first_document_number' => '0005',
        'last_document_number' => '0006',
    ]);

    $reporte = new CashCloseReport(DB::connection());

    $resultado = $reporte->periodZ(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    expect($resultado['rows'])->toHaveCount(2)
        ->and($resultado['totals']['sales_count'])->toBe(6)
        ->and($resultado['totals']['subtotal'])->toBe('150.0000')
        ->and($resultado['totals']['tax_total'])->toBe('24.0000')
        ->and($resultado['totals']['grand_total'])->toBe('174.0000');
});

/**
 * Un dia que NO se cerro no es un dia de cero: sale aparte, con su hecho.
 */
test('test_el_z_por_periodo_declara_los_dias_sin_cierre', function () {
    $storeId = ReportFixture::store();
    ReportFixture::plan($storeId);

    ReportFixture::cajaAbierta($storeId, '2026-01-10');

    $reporte = new CashCloseReport(DB::connection());

    $resultado = $reporte->periodZ(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-10', '2026-01-12')
    );

    expect($resultado['rows'])->toBe([])
        ->and($resultado['without_close'])->toHaveCount(1)
        ->and($resultado['without_close'][0]['days'][0]['date'])->toBe('2026-01-10')
        ->and($resultado['without_close'][0]['days'][0]['till_open'])->toBeTrue();
});
