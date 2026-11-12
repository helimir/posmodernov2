<?php

use App\Domain\Sales\SalePayments;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * 1.12 — lo APLICADO de un pago: la verdad de un cobro.
 *
 * REGLA que fija: *un pago se enseña con sus TRES cifras: lo RECIBIDO, lo
 * APLICADO y el CAMBIO. Lo aplicado es la verdad*.
 *
 *  - LO RECIBIDO (`sale_payments.amount`) es lo que el cliente entrego: el
 *    dato del cajero;
 *  - LO APLICADO (`sale_payments.applied`) es lo que el pago CUBRE de la
 *    venta: el dato del dominio. Es la verdad, y es la que cuadra con el
 *    total;
 *  - EL CAMBIO (`sale_payments.details.change`) es lo que se devolvio: solo
 *    hay cambio si el cliente dio de mas.
 *
 * EL DEFECTO QUE ESTOS TESTS CIERRAN: la pantalla de ventas enseñaba el
 * BRUTO. Con un sobrepago (100 entregados por una venta de 58) la ficha
 * decia 100 en una venta de 58, mientras el comprobante fiscal de la MISMA
 * venta decia 58: dos verdades distintas en dos pantallas de la misma venta.
 *
 * Y EL FALLBACK: las filas anteriores a la columna `applied` no la tienen, y
 * para esas lo aplicado ES lo recibido (una fila sin `applied` no tiene
 * cambio registrado). El mismo criterio que usa la caja, en un solo sitio.
 */

/**
 * El PEDIDO de una venta de la caja: dos unidades a 25 (58,0000 con el 16 %
 * de impuesto).
 *
 * @param  array<string, int>  $escenario
 * @param  list<array<string, mixed>>  $pagos
 * @param  array<string, mixed>  $linea  La linea de la venta, si no es la de
 *                                       la caja.
 * @return array<string, mixed>
 */
function pedido_de_aplicado(array $escenario, array $pagos, array $linea = []): array
{
    return [
        'lines' => [
            array_merge([
                'product_id' => $escenario['product_id'],
                'quantity' => '2',
                'unit_price' => '25.0000',
            ], $linea),
        ],
        'payments' => $pagos,
    ];
}

/**
 * El SOBREPAGO: el cliente entrega 100 por una venta de 58.
 *
 * REGLA que fija: *lo que el cliente entrego, lo que cubrio la venta y lo que
 * se devolvio son tres cifras, y la pantalla las enseña las tres*.
 */
test('test_el_sobrepago_entrega_las_tres_cifras_recibido_aplicado_y_cambio', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $cobro = $this->actingAs($usuario)->postJson('/caja/cobrar', pedido_de_aplicado($escenario, [
        ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '100.0000'],
    ]));

    $cobro->assertCreated()->assertJsonPath('grand_total', '58.0000');

    $pagos = (new SalePayments(DB::connection()))->forSale((int) $cobro->json('sale_id'));

    expect($pagos)->toHaveCount(1)
        ->and($pagos[0]['received'])->toBe('100.0000')
        ->and($pagos[0]['applied'])->toBe('58.0000')
        ->and($pagos[0]['change'])->toBe('42.0000')
        ->and($pagos[0]['type'])->toBe('cash');
});

/**
 * Una venta NORMAL: lo recibido es lo aplicado, y no hay cambio.
 */
test('test_una_venta_normal_entrega_recibido_igual_a_aplicado_y_cambio_cero', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $cobro = $this->actingAs($usuario)->postJson('/caja/cobrar', pedido_de_aplicado($escenario, [
        ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
    ]));

    $cobro->assertCreated()->assertJsonPath('grand_total', '58.0000');

    $pagos = (new SalePayments(DB::connection()))->forSale((int) $cobro->json('sale_id'));

    expect($pagos)->toHaveCount(1)
        ->and($pagos[0]['received'])->toBe('58.0000')
        ->and($pagos[0]['applied'])->toBe('58.0000')
        ->and($pagos[0]['change'])->toBe('0.0000');
});

/**
 * Una venta A CREDITO: lo aplicado es el TOTAL y no hay cambio.
 *
 * REGLA que fija: *el credito CUBRE la venta, pero no es dinero de la caja*.
 * Lo recibido y lo aplicado son el total de la venta, el cambio es cero, y el
 * tipo del medio de pago lo dice (`credit`): es DEUDA, no un cobro.
 */
test('test_una_venta_a_credito_entrega_el_aplicado_igual_al_total_y_sin_cambio', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $pedido = pedido_de_aplicado($escenario, [
        ['payment_method_id' => $credito, 'amount' => '58.0000'],
    ]);

    $pedido['customer_id'] = $clienteId;

    $cobro = $this->actingAs($usuario)->postJson('/caja/cobrar', $pedido);

    $cobro->assertCreated()->assertJsonPath('grand_total', '58.0000');

    $pagos = (new SalePayments(DB::connection()))->forSale((int) $cobro->json('sale_id'));

    expect($pagos)->toHaveCount(1)
        ->and($pagos[0]['received'])->toBe('58.0000')
        ->and($pagos[0]['applied'])->toBe('58.0000')
        ->and($pagos[0]['change'])->toBe('0.0000')
        ->and($pagos[0]['type'])->toBe('credit');
});

