<?php

namespace App\Domain\Fiscal\Enums;

/**
 * Alcance del redondeo: por línea o sobre el total. Es una DECISIÓN DE
 * PRODUCTO, no un requisito de la providencia.
 */
enum RoundingRule: string
{
    case Line = 'line';
    case Total = 'total';
}
