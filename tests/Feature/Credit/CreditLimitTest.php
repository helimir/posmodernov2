<?php

use App\Domain\Credit\CustomerBalanceService;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * 1.6 — el LIMITE DE CREDITO BLOQUEA la venta que dejaria al cliente por
 * encima de su limite.
 *
 * REGLA que fija: *si el cliente tiene limite de credito y la venta dejaria su
 * deuda por encima del limite, el cobro se rechaza*.
 *
 * LAS CINCO PARTES DE LA REGLA:
 *
 *  - `credit_limit` en `null` = SIN LIMITE: no bloquea nunca;
 *  - la deuda que se compara es la NUEVA: la deuda DERIVADA de esa tienda mas
 *    la parte que esta venta deja sin cobrar;
 *  - SOLO bloquea lo que AUMENTA la deuda: una venta cobrada completa
 *    (deuda nueva = deuda actual) nunca es bloqueada;
 *  - PAGAR EN EFECTIVO NUNCA SE BLOQUEA, aunque el cliente ya este por encima
 *    de su limite: el pago le BAJA la deuda, y bloquearlo seria una tonteria;
 *  - el mensaje dice cuanto debe ahora, cual es su limite y cuanto lo
 *    dejaria.
 *
 * EL LIMITE VIVE EN EL MAESTRO (`customers.credit_limit`), no en la ficha de
 * tienda: es un limite del cliente, no un saldo de una tienda. Y la deuda que
 * se compara es la DERIVADA del libro del cliente, no el cache.
 */

/**
 * El pedido de una venta de la caja: dos unidades a 25 (58,0000 con el 16 %
 * de impuesto).
 *
 * @param  array<string, int>  $escenario
 * @param  list<array<string, mixed>>  $pagos
 * @return array<string, mixed>
 */
function limite_pedido(array $escenario, array $pagos, ?int $clienteId = null): array
{
    $pedido = [
        'lines' => [
            [
                'product_id' => $escenario['product_id'],
                'quantity' => '2',
                'unit_price' => '25.0000',
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
 * Una DEUDA PREVIA del cliente: un movimiento de tipo `sale` en su libro.
 *
 * Es el libro del cliente el que dice lo que debe: la deuda es DERIVADA de sus
 * movimientos, y por eso el limite se compara contra esa verdad.
 */
function limite_deuda_previa(int $clienteId, int $storeId, string $importe): void
{
    (new CustomerBalanceService(DB::connection()))->registerSale(
        customerId: $clienteId,
        storeId: $storeId,
        amount: $importe,
        referenceNo: 'DEUDA-PREVIA',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );
}

/**
 * Una venta A CREDITO que deja la deuda por encima del limite NO se cobra.
 *
 * REGLA que fija: *el limite de credito BLOQUEA la venta*. El cliente debe
 * 80,0000, su limite es 100,0000, y una venta a credito de 58,0000 lo dejaria
 * en 138,0000: el cobro se rechaza y no queda NADA escrito.
 */
test('test_una_venta_a_credito_que_deja_la_deuda_por_encima_del_limite_no_cobra_y_no_deja_venta', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $clienteId = CreditFixture::customer($escenario['store_id'], null, null, '100.0000');
    $usuario = PosFixture::usuario($escenario['store_id']);

    // El cliente ya debe 80,0000.
    limite_deuda_previa($clienteId, $escenario['store_id'], '80.0000');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', limite_pedido($escenario, [
            ['payment_method_id' => $credito, 'amount' => '58.0000'],
        ], $clienteId));

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // El mensaje dice las TRES cifras: cuanto debe ahora, cual es su limite y
    // cuanto lo dejaria.
    expect((string) $respuesta->json('errorMsg'))
        ->toContain('El cliente debe 80.0000')
        ->toContain('su limite es 100.0000')
        ->toContain('esta venta lo dejaria en 138.0000');

    // Y NO queda venta: ni cabecera, ni lineas, ni pago, ni asiento, ni cambio
    // de stock, ni numero quemado.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('sale_totals')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0)
        ->and((string) DB::table('inventory_lots')->value('available'))->toBe('10.0000')
        ->and(DB::table('fiscal_document_reservations')->count())->toBe(0);
});

/**
 * Una venta A CREDITO que deja la deuda DENTRO del limite SI se cobra.
 *
 * REGLA que fija: *el limite bloquea lo que pasa de la linea, no lo que llega
 * a ella*. El cliente no debe nada y su limite es 100,0000: una venta a
 * credito de 58,0000 lo deja en 58,0000, y la venta se cobra.
 */
test('test_una_venta_a_credito_que_deja_la_deuda_dentro_del_limite_si_se_cobra', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $clienteId = CreditFixture::customer($escenario['store_id'], null, null, '100.0000');
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', limite_pedido($escenario, [
            ['payment_method_id' => $credito, 'amount' => '58.0000'],
        ], $clienteId));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('grand_total', '58.0000');

    // Y la deuda es la que se temia: 58,0000, dentro del limite.
    $servicio = new CustomerBalanceService(DB::connection());

    expect($servicio->due($clienteId, $escenario['store_id']))->toBe('58.0000');
});

