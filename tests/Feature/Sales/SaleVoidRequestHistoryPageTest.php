<?php

use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\VoidRequestFixture;

/**
 * La FICHA de la venta con la HISTORIAL de sus solicitudes de anulacion.
 *
 * Reglas que fija:
 *
 *  - la ficha ENSENA EL RECHAZO: el cajero tiene que ver que su peticion no
 *    esta pendiente, POR QUE se rechazo, QUIEN la rechazo y CUANDO. Una
 *    peticion que se cierra en silencio es una peticion que el cajero no
 *    sabe que paso;
 *  - la ficha ENSENA EL HISTORIAL: una venta puede tener VARIAS solicitudes
 *    (una rechazada y otra nueva), y el historial se conserva. La ficha
 *    enseña todas, de la mas nueva a la mas vieja;
 *  - la ficha NO ENSENA lo que no hay: una venta sin solicitudes no tiene
 *    bloque de solicitud ni historial.
 */
test('test_la_ficha_de_la_venta_ensena_el_rechazo_de_la_solicitud_con_el_motivo_quien_y_cuando', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'HIST-0001');

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

    $this->actingAs($contexto['cajero'])
        ->get('/ventas/'.$venta->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/show')
            ->where('sale.void_request.status', 'rejected')
            ->where('sale.void_request.reason', 'El cliente devolvio la mercaderia')
            ->where('sale.void_request.rejection_reason', 'La venta tiene el comprobante emitido y ya no se puede anular')
            ->where('sale.void_request.rejected_by.id', (int) $contexto['contador']->id)
            ->where('sale.void_request.rejected_by.name', (string) $contexto['contador']->name)
            ->has('sale.void_request.rejected_at')
            ->where('sale.void_request.requested_by.id', (int) $contexto['cajero']->id)
            ->where('sale.void_request.requested_by.name', (string) $contexto['cajero']->name)
            ->has('sale.void_request.requested_at')
            ->etc());
});

/**
 * La ficha ENSENA el historial de las solicitudes de la venta.
 *
 * REGLA que fija: *el historial se conserva y se ve*. Una venta puede tener
 * una solicitud rechazada y otra nueva, y las dos existen: la ficha las
 * enseña, de la mas nueva a la mas vieja, con el estado de cada una.
 */
test('test_la_ficha_de_la_venta_ensena_el_historial_de_sus_solicitudes_de_anulacion', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'HIST-0002');

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

    // Y la SEGUNDA peticion, con un motivo nuevo: el cajero puede tener
    // razon la segunda vez.
    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia y el supervisor ya lo sabe',
        ])
        ->assertCreated();

    $this->actingAs($contexto['cajero'])
        ->get('/ventas/'.$venta->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/show')
            // La VIVA: la que espera.
            ->where('sale.void_request.status', 'pending')
            ->where('sale.void_request.reason', 'El cliente devolvio la mercaderia y el supervisor ya lo sabe')
            // Y el HISTORIAL: las dos, de la mas nueva a la mas vieja.
            ->has('sale.void_request_history', 2)
            ->where('sale.void_request_history.0.status', 'pending')
            ->where('sale.void_request_history.0.reason', 'El cliente devolvio la mercaderia y el supervisor ya lo sabe')
            ->where('sale.void_request_history.1.status', 'rejected')
            ->where('sale.void_request_history.1.reason', 'El cliente devolvio la mercaderia')
            ->where('sale.void_request_history.1.rejection_reason', 'La venta tiene el comprobante emitido y ya no se puede anular')
            ->etc());
});

/**
 * Una venta SIN solicitudes no tiene historial de solicitudes.
 *
 * REGLA que fija: *lo que no hay no se enseña*. Un historial vacio en una
 * venta corriente es ruido, y el ruido esconde lo que importa.
 */
test('test_la_ficha_de_la_venta_sin_solicitudes_no_ensena_historial_de_solicitudes', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'HIST-0003');

    $this->actingAs($contexto['cajero'])
        ->get('/ventas/'.$venta->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/show')
            ->where('sale.void_request', null)
            ->where('sale.void_request_history', [])
            ->etc());
});
