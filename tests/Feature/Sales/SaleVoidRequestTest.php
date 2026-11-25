<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\VoidRequestFixture;

/**
 * La SOLICITUD de anulacion: el cajero PIDE que le anulen y quien puede
 * anular ANULA.
 *
 * Reglas que fija:
 *
 *  - la solicitud EXIGE un MOTIVO: sin motivo no hay solicitud, porque una
 *    solicitud sin motivo es una solicitud que nadie sabe por que se hizo;
 *  - la solicitud la registra quien NO puede anular: el cajero pide, y la
 *    anulacion la hace quien tiene el permiso de anular. El cajero que
 *    anula no esta pidiendo nada: esta deshaciendo su propia venta;
 *  - la solicitud PENDIENTE no se duplica: una venta tiene UNA solicitud
 *    PENDIENTE, y la segunda no pisa la primera (el motivo del primero es el
 *    que explica la anulacion);
 *  - una solicitud CERRADA (rechazada o cancelada) NO bloquea la venta: el
 *    cajero puede pedir la anulacion otra vez, con un motivo nuevo, y el
 *    historial se conserva;
 *  - la solicitud de una venta de OTRA TIENDA es un 404: no se le dice que
 *    existe;
 *  - la ANULACION de una venta con solicitud pendiente usa el MOTIVO que
 *    escribio QUIEN PIDIO: la voz de quien pide es la que explica la
 *    anulacion, y el motivo que la anulacion lleva es ese;
 *  - la anulacion de una venta de un dia CERRADO falla: corregir un dia
 *    cerrado exige REABRIRLO (con su motivo, y el Z sellado se queda en el
 *    historial), porque el Z es el cierre HECHO y sus cifras son las del dia.
 */

/**
 * La solicitud de anulacion EXIGE un MOTIVO.
 *
 * REGLA que fija: *una solicitud sin motivo es una solicitud que nadie sabe
 * por que se hizo*. Sin motivo no se registra nada, y la venta queda como
 * estaba: sin solicitud y sin rastro.
 */
test('test_la_solicitud_de_anulacion_exige_un_motivo', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0001');

    // 1. SIN MOTIVO: un 422 con el `errorMsg` del contrato.
    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 2. Y CON UN MOTIVO EN BLANCO, tambien: el motivo se exige de verdad.
    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', ['reason' => '   '])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 3. La venta NO se toco: no hay solicitud.
    $fila = DB::table('sales')->where('id', $venta->id)->first();

    expect((string) $fila->status)->toBe('paid')
        ->and(DB::table('sale_void_requests')->where('sale_id', $venta->id)->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'sale.void_requested')->count())->toBe(0);
});

/**
 * El cajero REGISTRA la solicitud con su motivo, y queda auditada.
 *
 * REGLA que fija: *la solicitud es una peticion que queda escrita con QUIEN
 * la hizo y POR QUE*. Una solicitud sin rastro es una solicitud que nadie
 * puede defender despues, y el motivo escrito es lo que explica la
 * anulacion que vendra.
 */
test('test_el_cajero_registra_una_solicitud_de_anulacion_con_su_motivo', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0002');

    $respuesta = $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ]);

    $respuesta->assertCreated()
        ->assertJsonPath('sale_id', $venta->id)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('reason', 'El cliente devolvio la mercaderia')
        ->assertJsonPath('requested_by.id', (int) $contexto['cajero']->id);

    // 1. La SOLICITUD queda con su motivo y con quien la pidio.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect($solicitud)->not->toBeNull()
        ->and((string) $solicitud->status)->toBe('pending')
        ->and((string) $solicitud->reason)->toBe('El cliente devolvio la mercaderia')
        ->and((int) $solicitud->requested_by)->toBe((int) $contexto['cajero']->id)
        ->and($solicitud->requested_at)->not->toBeNull();

    // 2. Y la VENTA sigue en pie: pedir la anulacion no anula nada.
    expect((string) DB::table('sales')->where('id', $venta->id)->value('status'))->toBe('paid');

    // 3. Y la AUDITORIA: quien pidio, sobre que venta, en que tienda y por
    //    que.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'sale.void_requested')
        ->where('entity_type', 'sale')
        ->where('entity_id', $venta->id)
        ->first();

    expect($auditoria)->not->toBeNull()
        ->and((int) $auditoria->user_id)->toBe((int) $contexto['cajero']->id)
        ->and((int) $auditoria->store_id)->toBe((int) $contexto['store_id']);

    $contextoAuditado = json_decode((string) $auditoria->context, true);

    expect($contextoAuditado['reason'])->toBe('El cliente devolvio la mercaderia')
        ->and($contextoAuditado['invoice_no'])->toBe('SOL-0002');
});

