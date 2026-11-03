<?php

use App\Domain\Inventory\StockInvariant;
use App\Models\Product;
use App\Models\ProductStore;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.5.1 — el lote no tiene estado: su disponibilidad es aritmética.
 *
 * Regla que fija: *no hay estado que pueda quedar desincronizado*. El legacy
 * codificaba la disponibilidad con un enum (`stock`/`active`/`sold`) más dos
 * contadores que había que mantener sincronizados, y su `UPDATE` de consumo no
 * filtraba por el id del lote: tocaba TODOS los lotes activos del producto
 * (defecto H1, ADR-0004).
 */
uses(WithoutWrappingTransaction::class);

test('test_el_lote_no_tiene_estado_y_su_disponibilidad_es_aritmetica', function () {
    $columns = Schema::getColumnListing('inventory_lots');

    // 1. NO hay columna de estado.
    expect($columns)->not->toContain('status')
        ->and($columns)->not->toContain('stock_status')
        ->and($columns)->toContain('quantity_received')
        ->and($columns)->toContain('quantity_consumed')
        ->and($columns)->toContain('quantity_returned')
        ->and($columns)->toContain('available');

    // 2. `available` es GENERADA: nadie la escribe a mano.
    $generated = DB::selectOne(
        'select is_generated, generation_expression from information_schema.columns'
        ." where table_schema = 'public' and table_name = 'inventory_lots' and column_name = 'available'"
    );

    expect($generated)->not->toBeNull()
        ->and($generated->is_generated)->toBe('ALWAYS')
        ->and($generated->generation_expression)->toContain('quantity_received')
        ->and($generated->generation_expression)->toContain('quantity_consumed')
        ->and($generated->generation_expression)->toContain('quantity_returned');

    // 3. Y la traza de consumos: sin ella, una devolucion no sabria a que
    //    lote reponer.
    expect(Schema::getColumnListing('sale_item_lot_consumptions'))
        ->toContain('sale_item_id')
        ->toContain('lot_id')
        ->toContain('product_id')
        ->toContain('quantity');
});

test('test_el_stock_cacheado_coincide_con_la_suma_de_los_lotes_disponibles', function () {
    $store = Store::create(['name' => 'Tienda de prueba']);
    $product = Product::create(['code' => 'ZZP-1', 'name' => 'Producto de prueba']);

    ProductStore::create([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'purchase_price' => 10,
        'sell_price' => 20,
        'quantity_in_stock' => 0,
    ]);

    // Dos lotes: 10 y 5, cada uno abierto por su linea de compra.
    $purchaseId = DB::table('purchases')->insertGetId([
        'store_id' => $store->id,
        'purchase_date' => '2026-11-03',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([10, 5] as $index => $quantity) {
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
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $invariant = new StockInvariant(DB::connection());

    // La suma de los lotes es la verdad: 15.
    expect($invariant->availableInLots($product->id, $store->id))->toBe('15.0000');

    // El cache dice 0: la invariante NO se cumple todavia.
    expect($invariant->holds($product->id, $store->id))->toBeFalse();

    // Al refrescar el cache desde los lotes, la invariante se cumple.
    $invariant->refresh($product->id, $store->id);

    expect($invariant->holds($product->id, $store->id))->toBeTrue()
        ->and($invariant->cachedStock($product->id, $store->id))->toBe('15.0000');

    // Y consumed se calcula SOLO: no hay estado que sincronizar.
    $lotId = DB::table('inventory_lots')->orderBy('id')->value('id');

    DB::table('inventory_lots')->where('id', $lotId)->update(['quantity_consumed' => 4]);

    expect($invariant->availableInLots($product->id, $store->id))->toBe('11.0000');
});
