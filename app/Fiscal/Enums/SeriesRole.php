<?php

namespace App\Fiscal\Enums;

/**
 * El PAPEL de una serie fiscal: que numeracion reparte.
 *
 * REGLA que fija este enum: *el papel de una serie es un conjunto CERRADO*.
 * Son las tres columnas ENUM del esquema (`fiscal_series.series_role` y
 * `fiscal_document_reservations.series_role`), y el papel decide QUE
 * numeracion es: la del documento, la del numero de control o la del
 * comprobante de retencion.
 *
 * Y EL ESQUEMA ES LA VERDAD: el `enum` de PostgreSQL es una restriccion
 * CHECK, y este enum es su ESPEJO. El espejo no puede apartarse, asi que hay
 * un test que lo compara con la restriccion (`FiscalCatalogTest`): un enum
 * que declara un papel que el esquema no admite es un enum que ofrece
 * declarar algo que la base de datos va a rechazar.
 *
 * Y POR QUE VIVE EN `App\Fiscal` Y NO EN `App\Domain`: esta unidad
 * ADMINISTRA la fila, y el dominio fiscal no se toca. El enum describe lo
 * que la fila declara, no lo que el dominio emite.
 */
enum SeriesRole: string
{
    case Document = 'document';
    case Control = 'control';
    case RetentionReceipt = 'retention_receipt';

    /**
     * La ETIQUETA del papel: el texto que ve la persona.
     *
     * Es de INTERFAZ, y por eso esta en ingles.
     */
    public function label(): string
    {
        return match ($this) {
            self::Document => 'Document numbers',
            self::Control => 'Control numbers',
            self::RetentionReceipt => 'Retention receipt numbers',
        };
    }
}
