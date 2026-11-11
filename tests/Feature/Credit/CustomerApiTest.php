<?php

use App\Domain\Credit\CustomerBalanceService;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * 1.4 — la API de clientes: la lista, la ficha y el cobro.
 *
 * Reglas que fija:
 *
 *  - la LISTA busca por NOMBRE (como prefijo) o por `tax_id` (exacto, como el
 *    codigo de un producto), y PAGINA; el saldo que enseña es el DERIVADO de
 *    los movimientos del cliente en la tienda activa;
 *  - la FICHA muestra el saldo DERIVADO, la deuda, el limite de credito y el
 *    cache, y AVISA cuando el cache no coincide con el derivado: el cache
 *    puede desfasarse, la verdad no;
 *  - el COBRO reduce la deuda, y el saldo derivado baja con ella;
 *  - un cobro con importe CERO o NEGATIVO no se registra (422), y un cliente
 *    SIN FICHA en la tienda no tiene credito en ella (422);
 *  - sin sesion, la API no se sirve.
 *
 * El LIMITE DE CREDITO se MUESTRA y se AVISA si la deuda lo supera, pero NO
 * bloquea la venta: bloquear es una decision de negocio que todavia no esta
 * tomada.
 *
 * LA RUTA de la API es `/api/clientes`, y la de las PAGINAS es `/clientes`:
 * las PAGINAS son Inertia y las ESCRITURAS son JSON, y una ruta no puede
 * servir las dos cosas.
 */

/**
 * El escenario de la API: la tienda de demostracion, su cajero y tres
 * clientes con su ficha en la tienda.
 *
 * @return array<string, mixed>
 */
function escenario_de_api_de_clientes(): array
{
    $escenario = PosFixture::escenario();

    return [
        'escenario' => $escenario,
        'usuario' => PosFixture::usuario($escenario['store_id']),
        'ana' => CreditFixture::customer($escenario['store_id'], 'Ana Perez', 'V-12345678'),
        'andres' => CreditFixture::customer($escenario['store_id'], 'Andres Ruiz', 'V-87654321'),
        'brenda' => CreditFixture::customer($escenario['store_id'], 'Brenda Diaz', 'J-11111111'),
    ];
}

/**
 * El servicio de credito del cliente en la tienda.
 */
function servicio_de_credito_de_la_api(): CustomerBalanceService
{
    return new CustomerBalanceService(DB::connection());
}

/**
 * La LISTA busca por nombre como PREFIJO y por `tax_id` exacto, y pagina.
 */
test('test_la_lista_de_clientes_busca_por_nombre_por_documento_y_pagina', function () {
    $datos = escenario_de_api_de_clientes();
    $escenario = $datos['escenario'];

    // 1. POR NOMBRE, como PREFIJO: "An" encuentra a Ana y a Andres, y no a
    //    Brenda.
    $porNombre = $this->actingAs($datos['usuario'])->getJson('/api/clientes?q=An');

    $porNombre->assertOk()
        ->assertJsonPath('pagination.page', 1)
        ->assertJsonPath('filters.q', 'An');

    $ids = array_column($porNombre->json('customers'), 'id');

    expect($ids)->toContain($datos['ana'])
        ->and($ids)->toContain($datos['andres'])
        ->and($ids)->not->toContain($datos['brenda']);

    // 2. POR DOCUMENTO, EXACTO: el documento no es un prefijo.
    $porDocumento = $this->actingAs($datos['usuario'])->getJson('/api/clientes?q=V-87654321');

    $porDocumento->assertOk()->assertJsonPath('pagination.total', 1);

    expect($porDocumento->json('customers.0.id'))->toBe($datos['andres'])
        ->and($porDocumento->json('customers.0.tax_id'))->toBe('V-87654321');

    // 3. Y un documento que es PREFIJO de otro no los trae a los dos.
    $parcial = $this->actingAs($datos['usuario'])->getJson('/api/clientes?q=V-1234');

    expect($parcial->json('pagination.total'))->toBe(0);

    // 4. PAGINA: la lista trae la pagina pedida y el total de clientes.
    $pagina = $this->actingAs($datos['usuario'])->getJson('/api/clientes?page=1');

    $pagina->assertOk();

    expect($pagina->json('pagination.total'))->toBe(3)
        ->and($pagina->json('pagination.page'))->toBe(1)
        ->and($pagina->json('customers'))->toHaveCount(3);

    // El saldo que enseña la lista es el DERIVADO, como texto decimal.
    expect($pagina->json('customers.0.balance'))->toBeString()
        ->and($pagina->json('customers.0.due'))->toBeString();
});

