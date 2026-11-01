<?php

use App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider;
use App\Domain\Fiscal\ValueObjects\CancellationReason;
use App\Domain\Fiscal\ValueObjects\CancellationReceipt;
use App\Domain\Fiscal\ValueObjects\ControlAssignment;
use App\Domain\Fiscal\ValueObjects\DeliveryReceipt;
use App\Domain\Fiscal\ValueObjects\DeliveryRecipient;
use App\Domain\Fiscal\ValueObjects\FiscalDocumentData;
use App\Domain\Fiscal\ValueObjects\FiscalDocumentDraft;
use App\Domain\Fiscal\ValueObjects\ProviderStatus;
use App\Domain\Fiscal\ValueObjects\SubmissionReceipt;

/**
 * Actor intermedio que registra el orden de las llamadas. `portalChannel`
 * reproduce el canal que NO asigna número de control.
 */
class RecordingInvoiceProvider implements ElectronicInvoiceProvider
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private bool $portalChannel = false) {}

    public function submit(FiscalDocumentDraft $draft): SubmissionReceipt
    {
        $this->calls[] = 'submit';

        return new SubmissionReceipt(
            'SUB-1',
            new ProviderStatus('accepted'),
            new DateTimeImmutable('2027-04-19T10:31:00+00:00'),
        );
    }

    public function assignControl(SubmissionReceipt $receipt): ?ControlAssignment
    {
        $this->calls[] = 'assignControl';

        if ($this->portalChannel) {
            return null;
        }

        return new ControlAssignment(
            '00-00000001',
            '00-00000001',
            '00-00000999',
            new DateTimeImmutable('2027-04-19T10:32:00+00:00'),
        );
    }

    public function issue(FiscalDocumentDraft $draft, ?ControlAssignment $control): FiscalDocumentData
    {
        $this->calls[] = 'issue';

        return new FiscalDocumentData(
            documentType: $draft->documentType,
            documentNumber: (string) $draft->documentNumber,
            status: 'issued',
            controlNumber: $control?->controlNumber,
        );
    }

    public function cancel(FiscalDocumentData $document, CancellationReason $reason): CancellationReceipt
    {
        $this->calls[] = 'cancel';

        return new CancellationReceipt(
            $document->documentNumber,
            new ProviderStatus('cancelled', $reason->code),
            new DateTimeImmutable('2027-04-19T11:00:00+00:00'),
        );
    }

    public function queryStatus(FiscalDocumentData $document): ProviderStatus
    {
        $this->calls[] = 'queryStatus';

        return new ProviderStatus('issued');
    }

    public function deliver(FiscalDocumentData $document, DeliveryRecipient $recipient): DeliveryReceipt
    {
        $this->calls[] = 'deliver';

        return new DeliveryReceipt(
            $recipient->channel,
            $recipient->address,
            new ProviderStatus('delivered'),
            new DateTimeImmutable('2027-04-19T11:05:00+00:00'),
        );
    }
}

/**
 * 0.2.4-T — el emisor no emite solo: hay un actor intermedio.
 *
 * Regla que fija: la emisión atraviesa un actor que separa ENVIAR, ASIGNAR
 * CONTROL y CONSOLIDAR en tres pasos; el canal que no asigna control devuelve
 * `null` y la consolidación lo admite.
 */
test('test_el_contrato_del_proveedor_separa_emitir_asignar_control_y_consolidar', function () {
    // El contrato expone las seis operaciones y ninguna más.
    $methods = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(ElectronicInvoiceProvider::class))->getMethods(),
    );

    expect($methods)->toBe(['submit', 'assignControl', 'issue', 'cancel', 'queryStatus', 'deliver']);

    // `assignControl()` devuelve `?ControlAssignment`: el canal portal no asigna control.
    $assignControl = new ReflectionMethod(ElectronicInvoiceProvider::class, 'assignControl');

    expect((string) $assignControl->getReturnType())->toBe('?App\Domain\Fiscal\ValueObjects\ControlAssignment');

    $draft = new FiscalDocumentDraft(
        documentType: 'invoice',
        seriesPrefix: 'ZZ-001',
        documentNumber: 42,
        issuedAt: new DateTimeImmutable('2027-04-19T10:30:00+00:00'),
        currencyCode: 'ZZA',
        lines: [['description' => 'Articulo de prueba', 'quantity' => 2]],
    );

    // El flujo consolidado son TRES pasos, en ese orden.
    $provider = new RecordingInvoiceProvider;
    $receipt = $provider->submit($draft);
    $control = $provider->assignControl($receipt);
    $document = $provider->issue($draft, $control);

    expect($provider->calls)->toBe(['submit', 'assignControl', 'issue'])
        ->and($receipt->submissionId)->toBe('SUB-1')
        ->and($control->controlNumber)->toBe('00-00000001')
        ->and($document->status)->toBe('issued')
        ->and($document->controlNumber)->toBe('00-00000001');

    // El canal portal no asigna control: la consolidación sigue siendo posible.
    $portal = new RecordingInvoiceProvider(portalChannel: true);
    $portalControl = $portal->assignControl($portal->submit($draft));
    $portalDocument = $portal->issue($draft, $portalControl);

    expect($portalControl)->toBeNull()
        ->and($portalDocument->controlNumber)->toBeNull()
        ->and($portal->calls)->toBe(['submit', 'assignControl', 'issue']);

    // Las tres operaciones restantes también pasan por el actor.
    $cancellation = $provider->cancel($document, new CancellationReason('annulled'));
    $status = $provider->queryStatus($document);
    $delivery = $provider->deliver($document, new DeliveryRecipient('email', 'cliente@example.test'));

    expect($provider->calls)->toBe(['submit', 'assignControl', 'issue', 'cancel', 'queryStatus', 'deliver'])
        ->and($cancellation->status->status)->toBe('cancelled')
        ->and($cancellation->status->detail)->toBe('annulled')
        ->and($status->status)->toBe('issued')
        ->and($delivery->channel)->toBe('email')
        ->and($delivery->status->status)->toBe('delivered');
});
