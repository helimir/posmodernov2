<?php

use App\Domain\Inventory\StockInvariant;
use App\Domain\Purchasing\PurchaseService;
use App\Domain\Purchasing\SupplierService;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PurchasingFixture;

/**
 * La COMPRA: su fuente de verdad, la entrada del stock y su asiento.
 *
 * Reglas que fija:
 *
 *  - una compra NO es un gasto: es EXISTENCIAS que entran y DEUDA con el
 *    proveedor, y su asiento es el debe a las existencias y el haber al
 *    pasivo;
 *  - el stock de la compra entra POR LOTES (uno por linea) y el cache de la
 *    ficha se REFRESCA desde ellos: el stock nunca se escribe a mano;
 *  - la compra NO se borra: se anula (con su motivo) y la anulacion revierte
 *    el stock de TODAS sus lineas (el P0 #9 del legacy revierte el de una
 *    sola);
 *  - una tienda SIN pasivo NO deja de comprar: el hecho se escribe y lo que
 *    se avisa es la contabilidad;
 *  - la devolucion al proveedor saca la mercaderia de SU lote y baja la deuda.
 */

/**
 * El SERVICIO de compras, con la conexion de la prueba.
 */
function servicio_de_compras(): PurchaseService
{
    return new PurchaseService(
        DB::connection(),
        new SupplierService(DB::connection()),
        new StockInvariant(DB::connection()),
    );
}

/**
 * La FECHA DE NEGOCIO de la tienda, con su instante.
 */
function negocio_de_compras(int $storeId): DateTimeImmutable
{
    return (new PosContext(DB::connection()))->businessDateOf($storeId);
}

/**
 * La COMPRA nace en `purchases` y su ASIENTO es existencias y deuda.
 */
test('test_la_compra_nace_en_purchases_y_su_asiento_es_existencias_y_deuda_con_el_proveedor', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];

    $supplierId = PurchasingFixture::proveedor($storeId);
    $productId = PurchasingFixture::producto($storeId, (int) $escenario['tax_category_id'], 'HUMO-COMPRA-001');

    $negocio = negocio_de_compras($storeId);

    $resultado = servicio_de_compras()->register(
        storeId: $storeId,
        supplierId: $supplierId,
        invoiceNo: 'FAC-0001',
        referenceNo: null,
        businessDate: $negocio,
        occurredAt: $negocio,
        lineas: [[
            'product_id' => $productId,
            'quantity' => '10',
            'unit_price' => '4.50',
            'discount' => '0',
        ]],
    );

    // 1. LA CABECERA: el hecho, con su proveedor y su fecha de negocio.
    $compra = DB::table('purchases')->where('id', $resultado->purchaseId)->first();

    expect($compra)->not->toBeNull()
        ->and((string) $compra->status)->toBe('received')
        ->and((int) $compra->supplier_id)->toBe($supplierId)
        ->and((string) $compra->invoice_no)->toBe('FAC-0001')
        ->and((string) $compra->purchase_date)->toBe($negocio->format('Y-m-d'));

    // 2. LAS LINEAS y los TOTALES.
    $lineas = DB::table('purchase_items')->where('purchase_id', $resultado->purchaseId)->get();

    expect($lineas)->toHaveCount(1)
        ->and((string) $lineas->first()->quantity)->toBe('10.0000')
        ->and((string) $lineas->first()->unit_price)->toBe('4.5000');

    $totales = DB::table('purchase_totals')->where('purchase_id', $resultado->purchaseId)->first();

    expect((string) $totales->grand_total)->toBe('45.0000');

    // 3. EL LOTE: uno por linea (1:1), con el coste que la tienda pago.
    $lote = DB::table('inventory_lots')->where('purchase_item_id', $lineas->first()->id)->first();

    expect($lote)->not->toBeNull()
        ->and((string) $lote->quantity_received)->toBe('10.0000')
        ->and((string) $lote->unit_cost)->toBe('4.5000')
        ->and((string) $lote->available)->toBe('10.0000');

    // 4. EL STOCK: el cache sale de los lotes, no de una escritura a mano.
    expect((string) DB::table('product_stores')
        ->where('product_id', $productId)
        ->where('store_id', $storeId)
        ->value('quantity_in_stock'))->toBe('10.0000');

    // 5. LA DEUDA del proveedor: lo que la tienda le debe.
    expect((string) DB::table('supplier_stores')
        ->where('supplier_id', $supplierId)
        ->where('store_id', $storeId)
        ->value('balance'))->toBe('45.0000');

    // 6. Y EL ASIENTO: el debe, a las EXISTENCIAS; el haber, al PASIVO. Y
    //    NINGUNA linea de gasto: una compra no es un gasto.
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'purchase')
        ->where('ledger_entries.reference_id', $resultado->purchaseId)
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect($asiento)->not->toBeNull()
        ->and((string) $asiento->type_code)->toBe('purchase')
        ->and((string) $asiento->amount)->toBe('45.0000');

    $lineasDelAsiento = DB::table('ledger_lines')->where('ledger_entry_id', $asiento->id)->get();

    $existencias = $lineasDelAsiento->firstWhere('account_code', '1300000');
    $pasivo = $lineasDelAsiento->firstWhere('account_code', '2100000');

    expect($existencias)->not->toBeNull()
        ->and((string) $existencias->debit)->toBe('45.0000')
        ->and((string) $existencias->credit)->toBe('0.0000')
        ->and($pasivo)->not->toBeNull()
        ->and((string) $pasivo->credit)->toBe('45.0000')
        ->and($lineasDelAsiento->pluck('account_code')->all())->not->toContain('5110000');
});