/**
 * Una venta MIXTA: cada pago entrega SU aplicado.
 *
 * REGLA que fija: *el applied es de cada pago, no de la venta*. Con 50,0000
 * en efectivo y 50,0000 a credito por una venta de 100,0000, cada pago
 * aplica 50,0000.
 */
test('test_una_venta_mixta_entrega_el_aplicado_de_cada_pago', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $servicio = PosFixture::servicioExento($escenario['store_id'], $escenario['exempt_tax_category_id']);
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $pedido = pedido_de_aplicado($escenario, [
        ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '50.0000'],
        ['payment_method_id' => $credito, 'amount' => '50.0000'],
    ], [
        'product_id' => $servicio,
        'quantity' => '2',
        'unit_price' => '50.0000',
    ]);

    $pedido['customer_id'] = $clienteId;

    $cobro = $this->actingAs($usuario)->postJson('/caja/cobrar', $pedido);

    $cobro->assertCreated()->assertJsonPath('grand_total', '100.0000');

    $pagos = (new SalePayments(DB::connection()))->forSale((int) $cobro->json('sale_id'));

    expect($pagos)->toHaveCount(2)
        ->and($pagos[0]['received'])->toBe('50.0000')
        ->and($pagos[0]['applied'])->toBe('50.0000')
        ->and($pagos[0]['change'])->toBe('0.0000')
        ->and($pagos[1]['received'])->toBe('50.0000')
        ->and($pagos[1]['applied'])->toBe('50.0000')
        ->and($pagos[1]['change'])->toBe('0.0000');
});

/**
 * Una fila VIEJA (sin `applied`): lo aplicado es lo RECIBIDO.
 *
 * REGLA que fija: *el fallback de las filas anteriores a la columna es su
 * bruto*. Una fila sin `applied` es una fila escrita antes de que la columna
 * existiera, y no tiene cambio registrado: el pago se guardo entero y lo que
 * se aplico a la venta fue el pago entero.
 */
test('test_una_fila_sin_aplicado_entrega_el_aplicado_igual_al_recibido', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $fecha = now()->format('Y-m-d');

    // Una venta VIEJA: el pago se guardo entero y sin `applied`, porque la
    // columna todavia no existia.
    $saleId = (int) DB::table('sales')->insertGetId([
        'store_id' => $escenario['store_id'],
        'invoice_no' => 'VIEJA-APLICADO',
        'sale_date' => $fecha,
        'status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sale_totals')->insert([
        'sale_id' => $saleId,
        'sub_total' => '50.0000',
        'discount' => '0.0000',
        'tax_total' => '8.0000',
        'rounding_adjustment' => '0.0000',
        'grand_total' => '58.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sale_payments')->insert([
        'sale_id' => $saleId,
        'payment_method_id' => $escenario['payment_method_id'],
        'amount' => '58.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pagos = (new SalePayments(DB::connection()))->forSale($saleId);

    expect($pagos)->toHaveCount(1)
        ->and($pagos[0]['received'])->toBe('58.0000')
        ->and($pagos[0]['applied'])->toBe('58.0000')
        ->and($pagos[0]['change'])->toBe('0.0000');
});

/**
 * Una venta cuyo total NO CUADRA con lo aplicado: el resumen lo dice, CON LA
 * CIFRA.
 *
 * REGLA que fija: *si la suma de lo aplicado no cuadra con el total de la
 * venta, el hueco se ve*. La venta con cliente y pago parcial es el caso real
 * de ese hueco: 30,0000 aplicados sobre una venta de 58,0000.
 */
test('test_una_venta_cuyo_total_no_cuadra_con_lo_aplicado_avisa_con_la_cifra', function () {
    $escenario = PosFixture::escenario();
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $pedido = pedido_de_aplicado($escenario, [
        ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '30.0000'],
    ]);

    $pedido['customer_id'] = $clienteId;

    $cobro = $this->actingAs($usuario)->postJson('/caja/cobrar', $pedido);

    $cobro->assertCreated()->assertJsonPath('grand_total', '58.0000');

    $resumen = (new SalePayments(DB::connection()))->summary((int) $cobro->json('sale_id'), '58.0000');

    expect($resumen->appliedTotal)->toBe('30.0000')
        ->and($resumen->total)->toBe('58.0000')
        ->and($resumen->difference)->toBe('-28.0000')
        ->and($resumen->matchesTotal)->toBeFalse();
});

/**
 * Una venta que CUADRA: el resumen no avisa.
 *
 * REGLA que fija: *el aviso es solo para el hueco*. Una venta a credito
 * entrega lo aplicado igual al total, y una venta cobrada tambien: en los dos
 * casos no hay nada que avisar.
 */
test('test_una_venta_que_cuadra_no_avisa', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $cobro = $this->actingAs($usuario)->postJson('/caja/cobrar', pedido_de_aplicado($escenario, [
        ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
    ]));

    $cobro->assertCreated();

    $resumen = (new SalePayments(DB::connection()))->summary((int) $cobro->json('sale_id'), '58.0000');

    expect($resumen->appliedTotal)->toBe('58.0000')
        ->and($resumen->receivedTotal)->toBe('58.0000')
        ->and($resumen->changeTotal)->toBe('0.0000')
        ->and($resumen->difference)->toBe('0.0000')
        ->and($resumen->matchesTotal)->toBeTrue();
});
