<?php

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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
 * La REGLA FIFO vive en `LotFifoAllocator`, que es la unica implementacion y
 * que usa tambien la transferencia: una regla de consumo que viviera en dos
 * sitios acabaria con el mismo lote consumido dos veces. Aqui solo se escribe
 * la TRAZA de la VENTA (`sale_item_lot_consumptions`), que es lo que permite
 * devolver el stock al lote EXACTO del que salio.
 *
 * El legacy no hacía esto: su `UPDATE` de consumo no filtraba por el id del
 * lote y tocaba TODOS los lotes activos del producto (defecto H1, ADR-0004).
 */
final readonly class LotConsumer
{
    /**
     * El allocator del FIFO.
     *
     * Es un algoritmo SIN ESTADO sobre la MISMA conexion, asi que se
     * construye aqui y no se inyecta: inyectarlo permitiria consumir en una
     * conexion distinta de la que escribe la traza.
     */
    private LotFifoAllocator $allocator;

    public function __construct(
        private Connection $connection,
        private StockInvariant $invariant,
    ) {
        $this->allocator = new LotFifoAllocator($connection);
    }

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
        // Un SERVICIO no genera lote, no consume stock y no aparece en la
        // traza: no hay nada que reponer porque nunca salio de un almacen.
        if ($this->isService($productId)) {
            return [];
        }

        $wanted = BigDecimal::of($quantity)->toScale(4, RoundingMode::HALF_UP);

        if ($wanted->isZero() || $wanted->isNegative()) {
            return [];
        }

        // Un UNICO lote tomado: si el consumo y la traza se hicieran en
        // transacciones distintas, otro proceso podria consumir entre medias.
        $traza = [];

        $this->connection->transaction(function () use ($productId, $storeId, $saleItemId, $wanted, &$traza): void {
            $traza = $this->allocator->allocate($productId, $storeId, (string) $wanted);

            foreach ($traza as $taken) {
                $this->connection->table('sale_item_lot_consumptions')->insert([
                    'sale_item_id' => $saleItemId,
                    'lot_id' => $taken['lot_id'],
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'quantity' => $taken['quantity'],
                    'unit_cost' => $taken['unit_cost'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // El cache se actualiza en la MISMA transaccion del consumo.
            $this->invariant->refresh($productId, $storeId);
        });

        return $traza;
    }

    /**
     * ¿El producto es un servicio? Un servicio no tiene almacen.
     */
    private function isService(int $productId): bool
    {
        return $this->connection->table('products')->where('id', $productId)->value('type') === 'service';
    }
}
