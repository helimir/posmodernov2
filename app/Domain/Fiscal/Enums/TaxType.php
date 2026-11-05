<?php

namespace App\Domain\Fiscal\Enums;

/**
 * El tipo del impuesto de un nodo de `fiscal_document_taxes`.
 *
 * El IVA TRASLADADO va en las lineas del comprobante de venta: es lo que el
 * comprador paga. El IVA RETENIDO es CREDITO del comprador y sale en su
 * PROPIO comprobante (`document_type='retention_receipt'`), con su serie y su
 * numeracion.
 *
 * Los dos son el mismo impuesto en espejo, pero NO son la misma fila: un
 * nodo de retenido que se cuela en una venta mezcla el credito del comprador
 * con lo que el comprador debe, y el comprobante deja de cuadrar con lo que
 * se emitio.
 */
enum TaxType: string
{
    case Transfer = 'transfer';
    case Withholding = 'withholding';
}
