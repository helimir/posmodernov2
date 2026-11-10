<?php

use App\Models\Product;
use App\Models\ProductStore;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\CatalogFixture;

/**
 * Las PANTALLAS del catalogo: el listado, el formulario de alta y la ficha.
 *
 * Reglas que fija:
 *
 *  - las tres pantallas exigen sesion: sin autenticar, REDIRIGEN (no
 *    responden 200 con una pantalla vacia);
 *  - el formulario de ALTA y la FICHA son la MISMA pantalla: una sola
 *    pantalla de producto, con el producto o sin el;
 *  - la ficha entrega el producto, su STOCK (que se muestra pero no se
 *    edita) y los PRECIOS por tienda;
 *  - la CATEGORIA FISCAL que manda es la del override de la tienda si la
 *    hay, y si no la del producto; y cuando no hay ninguna, la ficha AVISA:
 *    una linea sin tratamiento fiscal no se cobra en silencio.
 */

/**
 * La pantalla del LISTADO enseña los productos de la tienda activa.
 */
test('test_la_pagina_del_catalogo_lista_los_productos_de_la_tienda_activa', function () {
    $escenario = CatalogFixture::escenario();
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)->post('/catalogo', [
        'code' => 'CAT-PAGINA',
        'name' => 'Producto de la pagina',
        'type' => 'product',
        'is_active' => true,
        'sell_price' => '5.0000',
        'purchase_price' => '2.0000',
    ])->assertRedirect();

    $this->actingAs($usuario)
        ->get('/catalogo')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('catalog/index')
            ->has('products', 1)
            ->where('products.0.code', 'CAT-PAGINA')
            ->where('products.0.name', 'Producto de la pagina')
            ->etc());
});

/**
 * El formulario de ALTA es la MISMA pantalla de la ficha, sin producto, y
 * entrega los selectores.
 */
test('test_la_pagina_de_alta_del_catalogo_entrega_los_selectores_del_producto', function () {
    $escenario = CatalogFixture::escenario();
    $categoria = CatalogFixture::categoria('CAT-ALFA', 'Alfa');
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/catalogo/nuevo')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('catalog/form')
            // Sin producto: es el ALTA.
            ->where('product', null)
            // Los selectores: lo que hay en el catalogo maestro.
            ->has('categories', 1)
            ->where('categories.0.id', $categoria->id)
            ->where('categories.0.name', 'Alfa')
            ->has('brands', 0)
            ->has('units', 0)
            ->has('boxes', 0)
            // Las categorias fiscales del perfil de la tienda.
            ->has('tax_categories', 5)
            ->where('tax_categories', fn ($categorias): bool => collect($categorias)
                ->contains('code', 'gravable'))
            // Y las tiendas donde el producto puede tener precio.
            ->has('stores', 1)
            ->where('stores.0.id', $escenario['store_id'])
            ->where('stores.0.is_active_store', true)
            // La tienda del contexto y su moneda.
            ->where('store.id', $escenario['store_id'])
            ->where('currency.code', 'VES')
            ->etc());
});

/**
 * La FICHA entrega el producto, su stock y los precios por tienda.
 */
