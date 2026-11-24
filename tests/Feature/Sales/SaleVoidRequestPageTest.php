<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\VoidRequestFixture;

/**
 * La PANTALLA de la solicitud de anulacion: lo que ve el cajero y lo que ve
 * quien puede anular.
 *
 * Reglas que fija:
 *
 *  - la LISTA de solicitudes pendientes es de QUIEN PUEDE ANULAR: es su mesa
 *    de trabajo, y por eso lleva su permiso;
 *  - la lista solo enseña las solicitudes PENDIENTES: una venta ya anulada
 *    no esta pendiente de nada;
 *  - la lista es POR TIENDA: la tienda es la del contexto, y una solicitud
 *    de otra tienda no existe para quien pregunta;
 *  - la FICHA de la venta ensena la solicitud pendiente: el cajero tiene que
 *    ver que su peticion esta viva, con quien la hizo y por que.
 */

/**
 * La LISTA de solicitudes pendientes ensena quien pidio y por que.
 *
 * REGLA que fija: *la mesa de trabajo de quien anula enseña la peticion
 * COMPLETA*: que venta es, cuando se pidio, quien la pidio y por que. Una
 * lista sin el motivo no sirve para decidir nada.
 */
test('test_la_lista_de_solicitudes_pendientes_ensena_quien_pidio_y_por_que', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'PANT-0001');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['contador'])
        ->get('/ventas/solicitudes-anulacion')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/void-requests')
            ->has('requests', 1)
            ->where('requests.0.sale_id', $venta->id)
            ->where('requests.0.invoice_no', 'PANT-0001')
            ->where('requests.0.reason', 'El cliente devolvio la mercaderia')
            ->where('requests.0.requested_by.id', (int) $contexto['cajero']->id)
            ->where('requests.0.requested_by.name', (string) $contexto['cajero']->name)
            ->has('requests.0.requested_at')
            ->where('store.id', (int) $contexto['store_id'])
            ->etc());
});

/**
 * La LISTA no enseña las ventas ya anuladas.
 *
 * REGLA que fija: *una solicitud PENDIENTE es la que espera*. Cuando la
 * venta queda anulada, la solicitud ya no esta pendiente: el hecho esta
 * hecho y la lista se queda vacia de ese caso.
 */
test('test_la_lista_de_solicitudes_pendientes_no_ensena_las_ventas_ya_anuladas', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'PANT-0002');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['contador'])
        ->postJson('/ventas/'.$venta->id.'/anulacion', [])
        ->assertOk();

    $this->actingAs($contexto['contador'])
        ->get('/ventas/solicitudes-anulacion')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/void-requests')
            ->has('requests', 0)
            ->etc());
});

/**
 * La LISTA no enseña las solicitudes de OTRA TIENDA.
 *
 * REGLA que fija: *la lista es por tienda*. La tienda es la del contexto de
 * la sesion, y una solicitud de otra tienda no existe para quien pregunta.
 */
test('test_la_lista_de_solicitudes_pendientes_no_muestra_las_ventas_de_otra_tienda', function () {
    $contexto = VoidRequestFixture::escenario();

    $ventaAjena = VoidRequestFixture::venta((int) CreditFixture::store(), 'PANT-AJENA-1');

    DB::table('sales')->where('id', $ventaAjena->id)->update([
        'void_requested_at' => now(),
        'void_request_reason' => 'Solicitud de otra tienda',
        'void_requested_by' => (int) $contexto['cajero']->id,
    ]);

    $this->actingAs($contexto['contador'])
        ->get('/ventas/solicitudes-anulacion')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/void-requests')
            ->has('requests', 0)
            ->etc());
});

/**
 * Quien NO puede anular NO abre la lista de solicitudes.
 *
 * REGLA que fija: *la mesa de trabajo de la anulacion es de quien anula*.
 * El cajero puede pedir, y ve su peticion en la ficha de la venta; la cola
 * de las peticiones ajenas es de quien tiene el permiso de deshacer.
 */
test('test_quien_no_puede_anular_no_abre_la_lista_de_solicitudes_pendientes', function () {
    $contexto = VoidRequestFixture::escenario();

    $this->actingAs($contexto['cajero'])
        ->get('/ventas/solicitudes-anulacion')
        ->assertForbidden();
});

/**
 * La FICHA de la venta ensena la solicitud pendiente.
 *
 * REGLA que fija: *quien pide tiene que ver que su peticion esta viva*. La
 * ficha dice que la solicitud esta pendiente, quien la hizo y por que: una
 * peticion que no se ve es una peticion que nadie sabe si ha llegado.
 */
test('test_la_ficha_de_la_venta_ensena_la_solicitud_pendiente', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'PANT-0003');

    $this->actingAs($contexto['cajero'])
        ->postJson('/ventas/'.$venta->id.'/solicitud-anulacion', [
            'reason' => 'El cliente devolvio la mercaderia',
        ])
        ->assertCreated();

    $this->actingAs($contexto['cajero'])
        ->get('/ventas/'.$venta->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/show')
            ->where('sale.void_request.status', 'pending')
            ->where('sale.void_request.reason', 'El cliente devolvio la mercaderia')
            ->where('sale.void_request.requested_by.id', (int) $contexto['cajero']->id)
            ->where('sale.void_request.requested_by.name', (string) $contexto['cajero']->name)
            ->has('sale.void_request.requested_at')
            ->etc());
});

/**
 * Una venta SIN solicitud no tiene bloque de solicitud.
 *
 * REGLA que fija: *el bloque de solicitud solo aparece cuando hay
 * solicitud*. Un bloque vacio en una venta corriente es ruido, y el ruido
 * esconde lo que importa.
 */
test('test_la_ficha_de_la_venta_sin_solicitud_no_ensena_bloque_de_solicitud', function () {
    $contexto = VoidRequestFixture::escenario();

    $venta = VoidRequestFixture::venta($contexto['store_id'], 'PANT-0004');

    $this->actingAs($contexto['cajero'])
        ->get('/ventas/'.$venta->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/show')
            ->where('sale.void_request', null)
            ->etc());
});
