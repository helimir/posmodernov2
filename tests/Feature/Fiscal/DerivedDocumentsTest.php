<?php

use App\Domain\Fiscal\DerivedDocumentService;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Fiscal\ValueObjects\RetentionReceiptDraft;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.3.7-T — la nota referencia el original y el IVA retenido tiene documento
 * propio.
 *
 * Regla que fija: *el IVA retenido es crédito del comprador, con documento
 * propio*. Y una factura anulada no se corrige devolviendo su número: se
 * corrige con una NOTA, que referencia fecha, correlativo, número de control
 * y monto del original.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_la_nota_referencia_fecha_correlativo_control_y_monto_del_original', function () {
    $context = FiscalSeriesFixture::context();

    // El ENUNCIADO de la nota lo declara el PERFIL, no el codigo.
    DB::table('fiscal_profile_fields')->insert([
        'fiscal_profile_id' => $context['profile_id'],
        'key' => 'credit_note_denomination',
        'data_type' => 'string',
        'label' => 'Denominacion de la nota de credito',
        'value' => 'Nota de Credito',
        'required' => true,
        'applies_to' => 'document',
        'evidence_status' => 'verified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $seriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        // El 10 ya lo tiene el original; la siguiente reserva devuelve el 11.
        'next_number' => 11,
        'from_number' => 1,
        'to_number' => 9999,
    ]);

    $originalId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $context['profile_id'],
        'store_id' => $context['store_id'],
        'series_id' => $seriesId,
        'number' => 10,
        'document_type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-10-15',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 16,
        'total_amount' => 116,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('fiscal_document_control_assignments')->insert([
        'fiscal_document_id' => $originalId,
        'control_number' => '00-00000007',
        'control_range_from' => 1,
        'control_range_to' => 9999,
        'control_assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = new DerivedDocumentService(DB::connection(), new NumberSeries(DB::connection()));

    // 1. La NOTA DE CREDITO.
    $noteId = $service->issueNote($originalId, 'credit_note', new DateTimeImmutable('2026-11-02 12:00:00'));

    $note = DB::table('fiscal_documents')->where('id', $noteId)->first();

    // La numeracion del emisor es UNA SOLA: la nota sigue la serie.
    expect($note->document_type)->toBe('credit_note')
        ->and((int) $note->number)->toBe(11)
        ->and((int) $note->series_id)->toBe((int) $seriesId);

    $link = DB::table('fiscal_document_links')->where('fiscal_document_id', $noteId)->first();

    expect($link->link_type)->toBe('corrects')
        ->and((int) $link->parent_document_id)->toBe((int) $originalId)
        ->and($link->parent_reference)->toBe('10');

    // Y la referencia COMPLETA: fecha, correlativo, control y monto.
    $payload = json_decode(
        (string) DB::table('fiscal_document_events')->where('fiscal_document_id', $noteId)->value('payload'),
        true
    );

    expect($payload['denomination'])->toBe('Nota de Credito')
        ->and($payload['source_document_id'])->toBe((int) $originalId)
        ->and($payload['source_number'])->toBe('10')
        ->and($payload['source_date'])->toBe('2026-10-15')
        ->and($payload['source_control_number'])->toBe('00-00000007')
        ->and($payload['source_total'])->toBe('116.0000');

    // 2. El COMPROBANTE DE RETENCION: un documento PROPIO.
    $retentionSeriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'retention_receipt',
        'assigned_by' => 'issuer',
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 99999999,
    ]);

    $receiptId = $service->issueRetentionReceipt(
        $retentionSeriesId,
        new RetentionReceiptDraft(
            sourceDocumentId: $originalId,
            periodFrom: '2026-10-01',
            periodTo: '2026-10-31',
            baseAmount: '100.0000',
            taxCaused: '16.0000',
            taxRetained: '16.0000',
        ),
        new DateTimeImmutable('2026-11-02 12:30:00'),
    );

    $receipt = DB::table('fiscal_documents')->where('id', $receiptId)->first();

    // Propio: tipo, serie y numeracion de CATORCE caracteres (AAAAMM + 8).
    expect($receipt->document_type)->toBe('retention_receipt')
        ->and((int) $receipt->series_id)->toBe((int) $retentionSeriesId)
        ->and((string) $receipt->number)->toBe('20261100000001')
        ->and(strlen((string) $receipt->number))->toBe(14)
        ->and($receipt->period_from)->toBe('2026-10-01')
        ->and($receipt->period_to)->toBe('2026-10-31');

    // El IVA retenido es un nodo de CABECERA, con su base y su monto.
    $tax = DB::table('fiscal_document_taxes')->where('fiscal_document_id', $receiptId)->first();

    expect($tax->tax_type)->toBe('withholding')
        ->and($tax->line_id)->toBeNull()
        ->and((string) $tax->base_amount)->toBe('100.0000')
        ->and((string) $tax->tax_amount)->toBe('16.0000');

    // Y declara el control y el numero del documento de origen.
    $payload = json_decode(
        (string) DB::table('fiscal_document_events')->where('fiscal_document_id', $receiptId)->value('payload'),
        true
    );

    expect($payload['source_number'])->toBe('10')
        ->and($payload['source_control_number'])->toBe('00-00000007')
        ->and($payload['period_from'])->toBe('2026-10-01')
        ->and($payload['tax_caused'])->toBe('16.0000')
        ->and($payload['tax_retained'])->toBe('16.0000');
});
