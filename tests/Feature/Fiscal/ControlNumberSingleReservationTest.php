<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * 1.7 — la serie de control avanza UNA vez por venta.
 *
 * REGLA que fija: *la serie de control avanza UNA vez por venta, y el
 * comprobante y el documento fiscal llevan el MISMO numero de control*.
 *
 * EL DEFECTO que este test fija: T1 (el checkout) reservaba un numero de
 * control para el RECIBO, y el proveedor local (T2) reservaba OTRO para el
 * DOCUMENTO fiscal. La serie de control avanzaba DOS veces por venta (y se
 * agotaba el doble de rapido), y los dos numeros NO coincidian: el
 * comprobante impreso llevaba un control que el documento fiscal no
 * llevaba.
 *
 * Y LA REGLA se sostiene en los DOS caminos:
 *
 *  - con el interruptor ENCENDIDO, el documento fiscal REUTILIZA la reserva
 *    de la venta: la serie avanza UNA vez, y el `control_number` del cobro
 *    ES el `fiscal.control_number`;
 *  - con el interruptor APAGADO, la reserva es la de T1, y la serie avanza
 *    UNA vez;
 *  - el REINTENTO de una venta pendiente reutiliza la reserva y NO quema
 *    numeros;
 *  - un perfil que NO exige control no avanza la serie en ninguno de los dos
 *    caminos: un control que nadie pidio no se reserva.
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
function pedido_de_control_unico(array $escenario): array
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
 * El siguiente numero de la serie de control.
 */
function siguiente_de_control(int $serieId): int
{
    return (int) DB::table('fiscal_series')->where('id', $serieId)->value('next_number');
}

/**
 * El perfil PERDIÓ el codigo nativo del estado emitido: la emision falla al
 * consolidar y la venta queda pendiente de reintento.
 *
 * El fallo se provoca en el VOCABULARIO del perfil, y no en la serie de
 * control: agotar el rango de control ya no es un fallo de emision (el
 * numero lo toma la venta, y el documento lo reutiliza), y un fallo que no
 * existe no prueba nada.
 */
function control_unico_perfil_sin_estado_emitido(int $profileId): void
{
    DB::table('fiscal_status_vocabularies')
        ->where('fiscal_profile_id', $profileId)
        ->where('generic_status', 'issued')
        ->delete();
}

/**
 * El perfil RECUPERA el codigo nativo del estado emitido.
 */
function control_unico_perfil_con_estado_emitido(int $profileId): void
{
    DB::table('fiscal_status_vocabularies')->insert([
        'fiscal_profile_id' => $profileId,
        'native_code' => 'issued',
        'native_label' => 'Emitido (estado generico de la prueba)',
        'generic_status' => 'issued',
        'is_final' => false,
        'is_cancellation' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * 1.7 — con el interruptor ENCENDIDO la serie avanza UNA vez y el comprobante
 * lleva el control del documento.
 *
 * REGLA que fija: *la serie de control avanza UNA vez por venta*. La venta
 * reserva su control (T1) y el documento fiscal lo REUTILIZA (T2): no hay una
 * segunda reserva, y el `control_number` del cobro ES el
 * `fiscal.control_number`.
 */
test('test_con_el_interruptor_encendido_la_serie_de_control_avanza_un_numero_y_el_comprobante_lleva_el_del_documento', function () {
    config(['fiscal.emission_enabled' => true]);

    $antes = siguiente_de_control($this->escenario['control_series_id']);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_de_control_unico($this->escenario));

    $respuesta->assertCreated()
        ->assertJsonPath('fiscal.status', 'issued');

    // 1. La serie avanzo UNA vez: ni una mas.
    expect(siguiente_de_control($this->escenario['control_series_id']))->toBe($antes + 1);

    // 2. Y el COMPROBANTE lleva el MISMO numero que el DOCUMENTO fiscal.
    expect($respuesta->json('control_number'))->not->toBeNull()
        ->and($respuesta->json('control_number'))->toBe($respuesta->json('fiscal.control_number'));

    // 3. Y hay UNA sola reserva de control: la de la venta.
    expect(DB::table('fiscal_document_reservations')
        ->where('series_id', $this->escenario['control_series_id'])
        ->count())->toBe(1);
});

/**
 * 1.7 — con el interruptor APAGADO la serie avanza UNA vez.
 *
 * REGLA que fija: *la reserva del control es de T1, y el interruptor de la
 * emision no la cambia*. Apagado no es "sin control": es una emision que NO
 * ocurre, y la venta reserva su numero igual.
 */
test('test_con_el_interruptor_apagado_la_serie_de_control_avanza_un_numero', function () {
    config(['fiscal.emission_enabled' => false]);

    $antes = siguiente_de_control($this->escenario['control_series_id']);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_de_control_unico($this->escenario));

    $respuesta->assertCreated()
        ->assertJsonPath('fiscal.status', 'not_emitted');

    // 1. La serie avanzo UNA vez: la reserva es la de T1.
    expect(siguiente_de_control($this->escenario['control_series_id']))->toBe($antes + 1);

    // 2. Y el comprobante lleva su control.
    expect($respuesta->json('control_number'))->not->toBeNull()
        ->and(DB::table('fiscal_document_reservations')
            ->where('series_id', $this->escenario['control_series_id'])
            ->count())->toBe(1);
});

/**
 * 1.7 — el documento fiscal y la venta apuntan al MISMO control.
 *
 * REGLA que fija: *el comprobante y el documento fiscal son el MISMO
 * numero de control*. La reserva enlazada a la venta es la que el documento
 * usa: el control compuesto lo compone la SERIE con ese secuencial, y no
 * hay un segundo numero por ningun lado.
 */
test('test_el_documento_fiscal_y_la_venta_apuntan_al_mismo_control', function () {
    config(['fiscal.emission_enabled' => true]);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_de_control_unico($this->escenario));

    $respuesta->assertCreated()->assertJsonPath('fiscal.status', 'issued');

    $saleId = (int) $respuesta->json('sale_id');
    $documentId = (int) $respuesta->json('fiscal.document_id');

    // 1. La reserva de control de la VENTA: enlazada a ella.
    $reserva = DB::table('fiscal_document_reservations')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->where('series_role', 'control')
        ->first();

    expect($reserva)->not->toBeNull();

    // 2. El control COMPUESTO lo compone la serie con ese secuencial: el
    //    identificador y la longitud son DATOS de la serie.
    $serie = DB::table('fiscal_series')->where('id', $reserva->series_id)->first();

    $compuesto = $serie->control_identifier.'-'.str_pad(
        (string) $reserva->number,
        (int) $serie->sequence_length,
        '0',
        STR_PAD_LEFT
    );

    // 3. Y el DOCUMENTO lleva EXACTAMENTE ese control, el MISMO que el
    //    comprobante.
    $asignado = DB::table('fiscal_document_control_assignments')
        ->where('fiscal_document_id', $documentId)
        ->value('control_number');

    expect((string) $asignado)->toBe($compuesto)
        ->and((string) $asignado)->toBe($respuesta->json('control_number'));
});

