<?php

use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * La CAJA cobra: el endpoint del punto de venta hace una venta DE VERDAD.
 *
 * Reglas que fija:
 *
 *  - el cobro escribe una venta COMPLETA: cabecera, lineas, totales, pago y
 *    asiento contable, con el numero de documento y el de control RESERVADOS
 *    (un numero quemado es un numero perdido: ADR-0001);
 *  - el stock baja EXACTAMENTE lo vendido, y el cache de la tienda vuelve a
 *    ser la suma de los lotes;
 *  - el asiento CUADRA (debitos = creditos) y su importe es el total de la
 *    venta;
 *  - la venta queda `paid` cuando lo COBRADO cubre el total (aunque haya
 *    cambio: el sobrepago es el caso normal), y `confirmed` cuando queda algo
 *    por cobrar (un pago parcial o un pago a credito);
 *  - con un cliente, el cobro IMPUTA la VENTA y el COBRO en el libro del
 *    cliente: un movimiento POSITIVO de tipo `sale` por el total y uno
 *    NEGATIVO de tipo `payment` por lo cobrado, y el saldo derivado queda en
 *    cero cuando el cliente paga todo;
 *  - un importe invalido NO se cobra: responde 422 y no queda ninguna venta.
 *
 * El escenario es el de DEMOSTRACION del repo (`PosFixture`): la tienda
 * sembrada, su perfil fiscal, sus dos series y un producto con stock
 * abierto por una compra real.
 */

/**
 * El pedido de una venta de dos unidades a 25, con un 16 % de impuesto.
 *
 * @param  array<string, int>  $escenario
 * @param  string  $importe  Lo que entrega el cliente.
 * @return array<string, mixed>
 */
function pedido_de_caja(array $escenario, string $importe = '58.0000', ?int $customerId = null): array
{
    $pedido = [
        'lines' => [
            [
                'product_id' => $escenario['product_id'],
                'quantity' => '2',
                'unit_price' => '25.0000',
            ],
        ],
        'payments' => [
            [
                'payment_method_id' => $escenario['payment_method_id'],
                'amount' => $importe,
            ],
        ],
    ];

    if ($customerId !== null) {
        $pedido['customer_id'] = $customerId;
    }

    return $pedido;
}

/**
 * El cobro escribe una venta COMPLETA: cabecera, lineas, totales, pago y
 * asiento.
 */
test('test_el_cobro_escribe_la_venta_con_cabecera_lineas_totales_pago_y_asiento', function () {
    // La emision fiscal APAGADA: este test prueba la CAJA (la venta), no el
    // canal fiscal, y su premisa es que el documento fiscal lo cierra T2.
    // El interruptor se declara aqui para que el test no dependa del `.env`
    // de la maquina.
    config(['fiscal.emission_enabled' => false]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_caja($escenario));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('grand_total', '58.0000')
        ->assertJsonPath('change', '0.0000')
        ->assertJsonPath('invoice_no', 'serie-0011')
        ->assertJsonPath('control_number', '00-00000001');

    $saleId = (int) $respuesta->json('sale_id');

    // 1. La cabecera: con su numero, su estado y su fecha de negocio.
    $sale = DB::table('sales')->where('id', $saleId)->first();

    expect($sale)->not->toBeNull()
        ->and((int) $sale->store_id)->toBe($escenario['store_id'])
        ->and((int) $sale->created_by)->toBe((int) $usuario->id)
        ->and($sale->status)->toBe('paid')
        ->and($sale->invoice_no)->toBe('serie-0011')
        // El documento fiscal lo cierra T2: la venta todavia no lo tiene.
        ->and($sale->fiscal_document_id)->toBeNull();

    // 2. La linea, con la tasa CONGELADA y su evidencia.
    $items = DB::table('sale_items')->where('sale_id', $saleId)->get();

    expect($items)->toHaveCount(1);

    $item = $items->first();

    expect((int) $item->product_id)->toBe($escenario['product_id'])
        ->and((string) $item->quantity)->toBe('2.0000')
        ->and((string) $item->unit_price)->toBe('25.0000')
        ->and((int) $item->tax_category_id)->toBe($escenario['tax_category_id'])
        ->and((string) $item->tax_rate_snapshot)->toBe('16.0000')
        ->and((string) $item->base_amount)->toBe('50.0000')
        ->and((string) $item->tax_amount)->toBe('8.0000')
        ->and((string) $item->line_total)->toBe('58.0000');

    // 3. Los totales: el subtotal, el impuesto y el total.
    $totals = DB::table('sale_totals')->where('sale_id', $saleId)->first();

    expect((string) $totals->sub_total)->toBe('50.0000')
        ->and((string) $totals->tax_total)->toBe('8.0000')
        ->and((string) $totals->grand_total)->toBe('58.0000');

    // 4. El pago, con su importe y su detalle. El `details` es un `jsonb`:
    //    lo lee el MODELO, que lo devuelve como ARRAY.
    $payments = SalePayment::query()->where('sale_id', $saleId)->get();

    expect($payments)->toHaveCount(1)
        ->and((int) $payments->first()->payment_method_id)->toBe($escenario['payment_method_id'])
        ->and((string) $payments->first()->amount)->toBe('58.0000')
        // Y lo APLICADO: lo que el pago cubre de la venta.
        ->and((string) $payments->first()->applied)->toBe('58.0000')
        // El detalle lleva el efectivo recibido y el cambio.
        ->and($payments->first()->details['cash_received'])->toBe('58.0000')
        ->and($payments->first()->details['change'])->toBe('0.0000');

    // 5. Y el asiento contable existe.
    $entry = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->first();

    expect($entry)->not->toBeNull();
});

