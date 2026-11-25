<?php

namespace App\Domain\Accounting;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El INGRESO: su fuente de verdad y el asiento que deriva de ella.
 *
 * REGLA que fija esta clase: *un ingreso es un movimiento de dinero que
 * ENTRA en la tienda y que no viene de una venta*. El CATALOGO de origenes
 * (`income_sources`) dice de donde viene, y la cuenta contable del origen
 * (`income_sources.account_code`) dice donde se asienta: es un DATO del
 * negocio, como los roles del plan.
 *
 * EL ASIENTO es de tipo `bank_movement` (el codigo del catalogo
 * `ledger_entry_types`), y sus dos lineas son:
 *
 *  - el DEBE, a la cuenta que RECIBE el dinero: la caja de la tienda si el
 *    ingreso entra en el cajon, o la cuenta bancaria si entra en el banco;
 *  - el HABER, a la cuenta de INGRESO del origen.
 *
 * Y POR QUE EL INGRESO ES UN MOVIMIENTO DE TESORERIA: el catalogo de tipos
 * tiene SIETE tipos y ninguno es "ingreso", y no se inventa uno (el catalogo
 * es un conjunto cerrado). El ingreso es, exactamente, un movimiento de
 * dinero que entra; lo que lo DISTINGUE de un traspaso entre cuentas es que
 * su contrapartida es una cuenta de INGRESO, mientras que en un traspaso las
 * dos lineas son de tesoreria.
 *
 * Y LA ANULACION: el ingreso se MARCA, no se borra, y su ajuste es el
 * ESPEJO de su asiento (las mismas lineas con el debe y el haber
 * cambiados). Es la misma regla que la del gasto, porque es el mismo hecho:
 * un movimiento corregido se corrige, no desaparece.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class IncomeService
{
    public function __construct(private Connection $connection) {}

    /**
     * REGISTRA un ingreso y escribe su asiento.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El IMPORTE es POSITIVO: un ingreso de cero no es un ingreso, y una
     *     linea a cero no es una linea contable.
     *  2. El ingreso necesita un ORIGEN con cuenta contable: sin ella no hay
     *     donde abonarlo.
     *  3. La FECHA DE NEGOCIO es un DATO del llamante, y el dinero que entra
     *     en el cajon solo entra en una caja ABIERTA de esa fecha.
     *  4. El INGRESO Y SU ASIENTO se escriben en la MISMA transaccion.
     *
     * @param  string  $amount  El importe del ingreso. Texto decimal.
     * @param  string  $paymentType  `cash` o `bank`: donde entra el dinero.
     * @param  int|null  $bankAccountId  La cuenta bancaria que recibe, si el
     *                                   dinero entra en el banco.
     * @param  int|null  $incomeSourceId  El origen del ingreso.
     * @param  DateTimeImmutable|null  $occurredAt  El instante en que ocurrio.
     * @return int El id del ingreso.
     *
     * @throws RuntimeException Cuando el ingreso no se puede registrar.
     */
    public function register(
        int $storeId,
        string $amount,
        DateTimeImmutable $businessDate,
        string $paymentType,
        ?int $bankAccountId = null,
        ?int $incomeSourceId = null,
        ?string $description = null,
        ?string $reference = null,
        ?int $actorId = null,
        ?DateTimeImmutable $occurredAt = null,
    ): int {
        $importe = PosAmount::decimal($amount);

        if ($importe->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new RuntimeException(
                'El importe de un ingreso tiene que ser mayor que cero: un ingreso de cero no es un ingreso.'
            );
        }

        // Las CUENTAS se resuelven ANTES de escribir.
        $cuentaDelIngreso = $this->cuentaDelOrigen($incomeSourceId);
        $cuentaQueRecibe = (new TreasuryAccounts($this->connection))
            ->financia($storeId, $paymentType, $bankAccountId);

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
            $incomeSourceId,
            $description,
            $reference,
            $actorId,
            $instante,
            $cuentaDelIngreso,
            $cuentaQueRecibe
        ): int {
            $incomeId = (int) $this->connection->table('incomes')->insertGetId([
                'store_id' => $storeId,
                'income_source_id' => $incomeSourceId,
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

            // EL ASIENTO: el debe, a la cuenta que recibe el dinero; el haber,
            // a la cuenta de ingreso del origen.
            (new LedgerWriter($this->connection))->write(
                entryType: 'bank_movement',
                storeId: $storeId,
                occurredAt: $instante,
                businessDate: $businessDate,
                lines: [
                    ['account' => $cuentaQueRecibe, 'debit' => (string) $importe, 'credit' => '0'],
                    ['account' => $cuentaDelIngreso, 'debit' => '0', 'credit' => (string) $importe],
                ],
                referenceType: 'income',
                referenceId: $incomeId,
                actorId: $actorId,
                description: $description === null || trim($description) === ''
                    ? 'Ingreso'
                    : 'Ingreso: '.$description,
            );

            return $incomeId;
        });
    }

    /**
     * ANULA un ingreso: lo MARCA y escribe el asiento del ajuste.
     *
     * Las MISMAS reglas que la anulacion del gasto: el motivo es
     * obligatorio, el ingreso no se borra, un ingreso ya anulado no se anula
     * dos veces, y el ajuste es el espejo de su asiento.
     *
     * @param  string  $reason  POR QUE se anula el ingreso. Obligatorio.
     * @param  int|null  $actorId  Quien lo anula.
     *
     * @throws RuntimeException Cuando el ingreso no existe, ya esta anulado,
     *                          no tiene motivo, o no tiene asiento que ajustar.
     */
    public function cancel(int $incomeId, string $reason, ?int $actorId = null): void
    {
        $motivo = trim($reason);

        if ($motivo === '') {
            throw new RuntimeException(
                'Un ingreso se anula con un MOTIVO: un ingreso anulado sin motivo es un ingreso '
                .'que nadie sabe por que se toco.'
            );
        }

        $ingreso = $this->connection->table('incomes')->where('id', $incomeId)->first();

        if ($ingreso === null) {
            throw new RuntimeException("El ingreso {$incomeId} no existe.");
        }

        if ((string) $ingreso->status === 'cancelled') {
            throw new RuntimeException("El ingreso {$incomeId} ya esta anulado: no se anula dos veces.");
        }

        $this->connection->transaction(function () use ($incomeId, $motivo, $actorId, $ingreso): void {
            $espejo = $this->espejoDelAsiento($incomeId, (int) $ingreso->store_id);

            (new LedgerWriter($this->connection))->write(
                entryType: 'adjustment',
                storeId: (int) $ingreso->store_id,
                occurredAt: new DateTimeImmutable('now'),
                businessDate: new DateTimeImmutable((string) $ingreso->business_date),
                lines: $espejo,
                referenceType: 'income',
                referenceId: $incomeId,
                actorId: $actorId,
                description: 'Anulacion del ingreso '.$incomeId.': '.$motivo,
            );

            $this->connection->table('incomes')->where('id', $incomeId)->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancellation_reason' => $motivo,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * La cuenta de INGRESO de un origen.
     *
     * REGLA que fija este metodo: *la cuenta la DECLARA el origen*. Sin
     * cuenta no hay donde abonarlo, y el fallo lo dice.
     *
     * @throws RuntimeException Cuando el origen no existe o no tiene cuenta.
     */
    private function cuentaDelOrigen(?int $incomeSourceId): string
    {
        if ($incomeSourceId === null) {
            throw new RuntimeException(
                'Un ingreso necesita un origen: sin el no hay que cuenta abonarlo.'
            );
        }

        $origen = $this->connection->table('income_sources')
            ->where('id', $incomeSourceId)
            ->first(['id', 'code', 'name', 'account_code']);

        if ($origen === null) {
            throw new RuntimeException("El origen de ingreso {$incomeSourceId} no existe.");
        }

        $codigo = trim((string) ($origen->account_code ?? ''));

        if ($codigo === '') {
            throw new RuntimeException(
                "El origen de ingreso '{$origen->code}' no tiene cuenta contable: "
                .'sin ella el ingreso no tiene donde abonarse.'
            );
        }

        return $codigo;
    }

    /**
     * EL ESPEJO de un asiento: las mismas lineas con el debe y el haber
     * cambiados.
     *
     * @return list<array{account: string, debit: string, credit: string}>
     *
     * @throws RuntimeException Cuando el movimiento no tiene asiento.
     */
    private function espejoDelAsiento(int $incomeId, int $storeId): array
    {
        $asiento = $this->connection->table('ledger_entries')
            ->where('reference_type', 'income')
            ->where('reference_id', $incomeId)
            ->where('store_id', $storeId)
            ->orderBy('id')
            ->first(['id']);

        if ($asiento === null) {
            throw new RuntimeException(
                "El ingreso {$incomeId} no tiene asiento: no se puede anular lo que no esta en el libro."
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
                "El ingreso {$incomeId} no tiene lineas contables: no se puede anular."
            );
        }

        return $espejo;
    }
}
