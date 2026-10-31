<?php

namespace App\Domain\Fiscal\ValueObjects;

use DateTimeImmutable;

/**
 * Acuse de la anulación: el documento queda anulado y su número consumido.
 */
final readonly class CancellationReceipt
{
    public function __construct(
        public string $documentNumber,
        public ProviderStatus $status,
        public DateTimeImmutable $acceptedAt,
    ) {}
}
