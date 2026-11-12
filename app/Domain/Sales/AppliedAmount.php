<?php

namespace App\Domain\Sales;

/**
 * Lo APLICADO de un pago: el CRITERIO, en un solo sitio.
 *
 * REGLA que fija esta clase: *lo aplicado de una fila sin `applied` es su
 * BRUTO*.
 *
 * La columna `sale_payments.applied` es NULLABLE a proposito: las filas
 * anteriores a la migracion no la tienen, y una fila sin `applied` no tiene
 * cambio registrado: el pago se guardo entero y lo que se aplico a la venta
 * fue el pago entero. Por eso el fallback es su bruto, y no cero.
 *
 * ESTA CLASE ES EL UNICO SITIO DEL CRITERIO. Los lectores que agregan en la
 * base de datos (`SalePaymentStatus::forSale()` y
 * `CashDay::receivedByPaymentMethod()`) usan la EXPRESION de aqui, y el
 * lector de los pagos de una venta (`SalePayments`) usa la MISMA: tres
 * copias de la misma regla serian tres verdades, y las tres divergen.
 */
final class AppliedAmount
{
    /**
     * La EXPRESION SQL del criterio, con el ALIAS de la tabla de los pagos.
     *
     * @param  string  $alias  La tabla de los pagos (`sale_payments`).
     */
    public static function sql(string $alias): string
    {
        return 'COALESCE('.$alias.'.applied, '.$alias.'.amount)';
    }
}