/**
 * La FICHA trae los datos del cliente, el saldo DERIVADO, la deuda, el limite
 * de credito, el cache y el extracto de sus movimientos.
 */
test('test_la_ficha_del_cliente_trae_el_saldo_derivado_la_deuda_el_limite_el_cache_y_el_extracto', function () {
    $datos = escenario_de_api_de_clientes();
    $escenario = $datos['escenario'];
    $clienteId = $datos['ana'];

    // Una deuda de 100,0000 escrita por el servicio.
    $servicio = servicio_de_credito_de_la_api();

    $servicio->registerSale(
        customerId: $clienteId,
        storeId: $escenario['store_id'],
        amount: '100.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    $servicio->registerPayment(
        customerId: $clienteId,
        storeId: $escenario['store_id'],
        amount: '40.0000',
        referenceNo: 'RECIBO-1',
        entryDate: new DateTimeImmutable('2026-11-05'),
    );

    $ficha = $this->actingAs($datos['usuario'])->getJson('/api/clientes/'.$clienteId);

    $ficha->assertOk()
        ->assertJsonPath('customer.id', $clienteId)
        ->assertJsonPath('customer.name', 'Ana Perez')
        ->assertJsonPath('customer.tax_id', 'V-12345678')
        // El saldo DERIVADO: 100 de la venta menos 40 del cobro.
        ->assertJsonPath('balance', '60.0000')
        ->assertJsonPath('due', '60.0000')
        // Y el cache, que coincide con el derivado.
        ->assertJsonPath('cache.balance', '60.0000')
        ->assertJsonPath('cache.due', '60.0000')
        ->assertJsonPath('cache_matches', true)
        ->assertJsonPath('warnings', []);

    // El EXTRACTO: los movimientos de esa tienda, del mas nuevo al mas viejo.
    $ficha->assertJsonPath('entries.0.type', 'payment')
        ->assertJsonPath('entries.0.amount', '-40.0000')
        ->assertJsonPath('entries.0.reference_no', 'RECIBO-1')
        ->assertJsonPath('entries.1.type', 'sale')
        ->assertJsonPath('entries.1.amount', '100.0000')
        ->assertJsonPath('entries.1.balance_after', '100.0000');
});

/**
 * La ficha AVISA cuando el cache NO coincide con el derivado: el cache puede
 * desfasarse, la verdad no, y la pantalla tiene que saberlo.
 */
