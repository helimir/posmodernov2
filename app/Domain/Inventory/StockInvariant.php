<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Connection;

/**
 * La invariante permanente del stock.
 *
 * `product_stores.quantity_in_stock` es un CACHE: debe ser igual a la suma de
 * los lotes disponibles del producto en esa tienda. El cache se actualiza en la
 * MISMA transaccion del consumo, para que no haya una ventana en la que el
 * stock que se ve no sea el stock que hay.
 *
 * El legacy no tenia esta invariancia: sus contadores por cuenta se
 * desincronizaban al borrar una operacion (P0 #8 del mapa funcional).
 */
final readonly class StockInvariant
{
    public function __construct(private Connection $connection) {}

    /**
     * El stock cacheado coincide con la suma de los lotes disponibles.
     */
    public function holds(int $productId, int $storeId): bool
    {
        $cached = $this->cachedStock($productId, $storeId);
        $lots = $this->availableInLots($productId, $storeId);

        return bccomp((string) $cached, (string) $lots, 4) === 0;
    }

    /**
     * El stock cacheado de un producto en una tienda.
     */
    public function cachedStock(int $productId, int $storeId): string
    {
        $value = $this->connection->table('product_stores')
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->value('quantity_in_stock');

        return $value === null ? '0' : (string) $value;
    }

    /**
     * La suma de los lotes disponibles: la verdad.
     */
    public function availableInLots(int $productId, int $storeId): string
    {
        $value = $this->connection->table('inventory_lots')
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->sum('available');

        return $value === null ? '0' : (string) $value;
    }

    /**
     * Refresca el cache desde los lotes. Se llama DENTRO de la transaccion del
     * consumo: si el consumo se revierte, el cache tambien.
     */
    public function refresh(int $productId, int $storeId): void
    {
        $this->connection->table('product_stores')
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->update([
                'quantity_in_stock' => $this->availableInLots($productId, $storeId),
                'updated_at' => now(),
            ]);
    }
}