/**
 * El cobro reserva el numero de documento Y el de control, y el documento
 * queda enlazado a la venta.
 */
test('test_el_cobro_reserva_el_numero_de_documento_y_el_de_control', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_caja($escenario));

    $saleId = (int) $respuesta->json('sale_id');

    // 1. La reserva del DOCUMENTO: con su rol y enlazada a la venta.
    $documento = DB::table('fiscal_document_reservations')
        ->where('series_id', $escenario['document_series_id'])
        ->first();

    expect($documento)->not->toBeNull()
        ->and($documento->series_role)->toBe('document')
        ->and((int) $documento->number)->toBe(1)
        ->and($documento->reference_type)->toBe('sale')
        ->and((int) $documento->reference_id)->toBe($saleId);

    // 2. Y la del CONTROL: con su rol y ENLAZADA a la venta. Ese enlace es
    //    lo que hace que el documento fiscal reutilice el control del
    //    comprobante en vez de reservar otro.
    $control = DB::table('fiscal_document_reservations')
        ->where('series_id', $escenario['control_series_id'])
        ->first();

    expect($control)->not->toBeNull()
        ->and($control->series_role)->toBe('control')
        ->and((int) $control->number)->toBe(1)
        ->and($control->reference_type)->toBe('sale')
        ->and((int) $control->reference_id)->toBe($saleId);

    // 3. Y los DOS contadores de las series avanzan UNA vez cada uno: el
    //    numero se RESERVA, no se deriva de un SELECT.
    expect((int) DB::table('fiscal_series')
        ->where('id', $escenario['document_series_id'])->value('next_number'))->toBe(2)
        ->and((int) DB::table('fiscal_series')
            ->where('id', $escenario['control_series_id'])->value('next_number'))->toBe(2);
});

/**
 * El stock baja EXACTAMENTE lo vendido.
 */
test('test_el_cobro_baja_el_stock_exactamente_lo_vendido', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_caja($escenario))
        ->assertCreated();

    // 1. El LOTE: de diez unidades quedan ocho, y hay una traza del consumo.
    $lote = DB::table('inventory_lots')
        ->where('product_id', $escenario['product_id'])
        ->first();

    expect((string) $lote->available)->toBe('8.0000')
        ->and(DB::table('sale_item_lot_consumptions')->count())->toBe(1);

    // 2. Y el CACHE de la tienda vuelve a ser la suma de los lotes: es lo
    //    que ve la caja.
    expect((string) DB::table('product_stores')
        ->where('product_id', $escenario['product_id'])
        ->where('store_id', $escenario['store_id'])
        ->value('quantity_in_stock'))->toBe('8.0000');
});

/**
 * El asiento CUADRA y su importe es el total de la venta.
 */
