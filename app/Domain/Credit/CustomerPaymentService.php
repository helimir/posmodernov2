<?php

namespace App\Domain\Credit;

use App\Domain\Accounting\LedgerWriter;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El COBRO de un cliente: el movimiento de SU libro y el asiento contable.
 *
 * REGLA que fija este servicio: *un cobro a un cliente escribe el movimiento
 * en el libro del cliente Y el asiento contable, en la MISMA transaccion*.
 *
 * EL DEFECTO QUE ESTE SERVICIO CIERRA: el cobro suelto escribia SOLO el
 * libro del cliente. El efectivo del cobro no llegaba a la cuenta de
 * efectivo del libro contable, y el arqueo del dia daba un DESCUADRE FALSO:
 * el sistema decia que en la caja no habia ese dinero, y el cajero si lo
 * tenia.
 *
 * EL ASIENTO es de tipo `sale_payment` (el codigo del catalogo
 * `ledger_entry_types`): debito de la cuenta de EFECTIVO por lo cobrado, y
 * credito de la cuenta POR COBRAR por la deuda que se cancela. Los dos
 * importes van en POSITIVO: el signo lo lleva la cuenta, no el importe. Un
 * importe negativo en un asiento es una linea que el libro no admite.
 *
 * LA TRANSACCION es UNA: el movimiento del libro del cliente y el asiento se
 * escriben juntos o no se escribe ninguno. `CustomerBalanceService` abre su
 * propia transaccion, y esta la envuelve: Laravel anida con SAVEPOINT, y el
 * rollback del llamante se lleva el movimiento entero.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class CustomerPaymentService
{
    public function __construct(
        private Connection $connection,
        private CustomerBalanceService $balances,
        private LedgerWriter $ledger,
    ) {}

    /**
     * Cobra a un cliente y devuelve el id del movimiento de su libro.
     *
     * @param  string  $amount  El importe cobrado, como texto decimal.
     * @param  string  $referenceNo  El comprobante del cobro.
     * @param  DateTimeImmutable  $entryDate  La FECHA DE NEGOCIO del cobro: un dato del llamante.
     * @param  array<string, string>  $accounts  El plan de cuentas: rol => codigo.
     * @param  int|null  $saleId  La venta a la que se imputa el cobro.
     * @param  int|null  $actorId  Quien registra el cobro.
     * @param  DateTimeImmutable|null  $occurredAt  El instante del cobro; si no viene, la fecha de negocio.
     *
     * @throws RuntimeException Cuando el cliente no tiene ficha en la tienda,
     *                          o cuando el plan de cuentas no declara la
     *                          cuenta que el cobro necesita.
     */
    public function collect(
        int $customerId,
        int $storeId,
        string $amount,
        string $referenceNo,
        DateTimeImmutable $entryDate,
        array $accounts,
        ?int $saleId = null,
        ?int $actorId = null,
        ?DateTimeImmutable $occurredAt = null,
    ): int {
        $importe = $this->decimal($amount);

        $efectivo = $this->account($storeId, $accounts, 'cash');
        $porCobrar = $this->account($storeId, $accounts, 'receivable');

        return $this->connection->transaction(function () use (
            $customerId,
            $storeId,
            $importe,
            $referenceNo,
            $entryDate,
            $efectivo,
            $porCobrar,
            $saleId,
            $actorId,
            $occurredAt
        ): int {
            // 1. EL MOVIMIENTO DEL LIBRO DEL CLIENTE: NEGATIVO, porque el
            //    cliente paga. Y el cache de su ficha se refresca con el.
            $entryId = $this->balances->registerPayment(
                customerId: $customerId,
                storeId: $storeId,
                amount: $importe,
                referenceNo: $referenceNo,
                entryDate: $entryDate,
                saleId: $saleId,
            );

            // 2. EL ASIENTO: el efectivo entra a la caja y la deuda se
            //    cancela. Los dos importes en positivo: el signo va en la
            //    cuenta.
            $this->ledger->write(
                entryType: 'sale_payment',
                storeId: $storeId,
                occurredAt: $occurredAt ?? $entryDate,
                businessDate: $entryDate,
                lines: [
                    ['account' => $efectivo, 'debit' => $importe, 'credit' => '0'],
                    ['account' => $porCobrar, 'debit' => '0', 'credit' => $importe],
                ],
                referenceType: 'customer_payment',
                referenceId: $entryId,
                actorId: $actorId,
                description: 'Cobro '.$referenceNo,
            );

            return $entryId;
        });
    }

    /**
     * La cuenta contable de un rol del plan de cuentas.
     *
     * Sin ella el cobro NO se registra: un saldo inventado es peor que un
     * cobro que no se hace, porque un cobro que no se hace se ve y un saldo
     * inventado no.
     *
     * @param  array<string, string>  $accounts
     *
     * @throws RuntimeException Cuando el plan de cuentas no la declara.
     */
    private function account(int $storeId, array $accounts, string $role): string
    {
        $codigo = $accounts[$role] ?? null;

        if ($codigo === null || trim($codigo) === '') {
            throw new RuntimeException(
                "La tienda {$storeId} no tiene cuenta contable de '{$role}' en su plan de cuentas: "
                .'sin ella el cobro no se puede registrar.'
            );
        }

        return trim($codigo);
    }

    /**
     * Normaliza un importe a la escala de la columna.
     *
     * El importe llega como TEXTO decimal y sale como TEXTO decimal con 4
     * decimales. Un `float` se RECHAZA: un flotante no es dinero.
     *
     * @throws RuntimeException Cuando el importe no es un numero decimal.
     */
    private function decimal(mixed $value): string
    {
        if (is_float($value)) {
            throw new RuntimeException(
                'El importe del cobro debe ser un texto decimal, nunca un float.'
            );
        }

        $amount = is_string($value) ? trim($value) : (string) $value;

        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new RuntimeException("El importe del cobro '{$amount}' no es un numero decimal.");
        }

        return (string) BigDecimal::of($amount)->toScale(4, RoundingMode::HALF_UP);
    }
}
