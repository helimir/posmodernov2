<?php

use App\Domain\Inventory\LotConsumer;
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

afterEach(function () {
    DB::statement('TRUNCATE sale_item_lot_consumptions, sale_items, sale_totals, sales,'
        .' inventory_lots, purchase_items, purchase_totals, purchases, product_stores,'
        .' products, stores RESTART IDENTITY CASCADE');
});

test('test_la_disponibilidad_del_lote_es_la_aritmetica_recibido_menos_consumido_mas_devuelto', function () {
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

test('test_el_stock_en_cache_iguala_la_suma_de_lotes_y_no_se_sobrevende_bajo_concurrencia', function () {
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

    // 4. Y BAJO CONCURRENCIA no se sobrevende. El cache se pone al dia antes
    //    de empezar: ahora la invariante se cumple y se va a comprobar que
    //    sigue cumpliendose con DOS sesiones a la vez.
    $invariant->refresh($product->id, $store->id);

    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $store->id,
        'sale_date' => '2026-11-03',
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $saleItemId = DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'product_id' => $product->id,
        'store_id' => $store->id,
        'quantity' => 4,
        'unit_price' => 20,
        'discount' => 0,
        'tax_rate_snapshot' => 16,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // DOS SESIONES: el bloqueo de fila solo es real entre sesiones distintas.
    $a = DB::connection('pgsql');
    $b = DB::connection('pgsql_b');

    // Si la segunda sesion ESPERARA al bloqueo, esta sentencia la cortaria a
    // los 3 segundos. Con SKIP LOCKED no espera: falla al instante.
    $b->statement("SET lock_timeout = '3s'");

    // 5. La sesion A TOMA el lote mas antiguo y lo bloquea, sin confirmar.
    $a->beginTransaction();

    $consumerA = new LotConsumer($a, new StockInvariant($a));

    $consumerA->consume($product->id, $store->id, $saleItemId, '4');

    // 6. La sesion B intenta consumir 8: el lote de A esta bloqueado (SKIP
    //    LOCKED lo salta), el otro solo da 5, y dice que no hay stock SIN
    //    esperar al bloqueo de A.
    $consumerB = new LotConsumer($b, new StockInvariant($b));

    $started = microtime(true);

    expect(fn () => $consumerB->consume($product->id, $store->id, $saleItemId, '8'))
        ->toThrow(RuntimeException::class);

    expect(microtime(true) - $started)->toBeLessThan(2.5, 'La segunda sesion no debe esperar al lote bloqueado.');

    // 7. No hubo sobreventa: el total consumido no excede lo disponible, la
    //    traza tiene una sola fila y la invariante SIGUE cumpliendose.
    expect((float) DB::table('inventory_lots')
        ->where('product_id', $product->id)->sum('available'))->toBe(7.0)
        ->and((float) DB::table('product_stores')
            ->where('product_id', $product->id)->value('quantity_in_stock'))->toBe(7.0)
        ->and(DB::table('sale_item_lot_consumptions')->count())->toBe(1)
        ->and($invariant->holds($product->id, $store->id))->toBeTrue();

    // 8. Y al soltar A, la invariante sigue cumpliendose: el consumo se
    //    deshace con su cache, y no queda ni consumo ni cambio de cache.
    $a->rollBack();

    expect((float) DB::table('inventory_lots')
        ->where('product_id', $product->id)->sum('available'))->toBe(11.0)
        ->and((float) DB::table('product_stores')
            ->where('product_id', $product->id)->value('quantity_in_stock'))->toBe(11.0)
        ->and(DB::table('sale_item_lot_consumptions')->count())->toBe(0)
        ->and($invariant->holds($product->id, $store->id))->toBeTrue();
});
