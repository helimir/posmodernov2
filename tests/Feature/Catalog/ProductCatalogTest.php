<?php

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\CatalogFixture;

/**
 * El CATALOGO: el maestro de productos y su precio por tienda.
 *
 * Reglas que fija:
 *
 *  - el ALTA crea el producto Y abre su ficha (`product_stores`) en TODAS las
 *    tiendas activas, para que el precio por tienda exista desde el principio;
 *  - el codigo es UNICO (lo dice la base de datos) y obligatorio: un codigo
 *    repetido es un 422 y no se crea un segundo producto;
 *  - la EDICION guarda el maestro y el precio de la TIENDA ACTIVA, y NO toca
 *    el precio de otra tienda: el precio de cada tienda es de su tienda;
 *  - la BUSQUEDA encuentra por codigo y por nombre, y escapa los comodines
 *    del `LIKE`: escribir `50%` no devuelve medio catalogo;
 *  - los FILTROS por categoria y por estado acotan el listado;
 *  - el STOCK no se edita a mano: `quantity_in_stock` es un cache derivado de
 *    los lotes, y su lugar es la compra o el ajuste, no la ficha.
 */

/**
 * Los DATOS de un alta de producto.
 *
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function catalogo_alta(array $cambios = []): array
{
    return array_merge([
        'code' => 'CAT-001',
        'name' => 'Producto del catalogo',
        'description' => 'La descripcion del producto',
        'type' => 'product',
        'is_active' => true,
        'tax_category_id' => null,
        'sell_price' => '25.0000',
        'purchase_price' => '10.0000',
    ], $cambios);
}

/**
 * La FICHA de un producto en una tienda.
 *
 * @return object|null
 */
function catalogo_ficha(int $productId, int $storeId)
{
    return DB::table('product_stores')
        ->where('product_id', $productId)
        ->where('store_id', $storeId)
        ->first();
}

/**
 * El LISTADO del catalogo responde 200 y entrega su CONTRATO de props.
 */
test('test_el_listado_del_catalogo_responde_200_y_entrega_el_contrato_de_sus_props', function () {
    $escenario = CatalogFixture::escenario();
    $categoria = CatalogFixture::categoria('CAT-ALFA', 'Alfa');
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->post('/catalogo', catalogo_alta([
            'category_id' => $categoria->id,
            'tax_category_id' => $escenario['tax_category_id'],
        ]))
        ->assertRedirect();

    $this->actingAs($usuario)
        ->get('/catalogo')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('catalog/index')
            // La fila: el codigo, el nombre, la categoria, el precio de la
            // tienda activa, el stock, el tipo y el estado.
            ->has('products', 1)
            ->where('products.0.code', 'CAT-001')
            ->where('products.0.name', 'Producto del catalogo')
            ->where('products.0.category', 'Alfa')
            ->where('products.0.sell_price', '25.0000')
            ->where('products.0.quantity_in_stock', '0.0000')
            ->where('products.0.type', 'product')
            ->where('products.0.is_active', true)
            // La pagina: la de ahora, de cincuenta en cincuenta.
            ->where('pagination.page', 1)
            ->where('pagination.per_page', 50)
            ->where('pagination.total', 1)
            ->where('pagination.last_page', 1)
            // Los filtros que se estan aplicando.
            ->where('filters.q', '')
            ->where('filters.category_id', null)
            ->where('filters.status', null)
            // Los selectores del filtro.
            ->has('categories', 1)
            ->where('categories.0.id', $categoria->id)
            ->where('categories.0.name', 'Alfa')
            // La tienda ACTIVA: la del contexto, no la primera del sistema.
            ->where('store.id', $escenario['store_id'])
            ->where('store.name', 'Tienda de demostracion')
            // Y la MONEDA, con su simbolo y sus separadores.
            ->where('currency.code', 'VES')
            ->where('currency.symbol', 'Bs.')
            ->where('currency.decimals', 2)
            ->etc());
});

/**
 * El ALTA crea el producto y abre su ficha en TODAS las tiendas activas.
 */
