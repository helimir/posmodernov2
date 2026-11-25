<?php

use App\Domain\Reports\CashBookReport;
use App\Domain\Reports\ProfitAndLossReport;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\VatByRateReport;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ReportFixture;

/**
 * Los REPORTES DE CONTABILIDAD: el IVA, el PyG y el libro de caja.
 *
 * Reglas que fija:
 *
 *  - el IVA sale de los COMPROBANTES: una venta sin comprobante no esta en el
 *    reporte fiscal, y el reporte DICE cuantas ventas se quedan fuera;
 *  - el IVA RETENIDO no se mezcla con el TRASLADADO: los dos son filas
 *    distintas, y cada una dice de que tipo es;
 *  - el PyG sale del LIBRO y separa por CUENTA: un ingreso se asienta como
 *    `bank_movement`, el mismo tipo que un traspaso, y lo que los distingue
 *    es la cuenta de contrapartida;
 *  - el IVA NO es un ingreso del PyG: se abona en una cuenta de pasivo;
 *  - el libro de caja sale de los ASIENTOS de la cuenta de efectivo de cada
 *    tienda, con el saldo que se trae del periodo anterior.
 */

/**
 * La fila de una alicuota del reporte de IVA.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function vat_fila_de(array $rows, string $tipo, ?string $tasa): ?array
{
    foreach ($rows as $row) {
        if ($row['tax_type'] === $tipo && $row['tax_rate'] === $tasa) {
            return $row;
        }
    }

    return null;
}

/**
 * El IVA sale de los COMPROBANTES, no de las ventas.
 */
test('test_el_reporte_de_iva_sale_de_los_comprobantes', function () {
    $storeId = ReportFixture::store();

    ReportFixture::comprobante([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'tax_type' => 'transfer',
        'tax_rate' => '16.0000',
        'base' => '100.0000',
        'tax' => '16.0000',
    ]);

    $reporte = new VatByRateReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    $fila = vat_fila_de($resultado['rows'], 'transfer', '16.0000');

    expect($fila)->not->toBeNull()
        ->and($fila['base_amount'])->toBe('100.0000')
        ->and($fila['tax_amount'])->toBe('16.0000')
        ->and($fila['documents_count'])->toBe(1)
        ->and($resultado['totals']['tax_amount'])->toBe('16.0000');
});

/**
 * El RETENIDO no se mezcla con el TRASLADADO.
 */
test('test_el_reporte_de_iva_separa_el_retenido_del_trasladado', function () {
    $storeId = ReportFixture::store();

    ReportFixture::comprobante([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'tax_type' => 'transfer',
        'tax_rate' => '16.0000',
        'base' => '100.0000',
        'tax' => '16.0000',
    ]);

    ReportFixture::comprobante([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'document_type' => 'retention_receipt',
        'tax_type' => 'withholding',
        'tax_rate' => '16.0000',
        'base' => '100.0000',
        'tax' => '4.0000',
    ]);

    $reporte = new VatByRateReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    $trasladado = vat_fila_de($resultado['rows'], 'transfer', '16.0000');
    $retenido = vat_fila_de($resultado['rows'], 'withholding', '16.0000');

    expect($trasladado)->not->toBeNull()
        ->and($trasladado['tax_amount'])->toBe('16.0000')
        ->and($retenido)->not->toBeNull()
        ->and($retenido['tax_amount'])->toBe('4.0000');
});

/**
 * Una venta SIN comprobante no esta en el reporte fiscal, y el reporte DICE
 * cuantas son.
 */
test('test_el_reporte_de_iva_declara_las_ventas_sin_comprobante', function () {
    $storeId = ReportFixture::store();

    ReportFixture::comprobante([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'tax_type' => 'transfer',
        'tax_rate' => '16.0000',
        'base' => '100.0000',
        'tax' => '16.0000',
    ]);

    // Una venta COBRADA y con su comprobante: no es un hueco.
    $conComprobante = ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-10',
        'grand_total' => '116.0000',
    ]);

    DB::table('sales')->where('id', $conComprobante)->update([
        'fiscal_document_id' => DB::table('fiscal_documents')->orderBy('id')->value('id'),
    ]);

    // Y una venta SIN comprobante: el hueco del reporte fiscal.
    ReportFixture::sale([
        'store_id' => $storeId,
        'date' => '2026-01-11',
        'status' => 'fiscal_failed',
        'grand_total' => '58.0000',
    ]);

    $reporte = new VatByRateReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    expect($resultado['without_document']['sales_count'])->toBe(1)
        ->and($resultado['without_document']['amount'])->toBe('58.0000')
        // Y el impuesto del reporte sigue siendo el de los documentos.
        ->and($resultado['totals']['tax_amount'])->toBe('16.0000');
});

