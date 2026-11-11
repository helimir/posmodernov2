<?php

use App\Domain\Credit\CustomerBalanceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * 1.4 — el libro del cliente: la VENTA SUMA y el COBRO RESTA.
 *
 * Regla que fija: *el libro del cliente es un mini-libro: la venta suma y el
 * cobro resta*. El saldo del cliente es la SUMA de sus movimientos, y el signo
 * lo lleva el IMPORTE, nunca el tipo: en `customer_ledger_entries.amount`
 * POSITIVO es lo que el cliente DEBE y NEGATIVO es lo que ha pagado.
 *
 * EL DEFECTO QUE ESTE TEST CIERRA: la venta NO generaba ningun movimiento, y
 * el cobro generaba UNO NEGATIVO. Un cliente que compra por 100 y paga 100 en
 * efectivo quedaba con saldo -100,0000: un credito a favor del cliente en
 * lugar de una deuda de cero. Y un cliente a quien se le debe dinero no
 * tenia forma de que su deuda SUBIERA, porque no existia.
 *
 * LAS TRES REGLAS QUE FIJA:
 *
 *  - la VENTA con cliente genera un movimiento de tipo `sale` con el importe
 *    POSITIVO: el TOTAL de la venta, no solo la parte no pagada;
 *  - cada pago que NO es de tipo `credito` genera un movimiento de tipo
 *    `payment` con el importe NEGATIVO: lo APLICADO a esa venta;
 *  - un pago de tipo `credito` NO genera movimiento de pago: es deuda, no
 *    cobro.
 *
 * El `balance_after` de cada movimiento es el saldo DERIVADO en ese punto, y
 * el cache (`customer_stores.balance`/`due`) coincide con el derivado.
 */

/**
 * El escenario de una venta con cliente en la caja: la tienda de
 * demostracion, su perfil fiscal, un producto con stock, un SERVICIO exento
 * (para tener una venta de 100,0000 exactos) y el medio de pago de credito.
 *
 * @return array<string, mixed>
 */
function escenario_de_venta_con_cliente(): array
{
    $escenario = PosFixture::escenario();

    return [
        'escenario' => $escenario,
        'cliente' => CreditFixture::customer($escenario['store_id']),
        'credito' => PosFixture::medioDePagoDeCredito($escenario['store_id']),
        'servicio' => PosFixture::servicioExento(
            $escenario['store_id'],
            $escenario['exempt_tax_category_id']
        ),
    ];
}

/**
 * El pedido de una venta de dos unidades del producto de la caja (50,0000 de
 * base y 8,0000 de impuesto: 58,0000 en total).
 *
 * @param  array<string, mixed>  $escenario
 * @param  list<array<string, mixed>>  $pagos
 * @return array<string, mixed>
 */
function pedido_de_venta_con_cliente(array $escenario, array $pagos, ?int $clienteId = null): array
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
 * Los MOVIMIENTOS del cliente en la tienda, del mas antiguo al mas nuevo.
 *
 * @return Collection<int, object>
 */
function movimientos_del_cliente(int $clienteId, int $storeId)
{
    return DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->where('store_id', $storeId)
        ->orderBy('id')
        ->get();
}

/**
 * El servicio de credito del cliente en la tienda.
 */
function servicio_de_credito(): CustomerBalanceService
{
    return new CustomerBalanceService(DB::connection());
}

/**
 * La VENTA suma la deuda y el COBRO la resta: el saldo de un cliente que paga
 * justo lo que debe es CERO, y el de cada movimiento es el saldo DERIVADO en
 * ese punto.
 */
