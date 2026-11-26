<?php

namespace App\Domain\Purchasing;

/**
 * El RESULTADO de una compra, de su anulacion o de una devolucion al
 * proveedor.
 *
 * REGLA que fija esta clase: *el HECHO se escribe siempre, y lo que puede
 * faltar es la CONTABILIDAD*. Por eso el resultado dice LAS DOS COSAS: que
 * se hizo (`purchaseId`, `returnId`, `total`) y si su asiento se pudo
 * escribir (`entryWritten`, `entryId`), con el AVISO cuando no se pudo
 * (`warning`).
 *
 * Y EL AVISO NO ES UN FALLO: la compra entra, el stock entra y la deuda se
 * registra igual. Lo que no se puede escribir es el asiento, y eso se ve: en
 * la respuesta, en la auditoria y en el log. Es el MISMO criterio de la
 * F3-07: el hecho no se bloquea, la contabilidad se avisa.
 */
final readonly class PurchaseOutcome
{
    public function __construct(
        /** La compra. */
        public int $purchaseId,
        /** El total de la compra (o de la devolucion), como texto decimal. */
        public string $total,
        /** La devolucion al proveedor, si la operacion fue una devolucion. */
        public ?int $returnId = null,
        /** El asiento escrito, si se pudo escribir. */
        public ?int $entryId = null,
        /** ¿Se escribio el asiento? */
        public bool $entryWritten = false,
        /** El aviso: por que no se escribio el asiento, si no se escribio. */
        public ?string $warning = null,
    ) {}
}
