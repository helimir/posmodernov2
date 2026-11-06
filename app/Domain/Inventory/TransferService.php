<?php

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * La transferencia entre almacenes.
 *
 * UNA transferencia es UN MOVIMIENTO: una sola cabecera en `transfers` y N
 * lineas en `transfer_items`. El legacy generaba una cabecera de compra (y una
 * de `transfers`) POR CADA ARTICULO, de modo que el listado de compras se
 * multiplicaba (P0 #17 del mapa funcional).
 *
 * El despacho (que sale del almacen ORIGEN) y la recepcion (que entra en el
 * almacen DESTINO) son DOS MOMENTOS DISTINTOS, y por eso son DOS operaciones:
 *
 * - `dispatch()` mueve el stock SALIENTE: consume el lote de origen mas
 *   antiguo (la regla FIFO, la misma de la venta), escribe la traza en
 *   `transfer_item_lot_consumptions` y deja la cabecera en `in_transit`. El
 *   stock ya no esta en el origen, pero todavia no esta en el destino: esta en
 *   el camino.
 * - `receive()` crea el stock DESTINO: un lote nuevo que sabe de que lote de
 *   SALIDA vino (`inventory_lots.source_lot_id`), con el coste de ese lote.
 *   El lote de destino lo crea la RECEPCION, no el despacho (M29).
 *
 * Las dos operaciones son ATOMICAS: todo el cuerpo vive dentro de UNA
 * transaccion. Si no hay stock suficiente en el origen, no queda cabecera, ni
 * linea, ni consumo, ni cambio de cache: el legacy escribia la cabecera y
 * luego fallaba al descontar (P0 #10).
 */
final readonly class TransferService
{
    /**
     * La escala de las cantidades y de los costes: la de `numeric(25,4)`.
     */
    private const SCALE = 4;

    public function __construct(
        private Connection $connection,
        private LotFifoAllocator $allocator,
        private StockInvariant $invariant,
    ) {}

    /**
     * Despacha el stock del origen al camino: la cabecera y el stock que sale.
     *
     * @param  array<int, array{product_id: int, quantity: string}>  $lines  Las lineas del despacho.
     * @param  int|null  $actorId  El responsable de la operacion. El esquema
     *                             `transfers` (M29) no tiene columna de actor y
     *                             este trabajo no inventa una: el parametro se
     *                             acepta por simetria con el resto del dominio y
     *                             no se persiste.
     *
     * @throws RuntimeException Cuando la tienda origen es la misma que la
     *                          destino, cuando una linea pide una cantidad no
     *                          positiva o cuando no hay stock suficiente.
     */
    public function dispatch(
        int $sourceStoreId,
        int $destinationStoreId,
        array $lines,
        DateTimeImmutable $transferDate,
        ?string $referenceNo = null,
        ?int $actorId = null,
    ): int {
        if ($sourceStoreId === $destinationStoreId) {
            throw new RuntimeException(
                "Una transferencia a la misma tienda ({$sourceStoreId}) no es una transferencia."
            );
        }

        return $this->connection->transaction(function () use ($sourceStoreId, $destinationStoreId, $lines, $transferDate, $referenceNo): int {
            // UNA SOLA CABECERA para toda la transferencia: el legacy hacia
            // una por cada articulo.
            $transferId = (int) $this->connection->table('transfers')->insertGetId([
                'store_id' => $sourceStoreId,
                'destination_store_id' => $destinationStoreId,
                'reference_no' => $referenceNo,
                'transfer_date' => $transferDate->format('Y-m-d'),
                'status' => 'in_transit',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lines as $line) {
                $productId = (int) $line['product_id'];
                $quantity = $this->decimal((string) $line['quantity']);

                if ($quantity->isZero() || $quantity->isNegative()) {
                    throw new RuntimeException(
                        "La linea del producto {$productId} pide una cantidad no positiva: no hay stock que mover."
                    );
                }

                // El consumo FIFO: el stock sale del lote mas ANTIGUO
                // disponible. Si no alcanza, la excepcion revierte la
                // transferencia entera.
                $taken = $this->allocator->allocate($productId, $sourceStoreId, (string) $quantity);

                foreach ($taken as $lot) {
                    // Una linea de `transfer_items` POR LOTE consumido: si la
                    // cantidad se reparte entre varios lotes, hay varias lineas,
                    // cada una con su lote de salida y su coste.
                    $transferItemId = (int) $this->connection->table('transfer_items')->insertGetId([
                        'transfer_id' => $transferId,
                        'product_id' => $productId,
                        'source_lot_id' => $lot['lot_id'],
                        'quantity' => $lot['quantity'],
                        'unit_cost' => $lot['unit_cost'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // La traza del consumo. Una transferencia NO es una venta:
                    // no puede usar `sale_item_lot_consumptions`.
                    $this->connection->table('transfer_item_lot_consumptions')->insert([
                        'transfer_item_id' => $transferItemId,
                        'lot_id' => $lot['lot_id'],
                        'product_id' => $productId,
                        'store_id' => $sourceStoreId,
                        'quantity' => $lot['quantity'],
                        'unit_cost' => $lot['unit_cost'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // El cache del origen, en la MISMA transaccion del consumo.
                $this->invariant->refresh($productId, $sourceStoreId);
            }

            return $transferId;
        });
    }

    /**
     * Recibe la transferencia: el stock entra en el almacen destino.
     *
     * El lote de destino lo crea la RECEPCION (M29), no el despacho: mientras
     * la transferencia esta `in_transit`, el stock esta en el camino.
     *
     * @param  int|null  $actorId  El responsable de la operacion. El esquema
     *                             `transfers` (M29) no tiene columna de actor y
     *                             este trabajo no inventa una: el parametro se
     *                             acepta por simetria con el resto del dominio y
     *                             no se persiste.
     *
     * @throws RuntimeException Cuando la transferencia no existe o no esta en
     *                          transito.
     */
    public function receive(int $transferId, ?int $actorId = null): void
    {
        $this->connection->transaction(function () use ($transferId): void {
            $transfer = $this->connection->table('transfers')->where('id', $transferId)->first();

            if ($transfer === null) {
                throw new RuntimeException("La transferencia {$transferId} no existe.");
            }

            if ((string) $transfer->status !== 'in_transit') {
                throw new RuntimeException(
                    "La transferencia {$transferId} no esta en transito (estado: {$transfer->status}): no se puede recibir."
                );
            }

            $sourceStoreId = (int) $transfer->store_id;
            $destinationStoreId = (int) $transfer->destination_store_id;

            $items = $this->connection->table('transfer_items')
                ->where('transfer_id', $transferId)
                ->orderBy('id')
                ->get();

            foreach ($items as $item) {
                $productId = (int) $item->product_id;

                // La ficha del producto en la tienda DESTINO. Si no existe,
                // nace copiando la del origen: el stock que llega a un almacen
                // donde el producto no estaba dado de alta tiene que existir en
                // su ficha, o el cache de stock no tendria donde apoyarse.
                $this->ensureProductSheet($productId, $sourceStoreId, $destinationStoreId);

                // El LOTE DE DESTINO: sabe de que lote de SALIDA vino y a que
                // coste salio. No viene de ninguna compra, asi que no tiene
                // linea de compra que lo origine.
                $this->connection->table('inventory_lots')->insert([
                    'product_id' => $productId,
                    'store_id' => $destinationStoreId,
                    'purchase_item_id' => null,
                    'source_lot_id' => $item->source_lot_id,
                    'quantity_received' => $item->quantity,
                    'quantity_consumed' => 0,
                    'quantity_returned' => 0,
                    'unit_cost' => $item->unit_cost,
                    'received_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // El cache del destino, en la MISMA transaccion de la
                // recepcion.
                $this->invariant->refresh($productId, $destinationStoreId);
            }

            $this->connection->table('transfers')->where('id', $transferId)->update([
                'status' => 'received',
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * La ficha del producto (`product_stores`) en la tienda destino.
     *
     * El legacy (`_inc/transfer.php:59-121`) creaba la ficha del destino si no
     * existia, copiando los datos comerciales del origen. Se conserva esa
     * regla: el cache del stock vive en esa ficha, asi que sin ella el stock
     * que llega seria invisible en el destino.
     */
    private function ensureProductSheet(int $productId, int $sourceStoreId, int $destinationStoreId): void
    {
        $exists = $this->connection->table('product_stores')
            ->where('product_id', $productId)
            ->where('store_id', $destinationStoreId)
            ->exists();

        if ($exists) {
            return;
        }

        $source = $this->connection->table('product_stores')
            ->where('product_id', $productId)
            ->where('store_id', $sourceStoreId)
            ->first();

        if ($source === null) {
            throw new RuntimeException(
                "El producto {$productId} no tiene ficha en la tienda origen {$sourceStoreId}: "
                .'no se puede recibir la transferencia.'
            );
        }

        $this->connection->table('product_stores')->insert([
            'product_id' => $productId,
            'store_id' => $destinationStoreId,
            'tax_category_id' => $source->tax_category_id,
            'purchase_price' => $source->purchase_price,
            'sell_price' => $source->sell_price,
            'quantity_in_stock' => 0,
            'status' => $source->status,
            'sort_order' => $source->sort_order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Una cantidad como decimal con la escala de la columna.
     */
    private function decimal(string $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
