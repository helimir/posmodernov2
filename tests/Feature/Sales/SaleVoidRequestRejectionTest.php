<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\VoidRequestFixture;

/**
 * El RECHAZO de la solicitud de anulacion: quien puede anular dice que NO.
 *
 * Reglas que fija:
 *
 *  - el RECHAZO EXIGE un MOTIVO: un "no" sin motivo no le dice nada a quien
 *    pidio la anulacion, y una peticion que se cierra sin explicacion no se
 *    puede defender despues;
 *  - el RECHAZO NO ES UNA ANULACION: la venta sigue en pie, sin nota de
 *    credito y sin tocar su estado. Rechazar es "no lo hago" y anular es
 *    "lo hago": son HECHOS distintos, con estados distintos;
 *  - una solicitud PENDIENTE no se rechaza dos veces: el rechazo es un
 *    hecho, y un hecho repetido no es una segunda opinion;
 *  - una solicitud de una venta YA ANULADA no se rechaza: no hay nada que
 *    rechazar;
 *  - la solicitud de una venta de OTRA TIENDA es un 404: no se le dice que
 *    existe;
 *  - el RECHAZO QUEDA ANOTADO en la auditoria de negocio, con quien pidio,
 *    por que pidio, que venta era, cuando se pidio, cuando se rechazo y por
 *    que se rechazo. Y la IP NO: esa vive en `audit_logs.ip_address`, que es
 *    donde vive la informacion tecnica.
 */
test('test_el_rechazo_de_la_solicitud_de_anulacion_exige_un_motivo', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'RECH-0001');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    // 1. SIN MOTIVO: un 422 con el `errorMsg` del contrato.
    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 2. Y CON UN MOTIVO EN BLANCO, tambien: el motivo se exige de verdad.
    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', ['reason' => '   '])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 3. Y la solicitud NO se toco: sigue PENDIENTE, porque un rechazo sin
    //    motivo no es un rechazo.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('pending')
        ->and($solicitud->rejected_at)->toBeNull()
        ->and($solicitud->rejection_reason)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'sale.void_request_rejected')->count())->toBe(0);
});

/**
 * El RECHAZO deja la solicitud RECHAZADA, con su motivo, quien lo rechazo y
 * cuando.
 *
 * REGLA que fija: *el rechazo es un HECHO con su explicacion*. La solicitud
 * queda rechazada, y en la fila quedan las tres cosas que la explican: el
 * motivo del rechazo, quien lo escribio y el instante en que lo escribio.
 *
 * Y EL MOTIVO DE LA PETICION SE CONSERVA: es la voz de quien pidio, y el
 * rechazo no la reescribe.
 */
test('test_el_rechazo_de_la_solicitud_de_anulacion_deja_la_solicitud_rechazada', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'RECH-0002');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $respuesta = $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', [
            'reason' => 'La venta tiene el comprobante emitido y ya no se puede anular',
        ]);

    $respuesta->assertOk()
        ->assertJsonPath('sale_id', $venta->id)
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('reason', 'El cliente devolvio la mercaderia')
        ->assertJsonPath('rejection_reason', 'La venta tiene el comprobante emitido y ya no se puede anular')
        ->assertJsonPath('rejected_by.id', (int) $contexto['contador']->id)
        ->assertJsonPath('rejected_by.name', (string) $contexto['contador']->name)
        ->assertJsonStructure(['rejected_at', 'request_id']);

    // Y la FILA lo dice igual: el estado, el motivo, quien y cuando.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('rejected')
        ->and((string) $solicitud->rejection_reason)->toBe('La venta tiene el comprobante emitido y ya no se puede anular')
        ->and((int) $solicitud->rejected_by)->toBe((int) $contexto['contador']->id)
        ->and($solicitud->rejected_at)->not->toBeNull()
        // Y el motivo de QUIEN PIDIO no se pisa.
        ->and((string) $solicitud->reason)->toBe('El cliente devolvio la mercaderia');
});

/**
 * El RECHAZO NO ANULA la venta.
 *
 * REGLA que fija: *rechazar y anular son hechos distintos*. Rechazar es "no
 * lo hago": la venta sigue en pie, sin nota de credito y sin tocar su
 * estado. Anular es "lo hecho": consume el correlativo y deja al cliente
 * con su credito a favor. Quien rechaza NO anula.
 */
test('test_el_rechazo_de_la_solicitud_de_anulacion_no_anula_la_venta', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'RECH-0003');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', [
            'reason' => 'La venta no se puede anular',
        ])
        ->assertOk();

    // 1. La VENTA sigue en pie.
    expect((string) DB::table('sales')->where('id', $venta->id)->value('status'))->toBe('paid');

    // 2. Y NO hay nota de credito, ni auditoria de anulacion: nadie anulo
    //    nada.
    expect(DB::table('fiscal_documents')->where('document_type', 'credit_note')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'sale.voided')->count())->toBe(0);
});

/**
 * Una solicitud PENDIENTE no se rechaza DOS VECES.
 *
 * REGLA que fija: *el rechazo es un HECHO, y un hecho repetido no es una
 * segunda opinion*. La segunda vez no hay nada que rechazar, y el motivo del
 * PRIMER rechazo no se pisa.
 */
test('test_una_solicitud_pendiente_no_se_rechaza_dos_veces', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'RECH-0004');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', ['reason' => 'Primer motivo'])
        ->assertOk();

    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', ['reason' => 'Segundo motivo'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y la solicitud sigue con el PRIMER motivo: el segundo no pisa el
    // primero.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('rejected')
        ->and((string) $solicitud->rejection_reason)->toBe('Primer motivo')
        ->and(DB::table('audit_logs')->where('action', 'sale.void_request_rejected')->count())->toBe(1);
});

