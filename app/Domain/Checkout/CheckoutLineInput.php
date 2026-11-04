<?php

namespace App\Domain\Checkout;

/**
 * Una linea de la venta, tal como la cobra la caja.
 *
 * `unitPrice` es el PRECIO COBRADO, no el de catalogo: el legacy multiplicaba
 * el impuesto por el precio de catalogo, y si el POS habia cobrado otro
 * precio, el impuesto era incorrecto (defecto P0 #13 del mapa funcional).
 *
 * `taxCategoryId` es el OVERRIDE por tienda de la linea: si es `null`, se
 * usa la categoria del producto (o la del puente de la tienda).
 */
final readonly class CheckoutLineInput
{
    public function __construct(
        public int $productId,
        /** La cantidad, como texto decimal. */
        public string $quantity,
        /** El precio COBRADO, como texto decimal. */
        public string $unitPrice,
        /** El descuento de la linea, como texto decimal. */
        public string $discount = '0',
        /** El override de categoria fiscal de la linea; `null` si no lo hay. */
        public ?int $taxCategoryId = null,
    ) {}
}
