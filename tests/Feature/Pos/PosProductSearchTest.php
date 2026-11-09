<?php

use App\Models\Product;
use App\Models\ProductStore;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PosFixture;

/**
 * La BUSQUEDA de la caja: lo que el cajero encuentra.
 *
 * Reglas que fija:
 *
 *  - la busqueda es de UNA TIENDA: un producto que no tiene ficha en la
 *    tienda activa no se encuentra, por mucho que exista en otra;
 *  - la busqueda ve lo que HAY: un producto sin stock no aparece, y un
 *    SERVICIO (que no tiene almacen) aparece siempre;
 *  - busca por NOMBRE como prefijo y por CODIGO exacto: el codigo de un
 *    escaner de barras es el codigo, no un prefijo;
 *  - pagina por el tamano de pagina de la configuracion.
 */

/**
 * Un producto con ficha en UNA tienda que no es la de la caja.
 */
function producto_de_otra_tienda(int $categoryId): int
{
    $otra = Store::create(['name' => 'Tienda que no es la de la caja', 'status' => true]);

    $producto = Product::create([
        'code' => 'HUMO-OTRA-TIENDA',
        'name' => 'Producto de la caja de otra tienda',
        'tax_category_id' => $categoryId,
        'type' => 'product',
    ]);

    ProductStore::create([
        'product_id' => $producto->id,
        'store_id' => $otra->id,
        'purchase_price' => '10.0000',
        'sell_price' => '99.0000',
        'quantity_in_stock' => '50.0000',
        'status' => 'active',
    ]);

    return (int) $producto->id;
}

/**
 * La busqueda es de UNA tienda y ve lo que HAY.
 */
test('test_la_busqueda_filtra_por_la_tienda_y_por_el_stock', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $deOtraTienda = producto_de_otra_tienda($escenario['tax_category_id']);
    $sinStock = PosFixture::productoSinStock($escenario['store_id'], $escenario['tax_category_id']);
    $servicio = PosFixture::servicio($escenario['store_id'], $escenario['tax_category_id']);

    $respuesta = $this->actingAs($usuario)->getJson('/caja/productos');

    $respuesta->assertOk();

    $ids = array_map(
        static fn (array $producto): int => (int) $producto['id'],
        $respuesta->json('products')
    );

    // 1. El producto con stock de ESTA tienda esta.
    expect($ids)->toContain($escenario['product_id']);

    // 2. El de la OTRA tienda no: la busqueda es de la tienda activa.
    expect($ids)->not->toContain($deOtraTienda);

    // 3. El que no tiene stock tampoco: no se puede vender lo que no hay.
    expect($ids)->not->toContain($sinStock);

    // 4. Y el SERVICIO si: un servicio no tiene almacen, asi que no necesita
    //    stock para venderse.
    expect($ids)->toContain($servicio);

    // 5. Y el stock que se enseña es el REAL: la suma de los lotes.
    $producto = collect($respuesta->json('products'))
        ->firstWhere('id', $escenario['product_id']);

    expect($producto)->not->toBeNull()
        ->and($producto['sell_price'])->toBe('25.0000')
        ->and($producto['quantity_in_stock'])->toBe('10.0000')
        ->and($producto['code'])->toBe('HUMO-CAJA-001')
        ->and($producto['type'])->toBe('product');
});

/**
 * La busqueda encuentra por NOMBRE (como prefijo) y por CODIGO (exacto).
 */