/**
 * La compra ANULADA se marca, no se borra, y revierte el stock de TODAS sus
 * lineas.
 */
test('test_la_compra_anulada_se_marca_y_revierte_el_stock_de_todas_sus_lineas', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];

    $supplierId = PurchasingFixture::proveedor($storeId);
    $primero = PurchasingFixture::producto($storeId, (int) $escenario['tax_category_id'], 'HUMO-COMPRA-A');
    $segundo = PurchasingFixture::producto($storeId, (int) $escenario['tax_category_id'], 'HUMO-COMPRA-B');

    $negocio = negocio_de_compras($storeId);
    $servicio = servicio_de_compras();

    $resultado = $servicio->register(
        storeId: $storeId,
        supplierId: $supplierId,
        invoiceNo: 'FAC-0002',
        referenceNo: null,
        businessDate: $negocio,
        occurredAt: $negocio,
        lineas: [
            ['product_id' => $primero, 'quantity' => '10', 'unit_price' => '4.50', 'discount' => '0'],
            ['product_id' => $segundo, 'quantity' => '5', 'unit_price' => '2.00', 'discount' => '0'],
        ],
    );

    // Sin motivo, no se anula.
    expect(fn () => $servicio->cancel($resultado->purchaseId, '   '))
        ->toThrow(RuntimeException::class, 'se anula con un MOTIVO');

    $servicio->cancel($resultado->purchaseId, 'Se registro por duplicado');

    // 1. La compra NO se borra: se MARCA.
    $compra = DB::table('purchases')->where('id', $resultado->purchaseId)->first();

    expect($compra)->not->toBeNull()
        ->and((string) $compra->status)->toBe('cancelled')
        ->and((string) $compra->cancellation_reason)->toBe('Se registro por duplicado')
        ->and($compra->cancelled_at)->not->toBeNull();

    // 2. Y el STOCK de las DOS lineas vuelve a su sitio: el P0 #9 del legacy
    //    (borrar una compra revierte el stock de un solo producto) no se
    //    repite.
    foreach ([[$primero, '10.0000'], [$segundo, '5.0000']] as [$productId, $cantidad]) {
        expect((string) DB::table('product_stores')
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->value('quantity_in_stock'))->toBe('0.0000');

        expect((string) DB::table('inventory_lots')
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->value('available'))->toBe('0.0000');
    }

    // 3. Y la DEUDA del proveedor vuelve a cero: una compra anulada no se
    //    debe.
    expect((string) DB::table('supplier_stores')
        ->where('supplier_id', $supplierId)
        ->where('store_id', $storeId)
        ->value('balance'))->toBe('0.0000');

    // 4. Y el ASiento es el ESPEJO del de la compra: el debe, al pasivo; el
    //    haber, a las existencias.
    $espejo = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'purchase_cancel')
        ->where('ledger_entries.reference_id', $resultado->purchaseId)
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect($espejo)->not->toBeNull()
        ->and((string) $espejo->type_code)->toBe('adjustment');

    $lineasDelEspejo = DB::table('ledger_lines')->where('ledger_entry_id', $espejo->id)->get();

    $existencias = $lineasDelEspejo->firstWhere('account_code', '1300000');
    $pasivo = $lineasDelEspejo->firstWhere('account_code', '2100000');

    expect((string) $existencias->credit)->toBe('55.0000')
        ->and((string) $pasivo->debit)->toBe('55.0000');
});

