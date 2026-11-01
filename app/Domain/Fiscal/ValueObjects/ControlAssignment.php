<?php

namespace App\Domain\Fiscal\ValueObjects;

use DateTimeImmutable;

/**
 * Número de control asignado por el actor intermedio, con su rango total.
 * `printerTaxId` es el identificador fiscal genérico de la imprenta: el
 * dominio no conoce el código de ningún país.
 */
final readonly class ControlAssignment
{
    public function __construct(
        public string $controlNumber,
        public string $controlRangeFrom,
        public string $controlRangeTo,
        public DateTimeImmutable $assignedAt,
        public ?string $printerId = null,
        public ?string $printerTaxId = null,
    ) {}
}