test('test_el_alta_crea_el_producto_y_abre_su_ficha_en_todas_las_tiendas_activas', function () {
    $escenario = CatalogFixture::escenario();
    $otra = CatalogFixture::tienda('Sucursal Norte', 'NORTE');
    $cerrada = CatalogFixture::tienda('Sucursal Cerrada', 'CERRADA', false);
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->post('/catalogo', catalogo_alta([
            'tax_category_id' => $escenario['tax_category_id'],
        ]))
        ->assertRedirect();

    $producto = Product::query()->where('code', 'CAT-001')->first();

    expect($producto)->not->toBeNull();

    // Su ficha existe en las DOS tiendas activas, y en la CERRADA no: una
    // tienda cerrada no es una tienda donde se vende.
    $fichas = DB::table('product_stores')->where('product_id', $producto->id)->get();

    expect($fichas)->toHaveCount(2)
        ->and($fichas->pluck('store_id')->all())->toEqualCanonicalizing([
            $escenario['store_id'],
            $otra->id,
        ]);

    // El precio de la TIENDA ACTIVA es el que se ha mandado...
    $activa = catalogo_ficha((int) $producto->id, $escenario['store_id']);

    expect((string) $activa->sell_price)->toBe('25.0000')
        ->and((string) $activa->purchase_price)->toBe('10.0000')
        ->and((string) $activa->status)->toBe('active');

    // ...y el de la otra tienda es CERO: todavia no se ha puesto.
    $otraFicha = catalogo_ficha((int) $producto->id, $otra->id);

    expect((string) $otraFicha->sell_price)->toBe('0.0000')
        ->and((string) $otraFicha->purchase_price)->toBe('0.0000');

    // Y la tienda cerrada no tiene ficha.
    expect(catalogo_ficha((int) $producto->id, $cerrada->id))->toBeNull();
});

/**
 * El codigo es UNICO: un codigo repetido es un 422 y no un segundo
 * producto.
 */
test('test_el_alta_con_un_codigo_que_ya_existe_falla_con_422_y_no_crea_un_segundo_producto', function () {
    $escenario = CatalogFixture::escenario();
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->post('/catalogo', catalogo_alta())
        ->assertRedirect();

    $this->actingAs($usuario)
        ->postJson('/catalogo', catalogo_alta(['name' => 'Otro producto']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    expect(Product::query()->where('code', 'CAT-001')->count())->toBe(1);
});

/**
 * La EDICION guarda el maestro y el precio de la tienda ACTIVA, y no toca el
 * precio de OTRA tienda.
 */
test('test_la_edicion_guarda_el_nombre_y_el_precio_de_la_tienda_activa_y_no_toca_el_precio_de_otra_tienda', function () {
    $escenario = CatalogFixture::escenario();
    $otra = CatalogFixture::tienda('Sucursal Norte', 'NORTE');
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)->post('/catalogo', catalogo_alta())->assertRedirect();

    $producto = Product::query()->where('code', 'CAT-001')->firstOrFail();

    // El precio de la otra tienda, puesto con la API de precios.
    $this->actingAs($usuario)
        ->putJson('/catalogo/'.$producto->id.'/precios', [
            'store_id' => $otra->id,
            'sell_price' => '7.0000',
            'purchase_price' => '3.0000',
        ])
        ->assertOk()
        ->assertJsonPath('sell_price', '7.0000');

    // La edicion del maestro, con el precio de la tienda activa.
    $this->actingAs($usuario)
        ->put('/catalogo/'.$producto->id, catalogo_alta([
            'name' => 'Producto renombrado',
            'sell_price' => '30.0000',
            'purchase_price' => '12.0000',
        ]))
        ->assertRedirect();

    $producto->refresh();

    expect($producto->name)->toBe('Producto renombrado');

    $activa = catalogo_ficha((int) $producto->id, $escenario['store_id']);
    $otraFicha = catalogo_ficha((int) $producto->id, $otra->id);

    expect((string) $activa->sell_price)->toBe('30.0000')
        ->and((string) $activa->purchase_price)->toBe('12.0000')
        // El precio de la otra tienda es de la otra tienda: la ficha de una
        // tienda no reescribe el precio de otra.
        ->and((string) $otraFicha->sell_price)->toBe('7.0000')
        ->and((string) $otraFicha->purchase_price)->toBe('3.0000');
});

/**
 * La BUSQUEDA encuentra por codigo y por nombre, y escapa los comodines.
 */
test('test_la_busqueda_del_catalogo_encuentra_por_codigo_y_por_nombre_y_escapa_los_comodines', function () {
    $escenario = CatalogFixture::escenario();
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)->post('/catalogo', catalogo_alta([
        'code' => 'CAT-ALFA',
        'name' => 'Alfa cafe',
    ]))->assertRedirect();

    $this->actingAs($usuario)->post('/catalogo', catalogo_alta([
        'code' => 'CAT-BETA',
        'name' => 'Beta te',
    ]))->assertRedirect();

    // Por CODIGO, como prefijo.
    $this->actingAs($usuario)
        ->get('/catalogo?q=CAT-AL')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('products', 1)
            ->where('products.0.code', 'CAT-ALFA')
            ->where('filters.q', 'CAT-AL')
            ->etc());

    // Por NOMBRE, como prefijo.
    $this->actingAs($usuario)
        ->get('/catalogo?q=Alfa')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('products', 1)
            ->where('products.0.code', 'CAT-ALFA')
            ->etc());

    // Y el comodin del `LIKE` va ESCAPADO: escribir `%` no devuelve medio
    // catalogo.
    $this->actingAs($usuario)
        ->get('/catalogo?q=%25')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('products', 0)
            ->etc());
});

