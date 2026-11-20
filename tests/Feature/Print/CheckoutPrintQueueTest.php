<?php

use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F2-05 — el CABLEADO del cobro al ticket.
 *
 * Reglas que fija:
 *
 *  - el cobro ENCOLA el trabajo de impresion, con los BYTES CONGELADOS y el
 *    numero de control DEL DOCUMENTO: una venta tiene un numero de control, y
 *    el ticket lleva ese, no otro (es el defecto que la unidad 1.7 arrastro y
 *    que la 1.8 volvio a cerrar por el otro lado);
 *  - el `auto_print` de la tienda se LEE: con el interruptor apagado no se
 *    encola nada, y el cajero imprime desde la ficha, como hasta ahora;
 *  - el DESTINO no se adivina: sin destino activo no se encola, y con mas de
 *    uno tampoco (el sistema no elige por el cajero);
 *  - un fallo del destino NO TIRA LA VENTA: la venta existe, con su numero y
 *    su asiento, y el cobro responde 201 con el aviso;
 *  - la respuesta del cobro dice SI se encolo (con el identificador del
 *    trabajo) o POR QUE no se encolo (con su motivo).
 *
 * El escenario es el de DEMOSTRACION del repo (`PosFixture`): la tienda
 * sembrada, su perfil fiscal, sus dos series y un producto con stock.
 */

/**
 * El pedido de una venta de dos unidades a 25, con su pago.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function pedido_de_impresion(array $escenario): array
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
 * Un DESTINO de impresion de la tienda, con su impresora.
 *
 * El nombre es del ARGUMENTO porque el destino es UNIQUE por tienda: dos
 * destinos con el mismo nombre en la misma tienda son el mismo destino
 * escrito dos veces.
 *
 * @param  array<string, mixed>  $atributos
 */
function destino_de_impresion_de_la_tienda(int $storeId, string $nombre, array $atributos = []): int
{
    $destino = PrintFixture::destino($storeId, 'lan_agent', array_merge([
        'name' => $nombre,
    ], $atributos));

    return $destino['target_id'];
}

/**
 * La tienda IMPRIME AUTOMATICAMENTE: el interruptor que M04 creo y que nadie
 * leia.
 */
function la_tienda_imprime_automaticamente(int $storeId, bool $activo = true): void
{
    DB::table('store_settings')
        ->where('store_id', $storeId)
        ->update(['auto_print' => $activo, 'updated_at' => now()]);
}

/**
 * F2-05 — el cobro encola el trabajo de impresion, con los bytes CONGELADOS.
 *
 * REGLA que fija: *el ticket se encola al cobrar*. El cobro no espera a que
 * un humano abra el comprobante y pulse imprimir: el trabajo nace en la cola
 * con sus bytes ya generados, que es lo que el agente escribe.
 */
test('test_el_cobro_encola_el_trabajo_de_impresion_con_los_bytes_congelados', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    la_tienda_imprime_automaticamente($escenario['store_id']);

    $destinoId = destino_de_impresion_de_la_tienda($escenario['store_id'], 'Caja de la tienda');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    // 1. La respuesta dice que el ticket se ENCOLO, con su identificador.
    $respuesta->assertCreated()->assertJsonPath('print.status', 'queued');

    $jobId = (int) $respuesta->json('print.job_id');

    expect($jobId)->not->toBe(0)
        ->and($respuesta->json('print.reason'))->toBeNull();

    // 2. Y el trabajo EXISTE, en la cola, contra el destino de la tienda.
    $job = PrintJob::find($jobId);

    expect($job)->not->toBeNull()
        ->and($job->status)->toBe('queued')
        ->and((int) $job->store_id)->toBe($escenario['store_id'])
        ->and((int) $job->print_target_id)->toBe($destinoId)
        ->and((int) $job->attempts)->toBe(0)
        // Y el trabajo sabe QUIEN lo encolo: el cajero queda en la auditoria.
        ->and((int) $job->created_by)->toBe((int) $usuario->id);

    // 3. Los BYTES estan CONGELADOS: no estan vacios y su hash es el de esos
    //    bytes. Un trabajo sin bytes es un ticket que el agente no puede
    //    imprimir.
    $bytes = (string) $job->payload_bytes;

    expect($bytes)->not->toBe('')
        ->and((string) $job->payload_sha256)->toBe(hash('sha256', $bytes));

    // 4. Y el MODELO del ticket es el del comprobante: el mismo numero de
    //    documento y el mismo total.
    $documento = DB::table('fiscal_documents')
        ->where('id', (int) $respuesta->json('fiscal.document_id'))
        ->first();

    // El numero COMPLETO, con el prefijo de la serie: el del comprobante
    // (y el de la respuesta del cobro), no el secuencial suelto.
    $serie = DB::table('fiscal_series')
        ->where('id', (int) $documento->series_id)
        ->first();

    $numero = trim((string) $serie->prefix).(string) $documento->number;

    $modelo = $job->payload;

    $textos = array_map(static fn (array $linea): string => (string) $linea['text'], $modelo['lines']);

    expect($textos)->toContain('Nº '.$numero)
        // Y es EXACTAMENTE el que dice la venta: el papel y la respuesta del
        // cobro son el mismo documento.
        ->and($numero)->toBe((string) $respuesta->json('invoice_no'));

    // Y el MISMO total, con la moneda de la venta.
    $totales = array_values(array_filter(
        $textos,
        static fn (string $texto): bool => str_starts_with($texto, 'Total: 58.0000')
    ));

    expect($totales)->toHaveCount(1);
});

