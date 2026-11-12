<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * 1.7 — T2 fiscal CONECTADO al checkout.
 *
 * Reglas que fija:
 *
 *  - con el interruptor APAGADO la venta se escribe y NO hay documento fiscal:
 *    es el modo de puesta en marcha, y nada mas se toca;
 *  - con el interruptor ENCENDIDO la venta queda ENLAZADA a su documento
 *    fiscal, y el documento tiene su numero de control;
 *  - un fallo de emision NO puede hacer perder una venta: la venta EXISTE,
 *    queda en `fiscal_failed`, el cobro responde 201 con el aviso, y las
 *    reservas NO se liberan;
 *  - la respuesta del cobro dice el ESTADO FISCAL de la venta.
 *
 * El escenario es el de DEMOSTRACION del repo (`PosFixture`): la tienda
 * sembrada, su perfil fiscal, sus dos series y un producto con stock.
 */
beforeEach(function () {
    $this->escenario = PosFixture::escenario();
    $this->usuario = PosFixture::usuario($this->escenario['store_id']);
});

/**
 * El pedido de una venta de dos unidades a 25, con su pago.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function pedido_fiscal(array $escenario): array
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
            [
                'payment_method_id' => $escenario['payment_method_id'],
                'amount' => '58.0000',
            ],
        ],
    ];
}

/**
 * 1.7 — con el interruptor APAGADO la venta se escribe y NO hay documento.
 *
 * REGLA que fija: *el interruptor apagado es una emision que NO ocurre*, no
 * una emision que falla. La venta sale con `fiscal_document_id` en `null`, y
 * la respuesta dice que no se emitio.
 */
test('test_con_el_interruptor_apagado_la_venta_se_escribe_y_no_hay_documento_fiscal', function () {
    config(['fiscal.emission_enabled' => false]);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_fiscal($this->escenario));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('fiscal.status', 'not_emitted');

    $saleId = (int) $respuesta->json('sale_id');

    // 1. La venta EXISTE: el cobro no se pierde.
    expect(DB::table('sales')->where('id', $saleId)->exists())->toBeTrue();

    // 2. Y no hay documento fiscal: la venta queda sin enlazar.
    expect(DB::table('sales')->where('id', $saleId)->value('fiscal_document_id'))->toBeNull()
        ->and(DB::table('fiscal_documents')->count())->toBe(0);

    // 3. Y el estado fiscal de la respuesta lo dice.
    expect($respuesta->json('fiscal.document_id'))->toBeNull()
        ->and($respuesta->json('fiscal.message'))->toBeNull();
});

/**
 * 1.7 — con el interruptor ENCENDIDO la venta queda enlazada a su documento.
 *
 * REGLA que fija: *T2 es lo que enlaza la venta con su comprobante*. El
 * documento existe, esta `issued` y tiene su numero de control, y la venta
 * lo apunta.
 */
test('test_con_el_interruptor_encendido_la_venta_queda_enlazada_a_su_documento_fiscal', function () {
    config(['fiscal.emission_enabled' => true]);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_fiscal($this->escenario));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('fiscal.status', 'issued');

    $saleId = (int) $respuesta->json('sale_id');

    // 1. La venta esta ENLAZADA a su documento fiscal.
    $documentId = (int) DB::table('sales')->where('id', $saleId)->value('fiscal_document_id');

    expect($documentId)->not->toBe(0);

    // 2. Y el documento esta EMITIDO, con su numero de control.
    $documento = DB::table('fiscal_documents')->where('id', $documentId)->first();

    expect($documento)->not->toBeNull()
        ->and($documento->status)->toBe('issued')
        ->and((int) $documento->number)->toBe(1);

    $control = DB::table('fiscal_document_control_assignments')
        ->where('fiscal_document_id', $documentId)
        ->first();

    expect($control)->not->toBeNull()
        ->and((string) $control->control_number)->toMatch('/^\d{2}-\d{8}$/');

    // 3. Y la respuesta dice el estado fiscal CON su numero de control.
    expect($respuesta->json('fiscal.document_id'))->toBe($documentId)
        ->and($respuesta->json('fiscal.control_number'))->toBe((string) $control->control_number);

    // 4. Y la venta vuelve a su estado de PAGO: la emision fue correcta, asi
    //    que no hay nada que reconciliar.
    expect(DB::table('sales')->where('id', $saleId)->value('status'))->toBe('paid');
});

