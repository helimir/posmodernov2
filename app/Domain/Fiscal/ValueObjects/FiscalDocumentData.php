<?php

namespace App\Domain\Fiscal\ValueObjects;

/**
 * Documento fiscal consolidado. `status` es el estado GENÉRICO del
 * vocabulario del perfil; el código nativo viaja aparte.
 */
final readonly class FiscalDocumentData
{
    public function __construct(
        public string $documentType,
        public string $documentNumber,
        public string $status,
        public ?string $authorityReference = null,
        public ?string $authorityReferenceScheme = null,
        public ?string $qrContent = null,
        public ?string $controlNumber = null,
    ) {}
}
