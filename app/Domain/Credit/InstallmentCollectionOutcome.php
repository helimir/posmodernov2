<?php

namespace App\Domain\Credit;

/**
 * El RESULTADO del cobro de una cuota.
 *
 * REGLA que fija esta clase: *el cobro dice QUE se cobro y COMO QUEDA EL
 * PLAN*. El resultado no lleva el saldo del cliente (eso lo derivan
 * `CustomerBalanceService` y la pantalla), sino las tres cifras del hecho: el
 * importe cobrado, el estado en que queda el plan y el movimiento del libro
 * del cliente que lo respalda.
 */
final readonly class InstallmentCollectionOutcome
{
    public function __construct(
        /** La cuota cobrada. */
        public int $scheduleId,
        /** El plan al que pertenece la cuota. */
        public int $planId,
        /** El importe cobrado, como texto decimal. */
        public string $amount,
        /** El estado del plan despues del cobro (`active` o `paid`). */
        public string $planStatus,
        /** El movimiento del libro del cliente que registra el cobro. */
        public int $entryId,
        /** El saldo del cliente en la tienda despues del cobro. */
        public string $balance,
        /** La deuda del cliente en la tienda despues del cobro. */
        public string $due,
    ) {}
}