/**
 * 1.7 — un fallo de emision NO hace perder la venta.
 *
 * REGLA que fija: *un fallo fiscal NO puede hacer perder una venta*. La venta
 * ya esta confirmada por T1: si el cobro devolviera un error, el cajero
 * creeria que no se vendio, y cobraria otra vez. Eso es PEOR que un
 * comprobante pendiente.
 *
 * El fallo se provoca en el VOCABULARIO del perfil: sin el codigo nativo del
 * estado emitido, el canal no puede consolidar el documento. Agotar el rango
 * de control ya no es un fallo de emision (el numero lo toma la venta y el
 * documento lo reutiliza), y un fallo que no existe no prueba nada.
 */
test('test_con_el_interruptor_encendido_y_el_proveedor_fallando_la_venta_existe_y_no_se_pierde', function () {
    config(['fiscal.emission_enabled' => true]);

    // El perfil pierde el codigo nativo del estado emitido: la emision
    // falla al consolidar.
    DB::table('fiscal_status_vocabularies')
        ->where('fiscal_profile_id', $this->escenario['profile_id'])
        ->where('generic_status', 'issued')
        ->delete();

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_fiscal($this->escenario));

    // 1. El cobro responde 201: la venta ESTA.
    $respuesta->assertCreated();

    $saleId = (int) $respuesta->json('sale_id');

    // 2. Y la venta EXISTE, con su cabecera y su pago.
    $venta = DB::table('sales')->where('id', $saleId)->first();

    expect($venta)->not->toBeNull()
        ->and((string) $venta->invoice_no)->not->toBe('')
        ->and(DB::table('sale_payments')->where('sale_id', $saleId)->count())->toBe(1);

    // 3. La venta queda en `fiscal_failed`: el estado fiscal avisa de que
    //    falta el comprobante.
    expect($venta->status)->toBe('fiscal_failed');

    // 4. Y la respuesta dice que la emision FALLO, con su motivo.
    expect($respuesta->json('fiscal.status'))->toBe('failed')
        ->and($respuesta->json('fiscal.message'))->not->toBeNull()
        ->and($respuesta->json('fiscal.message'))->not->toBe('');

    // 5. Las reservas NO se liberan: el numero de la serie NO vuelve. Y la
    //    serie del control avanzo UNA vez: la de la venta.
    expect((int) DB::table('fiscal_series')
        ->where('id', $this->escenario['document_series_id'])->value('next_number'))->toBe(2)
        ->and((int) DB::table('fiscal_series')
            ->where('id', $this->escenario['control_series_id'])->value('next_number'))->toBe(2);

    // 6. Y el documento se queda en `draft`: el fallo no dejo el comprobante
    //    a medias.
    expect(DB::table('fiscal_documents')->where('id', (int) $venta->fiscal_document_id)->value('status'))
        ->toBe('draft');
});

/**
 * 1.7 — la respuesta del cobro dice el estado fiscal de la venta.
 *
 * REGLA que fija: *el cajero tiene que saber si el comprobante salio*. El
 * campo `fiscal` dice si se emitio (con su numero de control), si fallo (con
 * su motivo) o si no se emitio (el interruptor apagado).
 */
test('test_la_respuesta_del_cobro_dice_el_estado_fiscal_de_la_venta', function () {
    config(['fiscal.emission_enabled' => true]);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_fiscal($this->escenario));

    $respuesta->assertCreated()
        ->assertJsonStructure([
            'sale_id',
            'invoice_no',
            'control_number',
            'grand_total',
            'status',
            'change',
            'fiscal' => ['status', 'document_id', 'control_number', 'message'],
        ]);

    // El estado fiscal tiene UN vocabulario: emitido, fallido o no emitido.
    expect(in_array($respuesta->json('fiscal.status'), ['issued', 'failed', 'not_emitted'], true))
        ->toBeTrue();
});