/**
 * El PyG separa por CUENTA: un ingreso y un traspaso son el mismo tipo de
 * asiento, y solo la cuenta los distingue.
 */
test('test_el_reporte_de_resultados_separa_los_ingresos_por_cuenta', function () {
    $storeId = ReportFixture::store();
    ReportFixture::plan($storeId);

    // El INGRESO: debito a la caja, credito a la cuenta de ingresos.
    ReportFixture::asiento($storeId, '2026-01-10', [
        ['account' => '1100000', 'debit' => '50.0000'],
        ['account' => '4020000', 'credit' => '50.0000'],
    ], tipo: 'bank_movement', referenceType: 'income');

    // El TRASPASO: debito al banco, credito a la caja. El MISMO tipo de
    // asiento, y no es un ingreso.
    ReportFixture::asiento($storeId, '2026-01-10', [
        ['account' => '1110000', 'debit' => '20.0000'],
        ['account' => '1100000', 'credit' => '20.0000'],
    ], tipo: 'bank_movement', referenceType: 'bank_movement');

    $reporte = new ProfitAndLossReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    expect($resultado['rows'])->toHaveCount(1)
        ->and($resultado['rows'][0]['account_code'])->toBe('4020000')
        ->and($resultado['rows'][0]['amount'])->toBe('50.0000')
        ->and($resultado['totals']['income'])->toBe('50.0000')
        ->and($resultado['totals']['expense'])->toBe('0.0000')
        ->and($resultado['totals']['result'])->toBe('50.0000');
});

/**
 * El IVA NO es un ingreso del PyG.
 */
test('test_el_reporte_de_resultados_no_cuenta_el_impuesto_como_ingreso', function () {
    $storeId = ReportFixture::store();
    ReportFixture::plan($storeId);

    ReportFixture::asiento($storeId, '2026-01-10', [
        ['account' => '1100000', 'debit' => '116.0000'],
        ['account' => '4010000', 'credit' => '100.0000'],
        ['account' => '2110000', 'credit' => '16.0000'],
    ]);

    $reporte = new ProfitAndLossReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    expect($resultado['totals']['income'])->toBe('100.0000')
        ->and($resultado['totals']['result'])->toBe('100.0000')
        ->and($resultado['rows'])->toHaveCount(1);
});

/**
 * El libro de caja sale de los ASIENTOS de la cuenta de efectivo, con el
 * saldo que se trae.
 */
test('test_el_libro_de_caja_sale_de_los_asientos_de_la_caja', function () {
    $storeId = ReportFixture::store();
    ReportFixture::plan($storeId);

    // El saldo que se trae: una caja abierta con 100.
    ReportFixture::asiento($storeId, '2025-12-31', [
        ['account' => '1100000', 'debit' => '100.0000'],
        ['account' => '3100000', 'credit' => '100.0000'],
    ], tipo: 'opening_balance');

    // Y el movimiento del periodo: entran 58.
    ReportFixture::asiento($storeId, '2026-01-10', [
        ['account' => '1100000', 'debit' => '58.0000'],
        ['account' => '4010000', 'credit' => '50.0000'],
        ['account' => '2110000', 'credit' => '8.0000'],
    ]);

    $reporte = new CashBookReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-01', '2026-01-31')
    );

    expect($resultado['rows'])->toHaveCount(1)
        ->and($resultado['rows'][0]['opening_balance'])->toBe('100.0000')
        ->and($resultado['rows'][0]['debit'])->toBe('58.0000')
        ->and($resultado['rows'][0]['credit'])->toBe('0.0000')
        ->and($resultado['rows'][0]['closing_balance'])->toBe('158.0000');
});

/**
 * Un dia SIN movimiento no es un dia de ventas de cero: sale aparte, con el
 * hecho de si la caja estaba abierta.
 */
test('test_el_libro_de_caja_no_inventa_un_dia_de_cero', function () {
    $storeId = ReportFixture::store();
    ReportFixture::plan($storeId);

    ReportFixture::cajaAbierta($storeId, '2026-01-10');

    $reporte = new CashBookReport(DB::connection());

    $resultado = $reporte->build(
        ReportScope::of(null),
        ReportPeriod::between('2026-01-10', '2026-01-12')
    );

    // El libro no inventa filas de cero.
    expect($resultado['rows'])->toBe([]);

    // Y el dia sin movimiento sale APARTE, diciendo si la caja estaba
    // abierta.
    $sinMovimiento = $resultado['without_movement'];

    expect($sinMovimiento)->toHaveCount(1)
        ->and($sinMovimiento[0]['days'])->toHaveCount(3)
        ->and($sinMovimiento[0]['days'][0]['date'])->toBe('2026-01-10')
        ->and($sinMovimiento[0]['days'][0]['till_open'])->toBeTrue()
        ->and($sinMovimiento[0]['days'][1]['till_open'])->toBeFalse();
});
