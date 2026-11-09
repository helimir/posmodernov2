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
 * 0.9.11 — toda acción queda auditada.
 *
 * Regla que fija: *toda acción queda auditada*. La anulación DEJA su propio
 * evento y NO ALTERA el evento de emisión: la traza es APPEND-ONLY, así que
 * la emisión sigue siendo la que se emitió, con su carga útil original.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_la_anulacion_deja_evento_y_no_altera_el_evento_de_emision', function () {
    $context = FiscalSeriesFixture::context();

    $seriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'next_number' => 42,
        'from_number' => 1,
        'to_number' => 9999,
    ]);

    $numberSeries = new NumberSeries(DB::connection());
    $number = $numberSeries->reserve($seriesId);

    $reservationId = (int) DB::table('fiscal_document_reservations')
        ->where('series_id', $seriesId)
        ->where('number', $number)
        ->value('id');

    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $context['profile_id'],
        'store_id' => $context['store_id'],
        'series_id' => $seriesId,
        'number' => $number,
        'reservation_id' => $reservationId,
        'document_type' => 'invoice',
        'status' => 'draft',
        'issue_date' => '2026-11-02',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // El ACTOR INTERMEDIO: entrega la estructura, asigna el control y
    // consolida. En la anulación no interviene: la anulación es local.
    $provider = new class implements ElectronicInvoiceProvider
    {
        public function submit(FiscalDocumentDraft $draft): SubmissionReceipt
        {
            return new SubmissionReceipt(
                'SUB-1',
                new ProviderStatus('accepted'),
                new DateTimeImmutable('2026-11-02T10:00:00+00:00'),
            );
        }

        public function assignControl(SubmissionReceipt $receipt): ?ControlAssignment
        {
            return new ControlAssignment(
                '00-00000042',
                '00-00000042',
                '00-00000999',
                new DateTimeImmutable('2026-11-02T10:01:00+00:00'),
            );
        }

        public function issue(FiscalDocumentDraft $draft, ?ControlAssignment $control): FiscalDocumentData
        {
            return new FiscalDocumentData(
                documentType: $draft->documentType,
                documentNumber: (string) $draft->documentNumber,
                status: 'issued',
                controlNumber: $control?->controlNumber,
            );
        }

        public function cancel(FiscalDocumentData $document, CancellationReason $reason): CancellationReceipt
        {
            throw new RuntimeException('La anulación es local: el proveedor no interviene.');
        }

        public function queryStatus(FiscalDocumentData $document): ProviderStatus
        {
            throw new RuntimeException('No aplica en esta prueba.');
        }

        public function deliver(FiscalDocumentData $document, DeliveryRecipient $recipient): DeliveryReceipt
        {
            throw new RuntimeException('No aplica en esta prueba.');
        }
    };

    $service = new FiscalIssuanceService(DB::connection(), $provider);

    // 1. La EMISION deja su evento.
    $service->issue(
        $documentId,
        new FiscalDocumentDraft(
            documentType: 'invoice',
            seriesPrefix: 'ZZ',
            documentNumber: $number,
            issuedAt: new DateTimeImmutable('2026-11-02T10:00:00+00:00'),
            currencyCode: 'ZZA',
            lines: [['description' => 'Articulo de prueba', 'quantity' => 1]],
        ),
        requiresControlNumber: true,
    );

    $emision = DB::table('fiscal_document_events')->where('fiscal_document_id', $documentId)->first();

    expect($emision)->not->toBeNull()
        ->and($emision->action)->toBe('issue')
        ->and(DB::table('fiscal_document_events')->count())->toBe(1);

    $cargaDeLaEmision = json_decode((string) $emision->payload, true);

    expect(array_keys($cargaDeLaEmision))->toContain('submission_id', 'control_number');

    // 2. La ANULACION deja SU evento, con el motivo.
    $service->void($documentId, 'Error en el monto facturado');

    $eventos = DB::table('fiscal_document_events')
        ->where('fiscal_document_id', $documentId)
        ->orderBy('id')
        ->get();

    expect($eventos)->toHaveCount(2)
        ->and($eventos[1]->action)->toBe('cancel')
        ->and(json_decode((string) $eventos[1]->payload, true)['reason'])->toBe('Error en el monto facturado');

    // 3. Y NO ALTERA el evento de emision: la traza es append-only, y lo que
    //    se emitio sigue siendo lo que se emitio.
    $emisionDespues = DB::table('fiscal_document_events')->where('id', $emision->id)->first();

    expect((int) $emisionDespues->id)->toBe((int) $emision->id)
        ->and((string) $emisionDespues->action)->toBe('issue')
        ->and((string) $emisionDespues->occurred_at)->toBe((string) $emision->occurred_at)
        ->and((string) $emisionDespues->created_at)->toBe((string) $emision->created_at)
        ->and(json_decode((string) $emisionDespues->payload, true))->toBe($cargaDeLaEmision);

    // 4. Y el documento queda anulado, con su numero consumado.
    $documento = DB::table('fiscal_documents')->where('id', $documentId)->first();

    expect($documento->status)->toBe('void')
        ->and($documento->voided_at)->not->toBeNull();
});
