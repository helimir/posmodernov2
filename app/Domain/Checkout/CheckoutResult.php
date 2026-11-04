<?php

namespace App\Domain\Checkout;

/**
 * El resultado de la venta: lo que la caja necesita para imprimir.
 *
 * `controlNumber` es `null` cuando la venta no lleva serie de control: el
 * numero de control no se inventa, se reserva.
 */
final readonly class CheckoutResult
{
    public function __construct(
        public int $saleId,
        /** El numero del documento, con el prefijo que declara la serie. */
        public string $documentNumber,
        public ?string $controlNumber,
        /** El total de la venta, como texto decimal. */
        public string $grandTotal,
        /** `paid` si los pagos cubren el total; `confirmed` si no. */
        public string $status,
    ) {}
}
