<?php

use App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider;
use App\Domain\Fiscal\FiscalIssuanceService;
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
 * 0.3.4-T — la consolidación exige que el control corresponda al perfil.
 *
 * Regla que fija: *exactamente un número de control si el perfil lo exige*.
 * El perfil fiscal dice si el documento lleva control; el actor intermedio
 * dice cuál. Si las dos cosas no coinciden, el documento no se consolida.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

/**
 * Un actor intermedio de mentira: entrega la estructura, devuelve el control
 * que se le indique (o ninguno) y consolida.
 */
$provider = function (?ControlAssignment $control): ElectronicInvoiceProvider {
    return new class($control) implements ElectronicInvoiceProvider
    {
        public function __construct(private readonly ?ControlAssignment $control) {}

        public function submit(FiscalDocumentDraft $draft): SubmissionReceipt
        {
            return new SubmissionReceipt(
                'SUB-0001',
                new ProviderStatus('accepted', null, true),
                new DateTimeImmutable('2026-11-02 10:00:00'),
            );
        }

        public function assignControl(SubmissionReceipt $receipt): ?ControlAssignment
        {
            return $this->control;
        }

        public function issue(FiscalDocumentDraft $draft, ?ControlAssignment $control): FiscalDocumentData
        {
            return new FiscalDocumentData(
                documentType: 'invoice',
                documentNumber: (string) $draft->documentNumber,
                status: 'issued',
                authorityReference: 'CUFE-0001',
                authorityReferenceScheme: 'CUFE',
                qrContent: null,
                controlNumber: $control?->controlNumber,
            );
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
    };
};

test('test_la_consolidacion_se_rechaza_si_el_control_no_corresponde_al_perfil', function () use ($provider) {
    $context = FiscalSeriesFixture::context();

    $newDocument = function () use ($context): int {
        return DB::table('fiscal_documents')->insertGetId([
            'fiscal_profile_id' => $context['profile_id'],
            'store_id' => $context['store_id'],
            'document_type' => 'invoice',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $draft = new FiscalDocumentDraft(
        documentType: 'invoice',
        seriesPrefix: 'DOC',
        documentNumber: 42,
        issuedAt: new DateTimeImmutable('2026-11-02 10:00:00'),
        currencyCode: 'VES',
    );

    $control = new ControlAssignment(
        controlNumber: '00-00000001',
        controlRangeFrom: '00000001',
        controlRangeTo: '00009999',
        assignedAt: new DateTimeImmutable('2026-11-02 10:00:05'),
        printerTaxId: 'J-31234567-8',
    );

    // 1. El perfil EXIGE control y el actor NO devuelve ninguno: se rechaza
    //    y no queda nada persistido.
    $documentId = $newDocument();
    $service = new FiscalIssuanceService(DB::connection(), $provider(null));

    expect(fn () => $service->issue($documentId, $draft, true))->toThrow(RuntimeException::class);

    expect(DB::table('fiscal_documents')->where('id', $documentId)->value('status'))->toBe('draft')
        ->and(DB::table('fiscal_document_events')->count())->toBe(0)
        ->and(DB::table('fiscal_document_control_assignments')->count())->toBe(0);

    // 2. El perfil NO exige control y el actor devuelve uno: la DISCREPANCIA
    //    queda auditada y la asignación NO se crea (la invariante es cero).
    $documentId = $newDocument();
    $service = new FiscalIssuanceService(DB::connection(), $provider($control));

    $service->issue($documentId, $draft, false);

    expect(DB::table('fiscal_documents')->where('id', $documentId)->value('status'))->toBe('issued')
        ->and(DB::table('fiscal_document_control_assignments')->count())->toBe(0)
        ->and(DB::table('fiscal_document_events')->count())->toBe(1);

    $payload = json_decode((string) DB::table('fiscal_document_events')->value('payload'), true);

    expect($payload['discrepancy'])->toBe('control_no_requerido')
        ->and($payload['submission_id'])->toBe('SUB-0001');

    // 3. El perfil exige control y el actor devuelve uno: se consolida con
    //    EXACTAMENTE una asignacion, en su propia tabla.
    $documentId = $newDocument();
    $service = new FiscalIssuanceService(DB::connection(), $provider($control));

    $service->issue($documentId, $draft, true);

    $assignment = DB::table('fiscal_document_control_assignments')
        ->where('fiscal_document_id', $documentId)
        ->first();

    expect(DB::table('fiscal_documents')->where('id', $documentId)->value('status'))->toBe('issued')
        ->and(DB::table('fiscal_document_control_assignments')->where('fiscal_document_id', $documentId)->count())->toBe(1)
        ->and($assignment->control_number)->toBe('00-00000001')
        ->and((int) $assignment->control_range_from)->toBe(1)
        ->and((int) $assignment->control_range_to)->toBe(9999)
        ->and($assignment->printer_tax_id)->toBe('J-31234567-8');
});
