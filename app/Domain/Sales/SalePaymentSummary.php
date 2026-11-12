<?php

namespace App\Domain\Sales;

/**
 * El RESUMEN de los pagos de una venta: las sumas y el DESCUADRE.
 *
 * REGLA que fija esta clase: *si la suma de lo APLICADO no cuadra con el
 * total de la venta, el hueco se ve*. Una venta a credito entrega lo aplicado
 * igual al total, y una venta cobrada tambien: en los dos casos no hay nada
 * que avisar. Una venta cuyo pago no cubre el total deja un hueco, y ese
 * hueco es un hecho: se dice, con su cifra.
 *
 * LA DIFERENCIA LLEVA SU SIGNO: lo aplicado menos el total. Un cero es una
 * venta cuadrada, un negativo es un hueco y un positivo es un exceso, y un
 * numero absoluto esconderia cual de los dos es.
 */
final readonly class SalePaymentSummary
{
    /**
     * @param  string  $receivedTotal  La suma de lo RECIBIDO: lo que el
     *                                 cliente entrego.
     * @param  string  $appliedTotal  La suma de lo APLICADO: la verdad.
     * @param  string  $changeTotal  La suma del CAMBIO devuelto.
     * @param  string|null  $total  El total de la venta; `null` si la venta
     *                              no lo tiene, y entonces no hay nada con
     *                              que comparar.
     * @param  string|null  $difference  Lo aplicado menos el total, CON SU
     *                                   SIGNO; `null` si no hay total.
     * @param  bool|null  $matchesTotal  Si lo aplicado cuadra con el total;
     *                                   `null` si no hay total con el que
     *                                   comparar.
     */
    public function __construct(
        public string $receivedTotal,
        public string $appliedTotal,
        public string $changeTotal,
        public ?string $total,
        public ?string $difference,
        public ?bool $matchesTotal,
    ) {}
}
