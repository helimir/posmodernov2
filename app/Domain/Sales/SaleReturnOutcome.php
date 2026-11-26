<?php

namespace App\Domain\Sales;

/**
 * El RESULTADO de una devolucion de mercaderia a un cliente.
 *
 * REGLA que fija esta clase: *el HECHO se escribe siempre, y lo que puede
 * faltar es la CONTABILIDAD*. La mercaderia vuelve (y el lote EXACTO queda
 * repuesto) y el libro del cliente queda compensado, aunque la tienda no
 * tenga cuenta de pasivo: en ese caso lo que se avisa es el asiento, no la
 * devolucion. Es el MISMO criterio de la F3-07.
 */
final readonly class SaleReturnOutcome
{
    public function __construct(
        /** La devolucion. */
        public int $returnId,
        /** La venta de la que sale. */
        public int $saleId,
        /** El total devuelto, como texto decimal. */
        public string $total,
        /** El saldo del cliente DESPUES de la devolucion. */
        public string $balance,
        /** El credito a favor del cliente que deja la devolucion. */
        public string $credit,
        /** El asiento escrito, si se pudo escribir. */
        public ?int $entryId = null,
        /** ¿Se escribio el asiento? */
        public bool $entryWritten = false,
        /** El aviso: por que no se escribio el asiento, si no se escribio. */
        public ?string $warning = null,
    ) {}
}