test('test_la_venta_suma_la_deuda_y_el_cobro_la_resta_y_el_saldo_de_un_cliente_que_paga_todo_queda_en_cero', function () {
    $storeId = CreditFixture::store();
    $clienteId = CreditFixture::customer($storeId);

    $servicio = servicio_de_credito();

    // 1. LA VENTA: el movimiento es POSITIVO, porque lo que el cliente DEBE,
    //    y su `balance_after` es el saldo derivado tras la venta.
    $ventaId = $servicio->registerSale(
        customerId: $clienteId,
        storeId: $storeId,
        amount: '100.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    $venta = DB::table('customer_ledger_entries')->where('id', $ventaId)->first();

    expect($venta)->not->toBeNull()
        ->and($venta->type)->toBe('sale')
        ->and((string) $venta->amount)->toBe('100.0000')
        ->and((string) $venta->balance_after)->toBe('100.0000')
        ->and((string) $venta->reference_no)->toBe('VENTA-1')
        ->and((string) $venta->entry_date)->toBe('2026-11-04');

    // 2. EL COBRO: el movimiento es NEGATIVO, porque lo que ha pagado, y el
    //    `balance_after` es el saldo derivado tras el cobro.
    $cobroId = $servicio->registerPayment(
        customerId: $clienteId,
        storeId: $storeId,
        amount: '100.0000',
        referenceNo: 'RECIBO-1',
        entryDate: new DateTimeImmutable('2026-11-05'),
    );

    $cobro = DB::table('customer_ledger_entries')->where('id', $cobroId)->first();

    expect($cobro)->not->toBeNull()
        ->and($cobro->type)->toBe('payment')
        ->and((string) $cobro->amount)->toBe('-100.0000')
        ->and((string) $cobro->balance_after)->toBe('0.0000');

    // 3. Y el saldo derivado es CERO: el cliente pago justo lo que debia.
    //    NO es -100,0000: un saldo negativo es credito a favor del cliente, y
    //    aqui no hay ninguno.
    expect($servicio->balance($clienteId, $storeId))->toBe('0.0000')
        ->and($servicio->due($clienteId, $storeId))->toBe('0.0000')
        ->and($servicio->holds($clienteId, $storeId))->toBeTrue();

    $cache = DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $storeId)
        ->first();

    expect((string) $cache->balance)->toBe('0.0000')
        ->and((string) $cache->due)->toBe('0.0000');
});

/**
 * La VENTA de la caja genera el movimiento `sale` por el TOTAL y el `payment`
 * por lo cobrado: el saldo es exactamente la deuda.
 */
test('test_la_venta_de_la_caja_con_cliente_genera_el_movimiento_de_venta_por_el_total_y_el_de_cobro_por_lo_cobrado', function () {
    $datos = escenario_de_venta_con_cliente();
    $escenario = $datos['escenario'];
    $clienteId = $datos['cliente'];

    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)->postJson('/caja/cobrar', pedido_de_venta_con_cliente(
        $escenario,
        [['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000']],
        $clienteId
    ));

    $respuesta->assertCreated()->assertJsonPath('grand_total', '58.0000');

    // 1. LOS DOS MOVIMIENTOS: la VENTA (positiva, por el TOTAL) y el COBRO
    //    (negativo, por lo cobrado).
    $movimientos = movimientos_del_cliente($clienteId, $escenario['store_id']);

    expect($movimientos)->toHaveCount(2)
        ->and($movimientos[0]->type)->toBe('sale')
        ->and((string) $movimientos[0]->amount)->toBe('58.0000')
        ->and((int) $movimientos[0]->ref_invoice_id)->toBe((int) $respuesta->json('sale_id'))
        ->and($movimientos[1]->type)->toBe('payment')
        ->and((string) $movimientos[1]->amount)->toBe('-58.0000')
        ->and((int) $movimientos[1]->ref_invoice_id)->toBe((int) $respuesta->json('sale_id'));

    // 2. Y el saldo derivado es la DEUDA: cero, porque el cliente pago todo.
    $servicio = servicio_de_credito();

    expect($servicio->balance($clienteId, $escenario['store_id']))->toBe('0.0000')
        ->and($servicio->due($clienteId, $escenario['store_id']))->toBe('0.0000')
        ->and($servicio->holds($clienteId, $escenario['store_id']))->toBeTrue();
});

/**
 * Un pago de tipo CREDITO no es un cobro: no genera movimiento de pago y la
 * deuda queda igual al total de la venta.
 */