test('test_el_asiento_de_la_venta_cuadra_y_su_importe_es_el_total', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_caja($escenario));

    $saleId = (int) $respuesta->json('sale_id');

    $entry = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->first();

    $lines = DB::table('ledger_lines')->where('ledger_entry_id', $entry->id)->orderBy('id')->get();

    // Sin cliente, el debito es de la caja; el ingreso y el impuesto se
    // acreditan. Las cuentas son las del plan de la CONFIGURACION.
    expect($lines->pluck('account_code')->all())->toBe(['1100000', '4010000', '2110000']);

    $debe = '0';
    $haber = '0';

    foreach ($lines as $linea) {
        $debe = bcadd($debe, (string) $linea->debit, 4);
        $haber = bcadd($haber, (string) $linea->credit, 4);
    }

    expect(bccomp($debe, $haber, 4))->toBe(0)
        ->and(bccomp($debe, '58.0000', 4))->toBe(0)
        ->and(bccomp((string) $lines[0]->debit, '58.0000', 4))->toBe(0)
        ->and(bccomp((string) $lines[1]->credit, '50.0000', 4))->toBe(0)
        ->and(bccomp((string) $lines[2]->credit, '8.0000', 4))->toBe(0);
});

/**
 * La venta queda `paid` cuando lo COBRADO cubre el total.
 */
test('test_la_venta_queda_pagada_cuando_el_pago_cubre_el_total', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_caja($escenario, '58.0000'));

    $respuesta->assertCreated()->assertJsonPath('status', 'paid');

    expect(DB::table('sales')->where('id', (int) $respuesta->json('sale_id'))->value('status'))->toBe('paid');
});

/**
 * El SOBREPAGO deja la venta PAGADA y el cambio no se pierde: queda en el
 * detalle del pago y en la respuesta.
 *
 * ESTE TEST CAMBIO CON LA UNIDAD 1.6: antes afirmaba que el sobrepago dejaba
 * la venta `confirmed`, y eso era el DEFECTO. El estado comparaba los
 * importes BRUTOS con el total y exigia coincidencia EXACTA: el cliente
 * entrega 100 por una venta de 58, sobran 42, y la venta quedaba `confirmed`
 * aunque estuviera cobrada. El sobrepago es el caso NORMAL, no un caso limite.
 *
 * Ahora la venta queda `paid`, el cambio es de 42,0000, y lo que se persiste
 * distingue lo RECIBIDO (100,0000) de lo APLICADO (58,0000).
 */
test('test_el_sobrepago_deja_la_venta_pagada_y_registra_el_cambio_en_el_pago', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // El cliente entrega 100 por una venta de 58: sobran 42.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_caja($escenario, '100.0000'));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('change', '42.0000')
        ->assertJsonPath('grand_total', '58.0000');

    // El detalle del pago conserva el efectivo recibido y el cambio.
    $pago = SalePayment::query()->where('sale_id', (int) $respuesta->json('sale_id'))->first();

    expect($pago->details['cash_received'])->toBe('100.0000')
        ->and($pago->details['change'])->toBe('42.0000')
        // Y lo APLICADO es el total de la venta: lo que cubre el pago.
        ->and((string) $pago->applied)->toBe('58.0000');

    // Y el libro contable no se resiente: debita el TOTAL de la venta, no
    // lo que se entrego en el mostrador.
    $entry = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', (int) $respuesta->json('sale_id'))
        ->first();

    $debe = '0';

    foreach (DB::table('ledger_lines')->where('ledger_entry_id', $entry->id)->get() as $linea) {
        $debe = bcadd($debe, (string) $linea->debit, 4);
    }

    expect(bccomp($debe, '58.0000', 4))->toBe(0);
});

/**
 * Con un cliente, el cobro IMPUTA la VENTA y el COBRO en el libro del
 * cliente: la venta SUMA (positivo) y el cobro RESTA (negativo), y el saldo
 * derivado de un cliente que paga todo queda en CERO.
 *
 * ESTE TEST CAMBIO CON LA UNIDAD 1.4: antes afirmaba que el cobro dejaba UN
 * solo movimiento NEGATIVO y un saldo de -58,0000. Eso era el DEFECTO: un
 * cliente que compra por 58 y paga 58 quedaba con credito a favor. El libro
 * del cliente es un mini-libro, y la venta es un hecho entero: se registra
 * por el TOTAL.
 */
