<?php

namespace App\Domain\Fiscal\ValueObjects;

/**
 * Destinatario de la entrega del comprobante. `channel` es genérico: el
 * transporte concreto lo decide el perfil.
 */
final readonly class DeliveryRecipient
{
    public function __construct(
        public string $channel,
        public string $address,
    ) {}
}
