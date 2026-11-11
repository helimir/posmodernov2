<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * El REPARTO del asiento de la venta: entre la cuenta de efectivo y la cuenta
 * de clientes.
 *
 * REGLA que fija: *una venta con cliente reparte el total entre la cuenta de
 * efectivo (lo cobrado) y la cuenta de clientes (lo que queda a credito)*.
 *
 * EL DEFECTO QUE ESTE TEST CIERRA: el checkout escribia UN SOLO asiento por
 * venta y no contabilizaba los pagos. Sin cliente debita la caja (1100000) y
 * todo cuadra. CON cliente debita la cuenta por cobrar (1200000) y NUNCA toca
 * la caja: el efectivo que entra al cajon no aparece en la cuenta de
 * efectivo, y el arqueo del dia da un DESCUADRE FALSO en cada venta con
 * cliente.
 *
 * LAS CUATRO SITUACIONES, Y LO QUE AFIRMA CADA UNA:
 *
 *  - SIN CLIENTE: el total entero va a la caja, como antes. No se rompe lo que
 *    funciona;
 *  - CON CLIENTE PAGADO EN EFECTIVO: el efectivo va a la caja y la cuenta por
 *    cobrar NO se escribe (su importe seria cero);
 *  - CON CLIENTE A CREDITO: el total va a la cuenta por cobrar y la caja NO se
 *    escribe: un pago a credito no es dinero de la caja;
 *  - MIXTO: el efectivo aplicado va a la caja y el resto a la cuenta por
 *    cobrar.
 *
 * Y ADEMAS: el asiento CUADRA en todos los casos (debe = haber = total), y el
 * saldo de la cuenta por cobrar del libro contable es IGUAL al saldo del
 * libro del cliente (`customer_ledger_entries`).
 *
 * SENSIBILIDAD A LA MUTACION: este test afirma el CONTENIDO EXACTO del asiento
 * (cuenta, debito y credito de cada linea, en su orden). Si la linea de
 * efectivo no se escribiera, o se escribiera con el total en vez de lo
 * cobrado, la asercion falla. No basta con comprobar que el asiento existe:
 * un asiento que existe y esta mal es el defecto que se cierra aqui.
 */

/**
 * El pedido de una venta de la caja.
 *
 * @param  array<string, int>  $escenario
 * @param  list<array<string, mixed>>  $pagos
 * @return array<string, mixed>
 */
function reparto_pedido(
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
 * Un importe normalizado a la escala de la columna: `numeric(25,4)`.
 */
function reparto_importe(mixed $valor): string
{
    return (string) BigDecimal::of((string) $valor)->toScale(4, RoundingMode::HALF_UP);
}

/**
 * Las LINEAS del asiento de la venta, con su cuenta, su debito y su credito.
 *
 * @return list<array{account: string, debit: string, credit: string}>
 */
function reparto_lineas(int $saleId): array
{
    $entry = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->first();

    expect($entry)->not->toBeNull();

    $lineas = [];

    foreach (DB::table('ledger_lines')->where('ledger_entry_id', $entry->id)->orderBy('id')->get() as $linea) {
        $lineas[] = [
            'account' => (string) $linea->account_code,
            'debit' => reparto_importe($linea->debit),
            'credit' => reparto_importe($linea->credit),
        ];
    }

    return $lineas;
}

/**
 * El SALDO de una cuenta contable en la tienda: debito menos credito.
 *
 * @param  int  $storeId  La tienda de la cuenta.
 */
function reparto_saldo_de_cuenta(int $storeId, string $cuenta): string
{
    $movimiento = DB::table('ledger_lines')
        ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
        ->where('ledger_entries.store_id', $storeId)
        ->where('ledger_lines.account_code', $cuenta)
        ->selectRaw(
            'COALESCE(SUM(ledger_lines.debit), 0) AS debe, '
            .'COALESCE(SUM(ledger_lines.credit), 0) AS haber'
        )
        ->first();

    $debe = BigDecimal::of(reparto_importe($movimiento->debe ?? '0'));
    $haber = BigDecimal::of(reparto_importe($movimiento->haber ?? '0'));

    return (string) $debe->minus($haber)->toScale(4, RoundingMode::HALF_UP);
}

/**
 * El SALDO del libro del cliente: la suma de sus movimientos.
 */
function reparto_saldo_del_cliente(int $clienteId, int $storeId): string
{
    $saldo = DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->where('store_id', $storeId)
        ->sum('amount');

    return reparto_importe($saldo ?? '0');
}

/**
 * Una venta SIN CLIENTE deja el TOTAL en la cuenta de efectivo: no se rompe lo
 * que funciona.
 */
test('test_una_venta_sin_cliente_deja_el_total_en_la_cuenta_de_efectivo', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reparto_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
        ]));

    $respuesta->assertCreated()
        ->assertJsonPath('grand_total', '58.0000')
        ->assertJsonPath('status', 'paid');

    // El ASIENTO: el total debita la caja, y el ingreso y el impuesto se
    // acreditan. No hay cuenta por cobrar: no hay cliente.
    expect(reparto_lineas((int) $respuesta->json('sale_id')))->toBe([
        ['account' => '1100000', 'debit' => '58.0000', 'credit' => '0.0000'],
        ['account' => '4010000', 'debit' => '0.0000', 'credit' => '50.0000'],
        ['account' => '2110000', 'debit' => '0.0000', 'credit' => '8.0000'],
    ]);

    // Y el saldo de la cuenta de efectivo es el total de la venta.
    expect(reparto_saldo_de_cuenta($escenario['store_id'], '1100000'))->toBe('58.0000')
        ->and(reparto_saldo_de_cuenta($escenario['store_id'], '1200000'))->toBe('0.0000');
});

