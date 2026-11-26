<?php

namespace App\Domain\Credit;

/**
 * El RESULTADO de la creacion de un plan de cuotas.
 *
 * REGLA que fija esta clase: *el plan dice las TRES CIFRAS de lo que
 * financia*: el principal (lo que la venta dejo sin cobrar), el interes (lo que
 * el plan cobra por aplazar) y el total (lo que el cliente va a pagar). Las
 * tres son TEXTO decimal: un importe no es un `float`.
 */
final readonly class InstallmentPlanOutcome
{
    public function __construct(
        /** El plan creado. */
        public int $planId,
        /** La venta que el plan financia. */
        public int $saleId,
        /** El principal del plan, como texto decimal. */
        public string $principal,
        /** El interes del plan, como texto decimal. */
        public string $interest,
        /** El total a financiar (principal mas interes), como texto decimal. */
        public string $total,
        /** El numero de cuotas. */
        public int $count,
        /** La primera fecha de vencimiento, en `Y-m-d`. */
        public string $firstDueDate,
        /** El asiento del interes, si se pudo escribir. */
        public ?int $entryId = null,
        /** El saldo del cliente en la tienda despues de crear el plan. */
        public string $balance = '0.0000',
        /** La deuda del cliente en la tienda despues de crear el plan. */
        public string $due = '0.0000',
    ) {}
}