test('test_el_cobro_con_cliente_registra_la_venta_y_el_cobro_y_el_saldo_derivado_queda_en_cero', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);
    $clienteId = CreditFixture::customer($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_caja($escenario, '58.0000', $clienteId));

    $respuesta->assertCreated();

    // 1. LOS DOS MOVIMIENTOS: la VENTA, POSITIVA por el total, y el COBRO,
    //    NEGATIVO por lo cobrado. Los dos apuntan a la venta.
    $movimientos = DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->where('store_id', $escenario['store_id'])
        ->orderBy('id')
        ->get();

    expect($movimientos)->toHaveCount(2)
        ->and($movimientos[0]->type)->toBe('sale')
        ->and((string) $movimientos[0]->amount)->toBe('58.0000')
        ->and((int) $movimientos[0]->ref_invoice_id)->toBe((int) $respuesta->json('sale_id'))
        ->and($movimientos[1]->type)->toBe('payment')
        ->and((string) $movimientos[1]->amount)->toBe('-58.0000')
        ->and((int) $movimientos[1]->ref_invoice_id)->toBe((int) $respuesta->json('sale_id'));

    // 2. Y el saldo derivado es CERO: el cliente pago justo lo que debia, y
    //    el cache de la ficha va detras.
    expect((string) DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $escenario['store_id'])
        ->value('balance'))->toBe('0.0000')
        ->and((string) DB::table('customer_ledger_entries')
            ->where('customer_id', $clienteId)
            ->orderByDesc('id')
            ->value('balance_after'))->toBe('0.0000');
});

/**
 * Un importe INVALIDO no se cobra: responde 422 y no queda ninguna venta.
 */
test('test_un_importe_invalido_responde_422_y_no_cobra', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // 1. Una linea con cantidad cero.
    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'lines' => [
                ['product_id' => $escenario['product_id'], 'quantity' => '0', 'unit_price' => '25.0000'],
            ],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 2. Una venta sin lineas.
    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'lines' => [],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 3. Un pago de importe cero.
    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'lines' => [
                ['product_id' => $escenario['product_id'], 'quantity' => '2', 'unit_price' => '25.0000'],
            ],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '0'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 4. Y nada de eso se cobro: no hay venta, ni linea, ni pago, ni cambio
    //    de stock, ni numero quemado.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('sale_totals')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and(DB::table('fiscal_document_reservations')->count())->toBe(0)
        ->and((string) DB::table('inventory_lots')->value('available'))->toBe('10.0000');
});

/**
 * Un pago de tipo CREDITO sin CLIENTE no se cobra: responde 422 y no queda
 * ninguna venta.
 *
 * REGLA que fija: *cobrar a credito SIN cliente es un error*. Un pago a
 * credito es DEUDA, y una deuda sin destino no se registra: el movimiento
 * necesita un cliente al que imputarse. Y la pantalla no lo bloquea en el
 * navegador: lo manda al servidor y el servidor lo dice.
 */
test('test_un_pago_a_credito_sin_cliente_responde_422_y_no_cobra', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $credito,
                    'amount' => '58.0000',
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y nada de eso se cobro: no hay venta, ni linea, ni pago, ni cambio de
    // stock, ni numero quemado.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('sale_totals')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and(DB::table('fiscal_document_reservations')->count())->toBe(0)
        ->and((string) DB::table('inventory_lots')->value('available'))->toBe('10.0000');
});

/**
 * El cobro DELEGA en el dominio: la venta no se escribe a mano.
 *
 * Esta es la prueba que falla si el endpoint deja de invocar el
 * `CheckoutService`: las reservas, el enlace de la reserva con la venta y el
 * cache del stock los escribe el DOMINIO, y nada mas los escribe.
 */
test('test_el_cobro_delega_la_venta_en_el_checkout_del_dominio', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_caja($escenario));

    $respuesta->assertCreated();

    $saleId = (int) $respuesta->json('sale_id');

    // 1. El numero lo RESERVO el dominio, desde la serie: la serie avanzo y
    //    la reserva existe con su rol.
    expect((int) DB::table('fiscal_series')
        ->where('id', $escenario['document_series_id'])->value('next_number'))->toBe(2)
        ->and(DB::table('fiscal_document_reservations')
            ->where('series_id', $escenario['document_series_id'])
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->count())->toBe(1);

    // 2. La venta sabe que serie es la suya: el numero de documento es el
    //    prefijo de la serie MAS el numero reservado.
    $prefijo = (string) DB::table('fiscal_series')
        ->where('id', $escenario['document_series_id'])
        ->value('prefix');

    expect(DB::table('sales')->where('id', $saleId)->value('invoice_no'))
        ->toBe($prefijo.'1');

    // 3. Y el cache del stock lo refresco el dominio: es la suma de los
    //    lotes, no un numero escrito a mano.
    $lotes = (string) DB::table('inventory_lots')
        ->where('product_id', $escenario['product_id'])
        ->sum('available');

    expect((string) DB::table('product_stores')
        ->where('product_id', $escenario['product_id'])
        ->where('store_id', $escenario['store_id'])
        ->value('quantity_in_stock'))->toBe($lotes);
});
