<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Reposicion del stock por lotes.
 *
 * La devolucion repone **el lote exacto** del que salio la linea de venta, que
 * se lee de la traza `sale_item_lot_consumptions`. NO repone el lote mas
 * antiguo disponible ni crea un lote nuevo: el legacy hacia las dos cosas, y
 * por eso sus lotes quedaban con estados incoherentes (P0 #10 del mapa
 * funcional).
 *
 * Si la traza no dice de que lote salio la linea, no hay a que reponer: es un
 * error de datos, no una operacion silenciosa.
 */
final readonly class LotReturner
{
    public function __construct(
        private Connection $connection,
        private StockInvariant $invariant,
    ) {}

    /**
     * Repone la cantidad indicada de una linea de venta.
     *
     * @param  string  $quantity  Cantidad a reponer, como decimal.
     * @return array<int, array{lot_id: int, quantity: string}> La traza de la reposicion.
     *
     * @throws RuntimeException Cuando la linea no tiene traza de consumo.
     */
    public function returnToLot(int $saleItemId, string $quantity): array
    {
        $wanted = (float) $quantity;

        if ($wanted <= 0) {
            return [];
        }

        $traza = [];

        $this->connection->transaction(function () use ($saleItemId, $wanted, &$traza): void {
            // De que lotes salio la linea, en orden inverso al consumo: se
            // repone primero el ultimo lote que se toco.
            $consumptions = $this->connection->table('sale_item_lot_consumptions')
                ->where('sale_item_id', $saleItemId)
                ->orderByDesc('id')
                ->get();

            if ($consumptions->isEmpty()) {
                throw new RuntimeException(
                    "La linea de venta {$saleItemId} no tiene traza de consumo: no hay lote que reponer."
                );
            }

            $remaining = $wanted;

            foreach ($consumptions as $consumption) {
                if ($remaining <= 0) {
                    break;
                }

                $taken = $remaining;

                $this->connection->table('inventory_lots')
                    ->where('id', $consumption->lot_id)
                    ->update([
                        'quantity_returned' => $this->connection->raw(
                            'quantity_returned + ' . $this->number($taken)
                        ),
                        'updated_at' => now(),
                    ]);

                $traza[] = [
                    'lot_id' => (int) $consumption->lot_id,
                    'quantity' => $this->number($taken),
                ];

                $remaining -= $taken;
            }

            // El cache se actualiza en la MISMA transaccion.
            $productId = (int) $consumptions->first()->product_id;
            $storeId = (int) $consumptions->first()->store_id;

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
