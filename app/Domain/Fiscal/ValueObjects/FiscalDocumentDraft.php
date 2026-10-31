<?php

namespace App\Domain\Fiscal\ValueObjects;

use DateTimeImmutable;

/**
 * Documento que el emisor entrega al actor intermedio para su validación.
 */
final readonly class FiscalDocumentDraft
{
    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function __construct(
        public string $documentType,
        public string $seriesPrefix,
        public int $documentNumber,
        public DateTimeImmutable $issuedAt,
        public string $currencyCode,
        public array $lines = [],
    ) {}
}
