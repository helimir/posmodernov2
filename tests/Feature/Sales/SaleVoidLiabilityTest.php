<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * La ANULACION de una venta cuando la tienda NO tiene cuenta de pasivo.
 *
 * Reglas que fija:
 *
 *  - el pasivo es OBLIGATORIO del plan COMPLETO, y su ausencia NO impide
 *    cobrar: la tienda cobra bien y lo que no puede es deshacer una venta
 *    cobrada por error. Un 422 en el cobro dejaria al cajero bloqueado, y eso
 *    es PEOR que no poder cobrar;
 *  - la anulacion de una venta COBRADA SIN cuenta de pasivo FALLA con un 422
 *    que dice COMO se resuelve: asignar el rol 'liability' a una cuenta de
 *    tipo liability en el plan de cuentas. Y no se hace a medias: la venta
 *    sigue como estaba, no hay nota de credito, no hay reversa y no se quema
 *    ningun numero;
 *  - la ANULACION de una venta NO cobrada NO necesita el pasivo: la reversa
 *    devuelve lo que el cliente ENTREGO, y si no entrego nada, no hay pasivo
 *    que devolver. Su "debe" esta en la cuenta `receivable`, que es
 *    obligatoria;
 *  - la anulacion RECHAZADA NO se audita como anulacion (no ocurrio): lo que
 *    queda es el aviso de que el pasivo falta.
 */

/**
 * El ESCENARIO del pasivo: la tienda, su usuario con el permiso y la cuenta
 * de pasivo QUE FALTA.
 *
 * @param  list<string>  $permisos
 * @return array<string, mixed>
 */
function escenario_del_pasivo(array $permisos = ['sales.void', 'sales.create', 'sales.read']): array
{
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('anulador-'.uniqid(), $permisos);

    $usuario = PermissionFixture::usuarioConCaja($rol, $escenario['store_id']);

    // La tienda se queda SIN cuenta de pasivo.
    DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('role', 'liability')
        ->delete();

    return [
        'escenario' => $escenario,
        'usuario' => $usuario,
    ];
}

/**
 * La ANULACION de una venta COBRADA falla con un 422 que dice COMO se
 * resuelve, y NO se hace a medias.
 *
 * REGLA que fija este test: *sin cuenta de pasivo, una venta cobrada no se
 * puede anular, y el error dice que cuenta falta y que hay que hacer*. La
 * reversa es el espejo del asiento de la venta con el efectivo sustituido
 * por el pasivo: sin pasivo no hay donde asentar el credito a favor del
 * cliente, y una anulacion a medias es una venta que nadie sabe lo que es.
 */
test('test_la_anulacion_de_una_venta_cobrada_falla_con_422_si_la_tienda_no_tiene_cuenta_de_pasivo', function () {
    config(['fiscal.emission_enabled' => true]);

    $contexto = escenario_del_pasivo();
    $escenario = $contexto['escenario'];

    // La VENTA se cobra entera: 58,0000 en efectivo.
    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $escenario['payment_method_id'],
                    'amount' => '58.0000',
                ],
            ],
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // El NUMERO de la serie, antes del intento: un fallo no quema numeros.
    $serieId = (int) $escenario['document_series_id'];
    $numerosAntes = (int) DB::table('fiscal_series')->where('id', $serieId)->value('next_number');

    // La ANULACION falla con un 422.
    $respuesta = $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado']);

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // Y el mensaje dice QUE falta y COMO se resuelve.
    $mensaje = (string) $respuesta->json('errorMsg');

    expect($mensaje)->toContain('liability')
        ->and($mensaje)->toContain('chart of accounts');

    // Y NO se anulo a medias: la venta sigue como estaba.
    expect((string) DB::table('sales')->where('id', $saleId)->value('status'))->toBe('paid');

    // Y no hay nota de credito, ni evento de anulacion, ni reversa.
    expect(DB::table('fiscal_documents')->where('document_type', 'credit_note')->count())->toBe(0)
        ->and(DB::table('fiscal_document_events')->where('action', 'cancel')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->where('reference_type', 'sale_void')->count())->toBe(0);

    // Y el numero NO se quemo: la anulacion ni empezo a escribir.
    expect((int) DB::table('fiscal_series')->where('id', $serieId)->value('next_number'))
        ->toBe($numerosAntes);

    // Y la caja del dia NO se movio: el dinero sigue en el cajon.
    expect((string) DB::table('ledger_lines')
        ->where('account_code', '1100000')
        ->value('debit'))->toBe('58.0000');
});

/**
 * La ANULACION RECHAZADA no se audita como anulacion.
 *
 * REGLA que fija este test: *una anulacion que no ocurrio NO se audita como
 * si hubiera ocurrido*. La auditoria es append-only y es el rastro de los
 * HECHOS: un 'sale.voided' de una venta que sigue viva seria una mentira.
 */
test('test_la_anulacion_rechazada_por_falta_de_pasivo_no_queda_auditada_como_anulacion', function () {
    $contexto = escenario_del_pasivo();
    $escenario = $contexto['escenario'];

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $escenario['payment_method_id'],
                    'amount' => '58.0000',
                ],
            ],
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertStatus(422);

    // Y NO hay rastro de anulacion: la venta sigue viva y no se anulo.
    expect(DB::table('audit_logs')
        ->where('action', 'sale.voided')
        ->where('entity_type', 'sale')
        ->where('entity_id', $saleId)
        ->count())->toBe(0)
        ->and((string) DB::table('sales')->where('id', $saleId)->value('status'))->toBe('paid');
});

