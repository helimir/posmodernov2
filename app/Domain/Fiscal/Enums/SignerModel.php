<?php

namespace App\Domain\Fiscal\Enums;

/**
 * Modelo de firma: selecciona la RESPONSABILIDAD de la firma, no el país.
 */
enum SignerModel: string
{
    case Authority = 'authority';
    case Psp = 'psp';
    case Printer = 'printer';
    case LegalSignature = 'legal_signature';
}
