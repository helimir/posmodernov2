<?php

namespace App\Domain\Fiscal\Enums;

/**
 * Método del impuesto: la tasa va dentro del precio o se suma al precio.
 */
enum TaxMethod: string
{
    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';
}
