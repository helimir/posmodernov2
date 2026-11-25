<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\VoidRequestFixture;

/**
 * La CANCELACION de la solicitud de anulacion: quien la pidio la retira.
 *
 * Reglas que fija:
 *
 *  - la CANCELACION EXIGE un MOTIVO: una peticion que se retira sin
 *    explicacion no dice por que se retiro, y quien la ve no puede entender
 *    nada;
 *  - SOLO QUIEN PIDIO cancela la suya: el permiso de pedir es el de
 *    retirar la peticion, pero la fila dice de quien es. Nadie cancela la
 *    peticion de otro;
 *  - una solicitud RECHAZADA no se cancela: ya esta cerrada, y lo que la
 *    cierra es su estado, no una segunda decision;
 *  - una solicitud CANCELADA no se cancela dos veces: la cancelacion es un
 *    hecho;
 *  - la CANCELACION NO ANULA la venta: es un "no la hago", como el rechazo;
 *  - la cancelacion queda anotada en la auditoria de negocio, con quien
 *    pidio, por que pidio, que venta era, cuando se pidio, cuando se
 *    cancelo, quien la cancelo y por que.
 */
test('test_la_cancelacion_de_la_solicitud_de_anulacion_exige_un_motivo', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'CANC-0001');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    // 1. SIN MOTIVO: un 422 con el `errorMsg` del contrato.
    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/cancelacion', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 2. Y CON UN MOTIVO EN BLANCO, tambien.
    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/cancelacion', ['reason' => '   '])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 3. Y la solicitud NO se toco: sigue PENDIENTE.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('pending')
        ->and($solicitud->cancelled_at)->toBeNull()
        ->and($solicitud->cancellation_reason)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'sale.void_request_cancelled')->count())->toBe(0);
});

/**
 * Quien PIDIO cancela su propia solicitud, y queda CANCELADA con su motivo,
 * quien y cuando.
 *
 * REGLA que fija: *la peticion se retira con su explicacion*. La solicitud
 * queda cancelada, y en la fila quedan las tres cosas que la explican: el
 * motivo de la cancelacion, quien la escribio y el instante en que la
 * escribio.
 *
 * Y EL MOTIVO DE LA PETICION SE CONSERVA: es la voz de quien pidio, y
 * cancelar no la borra (la peticion existio, y el historial la guarda).
 */
test('test_quien_pidio_la_anulacion_cancela_su_solicitud', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'CANC-0002');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $respuesta = $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/cancelacion', [
            'reason' => 'El cliente se lo penso y no devuelve nada',
        ]);

    $respuesta->assertOk()
        ->assertJsonPath('sale_id', $venta->id)
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('reason', 'El cliente devolvio la mercaderia')
        ->assertJsonPath('cancellation_reason', 'El cliente se lo penso y no devuelve nada')
        ->assertJsonPath('cancelled_by.id', (int) $contexto['cajero']->id)
        ->assertJsonPath('cancelled_by.name', (string) $contexto['cajero']->name)
        ->assertJsonStructure(['cancelled_at', 'request_id']);

    // Y la FILA lo dice igual.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('cancelled')
        ->and((string) $solicitud->cancellation_reason)->toBe('El cliente se lo penso y no devuelve nada')
        ->and((int) $solicitud->cancelled_by)->toBe((int) $contexto['cajero']->id)
        ->and($solicitud->cancelled_at)->not->toBeNull()
        ->and((string) $solicitud->reason)->toBe('El cliente devolvio la mercaderia');

    // Y LA VENTA sigue en pie: cancelar la peticion no es anular la venta.
    expect((string) DB::table('sales')->where('id', $venta->id)->value('status'))->toBe('paid')
        ->and(DB::table('fiscal_documents')->where('document_type', 'credit_note')->count())->toBe(0);
});

/**
 * NADIE cancela la solicitud de otro.
 *
 * REGLA que fija: *la peticion es de quien la pidio*. Otro cajero con el
 * MISMO permiso puede pedir su propia anulacion, pero no puede retirar la
 * de su compañero: la peticion retirada en nombre de otro es una peticion
 * que el otro no hizo.
 */
