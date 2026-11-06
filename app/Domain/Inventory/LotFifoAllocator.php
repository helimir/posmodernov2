<?php

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * La regla FIFO del stock por lotes, SIN traza.
 *
 * Este collaborator es la UNICA implementacion del consumo FIFO: la usan la
 * venta (`LotConsumer`) y la transferencia (`TransferService`). La regla es una
 * sola y no puede vivir en dos sitios: si el FIFO de la venta y el de la
 * transferencia se desincronizasen, el mismo lote se consumiria dos veces.
 *
 * El orden es por ANTIGUEDAD y, a igualdad, por id: `ORDER BY received_at
 * ASC, id ASC`. El desempate por id es lo que hace el consumo determinista:
 * sin el, dos operaciones concurrentes podrian tomar lotes distintos de la
 * misma fecha y el resultado dependeria del plan de ejecucion.
 *
 * El bloqueo es `FOR UPDATE SKIP LOCKED`: si otro proceso tiene el lote
 * tomado, este lo salta y sigue con el siguiente. Con 5 unidades disponibles
 * y dos salidas concurrentes de 4, la que encuentra lotes toma lo que hay y
 * la que no encuentra dice "stock insuficiente" en vez de esperar. Sin salida
 * por encima del stock, nunca por sobrevente.
 *
 * Aqui NO se escribe la traza: este collaborator solo dice DE QUE LOTE sale
 * CUANTO y CUANTO CUESTA. La traza la escribe el llamador, en la tabla que
 * le corresponde: una venta escribe en `sale_item_lot_consumptions` y una
 * transferencia en `transfer_item_lot_consumptions`. Una transferencia no
 * tiene venta, asi que no puede usar la traza de las ventas.
 *
 * El legacy no hacia esto: su `UPDATE` de consumo no filtraba por el id del
 * lote y tocaba TODOS los lotes activos del producto (defecto H1, ADR-0004).
 */
final readonly class LotFifoAllocator
{
    /**
     * La escala de las cantidades: la de la columna `numeric(25,4)`.
     */
    private const SCALE = 4;

    public function __construct(private Connection $connection) {}

    /**
     * Consume la cantidad indicada del producto en la tienda.
     *
     * El consumo y el descuento del lote ocurren en la MISMA transaccion: si
     * se hicieran en transacciones distintas, otro proceso podria consumir
     * entre medias lo que este ya ha tomado.
     *
     * @param  string  $quantity  Cantidad a consumir, como decimal.
     * @return list<array{lot_id: int, quantity: string, unit_cost: string}> El plan por lote.
     *
     * @throws RuntimeException Cuando no hay stock suficiente.
     */
    public function allocate(int $productId, int $storeId, string $quantity): array
    {
        $wanted = BigDecimal::of($quantity)->toScale(self::SCALE, RoundingMode::HALF_UP);

        if ($wanted->isZero() || $wanted->isNegative()) {
            return [];
        }

        $taken = [];

        $this->connection->transaction(function () use ($productId, $storeId, $wanted, &$taken): void {
            $lots = $this->connection->select(
                'SELECT id, available, unit_cost FROM inventory_lots'
                .' WHERE product_id = ? AND store_id = ? AND available > 0'
                .' ORDER BY received_at ASC, id ASC'
                .' FOR UPDATE SKIP LOCKED',
                [$productId, $storeId]
            );

            $remaining = $wanted;

            foreach ($lots as $lot) {
                if ($remaining->isZero()) {
                    break;
                }

                $available = BigDecimal::of((string) $lot->available)->toScale(self::SCALE, RoundingMode::HALF_UP);

                if ($available->isZero() || $available->isNegative()) {
                    continue;
                }

                // Multi-lote: el lote se agota y se sigue con el siguiente.
                $here = $available->isLessThanOrEqualTo($remaining) ? $available : $remaining;

                $this->connection->table('inventory_lots')
                    ->where('id', $lot->id)
                    ->update([
                        'quantity_consumed' => $this->connection->raw(
                            'quantity_consumed + '.(string) $here
                        ),
                        'updated_at' => now(),
                    ]);

                $taken[] = [
                    'lot_id' => (int) $lot->id,
                    'quantity' => (string) $here,
                    'unit_cost' => $this->decimal($lot->unit_cost),
                ];

                $remaining = $remaining->minus($here);
            }

            if ($remaining->isPositive()) {
                // Se deshace todo: la transaccion se revierte sola.
                throw new RuntimeException(
                    'Stock insuficiente: no hay lotes disponibles para la cantidad solicitada.'
                );
            }
        });

        return $taken;
    }

    /**
     * Un decimal con la escala de la columna, sin notacion cientifica.
     */
    private function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
