<?php

namespace App\Domain\Accounting;

use App\Models\PaymentMethod;
use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;

/**
 * El DIA DE CAJA: las cifras que se DERIVAN de los asientos y de los pagos.
 *
 * REGLA que fija esta clase: *el saldo EN CURSO de la caja se deriva de los
 * asientos de ESA fecha de negocio*. No es un contador, no es una columna y
 * no se escribe: se LEE de los asientos, y por eso no puede quedar
 * desfasado de lo que el libro dice.
 *
 * LAS CUATRO CIFRAS, Y DE DONDE SALE CADA UNA:
 *
 *  1. EL SALDO DE PARTIDA (`opening_balance`): un DATO. El dinero que hay en
 *     la caja al empezar, contado a mano. Vive en `cash_registers`.
 *  2. EL SALDO EN CURSO: un DERIVADO. La suma de los debitos menos los
 *     creditos de la cuenta de efectivo de la tienda, en los asientos de esa
 *     fecha de negocio. Una cuenta de efectivo es un ACTIVO, y su saldo es
 *     debito menos credito.
 *  3. EL ESPERADO: el saldo de partida MAS el saldo en curso. Es lo que
 *     deberia haber en la caja si nadie se equivoca.
 *  4. EL SALDO CONTADO (`closing_balance`): otro DATO. El conteo de una caja
 *     es un hecho fisico. Y el DESCUADRE es el contado menos el esperado, CON
 *     SU SIGNO: un descuadre de cero es una caja cuadrada, y cualquier otra
 *     cosa se ve.
 *
 * LA FECHA DE NEGOCIO es un DATO del llamante (`DateTimeImmutable`), nunca un
 * `DAY()/MONTH()/YEAR()` calculado: el legacy filtraba asi (trampa 62), y
 * partia el dia a la medianoche del servidor en vez de al del negocio.
 *
 * LOS IMPORTES viajan como TEXTO decimal (`'120.0000'`), nunca como `float`:
 * las columnas son `numeric(25,4)` y un flotante no es un dinero.
 */
