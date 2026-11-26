<?php

use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Inventory\LotConsumer;
use App\Domain\Inventory\LotReturner;
use App\Domain\Inventory\StockInvariant;
use App\Domain\Sales\SaleReturnService;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * La DEVOLUCION DE MERCADERIA a un cliente.
 *
 * Reglas que fija:
 *
 *  - la mercaderia vuelve al LOTE EXACTO del que salio la linea de venta (no
 *    al lote mas antiguo disponible ni a uno nuevo), y el cache del stock se
 *    refresca desde los lotes;
 *  - el REINTEGRO lo registra el libro del cliente con su TIPO PROPIO
 *    (`return`), y no es un cobro;
 *  - el valor devuelto cancela primero la DEUDA del cliente (la cuenta por
 *    cobrar) y el resto es su CREDITO a favor, que es un PASIVO de la tienda;
 *  - una tienda SIN pasivo NO deja de devolver mercaderia: el hecho se
 *    escribe y lo que se avisa es la contabilidad.
 */

/**
 * El SERVICIO de devoluciones, con la conexion de la prueba.
 */
function servicio_de_devoluciones(): SaleReturnService
{
    return new SaleReturnService(
        DB::connection(),
        new LotReturner(DB::connection(), new StockInvariant(DB::connection())),
        new CustomerBalanceService(DB::connection()),
    );
}

/**
 * El ESCENARIO de la devolucion: la tienda, el cliente, la venta a credito y
 * el consumo de sus lotes.
 *
 * @return array{store_id: int, sale_id: int, sale_item_id: int, customer_id: int, product_id: int}
 */
function escenario_de_devolucion(bool $pagada = false, string $cantidad = '2'): array
{
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];

    $cliente = PermissionFixture::cliente($storeId);

    $negocio = (new PosContext(DB::connection()))->businessDateOf($storeId);

    $base = bcmul('25.0000', $cantidad, 4);
    $impuesto = bcmul($base, '0.16', 4);
    $total = bcadd($base, $impuesto, 4);

    $saleId = (int) DB::table('sales')->insertGetId([
        'store_id' => $storeId,
        'customer_id' => (int) $cliente->id,
        'invoice_no' => 'VTA-0001',
        'sale_date' => $negocio->format('Y-m-d'),
        'status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $saleItemId = (int) DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'product_id' => (int) $escenario['product_id'],
        'store_id' => $storeId,
        'quantity' => $cantidad,
        'unit_price' => '25.0000',
        'discount' => '0.0000',
        'tax_rate_snapshot' => '16.0000',
        'tax_amount' => $impuesto,
        'base_amount' => $base,
        'line_total' => $total,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sale_totals')->insert([
        'sale_id' => $saleId,
        'sub_total' => $base,
        'discount' => '0.0000',
        'tax_total' => $impuesto,
        'rounding_adjustment' => '0.0000',
        'grand_total' => $total,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // EL CONSUMO: la linea salio del lote mas antiguo del producto.
    (new LotConsumer(DB::connection(), new StockInvariant(DB::connection())))
        ->consume((int) $escenario['product_id'], $storeId, $saleItemId, $cantidad);

    // EL LIBRO DEL CLIENTE: la venta suma lo que el cliente debe.
    $libro = new CustomerBalanceService(DB::connection());

    $libro->registerSale(
        customerId: (int) $cliente->id,
        storeId: $storeId,
        amount: $total,
        referenceNo: 'VTA-0001',
        entryDate: $negocio,
        saleId: $saleId,
    );

    if ($pagada) {
        $libro->registerPayment(
            customerId: (int) $cliente->id,
            storeId: $storeId,
            amount: $total,
            referenceNo: 'VTA-0001',
            entryDate: $negocio,
            saleId: $saleId,
        );
    }

    return [
        'store_id' => $storeId,
        'sale_id' => $saleId,
        'sale_item_id' => $saleItemId,
        'customer_id' => (int) $cliente->id,
        'product_id' => (int) $escenario['product_id'],
    ];
}

/**
 * La PANTALLA de las devoluciones y su ALTA por HTTP.
 */
test('test_la_pantalla_de_las_devoluciones_responde_y_su_alta_repone_el_lote_exacto', function () {
    $escenario = escenario_de_devolucion();
    $storeId = $escenario['store_id'];

    $usuario = PosFixture::usuario($storeId);

    $this->actingAs($usuario)
        ->get('/ventas/devoluciones')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/returns')
            ->has('returns')
            // Las VENTAS recientes de la tienda, para elegir de cual se
            // devuelve la mercaderia.
            ->has('sales')
            ->where('business_date', PosFixture::businessDate($storeId))
            ->where('store.id', $storeId)
            ->etc());

    // Y las LINEAS de la venta dicen cuanto se puede devolver de cada una.
    $lineas = $this->actingAs($usuario)
        ->getJson('/api/ventas/'.$escenario['sale_id'].'/devolucion')
        ->assertOk()
        ->json('lines');

    expect($lineas)->toHaveCount(1)
        ->and((string) $lineas[0]['quantity'])->toBe('2.0000')
        ->and((string) $lineas[0]['available'])->toBe('2.0000');

    // El ALTA: la devolucion repone el lote y compensa el libro del cliente.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/ventas/'.$escenario['sale_id'].'/devolucion', [
            'lines' => [
                ['sale_item_id' => $escenario['sale_item_id'], 'quantity' => '2'],
            ],
        ]);

    $respuesta->assertCreated()
        ->assertJsonPath('total', '58.0000')
        ->assertJsonPath('balance', '0.0000')
        ->assertJsonPath('entry_written', true);

    // Y la AUDITORIA dice quien registro la devolucion.
    expect(DB::table('audit_logs')
        ->where('action', 'sale.returned')
        ->where('entity_id', (int) $respuesta->json('return_id'))
        ->count())->toBe(1);
});

