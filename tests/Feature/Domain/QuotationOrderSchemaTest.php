<?php

use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.5.3 — cotizaciones y pedidos, como entidades DISTINTAS.
 *
 * Regla que fija: *el pedido en espera del POS no es un pedido comercial*. El
 * legacy tenia una sola tabla `quotation_info` con un `is_order` que decidia
 * que era, y los pedidos aparecian en el listado de cotizaciones. Aqui son
 * dos tablas con la misma forma, y el pedido en espera del POS tiene una
 * TERCERA: `held_orders`.
 */
test('test_una_cotizacion_y_un_pedido_son_entidades_distintas', function () {
    // 1. Las dos entidades son tablas distintas, cada una con su desglose.
    foreach (['quotations', 'quotation_items', 'quotation_totals', 'orders', 'order_items', 'order_totals'] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue("Falta la tabla {$tabla}.");
    }

    // 2. El discriminador `is_order` desaparecio: ya no hay una tabla que sea
    //    las dos cosas.
    expect(Schema::getColumnListing('quotations'))->not->toContain('is_order')
        ->and(Schema::getColumnListing('orders'))->not->toContain('is_order');

    // 3. Y el pedido en espera del POS tiene SU PROPIA tabla: no es un pedido
    //    comercial.
    expect(Schema::hasTable('held_orders'))->toBeTrue()
        ->and(Schema::hasTable('held_order_items'))->toBeTrue()
        ->and(Schema::hasTable('held_order_totals'))->toBeTrue();

    $store = Store::create(['name' => 'Tienda de cotizaciones']);

    // 4. Una cotizacion NO es un pedido: cada una vive en su tabla, y la
    //    fila de una no aparece en la otra.
    $quotationId = DB::table('quotations')->insertGetId([
        'store_id' => $store->id,
        'reference_no' => 'COT-0001',
        'date' => '2026-11-04',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'store_id' => $store->id,
        'reference_no' => 'PED-0001',
        'date' => '2026-11-04',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('quotations')->count())->toBe(1)
        ->and(DB::table('orders')->count())->toBe(1)
        // La fila del pedido NO esta en las cotizaciones, ni al reves.
        ->and(DB::table('quotations')->where('reference_no', 'PED-0001')->count())->toBe(0)
        ->and(DB::table('orders')->where('reference_no', 'COT-0001')->count())->toBe(0);

    // 5. Y cada una tiene su propio desglose, con su propia clave foranea.
    $productId = DB::table('products')->insertGetId([
        'code' => 'ZZP-COT-1',
        'name' => 'Producto de la cotizacion',
        'type' => 'product',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('quotation_items')->insert([
        'quotation_id' => $quotationId,
        'product_id' => $productId,
        'quantity' => '2',
        'unit_price' => '10.0000',
        'discount' => '0',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_items')->insert([
        'order_id' => $orderId,
        'product_id' => $productId,
        'quantity' => '3',
        'unit_price' => '10.0000',
        'discount' => '0',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('quotation_totals')->insert([
        'quotation_id' => $quotationId,
        'sub_total' => '20.0000',
        'discount' => '0',
        'tax_total' => '0',
        'grand_total' => '20.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_totals')->insert([
        'order_id' => $orderId,
        'sub_total' => '30.0000',
        'discount' => '0',
        'tax_total' => '0',
        'grand_total' => '30.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('quotation_items')->where('quotation_id', $quotationId)->count())->toBe(1)
        ->and(DB::table('order_items')->where('order_id', $orderId)->count())->toBe(1)
        ->and(DB::table('quotation_totals')->where('quotation_id', $quotationId)->count())->toBe(1)
        ->and(DB::table('order_totals')->where('order_id', $orderId)->count())->toBe(1);

    // 6. Y el pedido en espera del POS NO es un pedido comercial: su tabla es
    //    otra, y su linea cuelga de `held_orders`.
    $heldId = DB::table('held_orders')->insertGetId([
        'store_id' => $store->id,
        'reference_no' => 'ESPERA-0001',
        'date' => '2026-11-04',
        'status' => 'held',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('held_order_items')->insert([
        'held_order_id' => $heldId,
        'product_id' => $productId,
        'quantity' => '1',
        'unit_price' => '10.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('orders')->where('reference_no', 'ESPERA-0001')->count())->toBe(0)
        ->and(DB::table('held_order_items')->where('held_order_id', $heldId)->count())->toBe(1)
        // La linea del pedido en espera NO es una linea de pedido comercial.
        ->and(DB::table('order_items')->where('quantity', '1')->count())->toBe(0)
        ->and(DB::table('order_items')->where('quantity', '3')->count())->toBe(1);
});
