<?php

namespace App\Domain\Fiscal\ValueObjects;

/**
 * Estado que devuelve el actor intermedio. `status` es genérico; el código
 * nativo vive en el vocabulario del perfil.
 */
final readonly class ProviderStatus
{
    public function __construct(
        public string $status,
        public ?string $detail = null,
        public bool $isFinal = false,
    ) {}
}
