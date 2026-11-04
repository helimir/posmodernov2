<?php

namespace App\Domain\Tax;

use DateTimeImmutable;

/**
 * El resultado del calculo: lo que el documento persiste.
 *
 * El desglose por linea lleva los snapshots congelados, para que el documento
 * emitido sea autonomo.
 */
final readonly class TaxDocumentResult
{
    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function __construct(
        public array $lines,
        public string $subtotal,
        public string $taxTotal,
        public string $discountTotal,
        public string $grandTotal,
        /**
         * Lo que hay que sumar a las lineas para llegar al total redondeado.
         * `suma de las lineas + roundingAdjustment = total redondeado`.
         */
        public string $roundingAdjustment,
        public DateTimeImmutable $issuedAt,
    ) {}
}