/**
 * Una venta ANULADA no admite una solicitud de anulacion.
 *
 * REGLA que fija: *la anulacion es un HECHO, y un hecho no se vuelve a
 * pedir*. La venta ya esta anulada: no hay nada que anular ni que pedir, y
 * pedirlo seria ensenar un estado que no existe.
 */
test('test_una_venta_anulada_no_admite_una_solicitud_de_anulacion', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0003');

    DB::table('sales')->where('id', $venta->id)->update(['status' => 'cancelled']);

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', ['reason' => 'Error en el monto'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y la venta no se toco.
    $fila = DB::table('sales')->where('id', $venta->id)->first();

    expect((string) $fila->status)->toBe('cancelled')
        ->and(DB::table('sale_void_requests')->where('sale_id', $venta->id)->count())->toBe(0);
});

/**
 * Una venta con solicitud PENDIENTE no admite otra solicitud.
 *
 * REGLA que fija: *una venta tiene UNA solicitud pendiente*. La segunda no
 * pisa la primera: el motivo del primero es el que explica la anulacion, y
 * pisarlo seria dejar la anulacion sin explicacion.
 */
test('test_una_venta_con_solicitud_pendiente_no_admite_otra_solicitud', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0004');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', ['reason' => 'Primer motivo'])
        ->assertCreated();

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', ['reason' => 'Segundo motivo'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y el motivo del PRIMERO sigue siendo el que explica la anulacion.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->reason)->toBe('Primer motivo')
        ->and(DB::table('sale_void_requests')->where('sale_id', $venta->id)->count())->toBe(1);
});

/**
 * Una venta cuya solicitud fue RECHAZADA admite una solicitud NUEVA.
 *
 * REGLA que fija: *el cajero puede tener razon la segunda vez*. Una
 * peticion rechazada esta CERRADA, no vigente: la venta puede pedir otra
 * anulacion, con un motivo nuevo.
 *
 * Y EL HISTORIAL SE CONSERVA: la peticion rechazada sigue existiendo, con su
 * motivo y con su rechazo. El historial es el modelo, y una peticion
 * reescrita es una peticion que nadie puede defender despues.
 */
test('test_la_venta_una_solicitud_rechazada_admite_una_solicitud_nueva', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0010');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', ['reason' => 'Primer motivo'])
        ->assertCreated();

    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', ['reason' => 'La venta no se puede anular'])
        ->assertOk();

    // Y ahora la peticion se puede hacer OTRA VEZ, con un motivo nuevo.
    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', ['reason' => 'Segundo motivo'])
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('reason', 'Segundo motivo');

    // Y EL HISTORIAL SE CONSERVA: las dos solicitudes existen.
    $solicitudes = DB::table('sale_void_requests')
        ->where('sale_id', $venta->id)
        ->orderBy('id')
        ->get();

    expect($solicitudes)->toHaveCount(2)
        ->and((string) $solicitudes[0]->status)->toBe('rejected')
        ->and((string) $solicitudes[0]->reason)->toBe('Primer motivo')
        ->and((string) $solicitudes[0]->rejection_reason)->toBe('La venta no se puede anular')
        ->and((string) $solicitudes[1]->status)->toBe('pending')
        ->and((string) $solicitudes[1]->reason)->toBe('Segundo motivo');
});

/**
 * Una venta cuya solicitud fue CANCELADA admite una solicitud NUEVA.
 *
 * REGLA que fija: *retirar una peticion no cierra la venta*. El cajero se
 * lo pense, la retiro, y despues puede pedirla otra vez: con un motivo
 * nuevo, y sin perder la peticion que retiro.
 */
test('test_la_venta_una_solicitud_cancelada_admite_una_solicitud_nueva', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0011');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', ['reason' => 'Primer motivo'])
        ->assertCreated();

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/cancelacion', ['reason' => 'El cliente se lo pense'])
        ->assertOk();

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', ['reason' => 'Segundo motivo'])
        ->assertCreated()
        ->assertJsonPath('status', 'pending');

    $solicitudes = DB::table('sale_void_requests')
        ->where('sale_id', $venta->id)
        ->orderBy('id')
        ->get();

    expect($solicitudes)->toHaveCount(2)
        ->and((string) $solicitudes[0]->status)->toBe('cancelled')
        ->and((string) $solicitudes[1]->status)->toBe('pending');
});

/**
 * La solicitud de anulacion de una venta de OTRA TIENDA es un 404.
 *
 * REGLA que fija: *una venta de otra tienda no existe para quien pregunta*.
 * Un 403 le diria al cajero que la venta EXISTE, y eso ya es informacion de
 * otra tienda.
 */
