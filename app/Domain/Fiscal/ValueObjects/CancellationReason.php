<?php

namespace App\Domain\Fiscal\ValueObjects;

/**
 * Motivo de anulación. El código es un dato del perfil, no un literal del
 * dominio.
 */
final readonly class CancellationReason
{
    public function __construct(
        public string $code,
        public ?string $note = null,
    ) {}
}