/**
 * Una tienda SIN pasivo COMPRA igual: el hecho no se bloquea y la
 * contabilidad se avisa.
 */
test('test_una_compra_en_una_tienda_sin_pasivo_no_se_bloquea_y_el_asiento_se_avisa', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];

    // La tienda se queda SIN cuenta de pasivo: el hueco se ve en el plan.
    DB::table('store_accounts')
        ->where('store_id', $storeId)
        ->where('role', 'liability')
        ->delete();

    $supplierId = PurchasingFixture::proveedor($storeId);
    $productId = PurchasingFixture::producto($storeId, (int) $escenario['tax_category_id'], 'HUMO-COMPRA-C');

    $negocio = negocio_de_compras($storeId);

    $resultado = servicio_de_compras()->register(
        storeId: $storeId,
        supplierId: $supplierId,
        invoiceNo: 'FAC-0003',
        referenceNo: null,
        businessDate: $negocio,
        occurredAt: $negocio,
        lineas: [[
            'product_id' => $productId,
            'quantity' => '3',
            'unit_price' => '10.00',
            'discount' => '0',
        ]],
    );

    // 1. LA COMPRA ESTA: la mercaderia entra y la deuda se registra.
    expect(DB::table('purchases')->where('id', $resultado->purchaseId)->count())->toBe(1)
        ->and((string) DB::table('product_stores')
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->value('quantity_in_stock'))->toBe('3.0000')
        ->and((string) DB::table('supplier_stores')
            ->where('supplier_id', $supplierId)
            ->where('store_id', $storeId)
            ->value('balance'))->toBe('30.0000');

    // 2. Y EL ASIENTO NO SE ESCRIBE: lo que falta es la contabilidad.
    expect($resultado->entryWritten)->toBeFalse()
        ->and($resultado->entryId)->toBeNull()
        ->and($resultado->warning)->toContain('liability')
        ->and(DB::table('ledger_entries')->where('reference_type', 'purchase')->count())->toBe(0);
});

/**
 * La DEVOLUCION al proveedor saca la mercaderia de SU lote y baja la deuda.
 */