/**
 * F2-05 — con el `auto_print` APAGADO el cobro no encola nada.
 *
 * REGLA que fija: *el interruptor de la tienda manda*. Con el interruptor
 * apagado no se encola automaticamente, y el cajero imprime desde la ficha de
 * la venta, como hasta ahora. El interruptor existe desde M04 y NADIE lo
 * leia: esta unidad lo lee.
 */
test('test_el_cobro_no_toca_la_cola_de_impresion_con_el_auto_print_apagado', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // El interruptor APAGADO.
    la_tienda_imprime_automaticamente($escenario['store_id'], false);

    destino_de_impresion_de_la_tienda($escenario['store_id'], 'Caja de la tienda');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    // 1. El cobro responde 201 (la venta esta) y dice POR QUE no se encolo.
    $respuesta->assertCreated()
        ->assertJsonPath('print.status', 'not_queued')
        ->assertJsonPath('print.reason', 'auto_print_disabled')
        ->assertJsonPath('print.job_id', null);

    // 2. Y la cola de impresion NO se toca.
    expect(DB::table('print_jobs')->count())->toBe(0);
});

/**
 * F2-05 — sin destino ACTIVO el cobro no encola nada.
 *
 * REGLA que fija: *sin destino no hay a donde mandar el ticket*. El sistema
 * no adivina una impresora que la tienda no ha declarado.
 */
test('test_el_cobro_no_toca_la_cola_de_impresion_sin_destino_activo', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    la_tienda_imprime_automaticamente($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    // 1. El cobro responde 201 y dice que no hay destino.
    $respuesta->assertCreated()
        ->assertJsonPath('print.status', 'not_queued')
        ->assertJsonPath('print.reason', 'no_destination');

    // 2. Y la cola de impresion NO se toca.
    expect(DB::table('print_jobs')->count())->toBe(0);
});

/**
 * F2-05 — con mas de un destino ACTIVO el cobro no encola nada.
 *
 * REGLA que fija: *el sistema NO elige por el cajero*. Con varios destinos
 * activos, elegir uno seria adivinar: el ticket puede salir por la
 * impresora equivocada, y una impresora equivocada es un ticket en la basura.
 * El ticket queda pendiente de que alguien lo imprima y elija destino.
 */
test('test_el_cobro_no_elige_el_destino_cuando_hay_mas_de_uno_activo', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    la_tienda_imprime_automaticamente($escenario['store_id']);

    destino_de_impresion_de_la_tienda($escenario['store_id'], 'Caja de la tienda');
    destino_de_impresion_de_la_tienda($escenario['store_id'], 'Caja del mostrador');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    // 1. El cobro responde 201 y dice que hay mas de un destino.
    $respuesta->assertCreated()
        ->assertJsonPath('print.status', 'not_queued')
        ->assertJsonPath('print.reason', 'ambiguous_destination');

    // 2. Y la cola de impresion NO se toca: el sistema no eligio.
    expect(DB::table('print_jobs')->count())->toBe(0);
});