/**
 * Una solicitud de una venta YA ANULADA no se rechaza.
 *
 * REGLA que fija: *no se rechaza lo que ya no esta en pie*. La anulacion es
 * un HECHO: la venta quedo anulada y su peticion ya no espera nada. Un
 * rechazo sobre una venta anulada no dice nada, porque ya no hay nada que
 * decidir.
 */
test('test_no_se_rechaza_la_solicitud_de_anulacion_de_una_venta_ya_anulada', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'RECH-0005');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    // La venta se anula: el hecho esta hecho.
    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/anulacion', [])
        ->assertOk();

    // Y el rechazo ya no tiene sentido.
    $respuesta = $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', [
            'reason' => 'La venta no se puede anular',
        ]);

    $respuesta->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y la solicitud NO se toco: sigue como estaba.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('pending')
        ->and($solicitud->rejected_at)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'sale.void_request_rejected')->count())->toBe(0);
});

/**
 * Quien NO tiene el permiso de anular NO rechaza la solicitud.
 *
 * REGLA que fija: *el permiso es la ley, y la pantalla es la cortesia*.
 * Rechazar es parte de la supervision de la anulacion, asi que va con su
 * permiso (`sales.void`): quien solo puede pedir, no puede responder.
 */
test('test_quien_no_tiene_el_permiso_de_anular_no_rechaza_la_solicitud_de_anulacion', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'RECH-0006');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', [
            'reason' => 'La venta no se puede anular',
        ])
        ->assertForbidden();

    // Y la solicitud sigue PENDIENTE.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $venta->id)->first();

    expect((string) $solicitud->status)->toBe('pending')
        ->and($solicitud->rejected_at)->toBeNull();
});

/**
 * La solicitud de una venta de OTRA TIENDA no se rechaza.
 *
 * REGLA que fija: *una venta de otra tienda no existe para quien pregunta*.
 * Un 403 le diria al cajero que la venta EXISTE, y eso ya es informacion de
 * otra tienda.
 */
test('test_la_solicitud_de_anulacion_de_una_venta_de_otra_tienda_no_se_rechaza', function () {
    $contexto = VoidRequestFixture::escenario();

    $ventaAjena = VoidRequestFixture::venta((int) CreditFixture::store(), 'RECH-AJENA-1');

    VoidRequestFixture::solicitud(
        $ventaAjena->id,
        (int) $ventaAjena->store_id,
        'Solicitud de otra tienda',
        (int) $contexto['cajero']->id,
    );

    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$ventaAjena->id.'/solicitud-anulacion/rechazo', [
            'reason' => 'La venta no se puede anular',
        ])
        ->assertNotFound();

    // Y la solicitud ajena sigue PENDIENTE.
    $solicitud = DB::table('sale_void_requests')->where('sale_id', $ventaAjena->id)->first();

    expect((string) $solicitud->status)->toBe('pending')
        ->and($solicitud->rejected_at)->toBeNull();
});

/**
 * El RECHAZO queda anotado en la AUDITORIA DE NEGOCIO, con lo que el cajero
 * tiene que ver.
 *
 * REGLA que fija: *un rechazo sin rastro es un rechazo que nadie puede
 * defender despues*. La anotacion lleva QUIEN PIDIO (con su nombre), POR
 * QUE PIDIO, QUE VENTA era, CUANDO se pidio, CUANDO se rechazo, QUIEN lo
 * rechazo y POR QUE se rechazo.
 *
 * Y LA IP NO VA EN EL CONTEXTO: la IP es informacion tecnica, y vive en
 * `audit_logs.ip_address`, que es donde vive. El contexto de negocio no la
 * mezcla.
 */
test('test_el_rechazo_de_la_solicitud_de_anulacion_queda_anotado_en_la_auditoria_de_negocio', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'RECH-0007');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion/rechazo', [
            'reason' => 'La venta tiene el comprobante emitido y ya no se puede anular',
        ])
        ->assertOk();

    $auditoria = DB::table('audit_logs')
        ->where('action', 'sale.void_request_rejected')
        ->where('entity_type', 'sale')
        ->where('entity_id', $venta->id)
        ->first();

    expect($auditoria)->not->toBeNull()
        ->and((int) $auditoria->user_id)->toBe((int) $contexto['contador']->id)
        ->and((int) $auditoria->store_id)->toBe((int) $contexto['store_id']);

    $carga = json_decode((string) $auditoria->context, true);

    expect($carga['requested_by']['id'])->toBe((int) $contexto['cajero']->id)
        ->and($carga['requested_by']['name'])->toBe((string) $contexto['cajero']->name)
        ->and($carga['request_reason'])->toBe('El cliente devolvio la mercaderia')
        ->and($carga['invoice_no'])->toBe('RECH-0007')
        ->and($carga['requested_at'])->not->toBeNull()
        ->and($carga['rejected_by']['id'])->toBe((int) $contexto['contador']->id)
        ->and($carga['rejected_by']['name'])->toBe((string) $contexto['contador']->name)
        ->and($carga['rejected_at'])->not->toBeNull()
        ->and($carga['rejection_reason'])->toBe('La venta tiene el comprobante emitido y ya no se puede anular');

    // Y LA IP NO ES UN DATO DE NEGOCIO: vive en su columna, no en el
    // contexto de la venta.
    expect($carga)->not->toHaveKey('ip_address')
        ->and($carga)->not->toHaveKey('ip')
        ->and($auditoria->ip_address)->not->toBeNull();
});
