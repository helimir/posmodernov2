<?php

namespace Tests\Fixtures;

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
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Un actor intermedio de mentira, COMPARTIDO.
 *
 * El actor real es un HTTP: lo que importa probar aqui no es su respuesta
 * sino CUANDO habla. Por eso este falso captura dos cosas:
 *
 *  - `$calls`: el ORDEN de las llamadas. El flujo es `submit` -> `assignControl`
 *    -> `issue`, y ese orden es el contrato del proveedor (design §C.1).
 *  - `$transactionLevels`: el NIVEL de transaccion de la base de datos en el
 *    instante de cada llamada. Si el dominio metiera la llamada HTTP dentro
 *    de su transaccion, aqui se veria un nivel mayor que cero: eso es lo que
 *    demuestra que la emision ocurre FUERA de la transaccion (design §B.1,
 *    decision C5).
 *
 * Los flags `failOnSubmit` y `failOnIssue` simulan un canal caido en cada
 * paso, para probar el camino del fallo y el del reintento.
 */
final class FakeInvoiceProvider implements ElectronicInvoiceProvider
{
    /**
     * El orden de las llamadas, en el momento en que ocurren.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * El nivel de transaccion en el momento de cada llamada.
     *
     * @var array<string, int>
     */
    public array $transactionLevels = [];

    /**
     * El proveedor falla al recibir la estructura.
     */
    public bool $failOnSubmit = false;

    /**
     * El proveedor falla al consolidar.
     */
    public bool $failOnIssue = false;

    /**
     * El numero de control que devuelve; `null` simula el canal que no
     * asigna control (el portal).
     */
    public ?string $controlNumber = '00-00000001';

    /**
     * La referencia de autorizacion que devuelve.
     */
    public string $authorityReference = 'REF-FAKE-0001';

    /**
     * El esquema de la referencia de autorizacion.
     */
    public string $authorityReferenceScheme = 'REF';

    public function submit(FiscalDocumentDraft $draft): SubmissionReceipt
    {
        $this->record('submit');

        if ($this->failOnSubmit) {
            throw new RuntimeException('El actor intermedio rechazo el envio.');
        }

        return new SubmissionReceipt(
            'SUB-'.count($this->calls),
            new ProviderStatus('accepted', null, true),
            $this->now(),
        );
    }

    public function assignControl(SubmissionReceipt $receipt): ?ControlAssignment
    {
        $this->record('assignControl');

        if ($this->controlNumber === null) {
            return null;
        }

        return new ControlAssignment(
            controlNumber: $this->controlNumber,
            controlRangeFrom: '1',
            controlRangeTo: '9999',
            assignedAt: $this->now(),
            printerTaxId: 'PRINTER-TAX-ID',
        );
    }

    public function issue(FiscalDocumentDraft $draft, ?ControlAssignment $control): FiscalDocumentData
    {
        $this->record('issue');

        if ($this->failOnIssue) {
            throw new RuntimeException('El actor intermedio no pudo consolidar.');
        }

        return new FiscalDocumentData(
            documentType: $draft->documentType,
            documentNumber: (string) $draft->documentNumber,
            status: 'issued',
            authorityReference: $this->authorityReference,
            authorityReferenceScheme: $this->authorityReferenceScheme,
            qrContent: null,
            controlNumber: $control?->controlNumber,
        );
    }

    public function cancel(FiscalDocumentData $document, CancellationReason $reason): CancellationReceipt
    {
        $this->record('cancel');

        return new CancellationReceipt(
            $document->documentNumber,
            new ProviderStatus('accepted', null, true),
            $this->now(),
        );
    }

    public function queryStatus(FiscalDocumentData $document): ProviderStatus
    {
        $this->record('queryStatus');

        return new ProviderStatus('issued', null, true);
    }

    public function deliver(FiscalDocumentData $document, DeliveryRecipient $recipient): DeliveryReceipt
    {
        $this->record('deliver');

        return new DeliveryReceipt(
            $recipient->channel,
            $recipient->address,
            new ProviderStatus('delivered', null, true),
            $this->now(),
        );
    }

    /**
     * Deja constancia de la llamada y del nivel de transaccion en el que se
     * hizo: ese nivel es la prueba de que la llamada al proveedor no ocurre
     * dentro de una transaccion.
     */
    private function record(string $method): void
    {
        $this->calls[] = $method;
        $this->transactionLevels[$method] = DB::connection()->transactionLevel();
    }

    private function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