/**
 * F2-05 — sin comprobante fiscal el cobro no encola nada.
 *
 * REGLA que fija: *el ticket es el comprobante en otro papel*. Sin un
 * comprobante emitido no hay ticket, porque sus datos son los del
 * comprobante: su numero, su control, su total y su QR.
 */
test('test_el_cobro_no_encola_el_ticket_sin_comprobante_fiscal', function () {
    // El interruptor de emision APAGADO: la venta se escribe y no hay
    // documento fiscal.
    config(['fiscal.emission_enabled' => false]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    la_tienda_imprime_automaticamente($escenario['store_id']);

    destino_de_impresion_de_la_tienda($escenario['store_id'], 'Caja de la tienda');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    // 1. El cobro responde 201 y dice que no hay comprobante.
    $respuesta->assertCreated()
        ->assertJsonPath('print.status', 'not_queued')
        ->assertJsonPath('print.reason', 'no_document');

    // 2. Y la cola de impresion NO se toca.
    expect(DB::table('print_jobs')->count())->toBe(0);
});

/**
 * F2-05 — con el comprobante SIN EMITIR el cobro no encola nada.
 *
 * REGLA que fija: *un comprobante a medias no es un comprobante*. El
 * documento que quedo en `draft` por un fallo de emision no se imprime: su
 * numero de control todavia no es publico, y un ticket con un numero que la
 * autoridad no ha visto es un ticket que nadie puede contrastar.
 */
test('test_el_cobro_no_encola_el_ticket_con_el_comprobante_sin_emitir', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // El perfil pierde el codigo nativo del estado emitido: la emision falla
    // al consolidar y el documento se queda en `draft`.
    DB::table('fiscal_status_vocabularies')
        ->where('fiscal_profile_id', $escenario['profile_id'])
        ->where('generic_status', 'issued')
        ->delete();

    la_tienda_imprime_automaticamente($escenario['store_id']);

    destino_de_impresion_de_la_tienda($escenario['store_id'], 'Caja de la tienda');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    // 1. El cobro responde 201 y dice que el comprobante no esta emitido.
    $respuesta->assertCreated()
        ->assertJsonPath('print.status', 'not_queued')
        ->assertJsonPath('print.reason', 'document_not_issued');

    // 2. Y la cola de impresion NO se toca.
    expect(DB::table('print_jobs')->count())->toBe(0);
});

/**
 * F2-05 — un fallo del destino NO tira la venta.
 *
 * REGLA que fija: *el fallo de la impresora NUNCA puede perder una venta*. La
 * venta ya esta confirmada por T1: si el ticket no sale, el cobro responde
 * 201 IGUAL, con el aviso, y el ticket queda pendiente. Si el cobro devolviera
 * un error, el cajero creeria que no se vendio, y cobraria otra vez: eso es
 * PEOR que un ticket pendiente.
 *
 * El destino que falla es uno que NO puede representar el ticket: pide la
 * codepagina CP858, que la libreria de impresion no sabe codificar. El
 * servidor lo rechaza ANTES de enviar nada, y no queda ningun trabajo a
 * medias.
 */
test('test_un_fallo_del_destino_no_tira_la_venta', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    la_tienda_imprime_automaticamente($escenario['store_id']);

    destino_de_impresion_de_la_tienda($escenario['store_id'], 'Caja que no puede', [
        'charset' => 'CP858',
    ]);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    // 1. El cobro responde 201: la venta ESTA.
    $respuesta->assertCreated();

    $saleId = (int) $respuesta->json('sale_id');

    // 2. Y la venta se ESCRIBIO, con su numero y su cabecera.
    $venta = DB::table('sales')->where('id', $saleId)->first();

    expect($venta)->not->toBeNull()
        ->and((string) $venta->invoice_no)->toBe($respuesta->json('invoice_no'))
        ->and((string) $venta->status)->toBe('paid');

    // 3. Y el ASIENTO contable esta: la venta esta contabilizada.
    $entry = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->first();

    expect($entry)->not->toBeNull();

    // 4. Y la respuesta dice que el ticket NO salio, con su motivo.
    $respuesta->assertJsonPath('print.status', 'not_queued')
        ->assertJsonPath('print.reason', 'dispatch_failed');

    expect($respuesta->json('print.message'))->toContain('CP858')
        ->and($respuesta->json('print.job_id'))->toBeNull();

    // 5. Y no queda NINGUN trabajo a medias: un ticket sin bytes no lo puede
    //    imprimir el agente.
    expect(DB::table('print_jobs')->count())->toBe(0);
});

