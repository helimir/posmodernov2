<?php

namespace App\Domain\Accounting;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El escritor de asientos contables.
 *
 * Es el UNICO pathway que escribe un asiento: cabecera (`ledger_entries`) y
 * lineas (`ledger_lines`), siempre juntas y en la misma transaccion.
 *
 * El legacy no tenia libro contable: cada modulo escribia su propia tabla de
 * movimientos (`bank_transaction_info`, `expense`, ...) y el gasto se
 * derivaba del movimiento bancario. Aqui el movimiento tiene UN TIPO, que se
 * consulta en el catalogo `ledger_entry_types` (design D7/D8), y el gasto
 * nace en `expenses` (design D19).
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float:
 * el saldo contable se compara con `bccomp` a escala 4.
 */
final readonly class LedgerWriter
{
    public function __construct(private Connection $connection) {}

    /**
     * Escribe un asiento contable y devuelve el id de la cabecera.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El tipo del asiento se TOMA DEL CATALOGO: se resuelve
     *     `entry_type_id` por codigo en `ledger_entry_types` y solo si esta
     *     ACTIVO. Un codigo que no existe, o un tipo apagado, no son un
     *     asiento: fallan. El codigo NUNCA se escribe como literal en la
     *     cabecera, solo su id.
     *  2. Una linea es de UNA cuenta: no puede tener debito y credito a la
     *     vez, no puede tener los dos a cero y no puede tener importes
     *     negativos.
     *  3. El asiento CUADRA: la suma de los debitos es igual a la suma de los
     *     creditos, comparada con `bccomp` a escala 4 y SIN tolerancia. El
     *     importe del asiento (`amount`) es la suma de los debitos.
     *  4. La cabecera y las lineas se escriben en UNA SOLA transaccion. Si el
     *     llamante ya esta dentro de una transaccion (el checkout lo estara),
     *     Laravel anida con SAVEPOINT y el rollback del llamante se lleva el
     *     asiento entero; si se llama suelto, esta abre la suya.
     *
     * @param  string  $entryType  Codigo del tipo en `ledger_entry_types`.
     * @param  int  $storeId  Tienda del asiento.
     * @param  DateTimeImmutable  $occurredAt  Instante en que ocurrio el hecho.
     * @param  DateTimeImmutable  $businessDate  FECHA DE NEGOCIO: un dato del llamante, no un calculo.
     * @param  array<int, array{account: string, debit: string, credit: string, description?: string|null}>  $lines  Las lineas del asiento, una por cuenta.
     * @param  string|null  $referenceType  Que hecho origina el movimiento.
     * @param  int|null  $referenceId  Id de ese hecho.
     * @param  int|null  $actorId  Quien lo registra.
     * @param  string|null  $description  La descripcion de la cabecera del asiento.
     *
     * @throws RuntimeException Cuando el tipo no es valido, una linea no lo es,
     *                          o el asiento no cuadra.
     */
    public function write(
        string $entryType,
        int $storeId,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $businessDate,
        array $lines,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $actorId = null,
        ?string $description = null,
    ): int {
        return $this->connection->transaction(function () use (
            $entryType,
            $storeId,
            $occurredAt,
            $businessDate,
            $lines,
            $referenceType,
            $referenceId,
            $actorId,
            $description
        ): int {
            $entryTypeId = $this->resolveEntryType($entryType);
            $prepared = $this->prepareLines($lines);
            $amount = $this->balance($prepared);

            $entryId = $this->connection->table('ledger_entries')->insertGetId([
                'entry_type_id' => $entryTypeId,
                'store_id' => $storeId,
                'business_date' => $businessDate->format('Y-m-d'),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'amount' => $amount,
                // El instante viaja con su desplazamiento: `occurred_at` es
                // `timestamptz` y no puede perder la zona.
                'occurred_at' => $occurredAt->format('Y-m-d H:i:sP'),
                'description' => $description,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rows = [];

            foreach ($prepared as $line) {
                $rows[] = [
                    'ledger_entry_id' => $entryId,
                    'account_code' => $line['account'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'description' => $line['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $this->connection->table('ledger_lines')->insert($rows);

            return (int) $entryId;
        });
    }

    /**
     * El id del tipo de asiento en el catalogo, si el codigo existe y esta
     * activo.
     *
     * `is_active` es de INTERFAZ (permitir apagar un tipo), pero un tipo
     * apagado no admite asientos nuevos: apagarlo es retirar el tipo del
     * catalogo sin borrarlo.
     *
     * @throws RuntimeException Cuando el codigo no existe o esta inactivo.
     */
    private function resolveEntryType(string $code): int
    {
        $id = $this->connection->table('ledger_entry_types')
            ->where('code', $code)
            ->where('is_active', true)
            ->value('id');

        if ($id === null) {
            throw new RuntimeException(
                "The ledger entry type '{$code}' is not an active type of the catalog."
            );
        }

        return (int) $id;
    }

    /**
     * Valida y normaliza las lineas del asiento.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{account: string, debit: string, credit: string, description: string|null}>
     *
     * @throws RuntimeException Cuando una linea no es una linea contable valida.
     */
    private function prepareLines(array $lines): array
    {
        if ($lines === []) {
            throw new RuntimeException('A ledger entry needs at least one line.');
        }

        $prepared = [];

        foreach (array_values($lines) as $index => $line) {
            $account = trim((string) ($line['account'] ?? ''));

            if ($account === '') {
                throw new RuntimeException("The ledger line {$index} has no account code.");
            }

            $debit = BigDecimal::of($this->decimal($line['debit'] ?? '0'));
            $credit = BigDecimal::of($this->decimal($line['credit'] ?? '0'));
            $description = $line['description'] ?? null;

            if ($debit->isNegative() || $credit->isNegative()) {
                throw new RuntimeException("The ledger line {$index} has a negative amount.");
            }

            if (! $debit->isZero() && ! $credit->isZero()) {
                throw new RuntimeException(
                    "The ledger line {$index} is debit and credit at the same time."
                );
            }

            if ($debit->isZero() && $credit->isZero()) {
                throw new RuntimeException("The ledger line {$index} has no amount.");
            }

            $prepared[] = [
                'account' => $account,
                'debit' => (string) $debit,
                'credit' => (string) $credit,
                'description' => $description === null ? null : (string) $description,
            ];
        }

        return $prepared;
    }

    /**
     * El asiento CUADRA y el importe del asiento es la suma de los debitos.
     *
     * @param  array<int, array{debit: string, credit: string}>  $lines
     *
     * @throws RuntimeException Cuando los debitos no son iguales a los creditos.
     */
    private function balance(array $lines): string
    {
        $debits = BigDecimal::zero()->toScale(4);
        $credits = BigDecimal::zero()->toScale(4);

        foreach ($lines as $line) {
            $debits = $debits->plus($line['debit']);
            $credits = $credits->plus($line['credit']);
        }

        if (! $debits->isEqualTo($credits)) {
            throw new RuntimeException(
                "The ledger entry does not balance: debits {$debits} are not equal to credits {$credits}."
            );
        }

        return (string) $debits;
    }

    /**
     * Normaliza un importe a la escala de la columna.
     *
     * El importe llega como TEXTO decimal y sale como TEXTO decimal con 4
     * decimales. Un `float` se RECHAZA: un flotante no es dinero, y el
     * redondeo del motor de impuestos es half-up, no un truncamiento.
     *
     * @throws RuntimeException Cuando el importe no es un numero decimal.
     */
    private function decimal(mixed $value): string
    {
        if (is_float($value)) {
            throw new RuntimeException(
                'A ledger amount must be a decimal string, never a float.'
            );
        }

        $amount = is_string($value) ? trim($value) : (string) $value;

        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new RuntimeException("The ledger amount '{$amount}' is not a decimal number.");
        }

        return (string) BigDecimal::of($amount)->toScale(4, RoundingMode::HALF_UP);
    }
}
