<?php

namespace App\Domain\Checkout;

/**
 * El REPARTO de un pago: lo aplicado a la venta y el cambio.
 *
 * REGLA que fija esta clase: *el reparto de los pagos entre lo aplicado a la
 * venta y el cambio lo calcula el DOMINIO, una sola vez*. El controlador lo
 * CONSUME: una segunda aritmetica de la misma verdad es una segunda verdad,
 * y las dos divergen.
 *
 * El `applied` de un pago es el MINIMO entre lo recibido y lo que queda del
 * total de la venta, en el ORDEN de los pagos; el `change` es lo que sobra, y
 * se devuelve al cliente. La SEMANTICA no cambia: solo cambia quien la
 * calcula y quien la usa.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class CheckoutPaymentAllocation
{
    public function __construct(
        /** El indice del pago en la lista de la entrada. */
        public int $index,
        /** Lo RECIBIDO, como texto decimal. */
        public string $received,
        /** Lo APLICADO a la venta, como texto decimal. */
        public string $applied,
        /** El CAMBIO, como texto decimal. */
        public string $change,
        /** El TIPO del medio de pago; `null` si no se declara. */
        public ?string $type,
    ) {}
}