test('test_la_ficha_avisa_cuando_el_cache_no_coincide_con_el_saldo_derivado', function () {
    $datos = escenario_de_api_de_clientes();
    $escenario = $datos['escenario'];
    $clienteId = $datos['ana'];

    // Un movimiento escrito FUERA del servicio: el cache se queda atras.
    DB::table('customer_ledger_entries')->insert([
        'customer_id' => $clienteId,
        'store_id' => $escenario['store_id'],
        'type' => 'sale',
        'reference_no' => 'VENTA-FUERA',
        'amount' => '100.0000',
        'entry_date' => '2026-11-04',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ficha = $this->actingAs($datos['usuario'])->getJson('/api/clientes/'.$clienteId);

    $ficha->assertOk()
        // El DERIVADO es la verdad: 100,0000.
        ->assertJsonPath('balance', '100.0000')
        ->assertJsonPath('due', '100.0000')
        // Y el cache dice 0,0000: esta DESFASADO.
        ->assertJsonPath('cache.balance', '0.0000')
        ->assertJsonPath('cache_matches', false);

    expect($ficha->json('warnings'))->not->toBeEmpty();
});

/**
 * El COBRO reduce la deuda, y el saldo derivado baja con ella.
 */
test('test_el_cobro_reduce_la_deuda_del_cliente_y_el_saldo_derivado_baja', function () {
    $datos = escenario_de_api_de_clientes();
    $escenario = $datos['escenario'];
    $clienteId = $datos['ana'];

    $servicio = servicio_de_credito_de_la_api();

    $servicio->registerSale(
        customerId: $clienteId,
        storeId: $escenario['store_id'],
        amount: '100.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    $cobro = $this->actingAs($datos['usuario'])->postJson('/api/clientes/'.$clienteId.'/cobros', [
        'amount' => '30.0000',
        'reference_no' => 'RECIBO-1',
    ]);

    $cobro->assertCreated()
        ->assertJsonPath('customer_id', $clienteId)
        ->assertJsonPath('amount', '30.0000')
        ->assertJsonPath('balance', '70.0000')
        ->assertJsonPath('due', '70.0000');

    // 1. El movimiento del cobro es NEGATIVO, y su `balance_after` es el
    //    saldo derivado.
    $movimiento = DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->where('type', 'payment')
        ->first();

    expect($movimiento)->not->toBeNull()
        ->and((string) $movimiento->amount)->toBe('-30.0000')
        ->and((string) $movimiento->balance_after)->toBe('70.0000')
        ->and((string) $movimiento->reference_no)->toBe('RECIBO-1');

    // 2. Y el saldo derivado baja: 100 de la venta menos 30 del cobro.
    expect($servicio->balance($clienteId, $escenario['store_id']))->toBe('70.0000')
        ->and($servicio->due($clienteId, $escenario['store_id']))->toBe('70.0000')
        ->and($servicio->holds($clienteId, $escenario['store_id']))->toBeTrue();
});

/**
 * Un cobro con importe CERO o NEGATIVO no se registra, y un cliente SIN
 * FICHA en la tienda no tiene credito en ella.
 */
test('test_un_cobro_con_importe_invalido_o_de_un_cliente_sin_ficha_falla_con_422', function () {
    $datos = escenario_de_api_de_clientes();
    $escenario = $datos['escenario'];
    $clienteId = $datos['ana'];

    // 1. Un importe CERO.
    $this->actingAs($datos['usuario'])
        ->postJson('/api/clientes/'.$clienteId.'/cobros', [
            'amount' => '0.0000',
            'reference_no' => 'RECIBO-0',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 2. Un importe NEGATIVO.
    $this->actingAs($datos['usuario'])
        ->postJson('/api/clientes/'.$clienteId.'/cobros', [
            'amount' => '-10.0000',
            'reference_no' => 'RECIBO-M',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 3. Un importe que no es un numero decimal.
    $this->actingAs($datos['usuario'])
        ->postJson('/api/clientes/'.$clienteId.'/cobros', [
            'amount' => 'diez',
            'reference_no' => 'RECIBO-X',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 4. Y un cliente SIN FICHA en la tienda: su credito en esa tienda no
    //    existe.
    $sinFicha = CreditFixture::clienteSinFicha('Cliente Sin Ficha', 'X-00000001');

    $this->actingAs($datos['usuario'])
        ->postJson('/api/clientes/'.$sinFicha.'/cobros', [
            'amount' => '10.0000',
            'reference_no' => 'RECIBO-S',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 5. Y nada de eso se escribio: el libro del cliente sigue vacio.
    expect(DB::table('customer_ledger_entries')->where('customer_id', $clienteId)->count())->toBe(0)
        ->and(DB::table('customer_ledger_entries')->where('customer_id', $sinFicha)->count())->toBe(0);
});

/**
 * Sin sesion, la API NO se sirve.
 */
test('test_sin_sesion_la_api_de_clientes_no_se_sirve', function () {
    $this->getJson('/api/clientes')->assertUnauthorized();
    $this->getJson('/api/clientes/1')->assertUnauthorized();
    $this->postJson('/api/clientes/1/cobros', ['amount' => '10.0000', 'reference_no' => 'X'])->assertUnauthorized();
});

/**
 * El ALTA del cliente: su ficha en la tienda activa nace con el saldo a cero,
 * porque lo que no esta movido no se debe.
 */
test('test_el_alta_de_un_cliente_le_abre_ficha_en_la_tienda_activa_con_saldo_cero', function () {
    $datos = escenario_de_api_de_clientes();
    $escenario = $datos['escenario'];

    $alta = $this->actingAs($datos['usuario'])->postJson('/api/clientes', [
        'name' => 'Cliente Nuevo',
        'tax_id' => 'V-99999999',
        'credit_limit' => '500.0000',
    ]);

    $alta->assertCreated()
        ->assertJsonPath('name', 'Cliente Nuevo')
        ->assertJsonPath('tax_id', 'V-99999999')
        ->assertJsonPath('credit_limit', '500.0000')
        ->assertJsonPath('balance', '0.0000')
        ->assertJsonPath('due', '0.0000');

    $clienteId = (int) $alta->json('id');

    // Y la ficha existe en la tienda activa, con el cache a cero.
    $ficha = DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $escenario['store_id'])
        ->first();

    expect($ficha)->not->toBeNull()
        ->and((string) $ficha->balance)->toBe('0.0000')
        ->and((string) $ficha->due)->toBe('0.0000')
        ->and($ficha->status)->toBe('active');

    // Y el cliente ya aparece en la lista.
    $this->actingAs($datos['usuario'])
        ->getJson('/api/clientes?q=V-99999999')
        ->assertOk()
        ->assertJsonPath('customers.0.id', $clienteId);
});