test('test_nadie_cancela_la_solicitud_de_anulacion_de_otro', function () {
    $contexto = VoidRequestFixture::escenario();

    $otro = VoidRequestFixture::otroCajero($contexto['store_id']);

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'CANC-0003');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($otro)
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/cancelacion', [
            'reason' => 'No es mi peticion',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y la solicitud sigue PENDIENTE, y con su dueño.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('pending')
        ->and($solicitud->cancelled_at)->toBeNull()
        ->and((int) $solicitud->requested_by)->toBe((int) $contexto['cajero']->id);
});

/**
 * Una solicitud RECHAZADA no se cancela.
 *
 * REGLA que fija: *una peticion cerrada esta cerrada*. El rechazo ya dijo
 * que no, con su motivo: cancelar despues seria una segunda opinion sobre
 * una peticion que ya no espera respuesta.
 */
test('test_una_solicitud_rechazada_no_se_cancela', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'CANC-0004');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', ['reason' => 'La venta no se puede anular'])
        ->assertOk();

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/cancelacion', [
            'reason' => 'Me lo pienso',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y la solicitud sigue RECHAZADA: el rechazo no se pisa.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('rejected')
        ->and($solicitud->cancelled_at)->toBeNull();
});

/**
 * Una solicitud CANCELADA no se cancela dos veces.
 *
 * REGLA que fija: *la cancelacion es un HECHO*. La segunda vez no hay nada
 * que cancelar, y el motivo de la primera no se pisa.
 */
test('test_una_solicitud_cancelada_no_se_cancela_dos_veces', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'CANC-0005');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/cancelacion', ['reason' => 'Primer motivo'])
        ->assertOk();

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/cancelacion', ['reason' => 'Segundo motivo'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y el motivo del PRIMERO sigue siendo el que explica la cancelacion.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('cancelled')
        ->and((string) $solicitud->cancellation_reason)->toBe('Primer motivo')
        ->and(DB::table('audit_logs')->where('action', 'sale.void_request_cancelled')->count())->toBe(1);
});

/**
 * La CANCELACION queda anotada en la AUDITORIA DE NEGOCIO.
 *
 * REGLA que fija: *una peticion retirada sin rastro es una peticion que
 * nadie puede defender despues*. La anotacion lleva QUIEN PIDIO, POR QUE
 * PIDIO, QUE VENTA era, CUANDO se pidio, CUANDO se cancelo, QUIEN la
 * cancelo y POR QUE se cancelo.
 */
test('test_la_cancelacion_de_la_solicitud_de_anulacion_queda_anotada_en_la_auditoria_de_negocio', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'CANC-0006');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/cancelacion', [
            'reason' => 'El cliente se lo penso y no devuelve nada',
        ])
        ->assertOk();

    $auditoria = DB::table('audit_logs')
        ->where('action', 'sale.void_request_cancelled')
        ->where('entity_type', 'sale')
        ->where('entity_id', $venta->id)
        ->first();

    expect($auditoria)->not->toBeNull()
        ->and((int) $auditoria->user_id)->toBe((int) $contexto['cajero']->id)
        ->and((int) $auditoria->store_id)->toBe((int) $contexto['store_id']);

    $carga = json_decode((string) $auditoria->context, true);

    expect($carga['requested_by']['id'])->toBe((int) $contexto['cajero']->id)
        ->and($carga['requested_by']['name'])->toBe((string) $contexto['cajero']->name)
        ->and($carga['request_reason'])->toBe('El cliente devolvio la mercaderia')
        ->and($carga['invoice_no'])->toBe('CANC-0006')
        ->and($carga['requested_at'])->not->toBeNull()
        ->and($carga['cancelled_by']['id'])->toBe((int) $contexto['cajero']->id)
        ->and($carga['cancelled_by']['name'])->toBe((string) $contexto['cajero']->name)
        ->and($carga['cancelled_at'])->not->toBeNull()
        ->and($carga['cancellation_reason'])->toBe('El cliente se lo penso y no devuelve nada');
});
