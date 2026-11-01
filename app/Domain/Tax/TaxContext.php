<?php

namespace App\Domain\Tax;

use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\TaxMethod;

/**
 * Datos fiscales resueltos ANTES de llamar al motor (design §C.2, D9).
 *
 * El motor de impuestos es puro: no lee la base de datos, ni la red, ni el
 * reloj. El perfil, la moneda, las categorías y las tasas se resuelven antes
 * y llegan aquí como datos.
 *
 * El prefijo obligatorio del correlativo y la frase del contribuyente formal
 * son DATOS del perfil: este contenedor los guarda y los COMPARA contra el
 * valor leído. Nunca hay un literal normativo en el dominio (C4).
 */
final readonly class TaxContext
{
    public function __construct(
        public RoundingRule $roundingRule,
        public bool $taxInclusiveDefault,
        public int $currencyDecimals,
        public ?string $exemptionMarker,
        public ?string $formalContributorPhrase,
        public ?string $seriesPrefixRequiredToken,
    ) {}

    /**
     * El método del impuesto lo decide el perfil fiscal, no la línea.
     */
    public function taxMethod(): TaxMethod
    {
        return $this->taxInclusiveDefault ? TaxMethod::Inclusive : TaxMethod::Exclusive;
    }

    /**
     * ¿El prefijo del correlativo lleva el token obligatorio? La comparación
     * es contra el valor leído del perfil, nunca contra un literal.
     */
    public function requiresSeriesPrefix(string $prefix): bool
    {
        if ($this->seriesPrefixRequiredToken === null || $this->seriesPrefixRequiredToken === '') {
            return false;
        }

        return str_contains($prefix, $this->seriesPrefixRequiredToken);
    }
}