/**
 * Una venta CON CLIENTE pagada en efectivo deja el efectivo en la CAJA y NO
 * deja nada en la cuenta por cobrar.
 *
 * ESTE TEST ES EL QUE FALLA CON EL DEFECTO: antes la linea de la caja no se
 * escribia con cliente, y el saldo de la cuenta de efectivo era cero.
 */
test('test_una_venta_con_cliente_pagada_en_efectivo_deja_el_efectivo_en_la_caja_y_nada_en_cuentas_por_cobrar', function () {
    $escenario = PosFixture::escenario();
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reparto_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
        ], $clienteId));

    $respuesta->assertCreated()
        ->assertJsonPath('grand_total', '58.0000')
        ->assertJsonPath('status', 'paid');

    // El ASIENTO: el efectivo debita la caja, y NO hay linea de cuenta por
    // cobrar: lo que quedo a credito es cero.
    expect(reparto_lineas((int) $respuesta->json('sale_id')))->toBe([
        ['account' => '1100000', 'debit' => '58.0000', 'credit' => '0.0000'],
        ['account' => '4010000', 'debit' => '0.0000', 'credit' => '50.0000'],
        ['account' => '2110000', 'debit' => '0.0000', 'credit' => '8.0000'],
    ]);

    // Y los SALDOS: la caja tiene el efectivo, y la cuenta por cobrar no tiene
    // nada.
    expect(reparto_saldo_de_cuenta($escenario['store_id'], '1100000'))->toBe('58.0000')
        ->and(reparto_saldo_de_cuenta($escenario['store_id'], '1200000'))->toBe('0.0000');
});

/**
 * Una venta CON CLIENTE a credito deja el TOTAL en la cuenta por cobrar y
 * NADA en la caja: un pago a credito no es dinero de la caja.
 */
test('test_una_venta_con_cliente_a_credito_deja_el_total_en_cuentas_por_cobrar_y_nada_en_la_caja', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reparto_pedido($escenario, [
            ['payment_method_id' => $credito, 'amount' => '58.0000'],
        ], $clienteId));

    $respuesta->assertCreated()
        ->assertJsonPath('grand_total', '58.0000');

    // El ASIENTO: el total debita la cuenta por cobrar, y NO hay linea de
    // caja: no entro efectivo al cajon.
    expect(reparto_lineas((int) $respuesta->json('sale_id')))->toBe([
        ['account' => '1200000', 'debit' => '58.0000', 'credit' => '0.0000'],
        ['account' => '4010000', 'debit' => '0.0000', 'credit' => '50.0000'],
        ['account' => '2110000', 'debit' => '0.0000', 'credit' => '8.0000'],
    ]);

    // Y los SALDOS: la caja esta vacia, y la cuenta por cobrar tiene el
    // total.
    expect(reparto_saldo_de_cuenta($escenario['store_id'], '1100000'))->toBe('0.0000')
        ->and(reparto_saldo_de_cuenta($escenario['store_id'], '1200000'))->toBe('58.0000');
});

/**
 * Una venta MIXTA reparte: el efectivo aplicado a la caja y el resto a la
 * cuenta por cobrar.
 *
 * La venta es de 100,0000 EXACTOS (un servicio EXENTO: dos unidades de
 * 50,0000), y se paga con 50,0000 en efectivo y 50,0000 a credito.
 */
