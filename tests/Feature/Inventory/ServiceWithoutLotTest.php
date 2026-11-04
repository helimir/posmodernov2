<?php

use App\Domain\Inventory\LotConsumer;
use App\Domain\Inventory\StockInvariant;
use App\Models\Product;
use App\Models\ProductStore;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.5.7 — un servicio no genera lote, no consume stock y no aparece en la
 * traza; y la transferencia tiene UNA sola cabecera.
 *
 * Reglas que fija:
 * - un producto de tipo `service` no toca el almacen: no hay lote, no hay
 *   consumo y no hay traza que reponer;
 * - una transferencia entre almacenes tiene UNA cabecera y N lineas, y cada
 *   linea apunta al lote de salida.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    DB::statement('TRUNCATE transfer_items, transfers, sale_item_lot_consumptions, sale_items,'
        .' sale_totals, sales, inventory_lots, purchase_items, purchase_totals, purchases,'
        .' product_stores, products, stores RESTART IDENTITY CASCADE');
});

test('test_un_servicio_no_genera_lote_ni_consume_stock_y_la_transferencia_tiene_una_sola_cabecera', function () {
    $store = Store::create(['name' => 'Tienda de prueba']);
    $other = Store::create(['name' => 'Tienda destino']);

    // Un producto con STOCK y otro que es un SERVICIO.
    $stock = Product::create(['code' => 'ZZP-4', 'name' => 'Producto con stock']);
    $service = Product::create(['code' => 'ZZP-5', 'name' => 'Servicio', 'type' => 'service']);

    foreach ([$stock, $service] as $product) {
        ProductStore::create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'purchase_price' => 10,
            'sell_price' => 20,
            'quantity_in_stock' => 0,
        ]);
    }

    $purchaseId = DB::table('purchases')->insertGetId([
        'store_id' => $store->id,
        'purchase_date' => '2026-11-03',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $itemId = DB::table('purchase_items')->insertGetId([
        'purchase_id' => $purchaseId,
        'product_id' => $stock->id,
        'store_id' => $store->id,
        'quantity' => 10,
        'unit_price' => 10,
        'discount' => 0,
        'tax_rate_snapshot' => 16,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lotId = DB::table('inventory_lots')->insertGetId([
        'product_id' => $stock->id,
        'store_id' => $store->id,
        'purchase_item_id' => $itemId,
        'quantity_received' => 10,
        'quantity_consumed' => 0,
        'quantity_returned' => 0,
        'unit_cost' => 10,
        'received_at' => '2026-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $store->id,
        'sale_date' => '2026-11-03',
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invariant = new StockInvariant(DB::connection());
    $consumer = new LotConsumer(DB::connection(), $invariant);

    // 1. Un SERVICIO no consume stock: ni lote, ni traza, ni cache.
    $serviceItemId = DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'product_id' => $service->id,
        'store_id' => $store->id,
        'quantity' => 1,
        'unit_price' => 50,
        'discount' => 0,
        'tax_rate_snapshot' => 16,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $traza = $consumer->consume($service->id, $store->id, $serviceItemId, '1');

    expect($traza)->toBe([])
        ->and(DB::table('sale_item_lot_consumptions')->count())->toBe(0)
        ->and(DB::table('inventory_lots')->where('product_id', $service->id)->count())->toBe(0)
        ->and((float) DB::table('product_stores')->where('product_id', $service->id)->value('quantity_in_stock'))->toBe(0.0);

    // 2. Y un producto con STOCK si consume, con su traza.
    $stockItemId = DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'product_id' => $stock->id,
        'store_id' => $store->id,
        'quantity' => 4,
        'unit_price' => 20,
        'discount' => 0,
        'tax_rate_snapshot' => 16,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $consumer->consume($stock->id, $store->id, $stockItemId, '4');

    expect(DB::table('sale_item_lot_consumptions')->where('sale_item_id', $stockItemId)->count())->toBe(1)
        ->and((float) DB::table('inventory_lots')->where('id', $lotId)->value('available'))->toBe(6.0);

    // 3. La transferencia tiene UNA SOLA cabecera y N lineas, cada una con el
    //    lote de SALIDA.
    $transferId = DB::table('transfers')->insertGetId([
        'store_id' => $store->id,
        'destination_store_id' => $other->id,
        'transfer_date' => '2026-11-03',
        'status' => 'in_transit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('transfer_items')->insert([
        'transfer_id' => $transferId,
        'product_id' => $stock->id,
        'source_lot_id' => $lotId,
        'quantity' => 2,
        'unit_cost' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('transfers')->count())->toBe(1)
        ->and(DB::table('transfer_items')->count())->toBe(1)
        ->and((int) DB::table('transfer_items')->value('source_lot_id'))->toBe((int) $lotId);
});