/**
 * La devolucion repone el LOTE EXACTO y compensa el libro del cliente.
 */
test('test_la_devolucion_al_cliente_repone_el_lote_exacto_y_compensa_el_libro_del_cliente', function () {
    $escenario = escenario_de_devolucion();
    $storeId = $escenario['store_id'];

    $loteAntes = DB::table('inventory_lots')
        ->where('product_id', $escenario['product_id'])
        ->where('store_id', $storeId)
        ->orderBy('id')
        ->first();

    $negocio = (new PosContext(DB::connection()))->businessDateOf($storeId);

    $resultado = servicio_de_devoluciones()->register(
        storeId: $storeId,
        saleId: $escenario['sale_id'],
        lineas: [['sale_item_id' => $escenario['sale_item_id'], 'quantity' => '2']],
        businessDate: $negocio,
        occurredAt: $negocio,
    );

    // 1. LA DEVOLUCION es un HECHO: su cabecera, su linea y su total.
    expect(DB::table('sale_returns')->where('id', $resultado->returnId)->count())->toBe(1)
        ->and(DB::table('sale_return_items')->where('sale_return_id', $resultado->returnId)->count())->toBe(1)
        ->and((string) $resultado->total)->toBe('58.0000');

    // 2. Y EL LOTE EXACTO: el de la linea devuelta, y NO se crea un lote
    //    nuevo.
    $lotes = DB::table('inventory_lots')
        ->where('product_id', $escenario['product_id'])
        ->where('store_id', $storeId)
        ->get();

    expect($lotes)->toHaveCount(1)
        ->and((string) $lotes->first()->quantity_returned)->toBe('2.0000')
        ->and((string) $lotes->first()->available)->toBe('10.0000');

    // 3. Y EL LIBRO DEL CLIENTE: el reintegro es de SU propio tipo, y el
    //    saldo baja a cero (el cliente no debe nada).
    $movimientos = DB::table('customer_ledger_entries')
        ->where('customer_id', $escenario['customer_id'])
        ->get();

    $reintegro = $movimientos->firstWhere('type', 'return');

    expect($reintegro)->not->toBeNull()
        ->and((string) $reintegro->amount)->toBe('-58.0000')
        ->and((string) $resultado->balance)->toBe('0.0000');

    // 4. Y EL ASIENTO: el ingreso y el impuesto se revierten, y el valor
    //    devuelto cancela la DEUDA del cliente (la cuenta por cobrar).
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'sale_return')
        ->where('ledger_entries.reference_id', $resultado->returnId)
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect($asiento)->not->toBeNull()
        ->and((string) $asiento->type_code)->toBe('refund');

    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $asiento->id)->get();

    expect((string) $lineas->firstWhere('account_code', '4010000')->debit)->toBe('50.0000')
        ->and((string) $lineas->firstWhere('account_code', '2110000')->debit)->toBe('8.0000')
        ->and((string) $lineas->firstWhere('account_code', '1200000')->credit)->toBe('58.0000');
});

