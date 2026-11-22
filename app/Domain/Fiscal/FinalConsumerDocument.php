<?php

namespace App\Domain\Fiscal;

/**
 * El DOCUMENTO del CONSUMIDOR FINAL: su identificativo y el TIPO que declara.
 *
 * REGLA que fija esta clase: *el tipo lo DECLARA quien cobra*. El sistema no
 * lo deduce del formato del documento, porque deducirlo seria adivinar: dos
 * paises llaman de distinta manera al mismo identificativo, y un tipo
 * equivocado en un comprobante fiscal es un dato falso.
 */
final readonly class FinalConsumerDocument
{
    /**
     * @param  string  $document  El identificativo: la cedula o el pasaporte.
     * @param  string  $kind  El tipo que declara el llamante.
     */
    public function __construct(
        public string $document,
        public string $kind,
    ) {}
}
