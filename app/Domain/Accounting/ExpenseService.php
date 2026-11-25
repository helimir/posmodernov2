<?php

namespace App\Domain\Accounting;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El GASTO: su fuente de verdad y el asiento que deriva de ella.
 *
 * REGLA que fija esta clase: *`expenses` es la FUENTE DE VERDAD del gasto, y
 * el asiento es la CONSECUENCIA* (design D19, spec `contabilidad-caja`). El
 * gasto NACE en `expenses`, y su asiento se deriva de el. Al reves (que es lo
 * que hacia el legacy) un gasto registrado sin movimiento bancario se perdia, y
 * un movimiento bancario sin gasto inventaba uno.
 *
 * EL ASIENTO es de tipo `expense` (el codigo del catalogo
 * `ledger_entry_types`), y sus dos lineas son:
 *
 *  - el DEBE, a la cuenta de GASTO de la categoria: la cuenta contable la
 *    DECLARA la categoria (`expense_categories.account_code`), porque es un
 *    dato del negocio y cada tienda decide en que cuenta carga sus gastos;
 *  - el HABER, a la cuenta que FINANCIA el pago: la caja de la tienda si se
 *    paga en efectivo, o la cuenta bancaria si se paga desde el banco.
 *
 * Y LA REGLA DE LA CAJA: *el saldo en curso de la caja del dia solo se mueve
 * con el EFECTIVO*. No es una convencion que el dominio recuerda: sale de la
 * cuenta que se acredita. `CashDay` deriva el saldo del dia de las lineas de
 * la cuenta de efectivo de la tienda, asi que un gasto pagado desde el BANCO
 * acredita la cuenta del banco y NO aparece en el saldo del cajon: el dinero
 * nunca estuvo ahi.
 *
 * Y LA ANULACION: el gasto se MARCA, no se borra (el `status` pasa a
 * `cancelled`, con su motivo y su responsable). Una fuente de verdad que se
 * borra deja al libro contable con un movimiento sin hecho que lo respalde, y
 * un gasto que se corrige es un hecho, no un borrado. La anulacion escribe
 * su propio asiento, de tipo `adjustment`, que es el ESPEJO del asiento del
 * gasto: las lineas del original, con el debe y el haber cambiados. El espejo
 * es exacto aunque despues la categoria cambie de cuenta.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class ExpenseService
{
    public function __construct(private Connection $connection) {}

    /**
     * REGISTRA un gasto y escribe su asiento.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El IMPORTE es POSITIVO: un gasto de cero no es un gasto, y una
     *     linea a cero no es una linea contable.
     *  2. El gasto necesita una CATEGORIA con cuenta contable: sin ella no
     *     hay donde cargarlo.
     *  3. La FECHA DE NEGOCIO es un DATO del llamante, y el dinero que sale
     *     en efectivo solo sale de una caja ABIERTA de esa fecha: un gasto en
     *     efectivo de un dia que no esta abierto moveria un saldo que no
     *     existe. Un gasto pagado desde el BANCO no necesita caja abierta:
     *     el banco no tiene dias.
     *  4. El GASTO Y SU ASIENTO se escriben en la MISMA transaccion: si el
     *     asiento falla, el gasto no existe.
     *
     * @param  string  $amount  El importe del gasto. Texto decimal.
     * @param  string  $paymentType  `cash` o `bank`.
     * @param  int|null  $bankAccountId  La cuenta bancaria que paga, si el
     *                                   pago es desde el banco.
     * @param  int|null  $expenseCategoryId  La categoria del gasto.
     * @param  DateTimeImmutable|null  $occurredAt  El instante en que ocurrio;
     *                                              si no viene, la fecha de negocio.
     * @return int El id del gasto.
     *
     * @throws RuntimeException Cuando el gasto no se puede registrar.
     */
    public function register(
        int $storeId,
        string $amount,
        DateTimeImmutable $businessDate,
        string $paymentType,
        ?int $bankAccountId = null,
        ?int $expenseCategoryId = null,
        ?string $description = null,
        ?string $reference = null,
        ?int $actorId = null,
        ?DateTimeImmutable $occurredAt = null,
    ): int {
        $importe = $this->importe($amount);

        if ($importe->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new RuntimeException(
                'El importe de un gasto tiene que ser mayor que cero: un gasto de cero no es un gasto.'
            );
        }

        // Las CUENTAS se resuelven ANTES de escribir: un gasto sin cuenta
        // donde asentar no es un gasto.
        $cuentaDelGasto = $this->cuentaDeLaCategoria($expenseCategoryId);
        $cuentaQuePaga = $this->cuentaQuePaga($storeId, $paymentType, $bankAccountId);

        // El dinero que sale en efectivo sale de una caja ABIERTA.
        if ($paymentType === TreasuryAccounts::PAGO_CAJA) {
            (new CashRegisterService($this->connection))
                ->registerFor($storeId, $businessDate);
        }

        $instante = $occurredAt ?? $businessDate;

        return $this->connection->transaction(function () use (
            $storeId,
            $importe,
            $businessDate,
            $paymentType,
            $bankAccountId,
            $expenseCategoryId,
            $description,
            $reference,
            $actorId,
            $instante,
            $cuentaDelGasto,
            $cuentaQuePaga
        ): int {
            $expenseId = (int) $this->connection->table('expenses')->insertGetId([
                'store_id' => $storeId,
                'expense_category_id' => $expenseCategoryId,
                'amount' => (string) $importe,
                'description' => $description,
                'reference' => $reference,
                'business_date' => $businessDate->format('Y-m-d'),
                'incurred_at' => $instante->format('Y-m-d H:i:sP'),
                'payment_type' => $paymentType,
                'bank_account_id' => $bankAccountId,
                'status' => 'recorded',
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // EL ASIENTO: el debe, a la cuenta de gasto de la categoria; el
            // haber, a la cuenta que financia el pago.
            (new LedgerWriter($this->connection))->write(
                entryType: 'expense',
                storeId: $storeId,
                occurredAt: $instante,
                businessDate: $businessDate,
                lines: [
                    ['account' => $cuentaDelGasto, 'debit' => (string) $importe, 'credit' => '0'],
                    ['account' => $cuentaQuePaga, 'debit' => '0', 'credit' => (string) $importe],
                ],
                referenceType: 'expense',
                referenceId: $expenseId,
                actorId: $actorId,
                description: $description === null || trim($description) === ''
                    ? 'Gasto'
                    : 'Gasto: '.$description,
            );

            return $expenseId;
        });
    }

    /**
     * ANULA un gasto: lo MARCA y escribe el asiento del ajuste.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El MOTIVO es OBLIGATORIO: un gasto anulado sin motivo es un gasto
     *     que nadie sabe por que se toco.
     *  2. El gasto NO se borra: se marca (`status`, `cancelled_at`,
     *     `cancelled_by` y `cancellation_reason`). `expenses` es la fuente de
     *     verdad del gasto, y una fuente de verdad que se borra deja al libro
     *     un movimiento sin hecho que lo respalde.
     *  3. Un gasto YA ANULADO no se anula dos veces: la anulacion es un
     *     HECHO, y un hecho repetido es un segundo reintegro en el libro.
     *  4. EL AJUSTE ES EL ESPEJO del asiento del gasto: las mismas lineas con
     *     el debe y el haber cambiados. Por eso el dinero vuelve a la cuenta
     *     que lo pago (a la caja si se pago en efectivo), y el saldo del dia
     *     vuelve a ser el que era.
     *
     * @param  string  $reason  POR QUE se anula el gasto. Obligatorio.
     * @param  int|null  $actorId  Quien lo anula.
     *
     * @throws RuntimeException Cuando el gasto no existe, ya esta anulado, no
     *                          tiene motivo, o no tiene asiento que ajustar.
     */
    public function cancel(int $expenseId, string $reason, ?int $actorId = null): void
    {
        $motivo = trim($reason);

        if ($motivo === '') {
            throw new RuntimeException(
                'Un gasto se anula con un MOTIVO: un gasto anulado sin motivo es un gasto '
                .'que nadie sabe por que se toco.'
            );
        }

        $gasto = $this->connection->table('expenses')->where('id', $expenseId)->first();

        if ($gasto === null) {
            throw new RuntimeException("El gasto {$expenseId} no existe.");
        }

        if ((string) $gasto->status === 'cancelled') {
            throw new RuntimeException("El gasto {$expenseId} ya esta anulado: no se anula dos veces.");
        }

        $this->connection->transaction(function () use ($expenseId, $motivo, $actorId, $gasto): void {
            $espejo = $this->espejoDelAsiento($expenseId, (int) $gasto->store_id);

            (new LedgerWriter($this->connection))->write(
                entryType: 'adjustment',
                storeId: (int) $gasto->store_id,
                occurredAt: new DateTimeImmutable('now'),
                businessDate: new DateTimeImmutable((string) $gasto->business_date),
                lines: $espejo,
                referenceType: 'expense',
                referenceId: $expenseId,
                actorId: $actorId,
                description: 'Anulacion del gasto '.$expenseId.': '.$motivo,
            );

            // El gasto se MARCA: no se borra.
            $this->connection->table('expenses')->where('id', $expenseId)->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancellation_reason' => $motivo,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * La cuenta de GASTO de una categoria.
     *
     * REGLA que fija este metodo: *la cuenta la DECLARA la categoria*. Sin
     * cuenta no hay donde cargar el gasto, y el fallo lo dice: que categoria
     * es y que hay que hacer.
     *
     * @throws RuntimeException Cuando la categoria no existe o no tiene cuenta.
     */
    private function cuentaDeLaCategoria(?int $expenseCategoryId): string
    {
        if ($expenseCategoryId === null) {
            throw new RuntimeException(
                'Un gasto necesita una categoria: sin ella no hay que cuenta cargarlo.'
            );
        }

        $categoria = $this->connection->table('expense_categories')
            ->where('id', $expenseCategoryId)
            ->first(['id', 'code', 'name', 'account_code']);

        if ($categoria === null) {
            throw new RuntimeException("La categoria de gasto {$expenseCategoryId} no existe.");
        }

        $codigo = trim((string) ($categoria->account_code ?? ''));

        if ($codigo === '') {
            throw new RuntimeException(
                "La categoria de gasto '{$categoria->code}' no tiene cuenta contable: "
                .'sin ella el gasto no tiene donde cargarse.'
            );
        }

        return $codigo;
    }

    /**
     * La cuenta que FINANCIA el pago: la caja o la cuenta bancaria.
     *
     * @throws RuntimeException Cuando la cuenta no se puede resolver.
     */
    private function cuentaQuePaga(int $storeId, string $paymentType, ?int $bankAccountId): string
    {
        return (new TreasuryAccounts($this->connection))
            ->financia($storeId, $paymentType, $bankAccountId);
    }

    /**
     * EL ESPEJO de un asiento: las mismas lineas con el debe y el haber
     * cambiados.
     *
     * REGLA que fija este metodo: *la reversa de un movimiento es su espejo*.
     * Es exacto aunque despues la categoria cambie de cuenta, y por eso no
     * recalcula nada: lee lo que se escribio.
     *
     * @return list<array{account: string, debit: string, credit: string}>
     *
     * @throws RuntimeException Cuando el movimiento no tiene asiento.
     */
    private function espejoDelAsiento(int $expenseId, int $storeId): array
    {
        $asiento = $this->connection->table('ledger_entries')
            ->where('reference_type', 'expense')
            ->where('reference_id', $expenseId)
            ->where('store_id', $storeId)
            ->orderBy('id')
            ->first(['id']);

        if ($asiento === null) {
            throw new RuntimeException(
                "El gasto {$expenseId} no tiene asiento: no se puede anular lo que no esta en el libro."
            );
        }

        $lineas = $this->connection->table('ledger_lines')
            ->where('ledger_entry_id', (int) $asiento->id)
            ->orderBy('id')
            ->get(['account_code', 'debit', 'credit']);

        $espejo = [];

        foreach ($lineas as $linea) {
            $espejo[] = [
                'account' => (string) $linea->account_code,
                'debit' => (string) $linea->credit,
                'credit' => (string) $linea->debit,
            ];
        }

        if ($espejo === []) {
            throw new RuntimeException(
                "El gasto {$expenseId} no tiene lineas contables: no se puede anular."
            );
        }

        return $espejo;
    }

    /**
     * Normaliza un importe a la escala de la columna.
     *
     * @throws RuntimeException Cuando el importe no es un numero decimal.
     */
    private function importe(mixed $value): BigDecimal
    {
        return PosAmount::decimal($value);
    }
}
