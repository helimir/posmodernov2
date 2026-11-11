<?php

use App\Domain\Accounting\CashDay;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Sales\SalePaymentStatus;
use App\Models\SalePayment;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * 1.6 — LAS REGLAS DEL COBRO: el estado de pago se calcula sobre lo APLICADO,
 * y el credito no cuenta como cobrado.
 *
 * REGLA que fija: *el estado de pago se calcula sobre lo APLICADO a la venta,
 * y un pago de tipo `credit` NO es cobrado*.
 *
 *  - `paid` si los pagos que NO son de credito cubren el total (aunque haya
 *    cambio: el sobrepago es el caso NORMAL, no un caso limite);
 *  - `confirmed` si queda algo por cobrar (credito, o pago parcial).
 *
 * EL DEFECTO QUE ESTOS TESTS CIERRAN: el estado comparaba los importes
 * BRUTOS con el total y exigia coincidencia EXACTA. Con un sobrepago (58 de
 * venta y 100 entregados) la venta quedaba `confirmed` aunque estuviera
 * cobrada, y una venta a credito (100 de tipo `credit` por 58 de venta)
 * quedaba `paid` aunque no se hubiera cobrado un peso. Las dos cosas son el
 * mismo defecto: el estado comparaba importes brutos cuando deberia comparar
 * lo que realmente CUBRE la venta y si ese dinero esta cobrado.
 *
 * Y LA SEGUNDA REGLA: *sin cliente, la venta se cobra COMPLETA*. Lo que no
 * cubre lo recibido, sin cliente, NO es deuda: no hay a quien imputarsela, y
 * una venta a medias no es una venta. Con cliente, esa parte ES deuda, y el
 * reparto del asiento la deja en la cuenta por cobrar.
 *
 * LO QUE SE PERSISTE: `sale_payments.amount` es lo RECIBIDO (el bruto) y
 * `sale_payments.applied` es lo APLICADO a la venta. Los dos son distintos
 * cuando hay cambio, y los dos se guardan: el bruto es lo que entro al
 * mostrador y el aplicado es lo que cubrio la venta.
 */

/**
 * El pedido de una venta de la caja: dos unidades a 25 (58,0000 con el 16 %
 * de impuesto).
 *
 * @param  array<string, int>  $escenario
 * @param  list<array<string, mixed>>  $pagos
 * @param  int|null  $clienteId  El cliente de la venta; `null` si es de
 *                               mostrador.
 * @param  int|null  $productoId  El producto que se vende.
 * @return array<string, mixed>
 */
function reglas_pedido(
    array $escenario,
    array $pagos,
    ?int $clienteId = null,
    ?int $productoId = null,
    string $cantidad = '2',
    string $precio = '25.0000'
): array {
    $pedido = [
        'lines' => [
            [
                'product_id' => $productoId ?? $escenario['product_id'],
                'quantity' => $cantidad,
                'unit_price' => $precio,
            ],
        ],
        'payments' => $pagos,
    ];

    if ($clienteId !== null) {
        $pedido['customer_id'] = $clienteId;
    }

    return $pedido;
}

/**
 * El SOBREPAGO deja la venta PAGADA: el cambio no es un pago pendiente.
 *
 * REGLA que fija: *lo que cubre la venta es lo que se aplica, y el sobrante
 * es el cambio*. El cliente entrega 100 por una venta de 58: sobran 42, y esos
 * 42 se devuelven. La venta esta COBRADA, y su estado lo dice: `paid`.
 *
 * El caso NORMAL, no el limite: el cliente entrega un billete de 100 por una
 * compra de 58 veinte veces al dia.
 */
test('test_el_sobrepago_deja_la_venta_pagada_y_el_aplicado_es_el_total_de_la_venta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reglas_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '100.0000'],
        ]));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('grand_total', '58.0000')
        ->assertJsonPath('change', '42.0000');

    // Y la cabecera lo dice tambien: el estado de la venta es el del cobro.
    expect(DB::table('sales')->where('id', (int) $respuesta->json('sale_id'))->value('status'))
        ->toBe('paid');

    // El pago: lo RECIBIDO es el bruto (100) y lo APLICADO es el total de la
    // venta (58). El cambio no se pierde: queda en el detalle del pago.
    $pago = SalePayment::query()->where('sale_id', (int) $respuesta->json('sale_id'))->first();

    expect($pago)->not->toBeNull()
        ->and((string) $pago->amount)->toBe('100.0000')
        ->and((string) $pago->applied)->toBe('58.0000')
        ->and($pago->details['cash_received'])->toBe('100.0000')
        ->and($pago->details['change'])->toBe('42.0000');
});

