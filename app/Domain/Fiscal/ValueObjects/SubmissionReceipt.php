<?php

namespace App\Domain\Fiscal\ValueObjects;

use DateTimeImmutable;

/**
 * Acuse del actor intermedio: la estructura fue recibida y está validándose.
 */
final readonly class SubmissionReceipt
{
    public function __construct(
        public string $submissionId,
        public ProviderStatus $status,
        public DateTimeImmutable $receivedAt,
    ) {}
}