/**
 * 1.7 — el REINTENTO de una venta pendiente NO avanza la serie.
 *
 * REGLA que fija: *el reintento reutiliza la reserva*. Una venta cuyo
 * comprobante se quedo pendiente tiene SU numero de control reservado: el
 * reintento lo reutiliza, no quema un correlativo nuevo por un fallo de la
 * red.
 */
test('test_el_reintento_de_una_venta_pendiente_no_avanza_la_serie_de_control', function () {
    config(['fiscal.emission_enabled' => true]);

    // El perfil pierde su codigo nativo del estado emitido: la emision falla
    // al consolidar y la venta queda pendiente.
    control_unico_perfil_sin_estado_emitido($this->escenario['profile_id']);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_de_control_unico($this->escenario));

    $respuesta->assertCreated()->assertJsonPath('fiscal.status', 'failed');

    $saleId = (int) $respuesta->json('sale_id');

    expect(DB::table('sales')->where('id', $saleId)->value('status'))->toBe('fiscal_failed');

    // La venta YA tiene su control reservado: la serie avanzo UNA vez.
    $antes = siguiente_de_control($this->escenario['control_series_id']);

    expect($antes)->toBe(2);

    // El perfil recupera su vocabulario: la emision ya puede consolidar.
    control_unico_perfil_con_estado_emitido($this->escenario['profile_id']);

    Artisan::call('ops:emit-fiscal', ['--sale' => $saleId]);

    // 1. La venta queda EMITIDA, con su documento.
    $documentId = (int) DB::table('sales')->where('id', $saleId)->value('fiscal_document_id');

    expect(DB::table('fiscal_documents')->where('id', $documentId)->value('status'))->toBe('issued')
        ->and(DB::table('sales')->where('id', $saleId)->value('status'))->toBe('paid');

    // 2. Y la serie de control NO avanzo: el reintento REUTILIZO la reserva.
    expect(siguiente_de_control($this->escenario['control_series_id']))->toBe($antes)
        ->and(DB::table('fiscal_document_reservations')
            ->where('series_id', $this->escenario['control_series_id'])
            ->count())->toBe(1);
});

/**
 * 1.7 — un perfil que NO exige control no avanza la serie de control.
 *
 * REGLA que fija: *un control que nadie pidio no se reserva*. Ni T1 ni el
 * proveedor reservan un numero de control para un perfil que no lo exige, y
 * la serie no avanza: el rango autorizado no se gasta en numeros que no
 * lleva ningun comprobante.
 */
test('test_un_perfil_que_no_exige_control_no_avanza_la_serie_de_control', function () {
    DB::table('fiscal_profiles')
        ->where('id', $this->escenario['profile_id'])
        ->update(['requires_control_number' => false, 'updated_at' => now()]);

    $antes = siguiente_de_control($this->escenario['control_series_id']);

    // 1. Con el interruptor APAGADO: T1 tampoco reserva.
    config(['fiscal.emission_enabled' => false]);

    $apagada = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_de_control_unico($this->escenario));

    $apagada->assertCreated();

    expect(siguiente_de_control($this->escenario['control_series_id']))->toBe($antes)
        ->and($apagada->json('control_number'))->toBeNull();

    // 2. Y con el interruptor ENCENDIDO: el proveedor tampoco reserva.
    config(['fiscal.emission_enabled' => true]);

    $encendida = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_de_control_unico($this->escenario));

    $encendida->assertCreated();

    expect(siguiente_de_control($this->escenario['control_series_id']))->toBe($antes)
        ->and($encendida->json('control_number'))->toBeNull()
        ->and($encendida->json('fiscal.control_number'))->toBeNull()
        ->and(DB::table('fiscal_document_reservations')
            ->where('series_id', $this->escenario['control_series_id'])
            ->count())->toBe(0);
});
