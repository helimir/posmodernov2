<?php

use App\Domain\Inventory\LotConsumer;
use App\Domain\Inventory\StockInvariant;
use App\Models\Product;
use App\Models\ProductStore;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.5.8 — dos ventas concurrentes no sobrevenden el stock.
 *
 * Regla que fija: *5 unidades disponibles y dos ventas concurrentes de 4 ⇒ una
 * consume y la otra recibe error de stock insuficiente*. Ni sobreventa, ni
 * espera: el bloqueo es `FOR UPDATE SKIP LOCKED`, así que la segunda venta no
 * espera al lote bloqueado, lo salta y dice que no hay stock.
 *
 * El test usa DOS SESIONES de PostgreSQL (`pgsql` y `pgsql_b`): el bloqueo de
 * fila sólo es real entre sesiones distintas, y con una sola sesión el
 * `SKIP LOCKED` no se probaría nada.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    DB::statement('TRUNCATE sale_item_lot_consumptions, sale_items, sale_totals, sales,'
        .' inventory_lots, purchase_items, purchase_totals, purchases, product_stores,'
        .' products, stores RESTART IDENTITY CASCADE');
});

test('test_dos_ventas_concurrentes_no_sobrevenden_el_stock', function () {
    $store = Store::create(['name' => 'Tienda de prueba']);
    $product = Product::create(['code' => 'ZZP-3', 'name' => 'Producto con poco stock']);

    ProductStore::create([
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

    $itemId = DB::table('purchase_items')->insertGetId([
        'purchase_id' => $purchaseId,
        'product_id' => $product->id,
        'store_id' => $store->id,
        'quantity' => 5,
        'unit_price' => 10,
        'discount' => 0,
        'tax_rate_snapshot' => 16,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // UN SOLO lote de 5 unidades: las dos ventas compiten por el mismo.
    DB::table('inventory_lots')->insert([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'purchase_item_id' => $itemId,
        'quantity_received' => 5,
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

    // Si la segunda venta ESPERARA al bloqueo, esta sentencia la cortaria a
    // los 3 segundos. Con SKIP LOCKED no espera: falla al instante.
    $b->statement("SET lock_timeout = '3s'");

    // 1. La sesion A TOMA el lote y lo bloquea, sin confirmar.
    $a->beginTransaction();

    $locked = $a->select(
        'SELECT id FROM inventory_lots WHERE product_id = ? AND store_id = ? AND available > 0 FOR UPDATE',
        [$product->id, $store->id]
    );

    expect($locked)->toHaveCount(1);

    // 2. La sesion B intenta vender 4: SKIP LOCKED salta el lote bloqueado, no
    //    encuentra ninguno y dice que no hay stock. Y NO ESPERA.
    $consumerB = new LotConsumer($b, new StockInvariant($b));

    $started = microtime(true);

    expect(fn () => $consumerB->consume($product->id, $store->id, $saleItemId, '4'))
        ->toThrow(RuntimeException::class);

    $elapsed = microtime(true) - $started;

    expect($elapsed)->toBeLessThan(2.5, 'La segunda venta no debe esperar al lote bloqueado.');

    // 3. Y no hubo sobreventa: el stock sigue intacto.
    expect((float) DB::table('inventory_lots')->value('available'))->toBe(5.0)
        ->and(DB::table('sale_item_lot_consumptions')->count())->toBe(0);

    // 4. A suelta el bloqueo.
    $a->rollBack();

    // 5. Ahora B si puede: toma 4 y quedan 1. Sin sobreventa, porque solo hay 5.
    $consumerB->consume($product->id, $store->id, $saleItemId, '4');

    expect((float) DB::table('inventory_lots')->value('available'))->toBe(1.0)
        ->and((float) DB::table('product_stores')->where('product_id', $product->id)->value('quantity_in_stock'))->toBe(1.0)
        ->and(DB::table('sale_item_lot_consumptions')->count())->toBe(1);

    // 6. Y una TERCERA venta de 4 ya no cabe: el stock esta agotado.
    expect(fn () => $consumerB->consume($product->id, $store->id, $saleItemId, '4'))
        ->toThrow(RuntimeException::class);

    expect((float) DB::table('inventory_lots')->value('available'))->toBe(1.0);
});
