<?php

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * El CAMINO del catalogo, por HTTP de verdad.
 *
 * Reglas que fija:
 *
 *  - el catalogo exige sesion: sin ella, redirige al login;
 *  - un producto creado por la API aparece en la CAJA con el precio que le
 *    puso el catalogo: el catalogo y la caja estan conectados de verdad, no
 *    solo en el papel;
 *  - el PRECIO COBRADO es el del catalogo: el motor de impuestos calcula
 *    sobre ese, no sobre el precio de la tabla.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS, Y POR QUE NO SE PUEDE SIMULAR:
 *
 * Un test con `actingAs()` verifica la PAGINA. Este verifica el CAMINO: el
 * arranque de verdad, el login de verdad, el token CSRF de verdad y el
 * enrutado real de las peticiones. Y el camino ya fallo dos veces en
 * produccion de un modo que ni el CI veia: el 419 del CSRF y las mayusculas
 * de las rutas de Inertia.
 *
 * El STOCK se abre con una COMPRA, como en el legacy y en el `SmokeSale`:
 * `inventory_lots.purchase_item_id` es NOT NULL y UNIQUE, asi que el stock
 * tiene que venir de una compra real.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El CLIENTE del camino: con las cookies de la sesion y con margen de tiempo.
 *
 * REGLA que fija este helper: *una peticion del camino espera a un servidor
 * que arranca la aplicacion entera*. El limite por defecto del cliente HTTP
 * (treinta segundos) es el MISMO que el del arranque del servidor, asi que
 * basta con que la maquina vaya cargada para que el camino se caiga por
 * tiempo. Aqui se espera de sobra.
 *
 * @param  array<string, mixed>  $opciones
 * @return PendingRequest
 */
function catalogo_cliente(CookieJar $jar, array $opciones = [])
{
    return Http::withOptions(array_merge([
        'cookies' => $jar,
        'timeout' => 180,
        'connect_timeout' => 30,
    ], $opciones));
}

/**
 * El STOCK de un producto: una COMPRA que abre el lote.
 */
function catalogo_compra_de_stock(int $storeId, int $productId, string $cantidad, string $costo): void
{
    $purchaseId = DB::table('purchases')->insertGetId([
        'store_id' => $storeId,
        'purchase_date' => now()->format('Y-m-d'),
        'status' => 'received',
        'invoice_no' => 'HUMO-CATALOGO-COMPRA',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $purchaseItemId = DB::table('purchase_items')->insertGetId([
        'purchase_id' => $purchaseId,
        'product_id' => $productId,
        'store_id' => $storeId,
        'quantity' => $cantidad,
        'unit_price' => $costo,
        'discount' => '0.0000',
        'tax_rate_snapshot' => '16.0000',
        'tax_amount' => '0.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('inventory_lots')->insert([
        'product_id' => $productId,
        'store_id' => $storeId,
        'purchase_item_id' => $purchaseItemId,
        'quantity_received' => $cantidad,
        'quantity_consumed' => '0.0000',
        'quantity_returned' => '0.0000',
        'unit_cost' => $costo,
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * El producto creado por el catalogo aparece en la caja con su precio.
 */
test('test_el_producto_creado_por_http_aparece_en_la_caja_con_el_precio_del_catalogo', function () {
    $servidor = PosHttpServer::start();

    try {
        // 1. El ESCENARIO, en la base de datos del camino: la tienda
        //    sembrada, su perfil fiscal, sus series, un producto con stock y
        //    un cajero con su vinculo por la tienda.
        $escenario = PosFixture::escenario();

        $cajero = PosFixture::usuario($escenario['store_id']);
        $cajero->email = 'catalogo@camino.test';
        $cajero->password = 'clave-del-camino';
        $cajero->save();

        $jar = new CookieJar;

        // 2. SIN SESION, el catalogo NO se sirve: redirige al login.
        $sinSesion = catalogo_cliente($jar, ['allow_redirects' => false])
            ->get($servidor->url('/catalogo'));

        expect($sinSesion->status())->toBe(302)
            ->and((string) $sinSesion->header('Location'))->toContain('/login');

        // 3. El login, con su token CSRF.
        $login = catalogo_cliente($jar)
            ->get($servidor->url('/login'));

        expect($login->status())->toBe(200);

        $token = token_csrf_de($login->body());

        expect($token)->not->toBe('');

        $sesion = catalogo_cliente($jar, ['allow_redirects' => false])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/login'), [
                'email' => 'catalogo@camino.test',
                'password' => 'clave-del-camino',
            ]);

        expect($sesion->status())->toBe(302);

        // 4. La pagina del catalogo: el token NUEVO (el login regenera la
        //    sesion, y con ella el token).
        $pagina = catalogo_cliente($jar)
            ->get($servidor->url('/catalogo'));

        expect($pagina->status())->toBe(200)
            ->and(pagina_de_inertia($pagina->body())['component'] ?? null)->toBe('catalog/index');

        $token = token_csrf_de($pagina->body());

        expect($token)->not->toBe('');

        // 5. El ALTA del producto, por la API del catalogo.
        $alta = catalogo_cliente($jar, ['allow_redirects' => false])
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/catalogo'), [
                'code' => 'HUMO-CATALOGO-001',
                'name' => 'Producto del catalogo',
                'type' => 'product',
                'is_active' => true,
                'tax_category_id' => $escenario['tax_category_id'],
                'sell_price' => '30.0000',
                'purchase_price' => '12.0000',
            ]);

        expect($alta->status())->toBe(302);

        // Y el producto existe, con su precio en la ficha de la tienda.
        $productId = (int) DB::table('products')->where('code', 'HUMO-CATALOGO-001')->value('id');

        expect($productId)->not->toBe(0)
            ->and((string) DB::table('product_stores')
                ->where('product_id', $productId)
                ->where('store_id', $escenario['store_id'])
                ->value('sell_price'))->toBe('30.0000');

        // 6. El STOCK: una compra abre el lote del producto.
        catalogo_compra_de_stock($escenario['store_id'], $productId, '10.0000', '12.0000');

        // 7. La CAJA: el producto aparece con el precio del catalogo y con el
        //    stock real.
        $caja = catalogo_cliente($jar)
            ->get($servidor->url('/caja'));

        expect($caja->status())->toBe(200);

        $props = pagina_de_inertia($caja->body())['props'] ?? [];

        $enLaCaja = collect($props['catalog'] ?? [])->firstWhere('code', 'HUMO-CATALOGO-001');

        expect($enLaCaja)->not->toBeNull()
            ->and($enLaCaja['sell_price'])->toBe('30.0000')
            ->and($enLaCaja['quantity_in_stock'])->toBe('10.0000');

        // 8. Y el COBRO usa ese precio: el motor calcula sobre el precio
        //    cobrado (30.0000 con el 16 % del perfil de demostracion).
        $presupuesto = catalogo_cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/caja/presupuesto'), [
                'lines' => [
                    [
                        'product_id' => $productId,
                        'quantity' => '1',
                        'unit_price' => '30.0000',
                    ],
                ],
            ]);

        expect($presupuesto->status())->toBe(200)
            ->and($presupuesto->json('subtotal'))->toBe('30.0000')
            ->and($presupuesto->json('tax_total'))->toBe('4.8000')
            ->and($presupuesto->json('grand_total'))->toBe('34.8000');
    } finally {
        $servidor->stop();
    }
});
