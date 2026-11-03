<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Consumo FIFO del stock por lotes.
 *
 * El orden es por ANTIGÜEDAD y, a igualdad, por id: `ORDER BY received_at
 * ASC, id ASC`. El desempate por id es lo que hace el consumo determinista: sin
 * él, dos ventas concurrentes podrían tomar lotes distintos de la misma
 * fecha y el resultado dependería del plan de ejecución.
 *
 * El bloqueo es `FOR UPDATE SKIP LOCKED`: si otro proceso tiene el lote
 * tomado, este lo salta y sigue con el siguiente. Con 5 unidades disponibles
 * y dos ventas concurrentes de 4, la que encuentra lotes toma lo que hay y la
 * que no encuentra dice "stock insuficiente" en vez de esperar. Sin venta
 * por encima del stock, nunca por sobreventa.
 *
 * El legacy no hacía esto: su `UPDATE` de consumo no filtraba por el id del
 * lote y tocaba TODOS los lotes activos del producto (defecto H1, ADR-0004).
 */
final readonly class LotConsumer
{
    public function __construct(
        private Connection $connection,
        private StockInvariant $invariant,
    ) {}

    /**
     * Consume la cantidad indicada del producto en la tienda.
     *
     * @param  string  $quantity  Cantidad a consumir, como decimal.
     * @return array<int, array{lot_id: int, quantity: string, unit_cost: string}> La traza por lote.
     *
     * @throws RuntimeException Cuando no hay stock suficiente.
     */
    public function consume(int $productId, int $storeId, int $saleItemId, string $quantity): array
    {
        $wanted = (float) $quantity;

        if ($wanted <= 0) {
            return [];
        }

        // Un UNICO lote tomado: si el consumo y la traza se hicieran en
        // transacciones distintas, otro proceso podria consumir entre medias.
        $traza = [];

        $this->connection->transaction(function () use ($productId, $storeId, $saleItemId, $wanted, &$traza): void {
            $lots = $this->connection->select(
                'SELECT id, available, unit_cost FROM inventory_lots'
                .' WHERE product_id = ? AND store_id = ? AND available > 0'
                .' ORDER BY received_at ASC, id ASC'
                .' FOR UPDATE SKIP LOCKED',
                [$productId, $storeId]
            );

            $remaining = $wanted;

            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }

                $available = (float) $lot->available;

                if ($available <= 0) {
                    continue;
                }

                // Multi-lote: el lote se agota y se sigue con el siguiente.
                $taken = $available <= $remaining ? $available : $remaining;

                $this->connection->table('inventory_lots')
                    ->where('id', $lot->id)
                    ->update([
                        'quantity_consumed' => $this->connection->raw(
                            'quantity_consumed + '.$this->number($taken)
                        ),
                        'updated_at' => now(),
                    ]);

                $this->connection->table('sale_item_lot_consumptions')->insert([
                    'sale_item_id' => $saleItemId,
                    'lot_id' => $lot->id,
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'quantity' => $this->number($taken),
                    'unit_cost' => $lot->unit_cost,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $traza[] = [
                    'lot_id' => (int) $lot->id,
                    'quantity' => $this->number($taken),
                    'unit_cost' => (string) $lot->unit_cost,
                ];

                $remaining -= $taken;
            }

            if ($remaining > 0.000001) {
                // Se deshace todo: la transaccion se revierte sola.
                throw new RuntimeException(
                    'Stock insuficiente: no hay lotes disponibles para la cantidad solicitada.'
                );
            }

            // El cache se actualiza en la MISMA transaccion del consumo.
            $this->invariant->refresh($productId, $storeId);
        });

        return $traza;
    }

    /**
     * Un decimal con la escala de la columna, sin notacion cientifica.
     */
    private function number(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
