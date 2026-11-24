<?php

use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * F3-03 — la FICHA de la venta anulada: lo que la pantalla tiene que decir.
 *
 * REGLA que fija: *la ficha de una venta anulada enseña el estado, el
 * motivo y su nota de credito*. La anulacion no es un estado invisible: la
 * persona que mira la venta tiene que ver que esta anulada, POR QUE, y que
 * documento la deja sin efecto.
 *
 * Y LA PANTALLA DICE QUE NO REPONE STOCK: la anulacion es fiscal y
 * contable, y la mercaderia sigue fuera. Quien lea la ficha no puede
 * creerse que el almacen esta como estaba.
 */
test('test_la_ficha_de_la_venta_anulada_ensena_el_estado_el_motivo_y_la_nota', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('anulador-'.uniqid(), ['sales.void', 'sales.create', 'sales.read']);

    $usuario = PermissionFixture::usuarioConCaja($rol, $escenario['store_id']);

    config(['fiscal.emission_enabled' => true]);

    $cobro = $this->actingAs($usuario)->postJson('/caja/cobrar', [
        'lines' => [
            ['product_id' => $escenario['product_id'], 'quantity' => '2', 'unit_price' => '25.0000'],
        ],
        'payments' => [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
        ],
    ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($usuario)
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk();

    $this->actingAs($usuario)
        ->get('/ventas/'.$saleId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/show')
            ->where('sale.status', 'cancelled')
            ->has('sale.void')
            ->where('sale.void.reason', 'Error en el monto facturado')
            ->has('sale.void.voided_at')
            ->has('sale.void.document.id')
            ->has('sale.void.document.number')
            ->has('sale.void.note.id')
            ->has('sale.void.note.number')
            ->has('sale.void.note.issue_date')
            ->etc());
});

/**
 * F3-03 — una venta que NO esta anulada no tiene bloque de anulacion.
 *
 * REGLA que fija: *el bloque de anulacion solo aparece cuando la venta
 * esta anulada*. Un bloque vacio en una venta corriente es ruido, y el
 * ruido esconde lo que importa.
 */
test('test_una_venta_no_anulada_no_tiene_bloque_de_anulacion', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('anulador-'.uniqid(), ['sales.void', 'sales.create', 'sales.read']);

    $usuario = PermissionFixture::usuarioConCaja($rol, $escenario['store_id']);

    $cobro = $this->actingAs($usuario)->postJson('/caja/cobrar', [
        'lines' => [
            ['product_id' => $escenario['product_id'], 'quantity' => '2', 'unit_price' => '25.0000'],
        ],
        'payments' => [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
        ],
    ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($usuario)
        ->get('/ventas/'.$saleId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('sale.status', 'paid')
            ->where('sale.void', null)
            ->etc());
});