test('test_la_solicitud_de_anulacion_de_una_venta_de_otra_tienda_es_un_404', function () {
    $contexto = VoidRequestFixture::escenario();

    $ventaAjena = VoidRequestFixture::venta((int) CreditFixture::store(), 'SOL-AJENA-1');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$ventaAjena->id.'/solicitud-anulacion', ['reason' => 'Error en el monto'])
        ->assertNotFound();

    // Y la venta ajena no se toco.
    $fila = DB::table('sales')->where('id', $ventaAjena->id)->first();

    expect((string) $fila->status)->toBe('paid')
        ->and(DB::table('sale_void_requests')->where('sale_id', $ventaAjena->id)->count())->toBe(0);
});

/**
 * El cajero NO ANULA: pide, y quien puede anular anula.
 *
 * REGLA que fija: *el cajero no deshace la venta que hizo*. El permiso es
 * el que manda, y el mostrador no lo tiene: sin `sales.void` la anulacion
 * ni se ofrece ni se acepta.
 */
test('test_el_cajero_no_puede_anular_una_venta', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0005');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/anulacion', ['reason' => 'Error en el monto'])
        ->assertForbidden();

    // Y la venta sigue en pie.
    expect((string) DB::table('sales')->where('id', $venta->id)->value('status'))->toBe('paid');
});

/**
 * Quien NO tiene el permiso de pedir la anulacion no puede pedirla.
 *
 * REGLA que fija: *el permiso es la ley, y la pantalla es la cortesia*.
 * Un rol que solo lee las ventas no puede dejar una solicitud: la solicitud
 * es una escritura, y las escrituras tienen permiso.
 */
test('test_quien_no_tiene_el_permiso_de_pedir_la_anulacion_no_puede_registrarla', function () {
    $contexto = VoidRequestFixture::escenario();

    $rol = PermissionFixture::rol('lector-solicitud-'.uniqid(), ['sales.read']);

    $lector = PermissionFixture::usuario($rol, $contexto['store_id']);

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0006');

    $this->actingAs($lector)
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', ['reason' => 'Error en el monto'])
        ->assertForbidden();

    expect(DB::table('sale_void_requests')->where('sale_id', $venta->id)->count())->toBe(0);
});

/**
 * La ANULACION de una venta con solicitud pendiente usa el MOTIVO de quien
 * PIDIO.
 *
 * REGLA que fija: *el motivo de la solicitud es el motivo de la
 * anulacion*. Quien pidio explico POR QUE, y esa explicacion es la que
 * queda escrita en la nota de credito y en la auditoria. Quien ejecuta la
 * anulacion no reescribe la voz de quien pidio.
 */
test('test_la_anulacion_de_una_venta_con_solicitud_pendiente_usa_el_motivo_de_quien_pidio', function () {
    $contexto = VoidRequestFixture::escenario();

    $escenario = $contexto['escenario'];

    config(['fiscal.emission_enabled' => true]);

    // La VENTA, cobrada de verdad: con comprobante emitido, para que la
    // anulacion tenga que emitir la nota de credito.
    $cobro = $this->actingAs($contexto['cajero'])
        ->postJson('/caja/cobrar', [
            'lines' => [
                ['product_id' => $escenario['product_id'], 'quantity' => '2', 'unit_price' => '25.0000'],
            ],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
            ],
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // La SOLICITUD, con el motivo del cajero.
    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$saleId.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    // El CONTADOR anula, y la respuesta lleva el motivo de QUIEN PIDIO.
    $respuesta = $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$saleId.'/anulacion', []);

    $respuesta->assertOk()
        ->assertJsonPath('sale_id', $saleId)
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('reason', 'El cliente devolvio la mercaderia');

    // 1. La venta queda anulada y con su nota de credito.
    expect((string) DB::table('sales')->where('id', $saleId)->value('status'))->toBe('cancelled')
        ->and(DB::table('fiscal_documents')->where('document_type', 'credit_note')->count())->toBe(1);

    // 2. Y el MOTIVO FISCAL de la anulacion es el del cajero.
    $evento = DB::table('fiscal_document_events')
        ->where('action', 'cancel')
        ->orderByDesc('id')
        ->first();

    expect($evento)->not->toBeNull();

    $carga = json_decode((string) $evento->payload, true);

    expect($carga['reason'])->toBe('El cliente devolvio la mercaderia');

    // 3. Y la AUDITORIA de la anulacion lleva el motivo del cajero y dice
    //    quien lo pidio.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'sale.voided')
        ->where('entity_type', 'sale')
        ->where('entity_id', $saleId)
        ->first();

    expect($auditoria)->not->toBeNull();

    $contextoAuditado = json_decode((string) $auditoria->context, true);

    expect($contextoAuditado['reason'])->toBe('El cliente devolvio la mercaderia')
        ->and((int) $contextoAuditado['void_requested_by'])->toBe((int) $contexto['cajero']->id);
});

