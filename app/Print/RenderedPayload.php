<?php

namespace App\Print;

/**
 * El resultado de generar los bytes ESC/POS.
 *
 * Los BYTES y su HASH. El hash es lo que viaja al agente y lo que permite
 * detectar corrupción o manipulación sin cifrar nada extra.
 */
final readonly class RenderedPayload
{
    public function __construct(
        public string $bytes,
        public string $sha256,
    ) {}
}