/**
 * La devolucion que deja al cliente con un CREDITO A FAVOR es un pasivo de
 * la tienda, y sin pasivo el hecho NO se bloquea.
 */
test('test_la_devolucion_al_cliente_que_deja_credito_a_favor_no_se_bloquea_y_el_pasivo_se_avisa', function () {
    $escenario = escenario_de_devolucion(pagada: true, cantidad: '4');
    $storeId = $escenario['store_id'];

    // La tienda se queda SIN cuenta de pasivo: el hueco se ve en el plan.
    DB::table('store_accounts')
        ->where('store_id', $storeId)
        ->where('role', 'liability')
        ->delete();

    $negocio = (new PosContext(DB::connection()))->businessDateOf($storeId);

    $resultado = servicio_de_devoluciones()->register(
        storeId: $storeId,
        saleId: $escenario['sale_id'],
        lineas: [['sale_item_id' => $escenario['sale_item_id'], 'quantity' => '2']],
        businessDate: $negocio,
        occurredAt: $negocio,
    );

    // 1. EL HECHO esta: la mercaderia ha vuelto y el libro del cliente queda
    //    compensado con su reintegro.
    expect(DB::table('sale_returns')->where('id', $resultado->returnId)->count())->toBe(1)
        ->and((string) DB::table('inventory_lots')
            ->where('product_id', $escenario['product_id'])
            ->where('store_id', $storeId)
            ->value('quantity_returned'))->toBe('2.0000')
        ->and((string) $resultado->balance)->toBe('-58.0000');
    // 2. Y EL CREDITO A FAVOR del cliente es el pasivo que la tienda le debe.
    expect((string) $resultado->credit)->toBe('58.0000');

    // 3. Y EL ASIENTO NO SE ESCRIBE: lo que falta es la contabilidad.
    expect($resultado->entryWritten)->toBeFalse()
        ->and($resultado->warning)->toContain('liability')
        ->and(DB::table('ledger_entries')->where('reference_type', 'sale_return')->count())->toBe(0);

    // 4. Y CON PASIVO, el mismo hecho se asienta contra el pasivo: el credito
    //    a favor del cliente es una deuda de la tienda.
    DB::table('store_accounts')->insert([
        'account_id' => (int) DB::table('accounts')->where('code', '2100000')->value('id'),
        'store_id' => $storeId,
        'role' => 'liability',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $segunda = servicio_de_devoluciones()->register(
        storeId: $storeId,
        saleId: $escenario['sale_id'],
        lineas: [['sale_item_id' => $escenario['sale_item_id'], 'quantity' => '1']],
        businessDate: $negocio,
        occurredAt: $negocio,
    );

    expect($segunda->entryWritten)->toBeTrue();

    $asiento = DB::table('ledger_entries')
        ->where('reference_type', 'sale_return')
        ->where('reference_id', $segunda->returnId)
        ->first();

    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $asiento->id)->get();

    expect((string) $lineas->firstWhere('account_code', '2100000')->credit)->toBe('29.0000');
});
