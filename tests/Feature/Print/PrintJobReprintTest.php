<?php

use App\Domain\Fiscal\NumberSeries;
use App\Models\PrintJob;
use App\Print\PrintJobReprinter;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FiscalSeriesFixture;
use Tests\Fixtures\PrintFixture;

/**
 * 0.8.5-T — la REIMPRESION de un ticket.
 *
 * Regla que fija: *reimprimir no es reemitir*.
 *
 * Una reimpresion es un trabajo de impresion NUEVO, con los MISMOS bytes
 * congelados, contra el MISMO documento fiscal. No consume correlativo (no
 * reserva numeros) ni altera el documento emitido: el estado `issued` del
 * documento es INDEPENDIENTE del estado del trabajo de impresion.
 */
test('test_reimprimir_crea_un_trabajo_nuevo_sin_consumir_correlativo_ni_alterar_el_documento', function () {
    // 1. El contexto fiscal: un perfil, una tienda y una serie.
    $contexto = FiscalSeriesFixture::context();
    $seriesId = FiscalSeriesFixture::series($contexto);

    // 2. El documento EMITIDO, con su numero reservado.
    $numero = (new NumberSeries(DB::connection()))->reserve($seriesId);

    $reservationId = (int) DB::table('fiscal_document_reservations')
        ->where('series_id', $seriesId)
        ->where('number', $numero)
        ->value('id');

    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $contexto['profile_id'],
        'store_id' => $contexto['store_id'],
        'series_id' => $seriesId,
        'number' => $numero,
        'reservation_id' => $reservationId,
        'document_type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-11-05',
        'issued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. El trabajo de impresion ORIGINAL, ya impreso.
    $destino = PrintFixture::destino($contexto['store_id']);

    $originalId = PrintFixture::trabajo($contexto['store_id'], $destino['target_id'], [
        'status' => 'printed',
    ]);

    $original = PrintJob::find($originalId);

    $documentosAntes = DB::table('fiscal_documents')->count();
    $reservasAntes = DB::table('fiscal_document_reservations')->count();

    // 4. La reimpresion.
    $nuevoId = app(PrintJobReprinter::class)->reprint($originalId);

    $nuevo = PrintJob::find($nuevoId);

    // 5. Es un trabajo NUEVO, con los MISMOS bytes y el mismo hash.
    expect($nuevoId)->not->toBe($originalId)
        ->and($nuevo->reason)->toBe('reprint')
        ->and($nuevo->reprint_of_job_id)->toBe($originalId)
        ->and($nuevo->status)->toBe('queued')
        ->and($nuevo->attempts)->toBe(0)
        ->and($nuevo->payload_bytes)->toBe($original->payload_bytes)
        ->and($nuevo->payload_sha256)->toBe($original->payload_sha256);

    // 6. El documento fiscal NO SE TOCA: mismo id, mismo estado, mismo numero.
    $documento = DB::table('fiscal_documents')->where('id', $documentId)->first();

    expect($documento->status)->toBe('issued')
        ->and((int) $documento->number)->toBe($numero)
        ->and(DB::table('fiscal_documents')->count())->toBe($documentosAntes)
        // Y CERO reservas nuevas: no se consume ningun correlativo.
        ->and(DB::table('fiscal_document_reservations')->count())->toBe($reservasAntes)
        ->and((int) DB::table('fiscal_document_reservations')->max('number'))->toBe($numero);
});