/**
 * F2-05 — el numero de control del ticket es EL DEL DOCUMENTO.
 *
 * REGLA que fija: *una venta tiene UN numero de control*. El comprobante
 * fiscal y el ticket ESC/POS son el MISMO documento en dos papeles: el
 * ticket lleva el control del documento, y ese control es el que la venta
 * reservo. El defecto que la unidad 1.7 arrastro (la serie de control
 * avanzando DOS veces por venta, y el ticket con un numero que el documento
 * no tenia) no puede volver por el otro lado.
 */
test('test_el_numero_de_control_del_ticket_es_el_del_documento', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    la_tienda_imprime_automaticamente($escenario['store_id']);

    destino_de_impresion_de_la_tienda($escenario['store_id'], 'Caja de la tienda');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    $respuesta->assertCreated();

    $documentId = (int) $respuesta->json('fiscal.document_id');

    // 1. El control del DOCUMENTO.
    $control = DB::table('fiscal_document_control_assignments')
        ->where('fiscal_document_id', $documentId)
        ->value('control_number');

    expect($control)->not->toBeNull();

    // 2. Y el del TICKET es ESE: el trabajo lleva el control del documento.
    $job = PrintJob::find((int) $respuesta->json('print.job_id'));

    expect($job)->not->toBeNull();

    $textos = array_map(static fn (array $linea): string => (string) $linea['text'], $job->payload['lines']);

    expect($textos)->toContain('Nº Control '.(string) $control);

    // 3. Y es el MISMO que el de la respuesta del cobro: una venta, un
    //    control, y la serie avanza UNA vez.
    expect($respuesta->json('control_number'))->toBe((string) $control)
        ->and($respuesta->json('fiscal.control_number'))->toBe((string) $control)
        ->and((int) DB::table('fiscal_series')
            ->where('id', $escenario['control_series_id'])->value('next_number'))->toBe(2);
});

/**
 * F2-05 — la respuesta del cobro dice SI se encolo y POR QUE no.
 *
 * REGLA que fija: *el cajero tiene que saber si el ticket salio*. El campo
 * `print` dice si se encolo (con el identificador del trabajo) o por que no
 * se encolo (con su motivo). El motivo es un CODIGO cerrado: el texto lo lee
 * el cajero y el codigo lo usa la pantalla.
 */
test('test_la_respuesta_del_cobro_dice_si_el_ticket_se_encolo_y_por_que_no', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    la_tienda_imprime_automaticamente($escenario['store_id']);

    $destinoId = destino_de_impresion_de_la_tienda($escenario['store_id'], 'Caja de la tienda');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    // 1. La FORMA de la respuesta: los cuatro datos del encolado.
    $respuesta->assertCreated()->assertJsonStructure([
        'print' => ['status', 'job_id', 'reason', 'message'],
    ]);

    // 2. Y ENCOLADO no lleva motivo: el motivo es de cuando NO se encolo.
    expect($respuesta->json('print.status'))->toBe('queued')
        ->and($respuesta->json('print.job_id'))->not->toBeNull()
        ->and($respuesta->json('print.reason'))->toBeNull()
        ->and($respuesta->json('print.message'))->toBeNull();

    // 3. Y sin destino, el motivo es un CODIGO cerrado: la pantalla decide
    //    con el, no adivinando el texto.
    DB::table('print_targets')->where('id', $destinoId)->update(['is_active' => false]);

    $otra = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_de_impresion($escenario));

    $motivos = [
        'auto_print_disabled',
        'no_destination',
        'ambiguous_destination',
        'no_document',
        'document_not_issued',
        'dispatch_failed',
    ];

    expect($otra->json('print.status'))->toBe('not_queued')
        ->and(in_array($otra->json('print.reason'), $motivos, true))->toBeTrue()
        ->and($otra->json('print.job_id'))->toBeNull()
        ->and($otra->json('print.message'))->not->toBeNull();
});
