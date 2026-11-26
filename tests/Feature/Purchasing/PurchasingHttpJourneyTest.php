<?php

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PurchasingFixture;

/**
 * LAS COMPRAS, LOS PROVEEDORES Y LAS DEVOLUCIONES, por HTTP.
 *
 * Reglas que fija:
 *
 *  - las PAGINAS son Inertia y las ESCRITURAS son JSON con el contrato de
 *    siempre (422 con `{"errorMsg": "..."}`);
 *  - el ALTA de una compra escribe la compra, sus lotes y su asiento, y deja
 *    constancia en la auditoria de quien la registro;
 *  - una compra invalida NO se registra: ni la compra ni sus lotes;
 *  - la ANULACION marca la compra con su motivo, y sin motivo no hay
 *    anulacion;
 *  - QUIEN REGISTRA LA COMPRA NO LA ANULA: registrar y anular son permisos
 *    distintos, porque anular es una correccion de supervision.
 */

/**
 * La PANTALLA de las compras responde con su contrato de props.
 */
test('test_la_pantalla_de_las_compras_es_de_la_tienda_y_su_alta_es_json_con_el_mismo_contrato', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $supplierId = PurchasingFixture::proveedor((int) $escenario['store_id']);
    $productId = PurchasingFixture::producto((int) $escenario['store_id'], (int) $escenario['tax_category_id'], 'HUMO-HTTP-001');

    $this->actingAs($usuario)
        ->get('/compras')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('purchasing/purchases')
            ->has('purchases')
            // Los PROVEEDORES de la tienda, con su saldo.
            ->has('suppliers')
            ->where('suppliers', fn ($proveedores): bool => collect($proveedores)->firstWhere(
                'id',
                $supplierId
            )['balance'] === '0.0000')
            // Y el CATALOGO de la tienda, para el formulario.
            ->has('products')
            ->where('business_date', PosFixture::businessDate((int) $escenario['store_id']))
            ->where('store.id', $escenario['store_id'])
            ->etc());

    // El ALTA: la compra, sus lotes y su asiento.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/compras', [
            'supplier_id' => $supplierId,
            'invoice_no' => 'FAC-HTTP-0001',
            'lines' => [
                [
                    'product_id' => $productId,
                    'quantity' => '10',
                    'unit_price' => '4.50',
                    'discount' => '0',
                ],
            ],
        ]);

    $respuesta->assertCreated()
        ->assertJsonPath('total', '45.0000')
        ->assertJsonPath('entry_written', true);

    $compraId = (int) $respuesta->json('id');

    expect(DB::table('purchases')->where('id', $compraId)->count())->toBe(1)
        ->and(DB::table('purchase_items')->where('purchase_id', $compraId)->count())->toBe(1)
        ->and(DB::table('inventory_lots')->where('product_id', $productId)->count())->toBe(1)
        ->and(DB::table('ledger_entries')
            ->where('reference_type', 'purchase')
            ->where('reference_id', $compraId)
            ->count())->toBe(1);

    // Y la AUDITORIA dice quien la registro, con que proveedor y cuanto.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'purchase.created')
        ->where('entity_type', 'purchase')
        ->where('entity_id', $compraId)
        ->first();

    expect($auditoria)->not->toBeNull()
        ->and((int) $auditoria->user_id)->toBe((int) $usuario->id)
        ->and((int) $auditoria->store_id)->toBe((int) $escenario['store_id'])
        ->and(json_decode((string) $auditoria->context, true)['total'])->toBe('45.0000');
});

/**
 * Una compra INVALIDA no se registra: ni la compra ni sus lotes.
 */
test('test_una_compra_invalida_no_se_registra_y_devuelve_422_con_su_mensaje', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // Lo que hay ANTES: el escenario de demostracion trae una compra (la que
    // abre sus lotes) y sus asientos.
    $comprasAntes = DB::table('purchases')->count();
    $lotesAntes = DB::table('inventory_lots')->count();
    $asientosAntes = DB::table('ledger_entries')->count();

    // Sin lineas: una compra sin mercaderia no es una compra.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/compras', [
            'supplier_id' => 1,
            'lines' => [],
        ]);

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // Y una cantidad que no es positiva.
    $supplierId = PurchasingFixture::proveedor((int) $escenario['store_id']);
    $productId = PurchasingFixture::producto((int) $escenario['store_id'], (int) $escenario['tax_category_id'], 'HUMO-HTTP-002');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/compras', [
            'supplier_id' => $supplierId,
            'lines' => [
                [
                    'product_id' => $productId,
                    'quantity' => '0',
                    'unit_price' => '4.50',
                ],
            ],
        ]);

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    expect($respuesta->json('errorMsg'))->toContain('cantidad no positiva');

    // Y NO se escribio nada: ni compra ni lotes. El escenario de
    // demostracion ya tiene una compra (la que abre sus lotes), asi que lo
    // que se compara es que NO ha aparecido ninguna mas.
    expect(DB::table('purchases')->count())->toBe($comprasAntes)
        ->and(DB::table('inventory_lots')->count())->toBe($lotesAntes)
        ->and(DB::table('ledger_entries')->count())->toBe($asientosAntes);
});