test('test_un_pago_de_credito_no_genera_movimiento_de_cobro_y_deja_la_deuda_igual_al_total', function () {
    $datos = escenario_de_venta_con_cliente();
    $escenario = $datos['escenario'];
    $clienteId = $datos['cliente'];

    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)->postJson('/caja/cobrar', pedido_de_venta_con_cliente(
        $escenario,
        [['payment_method_id' => $datos['credito'], 'amount' => '58.0000']],
        $clienteId
    ));

    $respuesta->assertCreated()->assertJsonPath('grand_total', '58.0000');

    // 1. SOLO el movimiento de la VENTA: el pago a credito no es un cobro.
    $movimientos = movimientos_del_cliente($clienteId, $escenario['store_id']);

    expect($movimientos)->toHaveCount(1)
        ->and($movimientos[0]->type)->toBe('sale')
        ->and((string) $movimientos[0]->amount)->toBe('58.0000')
        ->and($movimientos->where('type', 'payment'))->toHaveCount(0);

    // 2. Y la deuda es el TOTAL de la venta.
    $servicio = servicio_de_credito();

    expect($servicio->balance($clienteId, $escenario['store_id']))->toBe('58.0000')
        ->and($servicio->due($clienteId, $escenario['store_id']))->toBe('58.0000')
        ->and($servicio->holds($clienteId, $escenario['store_id']))->toBeTrue();
});

/**
 * Si la IMPUTACION del credito falla, la VENTA tambien revierte: la venta y
 * el movimiento del libro del cliente son un solo hecho.
 */
test('test_una_venta_con_un_cliente_sin_ficha_en_la_tienda_revierte_y_no_deja_venta', function () {
    $datos = escenario_de_venta_con_cliente();
    $escenario = $datos['escenario'];

    // Un cliente SIN FICHA en la tienda: su credito en ella no existe.
    $clienteId = CreditFixture::clienteSinFicha('Cliente Sin Ficha', 'X-00000001');

    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)->postJson('/caja/cobrar', pedido_de_venta_con_cliente(
        $escenario,
        [['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000']],
        $clienteId
    ));

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // Y NO queda venta: ni cabecera, ni lineas, ni pago, ni numero quemado.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and(DB::table('customer_ledger_entries')->where('customer_id', $clienteId)->count())->toBe(0)
        ->and(DB::table('fiscal_document_reservations')->count())->toBe(0);
});

/**
 * El pago MIXTO: lo cobrado en efectivo reduce la deuda y lo dejado a credito
 * NO la reduce, porque un pago a credito es deuda, no cobro.
 */
test('test_un_pago_mixto_deja_la_deuda_en_la_parte_a_credito', function () {
    $datos = escenario_de_venta_con_cliente();
    $escenario = $datos['escenario'];
    $clienteId = $datos['cliente'];

    $usuario = PosFixture::usuario($escenario['store_id']);

    // Una venta de 100,0000 exactos: dos unidades de un servicio EXENTO.
    $respuesta = $this->actingAs($usuario)->postJson('/caja/cobrar', [
        'customer_id' => $clienteId,
        'lines' => [
            [
                'product_id' => $datos['servicio'],
                'quantity' => '2',
                'unit_price' => '50.0000',
            ],
        ],
        'payments' => [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '50.0000'],
            ['payment_method_id' => $datos['credito'], 'amount' => '50.0000'],
        ],
    ]);

    $respuesta->assertCreated()->assertJsonPath('grand_total', '100.0000');

    // 1. La VENTA suma el TOTAL, y el COBRO resta lo cobrado EN EFECTIVO.
    $movimientos = movimientos_del_cliente($clienteId, $escenario['store_id']);

    expect($movimientos)->toHaveCount(2)
        ->and($movimientos[0]->type)->toBe('sale')
        ->and((string) $movimientos[0]->amount)->toBe('100.0000')
        ->and((string) $movimientos[0]->balance_after)->toBe('100.0000')
        ->and($movimientos[1]->type)->toBe('payment')
        ->and((string) $movimientos[1]->amount)->toBe('-50.0000')
        ->and((string) $movimientos[1]->balance_after)->toBe('50.0000');

    // 2. Y la deuda es la parte que NO se cobro: 50,0000.
    $servicio = servicio_de_credito();

    expect($servicio->balance($clienteId, $escenario['store_id']))->toBe('50.0000')
        ->and($servicio->due($clienteId, $escenario['store_id']))->toBe('50.0000');

    // 3. Y el CACHE coincide con el derivado: la invariante se sostiene.
    $cache = DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $escenario['store_id'])
        ->first();

    expect((string) $cache->balance)->toBe('50.0000')
        ->and((string) $cache->due)->toBe('50.0000')
        ->and($servicio->holds($clienteId, $escenario['store_id']))->toBeTrue();
});
