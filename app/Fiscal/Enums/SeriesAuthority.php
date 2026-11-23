<?php

namespace App\Fiscal\Enums;

/**
 * La AUTORIDAD que asigna el numero de una serie fiscal.
 *
 * REGLA que fija este enum: *la autoridad es un conjunto CERRADO*. Es la
 * columna ENUM `fiscal_series.assigned_by`, y dice QUIEN reparte el numero:
 * el emisor, la autoridad que concede el rango, la imprenta digital o el
 * proveedor de pagos.
 *
 * Y EL ESQUEMA ES LA VERDAD: el `enum` de PostgreSQL es una restriccion
 * CHECK, y este enum es su ESPEJO. El espejo no puede apartarse, asi que hay
 * un test que lo compara con la restriccion (`FiscalCatalogTest`).
 *
 * Y POR QUE VIVE EN `App\Fiscal` Y NO EN `App\Domain`: esta unidad
 * ADMINISTRA la fila, y el dominio fiscal no se toca.
 */
enum SeriesAuthority: string
{
    case Issuer = 'issuer';
    case Authority = 'authority';
    case Printer = 'printer';
    case Psp = 'psp';

    /**
     * La ETIQUETA de la autoridad: el texto que ve la persona.
     *
     * Es de INTERFAZ, y por eso esta en ingles.
     */
    public function label(): string
    {
        return match ($this) {
            self::Issuer => 'The issuer assigns the number',
            self::Authority => 'The authority assigns the number',
            self::Printer => 'The printer assigns the number',
            self::Psp => 'The payment service provider assigns the number',
        };
    }
}
