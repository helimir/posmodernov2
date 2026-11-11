<?php

namespace App\Domain\Checkout;

/**
 * El resultado de la venta: lo que la caja necesita para imprimir.
 *
 * `controlNumber` es `null` cuando la venta no lleva serie de control: el
 * numero de control no se inventa, se reserva.
 *
 * `payments` es el REPARTO de los pagos: lo aplicado a la venta y el cambio
 * de cada uno, con el indice que lo situa en la lista de la entrada. Lo
 * calcula el DOMINIO y lo CONSUME el controlador: una segunda aritmetica de
 * la misma verdad es una segunda verdad.
 */
final readonly class CheckoutResult
{
    /**
     * @param  list<CheckoutPaymentAllocation>  $payments  El reparto de cada pago.
     */
    public function __construct(
        public int $saleId,
        /** El numero del documento, con el prefijo que declara la serie. */
        public string $documentNumber,
        public ?string $controlNumber,
        /** El total de la venta, como texto decimal. */
        public string $grandTotal,
        /**
         * `paid` si lo cobrado cubre el total; `confirmed` si queda algo por
         * cobrar.
         */
        public string $status,
        public array $payments = [],
    ) {}
}
