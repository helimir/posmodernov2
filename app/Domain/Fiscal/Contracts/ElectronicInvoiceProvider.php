<?php

namespace App\Domain\Fiscal\Contracts;

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
 * Actor intermedio de la emisión fiscal (design §C.1, ADR-0002).
 *
 * El emisor NO emite solo: el flujo separa tres pasos — `submit()` entrega la
 * estructura, `assignControl()` recibe el número de control y `issue()`
 * consolida. El canal que no asigna control devuelve `null` y la
 * consolidación lo admite.
 */
interface ElectronicInvoiceProvider
{
    public function submit(FiscalDocumentDraft $draft): SubmissionReceipt;

    public function assignControl(SubmissionReceipt $receipt): ?ControlAssignment;

    public function issue(FiscalDocumentDraft $draft, ?ControlAssignment $control): FiscalDocumentData;

    public function cancel(FiscalDocumentData $document, CancellationReason $reason): CancellationReceipt;

    public function queryStatus(FiscalDocumentData $document): ProviderStatus;

    public function deliver(FiscalDocumentData $document, DeliveryRecipient $recipient): DeliveryReceipt;
}
