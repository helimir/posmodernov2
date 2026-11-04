<?php

namespace App\Domain\Checkout;

/**
 * Un pago de la venta.
 *
 * `amount` es el importe cobrado, como texto decimal: un `float` no es un
 * dinero. `details` es el detalle libre del medio de pago (el documento, la
 * referencia, el cambio): viaja como `jsonb`.
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
    ) {}
}