final readonly class CashDay
{
    public function __construct(private Connection $connection) {}

    /**
     * El SALDO EN CURSO: los debitos menos los creditos de la cuenta de
     * efectivo de la tienda, en los asientos de ESA fecha de negocio.
     *
     * REGLA que fija este metodo: *el saldo en curso se deriva de los
     * asientos de la fecha de negocio*. El filtro es por el par
     * `(store_id, business_date)`, que es la CLAVE NATURAL del registro de
     * caja: un movimiento cae en el registro de SU fecha de negocio, no en el
     * de "hoy".
     *
     * @param  int  $storeId  La tienda de la caja.
     * @param  DateTimeImmutable  $businessDate  Fecha de negocio: un dato del llamante.
     * @param  string  $cashAccount  El codigo de la cuenta de efectivo de la tienda.
     */
    public function runningBalance(int $storeId, DateTimeImmutable $businessDate, string $cashAccount): string
    {
        $movimientos = $this->connection->table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->where('ledger_entries.store_id', $storeId)
            ->where('ledger_entries.business_date', $businessDate->format('Y-m-d'))
            ->where('ledger_lines.account_code', $cashAccount)
            ->selectRaw(
                'COALESCE(SUM(ledger_lines.debit), 0) AS debe, '
                .'COALESCE(SUM(ledger_lines.credit), 0) AS haber'
            )
            ->first();

        $debe = PosAmount::decimal($movimientos->debe ?? '0');
        $haber = PosAmount::decimal($movimientos->haber ?? '0');

        // Una cuenta de efectivo es un ACTIVO: su saldo es debito menos
        // credito.
        return (string) $debe->minus($haber)->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * El ESPERADO: el saldo de partida MAS el saldo en curso.
     *
     * @param  string  $openingBalance  El saldo de partida. Texto decimal.
     * @param  string  $runningBalance  El saldo en curso. Texto decimal.
     */
    public function expected(string $openingBalance, string $runningBalance): string
    {
        return (string) PosAmount::decimal($openingBalance)
            ->plus(PosAmount::decimal($runningBalance))
            ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * El DESCUADRE: el saldo contado menos el esperado, CON SU SIGNO.
     *
     * Un descuadre de cero es una caja cuadrada, y cualquier otra cosa se
     * ve: por eso el signo no se esconde, y por eso el resultado NO es un
     * numero absoluto.
     *
     * @param  string  $countedBalance  El saldo contado. Texto decimal.
     * @param  string  $expectedBalance  El saldo esperado. Texto decimal.
     */
    public function difference(string $countedBalance, string $expectedBalance): string
    {
        return (string) PosAmount::decimal($countedBalance)
            ->minus(PosAmount::decimal($expectedBalance))
            ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * LO QUE ENTRO EN LA CAJA por cada medio de pago, en esa fecha de
     * negocio.
     *
     * REGLA que fija este metodo: *un pago de tipo CREDITO no es dinero de la
     * caja*. Es DEUDA, no cobro, y el cajero no lo cuenta: se marca como tal
     * (`is_cash` a falso) y se ve, pero no suma al total de efectivo.
     *
     * Es el CONTRASTE del cajero: lo que dice el sistema contra lo que
     * cuenta.
     *
     * @param  int  $storeId  La tienda de la caja.
     * @param  DateTimeImmutable  $businessDate  Fecha de negocio: un dato del llamante.
     * @return list<array{id: int|null, name: string|null, type: string, amount: string, is_cash: bool}>
     */
    public function receivedByPaymentMethod(int $storeId, DateTimeImmutable $businessDate): array
    {
        $filas = $this->connection->table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.store_id', $storeId)
            ->where('sales.sale_date', $businessDate->format('Y-m-d'))
            ->groupBy('sale_payments.payment_method_id')
            ->orderBy('sale_payments.payment_method_id')
            ->selectRaw(
                'sale_payments.payment_method_id as id, '
                .'SUM(sale_payments.amount) as amount'
            )
            ->get();

        if ($filas->isEmpty()) {
            return [];
        }

        $ids = [];

        foreach ($filas as $fila) {
            if ($fila->id !== null) {
                $ids[] = (int) $fila->id;
            }
        }

        $metodos = $this->paymentMethods($ids);

        $pagos = [];

        foreach ($filas as $fila) {
            $id = $fila->id === null ? null : (int) $fila->id;
            $metodo = $id === null ? null : ($metodos[$id] ?? null);

            // El TIPO es DATO de la fila, y su DEFECTO es el efectivo: un
            // medio de pago sin tipo declarado cobra en efectivo.
            $tipo = $metodo === null || $metodo->type === null
                ? 'cash'
                : (string) $metodo->type;

            $pagos[] = [
                'id' => $id,
                'name' => $metodo === null ? null : (string) $metodo->name,
                'type' => $tipo,
                'amount' => PosAmount::text($fila->amount),
                'is_cash' => $tipo !== PaymentMethod::TYPE_CREDIT,
            ];
        }

        return $pagos;
    }

    /**
     * El TOTAL DE EFECTIVO: lo que entro en la caja y es dinero de la caja.
     *
     * Lo que NO es de credito. Un pago a credito es deuda, y el cajero no lo
     * cuenta: por eso el total que se compara con el conteo excluye lo que
     * nunca estuvo en el cajon.
     *
     * @param  list<array{id: int|null, name: string|null, type: string, amount: string, is_cash: bool}>  $pagos
     */
    public function cashTotal(array $pagos): string
    {
        $total = BigDecimal::zero()->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);

        foreach ($pagos as $pago) {
            if ($pago['is_cash'] !== true) {
                continue;
            }

            $total = $total->plus(PosAmount::decimal($pago['amount']));
        }

        return (string) $total->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * Los MEDIOS DE PAGO del catalogo, por id.
     *
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function paymentMethods(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $filas = $this->connection->table('payment_methods')
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'type']);

        $metodos = [];

        foreach ($filas as $fila) {
            $metodos[(int) $fila->id] = $fila;
        }

        return $metodos;
    }
}
