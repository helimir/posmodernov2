<?php

use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\PrintTarget;
use App\Models\Store;
use App\Print\EscposRenderer;
use App\Print\PrintJobDispatcher;
use App\Print\PrintPayload;
use Illuminate\Support\Facades\DB;

/**
 * 0.8.3 — la generación de los bytes ESC/POS en el servidor.
 *
 * Regla que fija: el límite de payload del QR lo impone el servidor.
 *
 * El servidor genera el ESC/POS y congela los BYTES en el trabajo; el agente
 * LAN solo los escribe (design D11). Por eso el servidor es el unico que
 * puede decir "este ticket no es representable": si el destino no soporta QR,
 * o si el QR no cabe, el fallo ocurre AQUI, antes de enviar nada. Un ticket
 * mudo en el papel es un fallo del backoffice, no de la impresora.
 */
test('test_un_qr_sobre_un_perfil_sin_soporte_falla_en_el_servidor_antes_de_enviarlo', function () {
    $store = Store::create(['name' => 'Tienda de impresion '.uniqid('IMP', true)]);

    $printer = Printer::create([
        'code' => 'IMP-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora de la tienda',
    ]);

    $dispatcher = app(PrintJobDispatcher::class);
    $renderer = app(EscposRenderer::class);

    // 1. El destino que NO sabe con QR.
    $sinQr = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino sin QR',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.50:9100',
        'printer_profile' => 'default',
        'supports_qr' => false,
    ]);

    $conQr = new PrintPayload(
        lines: [['text' => 'TICKET DE PRUEBA']],
        qrContent: 'https://pos.local/ticket/1',
    );

    // 2. El servidor RECHAZA el ticket: el destino no soporta QR.
    $mensaje = null;

    try {
        $dispatcher->dispatch($store->id, $sinQr->id, $conQr);
    } catch (RuntimeException $error) {
        $mensaje = $error->getMessage();
    }

    expect($mensaje)->not->toBeNull()
        ->and($mensaje)->toContain('QR');

    // 3. Y no queda NADA a medias: no se creo ningun trabajo de impresion.
    expect(DB::table('print_jobs')->where('print_target_id', $sinQr->id)->count())->toBe(0);

    // 4. El caso positivo: con soporte de QR, los bytes se generan y el
    //    trabajo los congela con su hash.
    $conSoporte = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino con QR',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.51:9100',
        'printer_profile' => 'default',
        'supports_qr' => true,
    ]);

    $renderizado = $renderer->render($conSoporte, $conQr);

    expect($renderizado->bytes)->not->toBe('')
        ->and($renderizado->sha256)->toBe(hash('sha256', $renderizado->bytes));

    $jobId = $dispatcher->dispatch($store->id, $conSoporte->id, $conQr);
    $job = PrintJob::find($jobId);

    expect($job)->not->toBeNull()
        ->and($job->status)->toBe('queued')
        ->and($job->attempts)->toBe(0)
        ->and($job->payload_bytes)->toBe($renderizado->bytes)
        ->and($job->payload_sha256)->toBe($renderizado->sha256);

    // Los bytes se leen IGUALES la segunda vez: el `bytea` de PostgreSQL
    // vuelve como un flujo, y un flujo sin rebobinar se entrega vacio en la
    // segunda lectura.
    expect($job->payload_bytes)->toBe($renderizado->bytes)
        ->and($job->fresh()->payload_bytes)->toBe($renderizado->bytes);

    // 5. El LIMITE del QR lo impone el servidor, con el limite y el tamano
    //    real en el mensaje.
    $limitado = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino con QR limitado',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.52:9100',
        'printer_profile' => 'default',
        'supports_qr' => true,
        'max_qr_payload_bytes' => 32,
    ]);

    $mensaje = null;

    try {
        $dispatcher->dispatch($store->id, $limitado->id, new PrintPayload(
            lines: [],
            qrContent: str_repeat('X', 64),
        ));
    } catch (RuntimeException $error) {
        $mensaje = $error->getMessage();
    }

    expect($mensaje)->not->toBeNull()
        ->and($mensaje)->toContain('32')
        ->and($mensaje)->toContain('64');

    expect(DB::table('print_jobs')->where('print_target_id', $limitado->id)->count())->toBe(0);

    // 6. La codepagina CP858 no es codificable en la libreria: el destino
    //    que la pide se rechaza en el servidor, con una recomendacion.
    $cp858 = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $store->id,
        'name' => 'Destino en CP858',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.53:9100',
        'printer_profile' => 'default',
        'charset' => 'CP858',
    ]);

    $mensaje = null;

    try {
        $dispatcher->dispatch($store->id, $cp858->id, new PrintPayload(
            lines: [['text' => 'TICKET']],
        ));
    } catch (RuntimeException $error) {
        $mensaje = $error->getMessage();
    }

    expect($mensaje)->not->toBeNull()
        ->and($mensaje)->toContain('CP858')
        ->and($mensaje)->toContain('CP850');

    expect(DB::table('print_jobs')->where('print_target_id', $cp858->id)->count())->toBe(0);
});
