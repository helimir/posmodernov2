<?php

namespace App\Domain\Fiscal\ValueObjects;

use DateTimeImmutable;

/**
 * Acuse de la entrega del comprobante al destinatario.
 */
final readonly class DeliveryReceipt
{
    public function __construct(
        public string $channel,
        public string $address,
        public ProviderStatus $status,
        public DateTimeImmutable $deliveredAt,
    ) {}
}