test('test_la_busqueda_encuentra_por_nombre_y_por_codigo', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // 1. Por NOMBRE, como prefijo y en mayusculas.
    $porNombre = $this->actingAs($usuario)->getJson('/caja/productos?q=PROD');

    $porNombre->assertOk();

    expect($porNombre->json('products'))->toHaveCount(1)
        ->and((int) $porNombre->json('products.0.id'))->toBe($escenario['product_id']);

    // 2. Por CODIGO, exacto.
    $porCodigo = $this->actingAs($usuario)->getJson('/caja/productos?q=HUMO-CAJA-001');

    $porCodigo->assertOk();

    expect($porCodigo->json('products'))->toHaveCount(1)
        ->and((int) $porCodigo->json('products.0.id'))->toBe($escenario['product_id']);

    // 3. Y el codigo NO es un prefijo: 'HUMO' no encuentra 'HUMO-CAJA-001',
    //    porque el codigo se busca EXACTO.
    $porPrefijo = $this->actingAs($usuario)->getJson('/caja/productos?q=HUMO');

    $porPrefijo->assertOk();

    expect($porPrefijo->json('products'))->toBe([]);

    // 4. Y un termino que no existe no devuelve nada.
    $this->actingAs($usuario)
        ->getJson('/caja/productos?q=NO-EXISTE-NADA')
        ->assertOk()
        ->assertJsonPath('products', []);

    // 5. Y un comodin del `LIKE` es DATO, no un patron: escribir '%' en el
    //    buscador no devuelve el catalogo entero.
    $this->actingAs($usuario)
        ->getJson('/caja/productos?q=%')
        ->assertOk()
        ->assertJsonPath('products', []);
});

/**
 * La busqueda pagina el catalogo.
 */
test('test_la_busqueda_pagina_el_catalogo', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // Un tamano de pagina de uno: la pagina se ve en dos pantallazos.
    config(['pos.search_page_size' => 1]);

    $primera = $this->actingAs($usuario)->getJson('/caja/productos');
    $primera->assertOk();

    expect($primera->json('page'))->toBe(1)
        ->and($primera->json('page_size'))->toBe(1)
        ->and($primera->json('products'))->toHaveCount(1)
        // El total es de los productos que cumplen el filtro, no de los que
        // trae la pagina.
        ->and((int) $primera->json('total'))->toBe(1);

    $segunda = $this->actingAs($usuario)->getJson('/caja/productos?page=2');
    $segunda->assertOk();

    expect($segunda->json('page'))->toBe(2)
        ->and($segunda->json('products'))->toBe([]);
});

/**
 * La caja abre en la TIENDA del usuario, y sin vinculo en la primera tienda
 * activa.
 */
test('la_caja_abre_en_la_tienda_del_usuario_y_sin_vinculo_en_la_primera_activa', function () {
    $escenario = PosFixture::escenario();

    // 1. Con vinculo por tienda, la caja abre en la tienda del usuario.
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/caja')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('store.id', $escenario['store_id'])
            ->etc());

    // 2. Sin vinculo, la caja abre en la PRIMERA tienda activa. Para que se
    //    vea que es el plan general y no el vinculo, la tienda de
    //    demostracion se desactiva y se activa otra.
    DB::table('stores')->where('id', $escenario['store_id'])->update(['status' => false]);

    $pais = DB::table('countries')->where('iso_code', 'VE')->value('id');

    $otra = Store::create([
        'name' => 'Tienda activa de la caja',
        'code_name' => 'CAJA-ACTIVA',
        'country_id' => $pais,
        'status' => true,
    ]);

    $sinVinculo = User::factory()->create();

    $this->actingAs($sinVinculo)
        ->get('/caja')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('store.id', $otra->id)
            ->etc());
});

/**
 * La pantalla de la caja entrega el CONTRATO que la interfaz consume.
 */
test('test_la_pantalla_de_la_caja_entrega_el_contrato_de_sus_props', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)->get('/caja');

    $respuesta->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('pos/index')
            ->where('store.id', $escenario['store_id'])
            ->where('store.name', 'Tienda de demostracion')
            ->where('store.code_name', 'DEMO')
            ->where('store.timezone', 'America/Caracas')
            ->where('document_series_id', $escenario['document_series_id'])
            ->where('control_series_id', $escenario['control_series_id'])
            ->where('currency_code', 'VES')
            ->where('catalog_page_size', 24)
            ->has('catalog', 1)
            ->where('catalog.0.id', $escenario['product_id'])
            ->where('catalog.0.sell_price', '25.0000')
            ->where('catalog.0.quantity_in_stock', '10.0000')
            ->has('payment_methods', 1)
            ->where('payment_methods.0.id', $escenario['payment_method_id'])
            ->where('payment_methods.0.code', 'HUMO-EFECTIVO')
            ->etc());

    // Y la fecha de negocio es la de la TIENDA, no la del servidor.
    $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Caracas')))->format('Y-m-d');

    $respuesta->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('business_date', $fecha)
        ->etc());
});
