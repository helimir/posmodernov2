<?php

namespace App\Domain\Tax;

use App\Domain\Fiscal\Enums\TaxMethod;
use App\Domain\Fiscal\Enums\TaxType;

/**
 * Una linea del documento, con los datos fiscales YA resueltos.
 *
 * El motor no lee el perfil, ni las categorias, ni las tasas: eso se resuelve
 * antes y llega aqui. Por eso el porcentaje viene CONGELADO
 * (`taxRateSnapshot`): un cambio posterior de la tasa vigente no puede
 * alterar un documento emitido.
 */
final readonly class TaxLineInput
{
    public function __construct(
        public int $taxCategoryId,
        public int $taxRateId,
        /** El porcentaje congelado, tal como se leyo al emitir. */
        public string $taxRateSnapshot,
        /** El estado de la evidencia de esa tasa (`verified`, `pending_confirmation`...). */
        public ?string $evidenceStatus,
        public TaxMethod $method,
        /** El precio COBRADO, no el de catalogo. */
        public string $unitPrice,
        public string $quantity,
        /** El descuento de la linea. */
        public string $discount = '0',
        /**
         * El tipo del impuesto de la linea: TRASLADADO (lo que paga el
         * comprador) o RETENIDO (el credito del comprador, que sale en su
         * PROPIO documento).
         *
         * Por defecto es TRASLADADO: una linea de venta normal no dice de
         * mas, y el motor no MEZCLA los dos impuestos.
         */
        public TaxType $taxType = TaxType::Transfer,
    ) {}
}
