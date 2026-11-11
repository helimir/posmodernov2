<?php

use App\Models\ProductStore;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PosFixture;

/**
 * El REPORTE DE STOCK BAJO: los productos de la tienda que se estan
 * acabando.
 *
 * Reglas que fija:
 *
 *  - la pagina responde 200 con su contrato: los productos con su stock
 *    REAL, su precio de venta de la tienda, la paginacion y el umbral;
 *  - el stock que enseña es el REAL (la suma de los lotes disponibles), no el
 *    cache `product_stores.quantity_in_stock`: el cache es una copia, y una
 *    copia puede estar desfasada;
 *  - un producto SIN LOTES aparece con stock CERO: lo que no tiene stock no
 *    se puede vender, y esconderlo seria un fallo del reporte;
 *  - un producto con stock SUFICIENTE no aparece;
 *  - solo salen los productos de la TIENDA ACTIVA: el stock de otra tienda es
 *    de otra tienda.
 */

/**
 * El ID de un producto del reporte, si aparece.
 *
 * @param  iterable<int, array<string, mixed>>  $productos
 */
function stock_bajo_contiene(iterable $productos, int $productId): bool
{
    foreach ($productos as $producto) {
        if ((int) $producto['id'] === $productId) {
            return true;
        }
    }

    return false;
}

/**
 * La pagina del reporte responde 200 con su contrato.
 */
test('test_la_pagina_del_reporte_de_stock_bajo_responde_con_su_contrato', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/reportes/stock')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('reports/stock')
            ->where('store.id', $escenario['store_id'])
            ->where('store.name', 'Tienda de demostracion')
            // El UMBRAL sale de la configuracion, no de un literal de la
            // pantalla.
            ->where('threshold', 5)
            ->has('products')
            ->has('pagination')
            ->where('pagination.page', 1)
            ->where('pagination.per_page', 50)
            ->etc());
});

/**
 * El reporte enseña el stock REAL (la suma de los lotes), no el cache de la
 * tienda.
 */
test('test_el_reporte_de_stock_bajo_muestra_el_stock_real_y_no_el_cache', function () {
    $escenario = PosFixture::escenario();

    // Dos unidades de verdad, y un cache que dice 999.
    $productoId = PosFixture::productoConStock(
        $escenario['store_id'],
        $escenario['tax_category_id'],
        '2.0000'
    );

    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/reportes/stock')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('reports/stock')
            ->where('products', function ($productos) use ($productoId): bool {
                $producto = collect($productos)->firstWhere('id', $productoId);

                return $producto !== null
                    && $producto['stock'] === '2.0000'
                    && $producto['sell_price'] === '25.0000';
            })
            ->etc());

    // Y el cache de la tienda sigue mintiendo: lo que se enseño no fue el.
    expect((string) DB::table('product_stores')
        ->where('product_id', $productoId)
        ->where('store_id', $escenario['store_id'])
        ->value('quantity_in_stock'))->toBe('999.0000');
});

/**
 * Un producto SIN LOTES aparece con stock CERO: lo que no tiene stock no se
 * puede vender, y esconderlo seria un fallo del reporte.
 */
test('test_un_producto_sin_lotes_aparece_con_stock_cero', function () {
    $escenario = PosFixture::escenario();
    $productoId = PosFixture::productoSinStock($escenario['store_id'], $escenario['tax_category_id']);

    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/reportes/stock')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('reports/stock')
            ->where('products', function ($productos) use ($productoId): bool {
                $producto = collect($productos)->firstWhere('id', $productoId);

                return $producto !== null && $producto['stock'] === '0.0000';
            })
            ->etc());
});

/**
 * Un producto con stock SUFICIENTE no aparece.
 */
test('test_un_producto_con_stock_suficiente_no_aparece', function () {
    $escenario = PosFixture::escenario();

    // Diez unidades: por encima del umbral de cinco.
    $productoId = PosFixture::productoConStock(
        $escenario['store_id'],
        $escenario['tax_category_id'],
        '10.0000'
    );

    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/reportes/stock')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('reports/stock')
            ->where('products', function ($productos) use ($productoId): bool {
                return ! stock_bajo_contiene($productos, $productoId);
            })
            ->etc());
});

/**
 * Solo salen los productos de la TIENDA ACTIVA: el stock de otra tienda es
 * de otra tienda.
 */
test('test_el_reporte_de_stock_bajo_solo_muestra_los_productos_de_la_tienda_activa', function () {
    $escenario = PosFixture::escenario();

    // Un producto de la tienda activa, con stock bajo.
    $deLaTienda = PosFixture::productoConStock(
        $escenario['store_id'],
        $escenario['tax_category_id'],
        '1.0000'
    );

    // Y OTRO producto con la MISMA ficha, pero en otra tienda: el stock de
    // otra tienda no es de esta.
    $otraTiendaId = (int) DB::table('stores')->insertGetId([
        'name' => 'Sucursal del reporte de stock',
        'code_name' => 'STOCK-OTRA',
        'sort_order' => 9,
        'status' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productoId = (int) DB::table('products')->insertGetId([
        'code' => 'HUMO-STOCK-OTRA-TIENDA',
        'name' => 'Producto de otra tienda',
        'type' => 'product',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ProductStore::create([
        'product_id' => $productoId,
        'store_id' => $otraTiendaId,
        'purchase_price' => '10.0000',
        'sell_price' => '25.0000',
        'quantity_in_stock' => '0.0000',
        'status' => 'active',
    ]);

    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/reportes/stock')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('reports/stock')
            ->where('products', function ($productos) use ($deLaTienda, $productoId): bool {
                return stock_bajo_contiene($productos, $deLaTienda)
                    && ! stock_bajo_contiene($productos, $productoId);
            })
            ->etc());
});
