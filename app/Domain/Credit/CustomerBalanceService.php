<?php

namespace App\Domain\Credit;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El credito del cliente: su saldo, derivado de sus movimientos.
 *
 * REGLA que fija este servicio: *el saldo del cliente se deriva de sus
 * movimientos*. El saldo NO es un contador: es la SUMA de los movimientos del
 * cliente en esa tienda (`customer_ledger_entries`). El legacy lo guardaba
 * en el cliente Y en su ficha de tienda: dos copias del mismo dato que se
 * desincronizaban.
 *
 * EL SIGNO va en el importe, nunca en el tipo: en
 * `customer_ledger_entries.amount` POSITIVO es lo que el cliente DEBE y
 * NEGATIVO es lo que ha pagado o devuelto. El `type` (`sale`, `payment`,
 * `return`, `adjustment`) dice QUE paso, no en que sentido.
 *
 * `customer_stores.balance` y `customer_stores.due` son un CACHE con
 * invariante, como `product_stores.quantity_in_stock` con los lotes: se
 * actualizan DESPUES de cada movimiento, en la MISMA transaccion, a partir
 * del saldo derivado. El cache se puede desfasar; la verdad no se desfasa.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class CustomerBalanceService
{
    /** La escala de las columnas: `numeric(25,4)`. */
    private const SCALE = 4;

    public function __construct(private Connection $connection) {}

    /**
     * El saldo del cliente en una tienda: la SUMA de sus movimientos.
     *
     * Es la VERDAD: sale de los movimientos, nunca de un contador.
     */
    public function balance(int $customerId, int $storeId): string
    {
        $suma = $this->connection->table('customer_ledger_entries')
            ->where('customer_id', $customerId)
            ->where('store_id', $storeId)
            ->sum('amount');

        return $this->decimal($suma ?? '0');
    }

    /**
     * Lo que el cliente DEBE: el max(0, saldo).
     *
     * Un saldo NEGATIVO es credito a favor del cliente: no se debe nada, y la
     * deuda es cero, no un numero negativo.
     */
    public function due(int $customerId, int $storeId): string
    {
        $balance = BigDecimal::of($this->balance($customerId, $storeId));

        return $balance->isNegative()
            ? (string) BigDecimal::zero()->toScale(self::SCALE, RoundingMode::HALF_UP)
            : (string) $balance;
    }

    /**
     * Registra un COBRO del cliente y devuelve el id del movimiento.
     *
     * REGLAS que fija este metodo:
     *
     *  1. Un cobro REDUCE la deuda: su importe es SIEMPRE NEGATIVO. El signo
     *     del llamante no decide el signo del movimiento: un cobro no es un
     *     reintegro, y un reintegro se registra con su propio tipo.
     *  2. El `balance_after` es el saldo DERIVADO tras el movimiento: el
     *     servicio lo RECALCULA desde los movimientos, no lo arrastra de un
     *     contador. Es la SUMA de todos los movimientos, este incluido.
     *  3. El movimiento y el refreshing del CACHE van en la MISMA
     *     transaccion: si el cobro se revierte, el cache tambien.
     *
     * @param  string  $amount  El importe cobrado, como texto decimal.
     * @param  string  $referenceNo  El comprobante del cobro.
     * @param  DateTimeImmutable  $entryDate  La fecha del movimiento.
     * @param  int|null  $saleId  La venta a la que se imputa el cobro.
     *
     * @throws RuntimeException Cuando el cliente no tiene ficha en la tienda.
     */
    public function registerPayment(
        int $customerId,
        int $storeId,
        string $amount,
        string $referenceNo,
        DateTimeImmutable $entryDate,
        ?int $saleId = null,
    ): int {
        $importe = BigDecimal::of($this->decimal($amount))
            ->abs()
            ->multipliedBy('-1')
            ->toScale(self::SCALE, RoundingMode::HALF_UP);

        return $this->connection->transaction(function () use (
            $customerId,
            $storeId,
            $importe,
            $referenceNo,
            $entryDate,
            $saleId
        ): int {
            // El credito del cliente vive en su ficha de tienda: sin ella no
            // hay donde dejar el cache del saldo.
            $this->assertFicha($customerId, $storeId);

            $entryId = (int) $this->connection->table('customer_ledger_entries')->insertGetId([
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'type' => 'payment',
                'reference_no' => $referenceNo,
                'ref_invoice_id' => $saleId,
                'amount' => (string) $importe,
                'entry_date' => $entryDate->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // El `balance_after` es el saldo DERIVADO tras el movimiento: se
            // recalcula DESDE los movimientos, con este ya dentro.
            $saldo = $this->balance($customerId, $storeId);

            $this->connection->table('customer_ledger_entries')
                ->where('id', $entryId)
                ->update([
                    'balance_after' => $saldo,
                    'updated_at' => now(),
                ]);

            // El cache se actualiza en la MISMA transaccion del movimiento.
            $this->refreshCache($customerId, $storeId);

            return $entryId;
        });
    }

    /**
     * La invariante del cache: coincide con el saldo DERIVADO.
     *
     * El cache puede desfasarse (un movimiento escrito fuera del servicio no
     * lo refresca); la verdad no. Por eso se COMPARA, no se supone.
     */
    public function holds(int $customerId, int $storeId): bool
    {
        $cache = $this->connection->table('customer_stores')
            ->where('customer_id', $customerId)
            ->where('store_id', $storeId)
            ->first();

        if ($cache === null) {
            return false;
        }

        $derivado = $this->balance($customerId, $storeId);

        return bccomp((string) $cache->balance, $derivado, self::SCALE) === 0
            && bccomp((string) $cache->due, $this->due($customerId, $storeId), self::SCALE) === 0;
    }

    /**
     * Refresca el cache desde el saldo derivado.
     */
    private function refreshCache(int $customerId, int $storeId): void
    {
        $this->connection->table('customer_stores')
            ->where('customer_id', $customerId)
            ->where('store_id', $storeId)
            ->update([
                'balance' => $this->balance($customerId, $storeId),
                'due' => $this->due($customerId, $storeId),
                'updated_at' => now(),
            ]);
    }

    /**
     * El cliente tiene ficha en la tienda.
     *
     * @throws RuntimeException Cuando no la tiene.
     */
    private function assertFicha(int $customerId, int $storeId): void
    {
        $existe = $this->connection->table('customer_stores')
            ->where('customer_id', $customerId)
            ->where('store_id', $storeId)
            ->exists();

        if (! $existe) {
            throw new RuntimeException(
                "El cliente {$customerId} no tiene ficha en la tienda {$storeId}: su credito en esa tienda no existe."
            );
        }
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
                'El importe del credito debe ser un texto decimal, nunca un float.'
            );
        }

        $amount = is_string($value) ? trim($value) : (string) $value;

        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new RuntimeException("El importe del credito '{$amount}' no es un numero decimal.");
        }

        return (string) BigDecimal::of($amount)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
