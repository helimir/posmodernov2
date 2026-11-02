<?php

namespace App\Domain\Fiscal\ValueObjects;

/**
 * Datos del comprobante de retencion (documento fiscal PROPIO).
 *
 * El IVA retenido NO es una categoria de linea de un documento de venta: es
 * credito del comprador, lo emite el agente de retencion y tiene numeracion,
 * serie y ciclo de vida propios. El periodo de imposicion es un dato DEL
 * DOCUMENTO, no del impuesto.
 */
final readonly class RetentionReceiptDraft
{
    public function __construct(
        /** Documento fiscal sobre el que se retiene. */
        public int $sourceDocumentId,
        /** Periodo de imposicion, del tipo `YYYY-MM`. */
        public string $periodFrom,
        public string $periodTo,
        /** Base imponible sobre la que se causa el impuesto. */
        public string $baseAmount,
        /** Impuesto causado. */
        public string $taxCaused,
        /** Impuesto retenido: el credito del comprador. */
        public string $taxRetained,
    ) {}
}
