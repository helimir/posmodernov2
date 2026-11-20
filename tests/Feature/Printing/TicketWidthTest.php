<?php

use App\Models\Printer;
use App\Models\PrintTarget;
use App\Models\Store;
use App\Print\EscposRenderer;
use App\Print\PrintJobDispatcher;
use App\Print\PrintPayload;
use Illuminate\Support\Facades\DB;

/**
 * F2-07 — el ANCHO del destino contra el CONTENIDO del ticket: el "ticket
 * mudo".
 *
 * REGLA que fija: *una linea que no cabe en el ancho NO se recorta en
 * silencio: el ticket falla con un mensaje que la nombre*.
 *
 * Un comprobante fiscal no puede ir recortado: la linea diria una cosa y el
 * papel otra. Y no puede faltar una linea: un comprobante al que le falta una
 * linea es un comprobante FALSO.
 *
 * Y EL DESTINO DECLARA EL ANCHO: un destino sin `char_per_line` no puede
 * componer un ticket, y falla con un mensaje que lo dice. Nada a ciegas.
 */
test('test_una_linea_mas_larga_que_el_ancho_del_destino_falla_con_un_mensaje_que_la_nombra', function () {
    $store = Store::create(['name' => 'Tienda del ancho '.uniqid('AN', true)]);

    $printer = Printer::create([
        'code' => 'AN-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora de la tienda',
    ]);

    $target = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Caja de la tienda',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.80:9100',
        'printer_profile' => 'default',
        'char_per_line' => 32,
    ]);

    // La linea que NO cabe: 45 caracteres en un papel de 32.
    $larga = 'Producto de la tienda que no cabe en el papel';

    expect(mb_strlen($larga))->toBeGreaterThan(32);

    $mensaje = null;

    try {
        app(EscposRenderer::class)->render($target, new PrintPayload(
            lines: [['text' => $larga]],
        ));
    } catch (RuntimeException $error) {
        $mensaje = $error->getMessage();
    }

    // 1. El mensaje dice QUE LINEA es.
    expect($mensaje)->not->toBeNull('La linea que no cabe no ha fallado: el ticket sale mudo.')
        ->and($mensaje)->toContain($larga);

    // 2. Y CUANTO mide.
    expect($mensaje)->toContain((string) mb_strlen($larga));

    // 3. Y CUANTO admite la impresora.
    expect($mensaje)->toContain('32');

    // 4. Y dice de que destino es, que es donde se mira para arreglarlo.
    expect($mensaje)->toContain('Caja de la tienda');
});

/**
 * Un destino SIN ANCHO DECLARADO no puede componer un ticket.
 *
 * REGLA que fija: *el destino declara su ancho, y sin el no se compone nada*.
 * El ancho es DATO de la tienda: el sistema no lo adivina, y un ancho
 * inventado produce un ticket que sale cortado en silencio.
 */
test('test_un_destino_sin_ancho_declarado_falla_al_componer_el_ticket', function () {
    $store = Store::create(['name' => 'Tienda sin ancho '.uniqid('SA', true)]);

    $printer = Printer::create([
        'code' => 'SA-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora de la tienda',
    ]);

    // El destino NO declara el ancho: es lo que hay que probar.
    $target = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Caja sin ancho',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.81:9100',
        'printer_profile' => 'default',
        'char_per_line' => null,
    ]);

    $mensaje = null;

    try {
        app(EscposRenderer::class)->render($target, new PrintPayload(
            lines: [['text' => 'TICKET']],
        ));
    } catch (RuntimeException $error) {
        $mensaje = $error->getMessage();
    }

    expect($mensaje)->not->toBeNull('El destino sin ancho ha compuesto un ticket a ciegas.')
        ->and($mensaje)->toContain('no declara el ancho')
        ->and($mensaje)->toContain('Caja sin ancho');
});

/**
 * Un ticket que CABE se compone sin problemas.
 *
 * REGLA que fija: *la regla del ancho no rompe el ticket que cabe*. El
 * destino declara su ancho, el renderer lo comprueba, y el ticket sale.
 */
test('test_un_ticket_que_cabe_se_compone_sin_problemas', function () {
    $store = Store::create(['name' => 'Tienda del ajuste '.uniqid('AJ', true)]);

    $printer = Printer::create([
        'code' => 'AJ-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora de la tienda',
    ]);

    $target = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Caja de la tienda',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.82:9100',
        'printer_profile' => 'default',
        'char_per_line' => 42,
    ]);

    $renderizado = app(EscposRenderer::class)->render($target, new PrintPayload(
        title: 'TICKET',
        lines: [
            ['text' => 'Producto de la caja x 2 25.0000'],
            ['text' => 'Total: 58.0000', 'style' => 'bold'],
        ],
    ));

    expect($renderizado->bytes)->not->toBe('')
        ->and($renderizado->sha256)->toBe(hash('sha256', $renderizado->bytes))
        // Y las lineas salen EXACTAS: sin recortes y sin cambios.
        ->and($renderizado->bytes)->toContain('Producto de la caja x 2 25.0000')
        ->and($renderizado->bytes)->toContain('Total: 58.0000');
});

/**
 * El ticket NO se recorta en silencio.
 *
 * REGLA que fija: *una linea que no cabe NO sale recortada*. El renderer no
 * recorta: falla, y el mensaje lleva la linea COMPLETA, con todos sus
 * caracteres. Un comprobante con una linea recortada es un comprobante
 * FALSO, y un ticket mudo es peor que un ticket pendiente.
 */
test('test_el_ticket_no_se_recorta_en_silencio', function () {
    $store = Store::create(['name' => 'Tienda del recorte '.uniqid('RC', true)]);

    $printer = Printer::create([
        'code' => 'RC-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora de la tienda',
    ]);

    $target = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Caja de la tienda',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.83:9100',
        'printer_profile' => 'default',
        'char_per_line' => 32,
    ]);

    // La linea larga: 60 caracteres, en un papel de 32.
    $larga = 'Descripcion del producto con un nombre larguisimo que no cabe';

    $mensaje = null;

    try {
        app(EscposRenderer::class)->render($target, new PrintPayload(
            lines: [['text' => $larga]],
        ));
    } catch (RuntimeException $error) {
        $mensaje = $error->getMessage();
    }

    // 1. El fallo ocurre, y el mensaje lleva la linea COMPLETA: el texto no
    //    sale recortado ni en el papel ni en el mensaje.
    expect($mensaje)->not->toBeNull()
        ->and($mensaje)->toContain($larga)
        ->and(mb_strlen($larga))->toBeGreaterThan(32);

    // 2. Y el trabajo de impresion NO se crea: no queda ningun ticket a medias.
    $mensaje = null;

    try {
        app(PrintJobDispatcher::class)->dispatch(
            (int) $store->id,
            (int) $target->id,
            new PrintPayload(lines: [['text' => $larga]]),
        );
    } catch (RuntimeException $error) {
        $mensaje = $error->getMessage();
    }

    expect($mensaje)->not->toBeNull()
        ->and(DB::table('print_jobs')->where('print_target_id', $target->id)->count())->toBe(0);
});
