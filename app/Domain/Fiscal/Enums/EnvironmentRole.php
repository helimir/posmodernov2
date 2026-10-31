<?php

namespace App\Domain\Fiscal\Enums;

/**
 * Papel del despliegue: permite que el sistema sea emisor, imprenta o ambos,
 * sin bifurcar el núcleo.
 */
enum EnvironmentRole: string
{
    case Issuer = 'issuer';
    case Printer = 'printer';
}