/**
 * Una venta A CREDITO no esta pagada, aunque el pago cubra el total.
 *
 * REGLA que fija: *un pago de tipo `credit` NO es dinero: es DEUDA*. Cubre la
 * venta (su aplicado es el total), pero el dinero no esta cobrado, y por eso
 * la venta queda `confirmed`: queda algo por cobrar.
 */
test('test_una_venta_a_credito_no_esta_pagada_aunque_el_pago_cubra_el_total', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reglas_pedido($escenario, [
            ['payment_method_id' => $credito, 'amount' => '58.0000'],
        ], $clienteId));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('grand_total', '58.0000');

    // El pago CUBRE la venta: su aplicado es el total. Lo que no esta cobrado
    // es el dinero, y por eso la venta no esta pagada.
    $pago = SalePayment::query()->where('sale_id', (int) $respuesta->json('sale_id'))->first();

    expect((string) $pago->applied)->toBe('58.0000')
        ->and(DB::table('sales')->where('id', (int) $respuesta->json('sale_id'))->value('status'))
        ->toBe('confirmed');

    // Y la deuda del cliente es el total: no se cobro ni un peso.
    $servicio = new CustomerBalanceService(DB::connection());

    expect($servicio->due($clienteId, $escenario['store_id']))->toBe('58.0000');
});

/**
 * Una venta MIXTA no esta pagada: lo que va a credito no es cobro.
 *
 * REGLA que fija: *el estado mira lo que esta COBRADO*. Con 50,0000 en
 * efectivo y 50,0000 a credito, lo cobrado son 50,0000 de una venta de
 * 100,0000: queda algo por cobrar, y la venta queda `confirmed`.
 */
test('test_una_venta_mixta_no_esta_pagada_porque_el_credito_no_es_cobro', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $servicio = PosFixture::servicioExento(
        $escenario['store_id'],
        $escenario['exempt_tax_category_id']
    );
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reglas_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '50.0000'],
            ['payment_method_id' => $credito, 'amount' => '50.0000'],
        ], $clienteId, $servicio, '2', '50.0000'));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('grand_total', '100.0000');

    // El efectivo aplicado es 50,0000 (lo que entro al cajon) y el de credito
    // tambien: los dos cubren su parte de la venta.
    $pagos = SalePayment::query()->where('sale_id', (int) $respuesta->json('sale_id'))->orderBy('id')->get();

    expect($pagos)->toHaveCount(2)
        ->and((string) $pagos[0]->applied)->toBe('50.0000')
        ->and((string) $pagos[1]->applied)->toBe('50.0000');

    // Y la deuda es la parte que no se cobro: 50,0000.
    $servicioDeCredito = new CustomerBalanceService(DB::connection());

    expect($servicioDeCredito->due($clienteId, $escenario['store_id']))->toBe('50.0000');
});

/**
 * Un pago PARCIAL SIN CLIENTE no se cobra: la venta no se escribe.
 *
 * REGLA que fija: *sin cliente, la venta se cobra COMPLETA*. Lo que no cubre
 * lo recibido, sin cliente, NO es deuda: no hay a quien imputarsela. El cobro
 * falla con un error que dice CUANTO falta, y no queda NADA escrito: ni
 * cabecera, ni lineas, ni pago, ni asiento, ni cambio de stock, ni numero
 * quemado.
 */
test('test_un_pago_parcial_sin_cliente_no_cobra_y_no_deja_venta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reglas_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '30.0000'],
        ]));

    $respuesta->assertStatus(422)
        ->assertJsonPath(
            'errorMsg',
            'La venta no esta cobrada: faltan 28.0000. Sin cliente, una venta se cobra completa.'
        );

    // Y NO queda nada: la venta es una unidad atomica.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('sale_totals')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0)
        ->and(DB::table('ledger_lines')->count())->toBe(0)
        ->and((string) DB::table('inventory_lots')->value('available'))->toBe('10.0000')
        ->and(DB::table('fiscal_document_reservations')->count())->toBe(0);
});