test('test_la_ficha_del_catalogo_entrega_el_producto_su_stock_y_sus_precios_por_tienda', function () {
    $escenario = CatalogFixture::escenario();
    $otra = CatalogFixture::tienda('Sucursal Norte', 'NORTE');
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)->post('/catalogo', [
        'code' => 'CAT-FICHA',
        'name' => 'Producto de la ficha',
        'description' => 'La descripcion',
        'type' => 'product',
        'is_active' => true,
        'tax_category_id' => $escenario['tax_category_id'],
        'sell_price' => '25.0000',
        'purchase_price' => '10.0000',
    ])->assertRedirect();

    $producto = Product::query()->where('code', 'CAT-FICHA')->firstOrFail();

    $this->actingAs($usuario)
        ->get('/catalogo/'.$producto->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('catalog/form')
            ->where('product.id', $producto->id)
            ->where('product.code', 'CAT-FICHA')
            ->where('product.name', 'Producto de la ficha')
            ->where('product.description', 'La descripcion')
            ->where('product.type', 'product')
            ->where('product.is_active', true)
            // El STOCK: se muestra, pero es un cache de los lotes.
            ->where('product.quantity_in_stock', '0.0000')
            // La CATEGORIA FISCAL que manda: la del producto.
            ->where('product.tax_category_id', $escenario['tax_category_id'])
            ->where('product.store_tax_category_id', null)
            ->where('product.effective_tax_category_id', $escenario['tax_category_id'])
            ->where('product.missing_tax_category', false)
            // Y los PRECIOS por tienda: una fila por tienda activa.
            ->has('product.prices', 2)
            ->where('product.prices', function ($precios) use ($escenario, $otra): bool {
                $deLaTienda = collect($precios)->firstWhere('store_id', $escenario['store_id']);
                $deLaOtra = collect($precios)->firstWhere('store_id', $otra->id);

                return $deLaTienda !== null
                    && $deLaTienda['is_active_store'] === true
                    && $deLaTienda['sell_price'] === '25.0000'
                    && $deLaTienda['purchase_price'] === '10.0000'
                    && $deLaOtra !== null
                    && $deLaOtra['is_active_store'] === false
                    && $deLaOtra['sell_price'] === '0.0000';
            })
            ->etc());
});

/**
 * La CATEGORIA FISCAL que manda es el OVERRIDE de la tienda si lo hay.
 */
test('test_la_ficha_del_catalogo_muestra_el_override_de_categoria_fiscal_de_la_tienda', function () {
    $escenario = CatalogFixture::escenario();
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)->post('/catalogo', [
        'code' => 'CAT-OVERRIDE',
        'name' => 'Producto con override',
        'type' => 'product',
        'is_active' => true,
        'tax_category_id' => $escenario['tax_category_id'],
        'sell_price' => '25.0000',
    ])->assertRedirect();

    $producto = Product::query()->where('code', 'CAT-OVERRIDE')->firstOrFail();

    // La tienda cambia la categoria del producto: el override MANDA.
    $otraCategoria = (int) DB::table('tax_categories')
        ->where('fiscal_profile_id', $escenario['profile_id'])
        ->where('code', 'exento')
        ->value('id');

    ProductStore::query()
        ->where('product_id', $producto->id)
        ->where('store_id', $escenario['store_id'])
        ->update(['tax_category_id' => $otraCategoria]);

    $this->actingAs($usuario)
        ->get('/catalogo/'.$producto->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('catalog/form')
            // Las DOS: la del producto y la del override.
            ->where('product.tax_category_id', $escenario['tax_category_id'])
            ->where('product.store_tax_category_id', $otraCategoria)
            // Y la que MANDA es la del override.
            ->where('product.effective_tax_category_id', $otraCategoria)
            ->where('product.missing_tax_category', false)
            ->etc());
});

/**
 * Un producto SIN categoria fiscal AVISA en la ficha: la caja no cobra en
 * silencio una linea sin tratamiento fiscal.
 */
test('test_la_ficha_del_catalogo_avisa_de_un_producto_sin_categoria_fiscal', function () {
    $escenario = CatalogFixture::escenario();
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)->post('/catalogo', [
        'code' => 'CAT-SIN-CATEGORIA',
        'name' => 'Producto sin categoria fiscal',
        'type' => 'product',
        'is_active' => true,
        'sell_price' => '25.0000',
    ])->assertRedirect();

    $producto = Product::query()->where('code', 'CAT-SIN-CATEGORIA')->firstOrFail();

    $this->actingAs($usuario)
        ->get('/catalogo/'.$producto->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('catalog/form')
            ->where('product.tax_category_id', null)
            ->where('product.store_tax_category_id', null)
            ->where('product.effective_tax_category_id', null)
            ->where('product.missing_tax_category', true)
            ->etc());
});

/**
 * Sin sesion, el catalogo REDIRIGE: no se sirve una pantalla de catalogo a
 * quien no ha entrado.
 */
test('test_sin_autenticar_el_catalogo_redirige_al_login', function () {
    $this->get('/catalogo')->assertRedirect('/login');
    $this->get('/catalogo/nuevo')->assertRedirect('/login');
    $this->get('/catalogo/1')->assertRedirect('/login');
});
