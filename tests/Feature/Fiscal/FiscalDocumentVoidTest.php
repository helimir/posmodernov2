<?php

use App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider;
use App\Domain\Fiscal\FiscalIssuanceService;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Fiscal\ValueObjects\CancellationReason;
use App\Domain\Fiscal\ValueObjects\CancellationReceipt;
use App\Domain\Fiscal\ValueObjects\ControlAssignment;
use App\Domain\Fiscal\ValueObjects\DeliveryReceipt;
use App\Domain\Fiscal\ValueObjects\DeliveryRecipient;
use App\Domain\Fiscal\ValueObjects\FiscalDocumentData;
use App\Domain\Fiscal\ValueObjects\FiscalDocumentDraft;
use App\Domain\Fiscal\ValueObjects\ProviderStatus;
use App\Domain\Fiscal\ValueObjects\SubmissionReceipt;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.3.8-T — la anulación consume el correlativo.
 *
 * Regla que fija: *el número consumido no se reutiliza jamás*. Un documento
 * anulado conserva `void` y su `voided_at`, la reserva queda consumada y la
 * siguiente reserva devuelve el número SIGUIENTE, nunca el anulado.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_la_anulacion_consuma_el_correlativo_y_la_siguiente_reserva_devuelve_el_siguiente', function () {
    $context = FiscalSeriesFixture::context();

    $seriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'next_number' => 42,
        'from_number' => 1,
        'to_number' => 9999,
    ]);

    $numberSeries = new NumberSeries(DB::connection());

    // 1. La reserva del 42.
    expect($numberSeries->reserve($seriesId))->toBe(42);

    $reservationId = (int) DB::table('fiscal_document_reservations')
        ->where('series_id', $seriesId)
        ->where('number', 42)
        ->value('id');

    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $context['profile_id'],
        'store_id' => $context['store_id'],
        'series_id' => $seriesId,
        'number' => 42,
        'reservation_id' => $reservationId,
        'document_type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-11-02',
        'issued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Se anula.
    $service = new FiscalIssuanceService(DB::connection(), new class implements ElectronicInvoiceProvider
    {
        public function submit(FiscalDocumentDraft $draft): SubmissionReceipt
        {
            throw new RuntimeException('No aplica en esta prueba.');
        }

        public function assignControl(SubmissionReceipt $receipt): ?ControlAssignment
        {
            throw new RuntimeException('No aplica en esta prueba.');
        }

        public function issue(FiscalDocumentDraft $draft, ?ControlAssignment $control): FiscalDocumentData
        {
            throw new RuntimeException('No aplica en esta prueba.');
        }

        public function cancel(FiscalDocumentData $document, CancellationReason $reason): CancellationReceipt
        {
            throw new RuntimeException('No aplica en esta prueba.');
        }

        public function queryStatus(FiscalDocumentData $document): ProviderStatus
        {
            throw new RuntimeException('No aplica en esta prueba.');
        }

        public function deliver(FiscalDocumentData $document, DeliveryRecipient $recipient): DeliveryReceipt
        {
            throw new RuntimeException('No aplica en esta prueba.');
        }
    });

    $service->void($documentId, 'Error en el monto facturado');

    // 3. El documento anulado conserva `void` y su `voided_at`.
    $document = DB::table('fiscal_documents')->where('id', $documentId)->first();

    expect($document->status)->toBe('void')
        ->and($document->voided_at)->not->toBeNull();

    // 4. Y la reserva queda CONSUMADA.
    $reservation = DB::table('fiscal_document_reservations')->where('id', $reservationId)->first();

    expect($reservation->voided_at)->not->toBeNull();

    // 5. La siguiente reserva devuelve el 43: el 42 NO se reutiliza.
    expect($numberSeries->reserve($seriesId))->toBe(43);

    // 6. Y la anulacion quedo AUDITADA (append-only).
    $event = DB::table('fiscal_document_events')
        ->where('fiscal_document_id', $documentId)
        ->where('action', 'cancel')
        ->first();

    expect($event)->not->toBeNull()
        ->and(json_decode((string) $event->payload, true)['reason'])->toBe('Error en el monto facturado');
});
