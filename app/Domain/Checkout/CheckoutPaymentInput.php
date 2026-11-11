<?php

namespace App\Domain\Checkout;

/**
 * Un pago de la venta.
 *
 * `amount` es el importe cobrado, como texto decimal: un `float` no es un
 * dinero. `details` es el detalle libre del medio de pago (el documento, la
 * referencia, el cambio): viaja como `jsonb`.
 *
 * `paymentMethodType` es el TIPO del medio de pago (`cash`, `card`,
 * `transfer`, `credit`) y es DATO de la entrada: el dominio NO consulta la
 * base de datos para saberlo. El tipo decide si un pago es un COBRO (entra
 * al cajon) o es DEUDA (no entra), y por eso el reparto y el asiento
 * contable lo necesitan antes de escribir nada.
 */
final readonly class CheckoutPaymentInput
{
    public function __construct(
        /** El medio de pago; `null` si la tienda no lo declara. */
        public ?int $paymentMethodId,
        /** El importe cobrado, como texto decimal. */
        public string $amount,
        /** El detalle del pago, si lo hay. */
        public ?array $details = null,
        /**
         * El TIPO del medio de pago (`cash`, `card`, `transfer`, `credit`).
         *
         * `null` si la tienda no lo declara: un medio de pago sin tipo
         * declarado cobra en efectivo, y ese es el DEFECTO del catalogo.
         */
        public ?string $paymentMethodType = null,
    ) {}
}
