<?php

namespace App\Domain\Accounting;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El MOVIMIENTO BANCARIO: el dinero que se mueve entre las cuentas de la
 * tienda.
 *
 * REGLA que fija esta clase: *un movimiento bancario mueve dinero entre la
 * CAJA y las CUENTAS BANCARIAS de la tienda, y no sale ni entra de la
 * tienda*. El deposito del cajon al banco, el retiro del banco al cajon y el
 * traspaso entre dos cuentas bancarias son el mismo hecho: la treasury se
 * reparte.
 *
 * EL ASIENTO es de tipo `bank_movement` (el codigo del catalogo
 * `ledger_entry_types`), y sus dos lineas son:
 *
 *  - el DEBE, a la cuenta que RECIBE el dinero;
 *  - el HABER, a la cuenta que lo cede.
 *
 * Y LAS CUENTAS SON DE TESORERIA: la caja de la tienda (el rol `cash` de su
 * plan de cuentas) y las cuentas bancarias (la cuenta que declara su vinculo
 * por tienda, `bank_account_stores.account_code`). Las dos se resuelven con
 * `TreasuryAccounts`, que es el unico sitio que sabe de donde sale el dinero
 * de la tienda.
 *
 * Y LA REGLA DE LA CAJA: *el saldo en curso de la caja del dia solo se mueve
 * con el EFECTIVO*. Un traspaso entre dos bancos no lo mueve (no hay caja en
 * el medio), y uno que toca la caja si lo mueve, porque la caja es una de las
 * dos cuentas del movimiento. No es una regla que el dominio recuerda: sale
 * de la cuenta que se debita y de la que se acredita, que es lo que `CashDay`
 * lee.
 *
 * Y LA FUENTE DE VERDAD DE UN MOVIMIENTO BANCARIO ES EL PROPIO ASIENTO: no
 * hay tabla de contadores, porque el saldo de una cuenta se deriva de sus
 * movimientos (spec `contabilidad-caja`, "sin contadores derivados").
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class BankMovementService
{
    public function __construct(private Connection $connection) {}

    /**
     * REGISTRA un movimiento bancario y escribe su asiento.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El IMPORTE es POSITIVO: un movimiento de cero no es un movimiento, y
     *     una linea a cero no es una linea contable.
     *  2. LAS DOS CUENTAS SON DISTINTAS: mover dinero de una cuenta a si
     *     misma no es un movimiento, y un asiento con las dos lineas en la
     *     misma cuenta es un asiento que no dice nada.
     *  3. El dinero que entra o sale de la CAJA solo entra o sale de una caja
     *     ABIERTA de esa fecha de negocio.
     *  4. EL MOVIMIENTO Y SU ASIENTO se escriben en la MISMA transaccion.
     *
     * @param  string  $amount  El importe del movimiento. Texto decimal.
     * @param  string  $fromType  De donde sale: `cash` o `bank`.
     * @param  int|null  $fromBankAccountId  La cuenta bancaria que cede.
     * @param  string  $toType  Donde entra: `cash` o `bank`.
     * @param  int|null  $toBankAccountId  La cuenta bancaria que recibe.
     * @param  DateTimeImmutable|null  $occurredAt  El instante en que ocurrio.
     * @return int El id del asiento.
     *
     * @throws RuntimeException Cuando el movimiento no se puede registrar.
     */
    public function move(
        int $storeId,
        string $amount,
        DateTimeImmutable $businessDate,
        string $fromType,
        ?int $fromBankAccountId = null,
        string $toType = TreasuryAccounts::PAGO_CAJA,
        ?int $toBankAccountId = null,
        ?string $description = null,
        ?string $reference = null,
        ?int $actorId = null,
        ?DateTimeImmutable $occurredAt = null,
    ): int {
        $importe = PosAmount::decimal($amount);

        if ($importe->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new RuntimeException(
                'El importe de un movimiento tiene que ser mayor que cero: un movimiento de cero no es un movimiento.'
            );
        }

        $tesoreria = new TreasuryAccounts($this->connection);

        $origen = $tesoreria->financia($storeId, $fromType, $fromBankAccountId);
        $destino = $tesoreria->financia($storeId, $toType, $toBankAccountId);

        if ($origen === $destino) {
            throw new RuntimeException(
                "El movimiento va de la cuenta {$origen} a la misma cuenta {$destino}: "
                .'mover dinero de una cuenta a si misma no es un movimiento.'
            );
        }

        // El dinero que entra o sale de la CAJA sale o entra de una caja
        // ABIERTA de esa fecha.
        if ($fromType === TreasuryAccounts::PAGO_CAJA || $toType === TreasuryAccounts::PAGO_CAJA) {
            (new CashRegisterService($this->connection))
                ->registerFor($storeId, $businessDate);
        }

        $instante = $occurredAt ?? $businessDate;

        return $this->connection->transaction(function () use (
            $storeId,
            $importe,
            $businessDate,
            $origen,
            $destino,
            $description,
            $actorId,
            $instante
        ): int {
            return (new LedgerWriter($this->connection))->write(
                entryType: 'bank_movement',
                storeId: $storeId,
                occurredAt: $instante,
                businessDate: $businessDate,
                lines: [
                    ['account' => $destino, 'debit' => (string) $importe, 'credit' => '0'],
                    ['account' => $origen, 'debit' => '0', 'credit' => (string) $importe],
                ],
                referenceType: 'bank_movement',
                referenceId: null,
                actorId: $actorId,
                description: $description === null || trim($description) === ''
                    ? 'Movimiento bancario'
                    : 'Movimiento bancario: '.$description,
            );
        });
    }
}
