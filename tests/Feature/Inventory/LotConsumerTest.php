<?php

use App\Domain\Inventory\LotConsumer;
use App\Domain\Inventory\LotReturner;
use App\Domain\Inventory\StockInvariant;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.5.4 / 0.5.5 — el consumo FIFO toma los lotes por antigüedad y la
 * devolución repone el lote EXACTO.
 *
 * Reglas que fija:
 * - el FIFO ordena por `received_at ASC, id ASC`: el desempate por id hace el
 *   consumo determinista;
 * - el consumo es MULTI-LOTE: si un lote no alcanza, se sigue con el
 *   siguiente;
 * - la devolución repone el lote del que salio la línea, NO el lote más
 *   antiguo disponible ni uno nuevo.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    DB::statement('TRUNCATE sale_item_lot_consumptions, sale_items, sale_totals, sales,'
        .' inventory_lots, purchase_items, purchase_totals, purchases, product_stores,'
        .' products, stores RESTART IDENTITY CASCADE');
});

/**
 * @return array{0: int, 1: int} store y producto
 */
function escenario(): array
{
    $store = App\Models\Store::create(['name' => 'Tienda de prueba']);
    $product = App\Models\Product::create(['code' => 'ZZP-2', 'name' => 'Producto con lotes']);

    App\Models\ProductStore::create([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'purchase_price' => 10,
        'sell_price' => 20,
        'quantity_in_stock' => 0,
    ]);

    $purchaseId = DB::table('purchases')->insertGetId([
        'store_id' => $store->id,
        'purchase_date' => '2026-11-03',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Tres lotes, con antiguedades distintas: el mas viejo es el de 10.
    foreach ([[10, '2026-01-01'], [5, '2026-02-01'], [7, '2026-03-01']] as [$quantity, $received]) {
        $itemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => $quantity,
            'unit_price' => 10,
            'discount' => 0,
            'tax_rate_snapshot' => 16,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_lots')->insert([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'purchase_item_id' => $itemId,
            'quantity_received' => $quantity,
            'quantity_consumed' => 0,
            'quantity_returned' => 0,
            'unit_cost' => 10,
            'received_at' => $received,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return [$store->id, $product->id];
}

test('test_el_consumo_fifo_toma_los_lotes_por_antiguedad_y_la_devolucion_repone_el_lote_exacto', function () {
    [$storeId, $productId] = escenario();

    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $storeId,
        'sale_date' => '2026-11-03',
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $saleItemId = DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'product_id' => $productId,
        'store_id' => $storeId,
        'quantity' => 12,
        'unit_price' => 20,
        'discount' => 0,
        'tax_rate_snapshot' => 16,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invariant = new StockInvariant(DB::connection());
    $consumer = new LotConsumer(DB::connection(), $invariant);

    // 1. Consumir 12 toma MULTI-LOTE: 10 del mas viejo (2026-01-01) y 2 del
    //    siguiente (2026-02-01). El tercero no se toca.
    $traza = $consumer->consume($productId, $storeId, $saleItemId, '12');

    expect($traza)->toHaveCount(2)
        ->and($traza[0]['quantity'])->toBe('10.0000')
        ->and($traza[1]['quantity'])->toBe('2.0000');

    $lots = DB::table('inventory_lots')->orderBy('received_at')->get();

    expect((float) $lots[0]->available)->toBe(0.0)
        ->and((float) $lots[1]->available)->toBe(3.0)
        ->and((float) $lots[2]->available)->toBe(7.0);

    // 2. El cache se actualizo en la misma transaccion del consumo.
    expect($invariant->holds($productId, $storeId))->toBeTrue()
        ->and((float) $invariant->cachedStock($productId, $storeId))->toBe(10.0);

    // 3. La traza dice de que lotes salio la linea.
    $consumptions = DB::table('sale_item_lot_consumptions')->where('sale_item_id', $saleItemId)->get();

    expect($consumptions)->toHaveCount(2)
        ->and((int) $consumptions[0]->lot_id)->toBe((int) $lots[0]->id)
        ->and((int) $consumptions[1]->lot_id)->toBe((int) $lots[1]->id);

    // 4. Stock insuficiente: no se sobrevende.
    $otherItemId = DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'product_id' => $productId,
        'store_id' => $storeId,
        'quantity' => 99,
        'unit_price' => 20,
        'discount' => 0,
        'tax_rate_snapshot' => 16,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $consumer->consume($productId, $storeId, $otherItemId, '99'))
        ->toThrow(RuntimeException::class);

    // Y el rollback del consumo fallido no dejo nada a medias.
    expect((float) $invariant->cachedStock($productId, $storeId))->toBe(10.0);

    // 5. La devolucion repone el LOTE EXACTO: el segundo lote (2026-02-01),
    //    no el mas antiguo disponible ni uno nuevo.
    $returner = new LotReturner(DB::connection(), $invariant);

    $reposicion = $returner->returnToLot($saleItemId, '2');

    expect($reposicion)->toHaveCount(1)
        ->and((int) $reposicion[0]['lot_id'])->toBe((int) $lots[1]->id);

    $lots = DB::table('inventory_lots')->orderBy('received_at')->get();

    expect((float) $lots[0]->quantity_returned)->toBe(0.0)
        ->and((float) $lots[1]->quantity_returned)->toBe(2.0)
        ->and((float) $lots[2]->quantity_returned)->toBe(0.0);

    // 6. Y el cache vuelve a cuadrar con los lotes.
    expect($invariant->holds($productId, $storeId))->toBeTrue()
        ->and((float) $invariant->cachedStock($productId, $storeId))->toBe(12.0);
});