test('test_una_venta_mixta_reparte_el_efectivo_a_la_caja_y_el_resto_a_cuentas_por_cobrar', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $servicio = PosFixture::servicioExento(
        $escenario['store_id'],
        $escenario['exempt_tax_category_id']
    );
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reparto_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '50.0000'],
            ['payment_method_id' => $credito, 'amount' => '50.0000'],
        ], $clienteId, $servicio, '2', '50.0000'));

    $respuesta->assertCreated()
        ->assertJsonPath('grand_total', '100.0000');

    // El ASIENTO: 50 a la caja y 50 a la cuenta por cobrar. El ingreso se
    // acredita entero, y el impuesto es cero (la categoria es EXENTA), asi
    // que no se escribe linea de impuesto.
    expect(reparto_lineas((int) $respuesta->json('sale_id')))->toBe([
        ['account' => '1100000', 'debit' => '50.0000', 'credit' => '0.0000'],
        ['account' => '1200000', 'debit' => '50.0000', 'credit' => '0.0000'],
        ['account' => '4010000', 'debit' => '0.0000', 'credit' => '100.0000'],
    ]);

    // Y los SALDOS.
    expect(reparto_saldo_de_cuenta($escenario['store_id'], '1100000'))->toBe('50.0000')
        ->and(reparto_saldo_de_cuenta($escenario['store_id'], '1200000'))->toBe('50.0000');
});

/**
 * El asiento CUADRA en todos los casos: el debe es el haber, y los dos son el
 * total de la venta.
 */
test('test_el_asiento_de_la_venta_cuadra_en_todos_los_casos', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $servicio = PosFixture::servicioExento(
        $escenario['store_id'],
        $escenario['exempt_tax_category_id']
    );
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $efectivo = $escenario['payment_method_id'];

    $casos = [
        // Sin cliente, en efectivo.
        [static fn (): array => reparto_pedido($escenario, [
            ['payment_method_id' => $efectivo, 'amount' => '58.0000'],
        ]), '58.0000'],

        // Con cliente, en efectivo.
        [static fn (): array => reparto_pedido($escenario, [
            ['payment_method_id' => $efectivo, 'amount' => '58.0000'],
        ], $clienteId), '58.0000'],

        // Con cliente, a credito.
        [static fn (): array => reparto_pedido($escenario, [
            ['payment_method_id' => $credito, 'amount' => '58.0000'],
        ], $clienteId), '58.0000'],

        // Con cliente, mixto.
        [static fn (): array => reparto_pedido($escenario, [
            ['payment_method_id' => $efectivo, 'amount' => '50.0000'],
            ['payment_method_id' => $credito, 'amount' => '50.0000'],
        ], $clienteId, $servicio, '2', '50.0000'), '100.0000'],
    ];

    foreach ($casos as [$pedido, $total]) {
        $respuesta = $this->actingAs($usuario)->postJson('/caja/cobrar', $pedido());

        $respuesta->assertCreated();

        $lineas = reparto_lineas((int) $respuesta->json('sale_id'));

        $debe = '0';
        $haber = '0';

        foreach ($lineas as $linea) {
            $debe = (string) BigDecimal::of($debe)->plus($linea['debit'])->toScale(4, RoundingMode::HALF_UP);
            $haber = (string) BigDecimal::of($haber)->plus($linea['credit'])->toScale(4, RoundingMode::HALF_UP);
        }

        expect(bccomp($debe, $haber, 4))->toBe(0)
            ->and(bccomp($debe, $total, 4))->toBe(0);
    }
});

/**
 * El SALDO de la cuenta por cobrar del libro contable es IGUAL al saldo del
 * libro del cliente.
 *
 * REGLA que fija: *la cuenta por cobrar del libro contable y el libro del
 * cliente son la MISMA deuda, mirada desde dos sitios*. Si divergen, uno de
 * los dos miente, y el arqueo del dia hereda la mentira.
 */
test('test_el_saldo_de_cuentas_por_cobrar_es_igual_al_saldo_del_libro_del_cliente', function () {
    $escenario = PosFixture::escenario();
    $servicio = PosFixture::servicioExento(
        $escenario['store_id'],
        $escenario['exempt_tax_category_id']
    );
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    // Una venta de 100,0000 con 50,0000 en efectivo: quedan 50,0000 a
    // credito.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', reparto_pedido($escenario, [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '50.0000'],
        ], $clienteId, $servicio, '2', '50.0000'));

    $respuesta->assertCreated()
        ->assertJsonPath('grand_total', '100.0000');

    // 1. La cuenta por cobrar del LIBRO CONTABLE tiene lo que quedo a credito.
    expect(reparto_saldo_de_cuenta($escenario['store_id'], '1200000'))->toBe('50.0000');

    // 2. Y el LIBRO DEL CLIENTE tiene la misma cifra: la venta sumo el total
    //    y el cobro resto lo cobrado.
    expect(reparto_saldo_del_cliente($clienteId, $escenario['store_id']))->toBe('50.0000');

    // 3. Y los dos son IGUALES. Si uno de los dos se desfasara, aqui se ve.
    expect(reparto_saldo_de_cuenta($escenario['store_id'], '1200000'))
        ->toBe(reparto_saldo_del_cliente($clienteId, $escenario['store_id']));
});