/**
 * QUIEN REGISTRA LA COMPRA NO LA ANULA: son permisos distintos.
 */
test('test_quien_registra_la_compra_no_la_anula_y_quien_la_anula_no_la_registra', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];

    PermissionFixture::catalogo();

    // El CAJERO: recibe el genero y lo registra.
    $cajero = PermissionFixture::usuarioConCaja(
        Role::query()->where('slug', 'cashier')->firstOrFail(),
        $storeId,
    );

    // El CONTADOR: supervisa y deshace.
    $contador = PermissionFixture::usuario(
        Role::query()->where('slug', 'accountant')->firstOrFail(),
        $storeId,
    );

    expect($cajero->can('purchase.create'))->toBeTrue()
        ->and($cajero->can('purchase.cancel'))->toBeFalse()
        ->and($contador->can('purchase.cancel'))->toBeTrue()
        ->and($contador->can('purchase.create'))->toBeFalse();

    $supplierId = PurchasingFixture::proveedor($storeId);
    $productId = PurchasingFixture::producto($storeId, (int) $escenario['tax_category_id'], 'HUMO-HTTP-003');

    $alta = $this->actingAs($cajero)
        ->postJson('/api/compras', [
            'supplier_id' => $supplierId,
            'invoice_no' => 'FAC-HTTP-0002',
            'lines' => [
                [
                    'product_id' => $productId,
                    'quantity' => '2',
                    'unit_price' => '10.00',
                ],
            ],
        ]);

    $alta->assertCreated();

    $compraId = (int) $alta->json('id');

    // El cajero NO anula: se lo rechaza en el servidor.
    $this->actingAs($cajero)
        ->postJson('/api/compras/'.$compraId.'/anular', ['reason' => 'duplicado'])
        ->assertStatus(403);

    // Y la compra sigue en pie: el rechazo no anula nada.
    expect((string) DB::table('purchases')->where('id', $compraId)->value('status'))->toBe('received');

    // Y sin motivo, tampoco hay anulacion.
    $this->actingAs($contador)
        ->postJson('/api/compras/'.$compraId.'/anular', ['reason' => '  '])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    expect((string) DB::table('purchases')->where('id', $compraId)->value('status'))->toBe('received');

    // El contador SI anula: la compra se marca y no se borra.
    $this->actingAs($contador)
        ->postJson('/api/compras/'.$compraId.'/anular', ['reason' => 'Se registro por duplicado'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    $compra = DB::table('purchases')->where('id', $compraId)->first();

    expect((string) $compra->status)->toBe('cancelled')
        ->and((string) $compra->cancellation_reason)->toBe('Se registro por duplicado')
        ->and(DB::table('audit_logs')
            ->where('action', 'purchase.cancelled')
            ->where('entity_id', $compraId)
            ->count())->toBe(1);
});

/**
 * La PANTALLA de los proveedores y su ALTA.
 */
test('test_la_pantalla_de_los_proveedores_responde_y_su_alta_crea_el_proveedor_con_su_ficha', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/proveedores')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('purchasing/suppliers')
            ->has('suppliers')
            ->where('store.id', $escenario['store_id'])
            ->etc());

    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/proveedores', [
            'name' => 'Distribuidora del norte',
            'tax_id' => 'J-40123456-7',
            'phone' => '0412-1234567',
        ]);

    $respuesta->assertCreated()
        ->assertJsonPath('name', 'Distribuidora del norte')
        ->assertJsonPath('balance', '0.0000');

    $supplierId = (int) $respuesta->json('id');

    // El proveedor nace con su FICHA en la tienda: sin ella no hay donde
    // dejar la deuda.
    expect(DB::table('suppliers')->where('id', $supplierId)->count())->toBe(1)
        ->and(DB::table('supplier_stores')
            ->where('supplier_id', $supplierId)
            ->where('store_id', $escenario['store_id'])
            ->count())->toBe(1)
        ->and(DB::table('audit_logs')
            ->where('action', 'supplier.created')
            ->where('entity_id', $supplierId)
            ->count())->toBe(1);

    // Y un proveedor SIN nombre no se da de alta.
    $this->actingAs($usuario)
        ->postJson('/api/proveedores', ['name' => '  '])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);
});

/**
 * Sin sesion, las pantallas de compras y proveedores redirigen al login.
 */
test('test_sin_autenticar_las_pantallas_de_compras_y_proveedores_redirigen_al_login', function () {
    $this->get('/compras')->assertRedirect('/login');
    $this->get('/proveedores')->assertRedirect('/login');
    $this->get('/ventas/devoluciones')->assertRedirect('/login');
});
