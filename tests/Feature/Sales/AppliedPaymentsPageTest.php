<?php

use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * 1.12 — la PAGINA de la venta: lo que la pantalla enseña.
 *
 * REGLA que fija: *la ficha de la venta enseña las TRES cifras de cada pago,
 * y avisa cuando lo aplicado no cuadra con el total*.
 *
 * Este test es el de la COSTURA: no mira la base de datos, mira lo que la
 * pantalla recibe. El dato lo comprueba `AppliedPaymentsTest`; aqui se
 * comprueba el CONTRATO de las props, que es lo que la pagina tiene que
 * dibujar.
 *
 * Y EL AVISO: si la suma de lo aplicado no cuadra con el total, la pantalla
 * lo dice CON LA CIFRA. El hueco se ve, no se esconde.
 */

/**
 * El PEDIDO de una venta de 58 con un sobrepago de 100.
 *
 * @param  array<string, int>  $escenario
 * @param  string  $importe  Lo que entrega el cliente.
 * @return array<string, mixed>
 */
function pedido_con_sobrepago_de_la_pagina(array $escenario, string $importe = '100.0000'): array
{
    return [
        'lines' => [
            [
                'product_id' => $escenario['product_id'],
                'quantity' => '2',
                'unit_price' => '25.0000',
            ],
        ],
        'payments' => [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => $importe],
        ],
    ];
}

/**
 * 1.12 — el DETALLE enseña lo recibido, lo aplicado y el cambio.
 *
 * REGLA que fija: *la ficha no enseña el bruto, enseña las TRES cifras*. Con
 * un sobrepago, la ficha dice 100 recibido, 58 aplicado y 42 de cambio: el
 * 100 sin contexto es la cifra que esconde la verdad.
 */
test('test_el_detalle_de_la_venta_ensena_lo_recibido_lo_aplicado_y_el_cambio', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $cobro = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_con_sobrepago_de_la_pagina($escenario));

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($usuario)
        ->get('/ventas/'.$saleId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/show')
            ->has('sale.payments', 1)
            ->where('sale.payments.0.received', '100.0000')
            ->where('sale.payments.0.applied', '58.0000')
            ->where('sale.payments.0.change', '42.0000')
            ->where('sale.payments.0.type', 'cash')
            ->where('sale.payment_summary.received_total', '100.0000')
            ->where('sale.payment_summary.applied_total', '58.0000')
            ->where('sale.payment_summary.change_total', '42.0000')
            ->where('sale.payment_summary.total', '58.0000')
            ->where('sale.payment_summary.difference', '0.0000')
            ->where('sale.payment_summary.matches_total', true)
            ->etc());
});

/**
 * 1.12 — la LISTA enseña lo aplicado de cada venta.
 *
 * REGLA que fija: *el listado no esconde lo aplicado*. La lista y el detalle
 * tienen que decir lo mismo: si el detalle dice 58 aplicado, la lista no
 * puede decir 100.
 */
test('test_la_lista_de_ventas_ensena_lo_aplicado_de_cada_venta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $cobro = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_con_sobrepago_de_la_pagina($escenario));

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($usuario)
        ->get('/ventas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/index')
            ->has('sales', 1)
            ->where('sales.0.id', $saleId)
            ->where('sales.0.grand_total', '58.0000')
            ->where('sales.0.applied_total', '58.0000')
            ->etc());
});

/**
 * 1.12 — el AVISO aparece cuando lo aplicado no cuadra con el total.
 *
 * REGLA que fija: *el hueco se ve, y se ve con la cifra*. Una venta con
 * cliente y pago parcial (30,0000 sobre 58,0000) deja un hueco de 28,0000, y
 * la pantalla lo dice: el numero, no un "algo no cuadra".
 */
test('test_el_aviso_aparece_con_la_cifra_cuando_lo_aplicado_no_cuadra_con_el_total', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // El pago parcial con cliente: lo que no cubre es deuda, y la venta se
    // cobra. Lo aplicado (30,0000) deja un hueco de 28,0000 sobre el total.
    $pedido = pedido_con_sobrepago_de_la_pagina($escenario, '30.0000');
    $pedido['customer_id'] = CreditFixture::customer($escenario['store_id']);

    $cobro = $this->actingAs($usuario)->postJson('/caja/cobrar', $pedido);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($usuario)
        ->get('/ventas/'.$saleId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('sale.payment_summary.applied_total', '30.0000')
            ->where('sale.payment_summary.total', '58.0000')
            ->where('sale.payment_summary.difference', '-28.0000')
            ->where('sale.payment_summary.matches_total', false)
            ->etc());
});