/**
 * Un cliente SIN LIMITE no se bloquea nunca.
 *
 * REGLA que fija: *`credit_limit` en `null` es SIN LIMITE*. No es un limite
 * de cero: es la ausencia de limite. Un cliente con deuda de 500,0000 y sin
 * limite puede comprar a credito lo que necesite.
 */
test('test_un_cliente_sin_limite_de_credito_no_se_bloquea_nunca', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $clienteId = CreditFixture::customer($escenario['store_id'], null, null, null);
    $usuario = PosFixture::usuario($escenario['store_id']);

    // El cliente ya debe 500,0000: sin limite, no importa.
    limite_deuda_previa($clienteId, $escenario['store_id'], '500.0000');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', limite_pedido($escenario, [
            ['payment_method_id' => $credito, 'amount' => '58.0000'],
        ], $clienteId));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'confirmed');

    // Y su deuda es la que corresponde: la previa mas la de esta venta.
    $servicio = new CustomerBalanceService(DB::connection());

    expect($servicio->due($clienteId, $escenario['store_id']))->toBe('558.0000');
});

/**
 * Un cliente que YA DEBE mas que su limite puede PAGAR EN EFECTIVO.
 *
 * REGLA que fija: *el limite bloquea lo que AUMENTA la deuda, y el pago en
 * efectivo la BAJA*. El cliente debe 138,0000 y su limite es 100,0000: esta
 * venta no le aumenta la deuda (la cobra entera), asi que no se bloquea. Y su
 * cobro posterior le BAJA la deuda: 138 - 38 = 100, justo en el limite.
 */
test('test_un_cliente_que_ya_debe_mas_que_su_limite_puede_pagar_en_efectivo_y_su_cobro_le_baja_la_deuda', function () {
    $escenario = PosFixture::escenario();
    $clienteId = CreditFixture::customer($escenario['store_id'], null, null, '100.0000');
    $usuario = PosFixture::usuario($escenario['store_id']);

    // El cliente debe 138,0000: mas que su limite de 100,0000.
    limite_deuda_previa($clienteId, $escenario['store_id'], '138.0000');

    // 1. La VENTA, pagada en efectivo: se cobra. No aumenta la deuda.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', limite_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
        ], $clienteId));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'paid');

    $servicio = new CustomerBalanceService(DB::connection());

    // La deuda NO aumento: la venta sumo 58 y el cobro resto 58.
    expect($servicio->due($clienteId, $escenario['store_id']))->toBe('138.0000');

    // 2. Y su COBRO baja la deuda: el limite nunca bloquea un cobro.
    $cobro = $this->actingAs($usuario)
        ->postJson('/api/clientes/'.$clienteId.'/cobros', [
            'amount' => '38.0000',
            'reference_no' => 'RECIBO-1',
        ]);

    $cobro->assertCreated()
        ->assertJsonPath('amount', '38.0000')
        ->assertJsonPath('balance', '100.0000');

    expect($servicio->due($clienteId, $escenario['store_id']))->toBe('100.0000');
});

/**
 * El limite se compara con la DEUDA NUEVA, no con la actual.
 *
 * REGLA que fija: *lo que se compara es la deuda que queda DESPUES de la
 * venta*. El cliente debe 80,0000 y su limite es 100,0000: su deuda actual NO
 * excede el limite, asi que la venta a credito (que lo dejaria en 138,0000) se
 * rechaza, y la MISMA venta pagada en efectivo (que lo deja en 80,0000) se
 * cobra. Si se comparara con la deuda ACTUAL, las dos ventas pasarian.
 */
test('test_el_limite_se_compara_con_la_deuda_nueva_y_no_con_la_actual', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $clienteId = CreditFixture::customer($escenario['store_id'], null, null, '100.0000');
    $usuario = PosFixture::usuario($escenario['store_id']);

    // El cliente debe 80,0000: su deuda ACTUAL no excede el limite.
    limite_deuda_previa($clienteId, $escenario['store_id'], '80.0000');

    // 1. A CREDITO: la deuda nueva seria 138,0000. Se rechaza.
    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', limite_pedido($escenario, [
            ['payment_method_id' => $credito, 'amount' => '58.0000'],
        ], $clienteId))
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    expect(DB::table('sales')->count())->toBe(0);

    // 2. EN EFECTIVO: la deuda nueva es 80,0000, la misma de ahora. Se cobra.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', limite_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
        ], $clienteId));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'paid');

    $servicio = new CustomerBalanceService(DB::connection());

    expect($servicio->due($clienteId, $escenario['store_id']))->toBe('80.0000');
});