/**
 * La ANULACION de una venta A CREDITO (no cobrada) NO necesita el pasivo.
 *
 * REGLA que fija este test: *la reversa devuelve lo que el cliente
 * ENTREGO, y si no entrego nada, no hay pasivo que devolver*. La venta a
 * credito tiene su "debe" en la cuenta `receivable`, que es obligatoria, y la
 * reversa la cancela: sin pasivo, la anulacion se escribe igual.
 */
test('test_la_anulacion_de_una_venta_a_credito_no_necesita_cuenta_de_pasivo', function () {
    $contexto = escenario_del_pasivo();
    $escenario = $contexto['escenario'];

    $clienteId = CreditFixture::customer((int) $escenario['store_id']);

    $credito = PosFixture::medioDePagoDeCredito((int) $escenario['store_id']);

    // La VENTA a CREDITO: el cliente debe 58,0000 y no entrega nada.
    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', [
            'customer_id' => $clienteId,
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $credito,
                    'amount' => '58.0000',
                ],
            ],
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // El ASIENTO de la venta: la CARTERA, y no la caja.
    $original = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->first(['id']);

    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $original->id)->get();

    expect($lineas->pluck('account_code')->all())->toContain('1200000')
        ->and($lineas->pluck('account_code')->all())->not->toContain('1100000');

    // La ANULACION SALE: no hay pasivo que devolver.
    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Se cobro de mas'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('reversal_written', true);

    // Y la REVERSA existe, y cancela la cartera SIN tocar el pasivo.
    $reversa = DB::table('ledger_entries')
        ->where('reference_type', 'sale_void')
        ->where('reference_id', $saleId)
        ->first(['id']);

    expect($reversa)->not->toBeNull();

    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $reversa->id)->get();

    expect($lineas->pluck('account_code')->all())->toContain('1200000')
        ->and($lineas->pluck('account_code')->all())->not->toContain('2100000');

    // Y el cliente NO queda con credito a favor: no entrego nada.
    expect((string) DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $escenario['store_id'])
        ->value('balance'))->toBe('0.0000');
});

/**
 * La ANULACION de una venta PAGADA A MEDIAS SI necesita el pasivo.
 *
 * REGLA que fija este test: *el pasivo hace falta cuando el cliente entrego
 * ALGO*. Con 30 de los 58 cobrados, la reversa tiene que devolver esos 30:
 * ese es el credito a favor del cliente, y es un pasivo de la tienda.
 */
test('test_la_anulacion_de_una_venta_pagada_a_medias_necesita_cuenta_de_pasivo', function () {
    $contexto = escenario_del_pasivo();
    $escenario = $contexto['escenario'];

    $clienteId = CreditFixture::customer((int) $escenario['store_id']);

    // La VENTA: 58 de total, 30 cobrados y 28 a credito.
    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', [
            'customer_id' => $clienteId,
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $escenario['payment_method_id'],
                    'amount' => '30.0000',
                ],
            ],
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // La ANULACION falla: los 30 que el cliente entrego son un pasivo.
    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y la venta sigue viva: no se anulo a medias. Y su estado es el de una
    // venta A MEDIAS: `confirmed`, no `paid`.
    expect((string) DB::table('sales')->where('id', $saleId)->value('status'))->toBe('confirmed')
        ->and(DB::table('ledger_entries')->where('reference_type', 'sale_void')->count())->toBe(0);
});

/**
 * La TIENDA SIN PASIVO recupera la anulacion asignando el rol a una cuenta
 * EXISTENTE.
 *
 * REGLA que fija este test: *el 422 dice la via, y la via funciona*: el rol
 * `liability` se puede asignar a una cuenta de tipo liability que YA esta en
 * el catalogo (la `2100000` de la demostracion), y con esa cuenta la
 * anulacion se escribe.
 */
test('test_la_tienda_sin_pasivo_recupera_la_anulacion_asignando_el_rol_a_una_cuenta_existente', function () {
    $contexto = escenario_del_pasivo([
        'sales.void',
        'sales.create',
        'sales.read',
        'accounting.read',
        'accounting.update',
    ]);
    $escenario = $contexto['escenario'];

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $escenario['payment_method_id'],
                    'amount' => '58.0000',
                ],
            ],
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // 1. Sin pasivo, la anulacion falla.
    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertStatus(422);

    // 2. El plan DECLARA el hueco, y la via es la pantalla del plan.
    $this->actingAs($contexto['usuario'])
        ->get('/contabilidad/cuentas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('missing_roles', ['liability'])
            ->etc());

    // 3. Y la CUENTA EXISTENTE de pasivo recibe el rol.
    $pasivo = DB::table('accounts')->where('code', '2100000')->first();

    expect((string) $pasivo->type)->toBe('liability');

    $this->actingAs($contexto['usuario'])
        ->putJson('/api/contabilidad/cuentas/'.$pasivo->id.'/rol', ['role' => 'liability'])
        ->assertOk()
        ->assertJsonPath('role', 'liability')
        ->assertJsonPath('missing_roles', []);

    // 4. Y la anulacion YA SALE: la reversa se escribe con el pasivo.
    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('reversal_written', true);

    // Y la REVERSA lleva el importe al pasivo: el credito a favor del cliente.
    $pasivoDeLaReversa = DB::table('ledger_lines')
        ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
        ->where('ledger_entries.reference_type', 'sale_void')
        ->where('ledger_entries.reference_id', $saleId)
        ->where('ledger_lines.account_code', '2100000')
        ->value('credit');

    expect((string) $pasivoDeLaReversa)->toBe('58.0000');
});