/**
 * Los FILTROS por categoria y por estado acotan el listado.
 */
test('test_el_filtro_por_categoria_y_por_estado_del_catalogo_filtra_el_listado', function () {
    $escenario = CatalogFixture::escenario();
    $alfa = CatalogFixture::categoria('CAT-ALFA', 'Alfa');
    $beta = CatalogFixture::categoria('CAT-BETA', 'Beta');
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)->post('/catalogo', catalogo_alta([
        'code' => 'CAT-UNO',
        'name' => 'Producto uno',
        'category_id' => $alfa->id,
    ]))->assertRedirect();

    $this->actingAs($usuario)->post('/catalogo', catalogo_alta([
        'code' => 'CAT-DOS',
        'name' => 'Producto dos',
        'category_id' => $alfa->id,
        'is_active' => false,
    ]))->assertRedirect();

    $this->actingAs($usuario)->post('/catalogo', catalogo_alta([
        'code' => 'CAT-TRES',
        'name' => 'Producto tres',
        'category_id' => $beta->id,
    ]))->assertRedirect();

    // Por CATEGORIA: los dos de Alfa.
    $this->actingAs($usuario)
        ->get('/catalogo?category_id='.$alfa->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('products', 2)
            ->where('filters.category_id', $alfa->id)
            ->etc());

    // Por ESTADO: solo el inactivo.
    $this->actingAs($usuario)
        ->get('/catalogo?status=inactive')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('products', 1)
            ->where('products.0.code', 'CAT-DOS')
            ->where('filters.status', 'inactive')
            ->etc());

    // Y los dos juntos: el de Alfa que esta activo.
    $this->actingAs($usuario)
        ->get('/catalogo?category_id='.$alfa->id.'&status=active')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('products', 1)
            ->where('products.0.code', 'CAT-UNO')
            ->etc());
});

/**
 * El STOCK no se edita a mano: es un cache derivado de los lotes.
 */
test('test_la_ficha_del_catalogo_no_guarda_el_stock_editado_a_mano', function () {
    $escenario = CatalogFixture::escenario();
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)->post('/catalogo', catalogo_alta())->assertRedirect();

    $producto = Product::query()->where('code', 'CAT-001')->firstOrFail();

    // Se manda el stock en la peticion, a proposito.
    $this->actingAs($usuario)
        ->put('/catalogo/'.$producto->id, catalogo_alta([
            'quantity_in_stock' => '999.0000',
        ]))
        ->assertRedirect();

    $ficha = catalogo_ficha((int) $producto->id, $escenario['store_id']);

    expect((string) $ficha->quantity_in_stock)->toBe('0.0000');
});

/**
 * La API de PRECIOS guarda el precio de UNA tienda, y solo el de esa.
 */
test('test_la_api_de_precios_guarda_el_precio_de_una_tienda_y_solo_el_de_esa', function () {
    $escenario = CatalogFixture::escenario();
    $otra = CatalogFixture::tienda('Sucursal Norte', 'NORTE');
    $usuario = CatalogFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)->post('/catalogo', catalogo_alta())->assertRedirect();

    $producto = Product::query()->where('code', 'CAT-001')->firstOrFail();

    $this->actingAs($usuario)
        ->putJson('/catalogo/'.$producto->id.'/precios', [
            'store_id' => $otra->id,
            'sell_price' => '11.5000',
            'purchase_price' => '4.2500',
        ])
        ->assertOk()
        ->assertJsonPath('product_id', $producto->id)
        ->assertJsonPath('store_id', $otra->id)
        ->assertJsonPath('sell_price', '11.5000')
        ->assertJsonPath('purchase_price', '4.2500');

    // El precio de la tienda ACTIVA no se ha movido.
    $activa = catalogo_ficha((int) $producto->id, $escenario['store_id']);

    expect((string) $activa->sell_price)->toBe('25.0000');

    // Y la API devuelve los precios de TODAS las tiendas.
    $this->actingAs($usuario)
        ->getJson('/catalogo/'.$producto->id.'/precios')
        ->assertOk()
        ->assertJsonPath('product_id', $producto->id)
        ->assertJsonCount(2, 'prices');
});
