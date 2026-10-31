<?php

namespace App\Domain\Fiscal\Enums;

/**
 * Actor intermedio de la emisión. Dato genérico: no lleva ningún código de
 * país (design §C.1, §D.3).
 */
enum ProviderKind: string
{
    case Printer = 'printer';
    case Pac = 'pac';
    case Portal = 'portal';
}