/**
 * El MISMO pago parcial, CON CLIENTE, SI se cobra: lo que falta es deuda.
 *
 * REGLA que fija: *con cliente, la parte que no se cubre es DEUDA*. El cobro
 * se acepta, la venta queda `confirmed` (queda algo por cobrar) y la deuda
 * del cliente es exactamente la parte que no se cobro.
 */
test('test_un_pago_parcial_con_cliente_cobra_y_deja_la_parte_no_cobrada_como_deuda', function () {
    $escenario = PosFixture::escenario();
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reglas_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '30.0000'],
        ], $clienteId));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('grand_total', '58.0000');

    // La deuda es la parte NO cobrada: 58 - 30 = 28.
    $servicio = new CustomerBalanceService(DB::connection());

    expect($servicio->due($clienteId, $escenario['store_id']))->toBe('28.0000')
        ->and($servicio->holds($clienteId, $escenario['store_id']))->toBeTrue();

    // Y el pago quedo con su aplicado: lo que cubrio de la venta.
    $pago = SalePayment::query()->where('sale_id', (int) $respuesta->json('sale_id'))->first();

    expect((string) $pago->amount)->toBe('30.0000')
        ->and((string) $pago->applied)->toBe('30.0000');
});

/**
 * Una fila VIEJA (sin `applied`) se lee con su BRUTO.
 *
 * REGLA que fija: *el FALLBACK de las filas anteriores a la columna
 * `applied` es su bruto*. Una fila sin `applied` es una fila escrita antes de
 * que la columna existiera, y no tiene cambio registrado: el pago se guardo
 * entero y lo que se aplico a la venta fue el pago entero.
 *
 * Los DOS lectores tienen que resolverlo igual: el estado de pago y el
 * desglose de la caja. Si uno de los dos dijera cero, la caja del dia
 * contaria el mismo cobro dos veces (o ninguna).
 */
test('test_una_fila_de_pago_sin_aplicado_se_lee_con_su_bruto', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $fecha = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate()->format('Y-m-d');

    // Una venta VIEJA: el pago se guardo entero y sin `applied`, porque la
    // columna todavia no existia.
    $saleId = (int) DB::table('sales')->insertGetId([
        'store_id' => $escenario['store_id'],
        'invoice_no' => 'VIEJA-1',
        'sale_date' => $fecha,
        'status' => 'confirmed',
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

    // 1. El ESTADO: lo aplicado de la fila vieja es su bruto (58), y la venta
    //    esta cobrada.
    expect((new SalePaymentStatus(DB::connection()))->forSale($saleId))->toBe('paid');

    // 2. Y el DESGLOSE de la caja dice lo mismo: 58,0000.
    $desglose = (new CashDay(DB::connection()))->receivedByPaymentMethod(
        $escenario['store_id'],
        new DateTimeImmutable($fecha)
    );

    expect($desglose)->toHaveCount(1)
        ->and($desglose[0]['amount'])->toBe('58.0000');
});

/**
 * Lo que se PERSISTE es lo APLICADO, no lo bruto.
 *
 * REGLA que fija: *`sale_payments.amount` es lo recibido y
 * `sale_payments.applied` es lo aplicado*. Son DOS datos distintos, y el
 * segundo es el que dice cuanto cubre el pago. Con dos billetes (40 y 50 por
 * una venta de 58), el segundo pago RECIBE 50 pero APLICA 18: los otros 32
 * son el cambio.
 */
test('test_lo_aplicado_que_se_persiste_es_el_aplicado_y_no_el_bruto', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // El cliente entrega 40 y 50 por una venta de 58: sobran 32.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reglas_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '40.0000'],
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '50.0000'],
        ]));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('change', '32.0000');

    $pagos = SalePayment::query()->where('sale_id', (int) $respuesta->json('sale_id'))->orderBy('id')->get();

    expect($pagos)->toHaveCount(2)
        // El primero cubre todo lo que puede: 40 de 58.
        ->and((string) $pagos[0]->amount)->toBe('40.0000')
        ->and((string) $pagos[0]->applied)->toBe('40.0000')
        // El segundo recibe 50 pero solo aplica 18: los otros 32 son el
        // cambio.
        ->and((string) $pagos[1]->amount)->toBe('50.0000')
        ->and((string) $pagos[1]->applied)->toBe('18.0000')
        ->and($pagos[1]->details['change'])->toBe('32.0000');
});