test('test_la_devolucion_al_proveedor_saca_la_mercaderia_de_su_lote_y_baja_la_deuda', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];

    $supplierId = PurchasingFixture::proveedor($storeId);
    $primero = PurchasingFixture::producto($storeId, (int) $escenario['tax_category_id'], 'HUMO-COMPRA-D');
    $segundo = PurchasingFixture::producto($storeId, (int) $escenario['tax_category_id'], 'HUMO-COMPRA-E');

    $negocio = negocio_de_compras($storeId);
    $servicio = servicio_de_compras();

    $resultado = $servicio->register(
        storeId: $storeId,
        supplierId: $supplierId,
        invoiceNo: 'FAC-0004',
        referenceNo: null,
        businessDate: $negocio,
        occurredAt: $negocio,
        lineas: [
            ['product_id' => $primero, 'quantity' => '10', 'unit_price' => '4.50', 'discount' => '0'],
            ['product_id' => $segundo, 'quantity' => '10', 'unit_price' => '2.00', 'discount' => '0'],
        ],
    );

    $item = DB::table('purchase_items')
        ->where('purchase_id', $resultado->purchaseId)
        ->where('product_id', $primero)
        ->first();

    $devolucion = $servicio->returnToSupplier(
        purchaseId: $resultado->purchaseId,
        lineas: [['purchase_item_id' => (int) $item->id, 'quantity' => '4']],
        businessDate: $negocio,
        occurredAt: $negocio,
    );

    // 1. LA DEVOLUCION es un HECHO: su cabecera y su linea.
    expect(DB::table('purchase_returns')->where('id', $devolucion->returnId)->count())->toBe(1)
        ->and(DB::table('purchase_return_items')->where('purchase_return_id', $devolucion->returnId)->count())->toBe(1)
        ->and((string) $devolucion->total)->toBe('18.0000');

    // 2. Y EL LOTE ESACTO: el de la linea devuelta, y solo ese.
    expect((string) DB::table('inventory_lots')
        ->where('purchase_item_id', $item->id)
        ->value('available'))->toBe('6.0000');

    expect((string) DB::table('inventory_lots')
        ->where('product_id', $segundo)
        ->where('store_id', $storeId)
        ->value('available'))->toBe('10.0000');

    // 3. Y LA DEUDA baja por lo devuelto: 65 - 18.
    expect((string) DB::table('supplier_stores')
        ->where('supplier_id', $supplierId)
        ->where('store_id', $storeId)
        ->value('balance'))->toBe('47.0000');

    // 4. Y el ASIENTO es el espejo del de la compra: el debe, al pasivo del
    //    proveedor; el haber, a las existencias que salen.
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'purchase_return')
        ->where('ledger_entries.reference_id', $devolucion->returnId)
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect($asiento)->not->toBeNull()
        ->and((string) $asiento->type_code)->toBe('refund');

    $lineasDelAsiento = DB::table('ledger_lines')->where('ledger_entry_id', $asiento->id)->get();

    expect((string) $lineasDelAsiento->firstWhere('account_code', '2100000')->debit)->toBe('18.0000')
        ->and((string) $lineasDelAsiento->firstWhere('account_code', '1300000')->credit)->toBe('18.0000');
});

/**
 * El SALDO del proveedor se DERIVA de sus hechos, y el cache lo refleja.
 */
test('test_el_saldo_del_proveedor_se_deriva_de_sus_compras_y_el_cache_lo_refleja', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];

    $supplierId = PurchasingFixture::proveedor($storeId);
    $productId = PurchasingFixture::producto($storeId, (int) $escenario['tax_category_id'], 'HUMO-COMPRA-F');

    $negocio = negocio_de_compras($storeId);
    $servicio = servicio_de_compras();
    $proveedores = new SupplierService(DB::connection());

    // Un proveedor nuevo no debe nada.
    expect($proveedores->balance($supplierId, $storeId))->toBe('0.0000');

    $resultado = $servicio->register(
        storeId: $storeId,
        supplierId: $supplierId,
        invoiceNo: 'FAC-0005',
        referenceNo: null,
        businessDate: $negocio,
        occurredAt: $negocio,
        lineas: [[
            'product_id' => $productId,
            'quantity' => '2',
            'unit_price' => '12.00',
            'discount' => '0',
        ]],
    );

    // El saldo DERIVADO y el CACHE dicen lo mismo.
    expect($proveedores->balance($supplierId, $storeId))->toBe('24.0000')
        ->and((string) DB::table('supplier_stores')
            ->where('supplier_id', $supplierId)
            ->where('store_id', $storeId)
            ->value('balance'))->toBe('24.0000');

    // Y el saldo del MAESTRO no existe: la deuda vive en la ficha de tienda.
    expect(Schema::getColumnListing('suppliers'))->not->toContain('balance')
        ->and(Schema::getColumnListing('supplier_stores'))->toContain('balance');
});