/**
 * La ANULACION de una venta de un dia CERRADO falla, y dice que hay que
 * reabrir el dia.
 *
 * REGLA que fija: *corregir una venta de un dia cerrado exige reabrir ese
 * dia*. El Z sellado es el cierre HECHO, con sus cifras; anular una venta
 * sin reabrir dejaria un Z que ya no describe el dia. Por eso la anulacion
 * falla con un 422 que dice que el dia esta cerrado, y el que va a anular
 * tiene que reabrirlo antes (con su motivo, y el Z anterior se queda en el
 * historial).
 */
test('test_la_anulacion_de_una_venta_de_un_dia_cerrado_falla_y_exige_reabrir_el_dia', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0007');

    // El dia se CIERRA: el cierre sella el Z, y el Z es el cierre hecho.
    $caja = VoidRequestFixture::cajaDelDia($contexto['store_id']);

    VoidRequestFixture::cierraLaCaja((int) $caja->id, '0.0000', (int) $contexto['contador']->id);

    // La ANULACION falla: el dia esta cerrado.
    $respuesta = $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/anulacion', ['reason' => 'Error en el monto facturado']);

    $respuesta->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y el mensaje dice QUE PASA y QUE HAY QUE HACER: el dia esta cerrado y
    // hay que reabrirlo.
    expect((string) $respuesta->json('errorMsg'))
        ->toContain('CERRADO')
        ->toContain('reabrir');

    // 1. La venta NO se toco: no esta anulada a medias.
    expect((string) DB::table('sales')->where('id', $venta->id)->value('status'))->toBe('paid');

    // 2. Y no hay nota de credito, ni evento de anulacion, ni auditoria de
    //    una anulacion que no paso.
    expect(DB::table('fiscal_documents')->where('document_type', 'credit_note')->count())->toBe(0)
        ->and(DB::table('fiscal_document_events')->where('action', 'cancel')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'sale.voided')->count())->toBe(0);

    // 3. Y el Z sigue sellado: la anulacion no lo toco.
    expect((string) DB::table('cash_registers')->where('id', $caja->id)->value('status'))->toBe('closed')
        ->and(DB::table('cash_closes')->where('cash_register_id', $caja->id)->whereNull('reopened_at')->count())
        ->toBe(1);
});

/**
 * La ANULACION de una venta de un dia REABIERTO pasa.
 *
 * REGLA que fija: *reabrir el dia es lo que desbloquea la anulacion*. El Z
 * anterior NO se borra: se queda marcado, con quien lo reabrio y por que, y
 * el dia vuelve a estar en pie.
 */
test('test_la_anulacion_de_una_venta_de_un_dia_reabierto_pasa', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0008');

    $caja = VoidRequestFixture::cajaDelDia($contexto['store_id']);

    VoidRequestFixture::cierraLaCaja((int) $caja->id, '0.0000', (int) $contexto['contador']->id);

    VoidRequestFixture::reabreLaCaja(
        (int) $caja->id,
        'Se va a corregir una venta del dia',
        (int) $contexto['contador']->id
    );

    // Y ahora la anulacion pasa.
    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    expect((string) DB::table('sales')->where('id', $venta->id)->value('status'))->toBe('cancelled');

    // Y el Z ANTERIOR se queda en el historial, marcado: no se borra.
    $z = DB::table('cash_closes')->where('cash_register_id', $caja->id)->first();

    expect($z)->not->toBeNull()
        ->and($z->reopened_at)->not->toBeNull()
        ->and((int) $z->reopened_by)->toBe((int) $contexto['contador']->id);
});

/**
 * La solicitud de anulacion de una venta de un dia CERRADO se registra
 * igual.
 *
 * REGLA que fija: *la guarda del dia cerrado es de la ANULACION, no de la
 * solicitud*. Pedir la anulacion de una venta de ayer no la anula: la
 * solicitud espera, y quien puede anular la ejecutara cuando el dia este
 * reabierto.
 */
test('test_la_solicitud_de_anulacion_de_una_venta_de_un_dia_cerrado_se_registra_igual', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'SOL-0009');

    $caja = VoidRequestFixture::cajaDelDia($contexto['store_id']);

    VoidRequestFixture::cierraLaCaja((int) $caja->id, '0.0000', (int) $contexto['contador']->id);

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'pending');

    // Y la venta sigue en pie: pedir no es anular.
    expect((string) DB::table('sales')->where('id', $venta->id)->value('status'))->toBe('paid');
});
