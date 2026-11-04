<?php

namespace App\Domain\Tax;

use DateTimeImmutable;

/**
 * El documento a calcular.
 *
 * La fecha viaja en la entrada y no se pide al reloj: el motor es puro, y la
 * misma entrada da siempre el mismo resultado.
 */
final readonly class TaxDocumentInput
{
    /**
     * @param  list<TaxLineInput>  $lines
     */
    public function __construct(
        public array $lines,
        /** El descuento de documento, prorrateado a las lineas por base imponible. */
        public string $documentDiscount = '0',
        public DateTimeImmutable $issuedAt = new DateTimeImmutable('1970-01-01 00:00:00'),
    ) {}
}
