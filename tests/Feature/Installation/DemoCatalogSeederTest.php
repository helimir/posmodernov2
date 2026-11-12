<?php

use App\Domain\Checkout\CheckoutInput;
use App\Domain\Checkout\CheckoutLineInput;
use App\Domain\Checkout\CheckoutPaymentInput;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Fiscal\TaxRateResolver;
use App\Domain\Inventory\StockInvariant;
use App\Support\Pos\PosBudget;
use App\Support\Pos\PosContext;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * 1.9 — el CATALOGO DE DEMOSTRACION SIEMBRADO.
 *
 * EL PROBLEMA que esta unidad arregla: *una instalacion nueva NO TIENE
 * CATALOGO*. `db:seed` siembra la tienda, sus ajustes, su cuenta de caja, su
 * perfil fiscal y sus series, pero no siembra productos, ni fichas de tienda,
 * ni lotes, ni categorias de producto, ni marcas, ni unidades, ni cajas, ni
 * clientes. Una tienda recien sembrada NO PUEDE COBRAR NADA: el error es
 * *"El producto 1 no tiene categoria fiscal en la tienda 1"*.
 *
 * Un POS sin catalogo no es un POS.
 *
 * REGLAS que fija esta suite:
 *
 *  - una instalacion sembrada tiene un CATALOGO OPERABLE: productos con su
 *    ficha de tienda, con su precio, con su tratamiento fiscal y con su stock;
 *  - el STOCK sale de LOTES REALES, no de un numero escrito a mano: el lote y
 *    su linea de compra son 1:1 (`inventory_lots.purchase_item_id` es NOT NULL
 *    y UNIQUE), asi que el stock tiene que venir de una COMPRA, con cabecera,
 *    linea, `purchase_totals` y lote;
 *  - el CACHE del stock (`product_stores.quantity_in_stock`) es un DERIVADO:
 *    se refresca desde los lotes, y escribirlo a mano seria escribir una
 *    mentira;
 *  - el SERVICIO no tiene almacen: ni lote, ni compra, ni stock;
 *  - la CATEGORIA FISCAL que manda es la del OVERRIDE de la tienda si lo hay,
 *    y si no, la del producto: el override se siembra en `null` en casi todos,
 *    y en uno con su propio override, para que la regla se vea y se pruebe;
 *  - sembrar DOS VECES no duplica nada: ni productos, ni fichas, ni lotes, ni
 *    compras, ni clientes;
 *  - y la caja COBRA con el catalogo sembrado: el camino por HTTP de verdad.
 *
 * POR QUE ESTOS TESTS VAN SIN TRANSACCION ENVOLVENTE
 * (`WithoutWrappingTransaction`): el camino por HTTP necesita datos
 * COMMITTEADOS (el servidor es otro proceso, y otra conexion no ve lo no
 * confirmado), y el `migrate:fresh --seed` de la instalacion en frio no cabe
 * dentro de una transaccion: el DDL de PostgreSQL aborta la transaccion
 * entera. El aislamiento lo da el `TRUNCATE` del `afterEach`.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    // El DATO de la prueba se va: la base de pruebas no puede quedarse con la
    // tienda, el perfil fiscal ni el catalogo de una semilla de demostracion.
    // `CASCADE` se lleva las tablas que dependen de estas, y `RESTART
    // IDENTITY` deja los contadores como estaban.
    //
    // LO QUE NO SE TRUNCA: `ledger_entry_types`. Ese catalogo lo siembra la
    // MIGRACION (M18), no el sembrador: es parte del estado migrado, y
    // vaciarlo deja la base sin el catalogo de tipos de asiento (y sin el, el
    // `LedgerWriter` no puede escribir el asiento de una venta).
    DB::statement(
        'TRUNCATE users, stores, countries, currencies, fiscal_profiles, tax_categories,'
        .' fiscal_providers, categories, brands, units, boxes, products, customers,'
        .' payment_methods, legacy_translation_map RESTART IDENTITY CASCADE'
    );
});

/**
 * La SEMILLA COMPLETA: el `DatabaseSeeder` entero, con su catalogo.
 *
 * REGLA que fija este helper: *la prueba siembra lo que siembra la
 * instalacion*. Un sembrador a medias no es la instalacion: el camino que se
 * prueba es el de `migrate:fresh --seed`.
 */
function demo_catalogo_sembla(): void
{
    Artisan::call('db:seed', ['--force' => true]);
}

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function demo_token_csrf(string $html): string
{
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $coincidencia) !== 1) {
        return '';
    }

    return $coincidencia[1];
}

/**
 * La PAGINA de Inertia que devuelve el servidor: el componente y sus props.
 *
 * @return array<string, mixed>
 */
function demo_pagina_de_inertia(string $html): array
{
    if (preg_match('/data-page="([^"]+)"/', $html, $coincidencia) !== 1) {
        return [];
    }

    $json = html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8');

    $pagina = json_decode($json, true);

    return is_array($pagina) ? $pagina : [];
}

/**
 * La TIENDA de demostracion.
 */
function demo_tienda(): int
{
    return (int) DB::table('stores')->where('code_name', 'DEMO')->value('id');
}

/**
 * El PERFIL FISCAL de la tienda, con sus categorias.
 *
 * @return array<string, int>
 */
function demo_categorias_fiscales(int $tienda): array
{
    $pais = DB::table('stores')->where('id', $tienda)->value('country_id');

    $perfil = DB::table('fiscal_profiles')->where('country_id', $pais)->value('id');

    return DB::table('tax_categories')
        ->where('fiscal_profile_id', $perfil)
        ->pluck('id', 'code')
        ->all();
}

/**
 * 1.9 — el stock sembrado es REAL: la suma de los lotes, y el cache coincide.
 *
 * REGLA que fija: *el stock de demostracion sale de LOTES reales, y el cache
 * de la tienda es su DERIVADO*. Un cache escrito a mano es una mentira que la
 * invariante (`StockInvariant::holds()`) detecta; un lote inventado es peor,
 * porque el lote y su linea de compra son 1:1.
 */
test('test_la_semilla_deja_productos_con_stock_real_y_el_cache_coincide_con_los_lotes', function () {
    demo_catalogo_sembla();

    $tienda = demo_tienda();

    expect($tienda)->toBeGreaterThan(0);

    $invariant = new StockInvariant(DB::connection());

    $fichas = DB::table('product_stores')
        ->where('store_id', $tienda)
        ->where('status', 'active')
        ->get();

    expect($fichas->count())->toBeGreaterThan(0);

    $conStock = 0;

    foreach ($fichas as $ficha) {
        $tipo = (string) DB::table('products')->where('id', $ficha->product_id)->value('type');

        // El servicio no tiene almacen: se comprueba en su propia prueba.
        if ($tipo === 'service') {
            continue;
        }

        $conStock++;

        $lotes = DB::table('inventory_lots')
            ->where('product_id', $ficha->product_id)
            ->where('store_id', $tienda)
            ->sum('available');

        // El disponible es la SUMA de los lotes: la verdad.
        expect(bccomp((string) $lotes, '0', 4))->toBe(1)
            // Y el CACHE coincide con la verdad: la invariante se cumple.
            ->and($invariant->holds((int) $ficha->product_id, $tienda))->toBeTrue()
            ->and(bccomp((string) $ficha->quantity_in_stock, (string) $lotes, 4))->toBe(0);
    }

    expect($conStock)->toBeGreaterThan(0);
});

/**
 * 1.9 — la CATEGORIA FISCAL que manda es la del OVERRIDE de la tienda si lo
 * hay, y si no, la del producto.
 *
 * REGLA que fija: *el override de la tienda manda sobre la categoria del
 * producto*. El sembrador deja el override en `null` en casi todos (para que
 * la precedencia la resuelva el DOMINIO, no el sembrador) y en uno con su
 * propio override, para que la regla se vea y se pruebe.
 *
 * La prueba no mira la fila: mira el TOTAL que calcula el motor. Un override
 * que no cambia el total es un override que no manda.
 */
test('test_la_categoria_fiscal_del_override_de_la_tienda_manda_sobre_la_del_producto', function () {
    demo_catalogo_sembla();

    $tienda = demo_tienda();
    $categorias = demo_categorias_fiscales($tienda);

    expect($categorias)->toHaveKey('gravable')
        ->and($categorias)->toHaveKey('exento');

    $contexto = new PosContext(DB::connection());
    $serie = $contexto->documentSeriesId();

    expect($serie)->not->toBeNull();

    $presupuesto = new PosBudget(DB::connection(), new TaxRateResolver(DB::connection()));

    $conOverride = 0;
    $gravables = 0;

    foreach (DB::table('product_stores')->where('store_id', $tienda)->get() as $ficha) {
        $producto = DB::table('products')->where('id', $ficha->product_id)->first();

        // Todo producto sembrado tiene su tratamiento fiscal: sin el, la caja
        // no lo puede cobrar.
        expect($producto->tax_category_id)->not->toBeNull();

        $total = $presupuesto->quote(
            $tienda,
            (int) $serie,
            $contexto->issuedAt(),
            [new CheckoutLineInput(
                productId: (int) $producto->id,
                quantity: '1',
                unitPrice: '10.0000',
            )],
        );

        if ($ficha->tax_category_id === null) {
            // SIN OVERRIDE: manda la categoria del PRODUCTO.
            if ((int) $producto->tax_category_id === $categorias['gravable']) {
                $gravables++;

                // El 16 % del perfil de demostracion.
                expect($total['tax_total'])->toBe('1.6000')
                    ->and($total['grand_total'])->toBe('11.6000');
            }

            continue;
        }

        $conOverride++;

        // CON OVERRIDE: el override de la TIENDA manda, aunque el producto sea
        // gravable. Y aqui el override es EXENTO: sin impuesto.
        expect((int) $ficha->tax_category_id)->toBe($categorias['exento'])
            ->and((int) $producto->tax_category_id)->toBe($categorias['gravable'])
            ->and($total['tax_total'])->toBe('0.0000')
            ->and($total['grand_total'])->toBe('10.0000');
    }

    // Y hay UN producto con override: la regla se ve, pero no se repite por
    // todas partes.
    expect($conOverride)->toBe(1)
        ->and($gravables)->toBeGreaterThan(0);
});

/**
 * 1.9 — el SERVICIO sembrado no tiene lote, ni compra, ni stock.
 *
 * REGLA que fija: *un servicio no tiene almacen*. No genera lote, no consume
 * stock y no aparece en la traza: no hay nada que reponer porque nunca salio
 * de un almacen.
 */
test('test_el_servicio_sembrado_no_tiene_lote_ni_stock', function () {
    demo_catalogo_sembla();

    $tienda = demo_tienda();

    $servicio = DB::table('products')->where('code', 'DEMO-SERVICIO-ENVIO')->first();

    expect($servicio)->not->toBeNull('La semilla no siembra el servicio de demostracion.')
        ->and((string) $servicio->type)->toBe('service');

    // Ni lote, ni linea de compra: el servicio no pasa por el almacen.
    expect(DB::table('inventory_lots')->where('product_id', $servicio->id)->count())->toBe(0)
        ->and(DB::table('purchase_items')->where('product_id', $servicio->id)->count())->toBe(0);

    // Y su ficha no tiene stock.
    $ficha = DB::table('product_stores')
        ->where('product_id', $servicio->id)
        ->where('store_id', $tienda)
        ->first();

    expect($ficha)->not->toBeNull()
        ->and(bccomp((string) $ficha->quantity_in_stock, '0', 4))->toBe(0)
        ->and((string) $ficha->status)->toBe('active');
});

/**
 * 1.9 — los SELECTORES del catalogo no salen vacios.
 *
 * REGLA que fija: *una instalacion sembrada tiene categorias, marcas, unidades
 * y cajas*. Son los selectores de la pantalla de catalogo, y hoy salen
 * vacios: el formulario de producto no ofrece nada que elegir.
 */
test('test_los_selectores_del_catalogo_sembrado_no_salen_vacios', function () {
    demo_catalogo_sembla();

    foreach (['categories', 'brands', 'units', 'boxes'] as $tabla) {
        expect(DB::table($tabla)->count())->toBeGreaterThan(
            0,
            "La tabla {$tabla} no tiene filas: su selector sale vacio."
        );
    }

    // Y las categorias tienen JERARQUIA: hay una categoria con padre, para que
    // el arbol se vea.
    expect(DB::table('categories')->whereNotNull('parent_id')->count())->toBeGreaterThan(0);

    // Y las unidades son las tres que se venden de verdad: una por unidad, una
    // de peso y una de paquete.
    $unidades = DB::table('units')->pluck('code')->all();

    expect($unidades)->toContain('DEMO-UNIT-UNIDAD')
        ->and($unidades)->toContain('DEMO-UNIT-KILOGRAMO')
        ->and($unidades)->toContain('DEMO-UNIT-PAQUETE');
});

/**
 * 1.9 — el CLIENTE de demostracion tiene ficha en la tienda, limite de
 * credito y saldo cero.
 *
 * REGLA que fija: *el cliente de demostracion esta en la tienda, con un
 * limite de credito razonable y sin deuda*. Es lo que permite probar la
 * venta a credito, el saldo derivado y el limite que bloquea.
 */
test('test_el_cliente_sembrado_tiene_ficha_en_la_tienda_y_limite_de_credito', function () {
    demo_catalogo_sembla();

    $tienda = demo_tienda();

    $cliente = DB::table('customers')->where('tax_id', 'DEMO-00000000')->first();

    expect($cliente)->not->toBeNull('La semilla no siembra el cliente de demostracion.')
        ->and((string) $cliente->tax_id)->toBe('DEMO-00000000')
        ->and((bool) $cliente->is_active)->toBeTrue();

    // El LIMITE de credito es un tope, y es razonable: ni cero (que bloquearia
    // toda venta) ni infinito (que no bloquearia ninguna).
    expect($cliente->credit_limit)->not->toBeNull()
        ->and(bccomp((string) $cliente->credit_limit, '0', 4))->toBe(1);

    $ficha = DB::table('customer_stores')
        ->where('customer_id', $cliente->id)
        ->where('store_id', $tienda)
        ->first();

    expect($ficha)->not->toBeNull('El cliente de demostracion no tiene ficha en la tienda.')
        ->and((string) $ficha->status)->toBe('active')
        ->and(bccomp((string) $ficha->balance, '0', 4))->toBe(0)
        ->and(bccomp((string) $ficha->due, '0', 4))->toBe(0);

    // Y el saldo DERIVADO es cero: el cache coincide con la verdad.
    $balances = new CustomerBalanceService(DB::connection());

    expect((string) $balances->balance((int) $cliente->id, $tienda))->toBe('0.0000')
        ->and($balances->holds((int) $cliente->id, $tienda))->toBeTrue();
});

/**
 * 1.9 — sembrar DOS VECES no duplica nada.
 *
 * REGLA que fija: *sembrar dos veces no duplica nada*. Todo se resuelve por su
 * clave natural (`code`, o el vinculo tienda+producto), como hace el sembrador
 * de la tienda.
 *
 * Y EL STOCK es el caso dificil: sembrar dos veces no puede crear una segunda
 * compra con un segundo lote del mismo producto. aqui la compra se identifica
 * por su `invoice_no` (que es unico por tienda), la linea por su par (compra,
 * producto) y el lote por su linea de compra (que es 1:1 y UNICA).
 */
test('test_sembrar_dos_veces_el_catalogo_de_demonstracion_no_duplica_nada', function () {
    demo_catalogo_sembla();

    $tablas = [
        'categories', 'brands', 'units', 'boxes', 'products', 'product_stores',
        'purchases', 'purchase_items', 'purchase_totals', 'inventory_lots',
        'customers', 'customer_stores',
    ];

    $antes = [];

    foreach ($tablas as $tabla) {
        $antes[$tabla] = DB::table($tabla)->count();
    }

    $lotesAntes = DB::table('inventory_lots')->orderBy('id')->pluck('id')->all();
    $comprasAntes = DB::table('purchases')->orderBy('id')->pluck('id')->all();

    // La SEGUNDA siembra: el `DatabaseSeeder` entero, otra vez.
    demo_catalogo_sembla();

    foreach ($tablas as $tabla) {
        expect(DB::table($tabla)->count())->toBe(
            $antes[$tabla],
            "La tabla {$tabla} duplico filas al sembrar dos veces."
        );
    }

    // Y no hay una SEGUNDA compra con un segundo lote: los lotes son los
    // MISMOS, con los MISMOS identificadores.
    expect(DB::table('inventory_lots')->orderBy('id')->pluck('id')->all())->toBe($lotesAntes)
        ->and(DB::table('purchases')->orderBy('id')->pluck('id')->all())->toBe($comprasAntes);
});

/**
 * 1.9 — la CAJA cobra con el catalogo sembrado: el camino por HTTP de verdad.
 *
 * REGLA que fija: *una instalacion sembrada cobra*. El catalogo devuelve
 * productos CON STOCK, y un cobro se completa de punta a punta.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS: un test con `actingAs()` verifica
 * la PAGINA. Este verifica el CAMINO: el arranque de verdad, el login de
 * verdad, el token CSRF de verdad y el enrutado real de las peticiones, contra
 * un servidor de verdad (`php artisan serve`) y una base de datos propia.
 *
 * El pago es a CREDITO, con el cliente sembrado: es el unico medio de pago
 * que trae la semilla (los demas los declara cada negocio), y es el caso que
 * ejercita el cliente, su saldo derivado y su limite de credito.
 */
test('test_la_caja_cobra_con_el_catalogo_sembrado', function () {
    $servidor = PosHttpServer::start();

    try {
        // 1. La INSTALACION, en la base de datos del camino: la semilla
        //    completa, con su catalogo.
        demo_catalogo_sembla();

        $tienda = demo_tienda();

        expect($tienda)->toBeGreaterThan(0);

        // 2. El CAJERO: un usuario con su vinculo por la tienda y la caja del
        //    dia ABIERTA (no se cobra sin caja abierta).
        $cajero = PosFixture::usuario($tienda);
        $cajero->email = 'catalogo@camino.test';
        $cajero->password = 'clave-del-camino';
        $cajero->save();

        // 3. El LOGIN, con su token CSRF.
        $jar = new CookieJar;

        $login = $servidor->cliente($jar)->get($servidor->url('/login'));

        expect($login->status())->toBe(200);

        $token = demo_token_csrf($login->body());

        expect($token)->not->toBe('');

        $sesion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/login'), [
                'email' => 'catalogo@camino.test',
                'password' => 'clave-del-camino',
            ]);

        expect($sesion->status())->toBe(302);

        // 4. La CAJA: el catalogo devuelve los productos SEMBRADOS, con su
        //    precio y su stock real.
        $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));

        expect($caja->status())->toBe(200);

        $props = demo_pagina_de_inertia($caja->body())['props'] ?? [];
        $catalogo = collect($props['catalog'] ?? []);

        $producto = $catalogo->firstWhere('code', 'DEMO-COLA-500');

        expect($producto)->not->toBeNull('La caja no muestra el producto sembrado.')
            ->and((string) $producto['sell_price'])->toBe('2.0000')
            ->and(bccomp((string) $producto['quantity_in_stock'], '0', 4))->toBe(1);

        // El token del login ya no vale: entrar regenera la sesion.
        $token = demo_token_csrf($caja->body());

        expect($token)->not->toBe('');

        // 5. El COBRO: la venta se escribe de verdad, con el stock real y el
        //    cliente sembrado.
        $clienteId = (int) DB::table('customers')->where('tax_id', 'DEMO-00000000')->value('id');
        $creditoId = (int) DB::table('payment_methods')->where('code', 'CREDITO')->value('id');

        $cobro = $servidor->cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/caja/cobrar'), [
                'customer_id' => $clienteId,
                'lines' => [
                    [
                        'product_id' => (int) $producto['id'],
                        'quantity' => '2',
                        'unit_price' => '2.0000',
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $creditoId,
                        'amount' => '4.6400',
                    ],
                ],
            ]);

        expect($cobro->status())->toBe(201)
            ->and($cobro->json('grand_total'))->toBe('4.6400')
            ->and($cobro->json('invoice_no'))->not->toBeNull()
            ->and((int) $cobro->json('sale_id'))->toBeGreaterThan(0);

        // 6. Y la VENTA esta escrita, con su linea y su total.
        $ventaId = (int) $cobro->json('sale_id');

        expect(DB::table('sales')->where('id', $ventaId)->count())->toBe(1)
            ->and(DB::table('sale_items')->where('sale_id', $ventaId)->count())->toBe(1)
            ->and((string) DB::table('sale_totals')->where('sale_id', $ventaId)->value('grand_total'))->toBe('4.6400');

        // 7. Y el STOCK bajo: el lote se consumio de verdad.
        $lotes = DB::table('inventory_lots')
            ->where('product_id', $producto['id'])
            ->where('store_id', $tienda)
            ->sum('available');

        expect(bccomp((string) $lotes, '22.0000', 4))->toBe(0);

        // 8. Y la DEUDA del cliente es el total de la venta: un pago a credito
        //    es deuda, no dinero.
        $balances = new CustomerBalanceService(DB::connection());

        expect((string) $balances->balance($clienteId, $tienda))->toBe('4.6400')
            ->and($balances->holds($clienteId, $tienda))->toBeTrue();
    } finally {
        $servidor->stop();
    }
});

/**
 * 1.9 — una INSTALACION EN FRIO deja el sistema operable.
 *
 * REGLA que fija: *`migrate:fresh --seed` deja una instalacion que puede
 * cobrar*. No es una suma de filas: es una venta real, hecha con el dominio,
 * con el catalogo sembrado.
 */
test('test_una_instalacion_en_frio_deja_el_sistema_operable', function () {
    // 1. El FLUJO de la instalacion: migraciones y seeders, en ese orden.
    Artisan::call('migrate:fresh', ['--seed' => true]);

    $tienda = demo_tienda();

    expect($tienda)->toBeGreaterThan(0);

    // 2. El CATALOGO: productos con ficha, con precio y con stock real.
    $fichas = DB::table('product_stores')
        ->where('store_id', $tienda)
        ->where('status', 'active')
        ->get();

    expect($fichas->count())->toBeGreaterThan(0);

    $conStock = 0;

    foreach ($fichas as $ficha) {
        $lotes = DB::table('inventory_lots')
            ->where('product_id', $ficha->product_id)
            ->where('store_id', $tienda)
            ->sum('available');

        if (bccomp((string) $lotes, '0', 4) === 1) {
            $conStock++;
        }

        expect(bccomp((string) $ficha->quantity_in_stock, (string) $lotes, 4))->toBe(0)
            ->and((string) $ficha->sell_price)->not->toBe('0.0000');
    }

    expect($conStock)->toBeGreaterThan(0);

    // 3. Y el CLIENTE, con su ficha en la tienda.
    $clienteId = (int) DB::table('customers')->where('tax_id', 'DEMO-00000000')->value('id');

    expect($clienteId)->toBeGreaterThan(0)
        ->and(DB::table('customer_stores')->where('customer_id', $clienteId)->where('store_id', $tienda)->count())->toBe(1);

    // 4. Y la VENTA: el dominio cobra de verdad, con el catalogo sembrado.
    $contexto = new PosContext(DB::connection());
    $documentSeriesId = $contexto->documentSeriesId();
    $controlSeriesId = $contexto->controlSeriesId();

    expect($documentSeriesId)->not->toBeNull();

    $productoId = (int) DB::table('products')->where('code', 'DEMO-COLA-500')->value('id');
    $precio = (string) DB::table('product_stores')
        ->where('product_id', $productoId)
        ->where('store_id', $tienda)
        ->value('sell_price');

    $creditoId = (int) DB::table('payment_methods')->where('code', 'CREDITO')->value('id');

    $servicio = app(CheckoutService::class);

    $resultado = $servicio->checkout(new CheckoutInput(
        storeId: $tienda,
        customerId: $clienteId,
        documentSeriesId: (int) $documentSeriesId,
        controlSeriesId: $controlSeriesId === null ? null : (int) $controlSeriesId,
        lines: [
            new CheckoutLineInput(
                productId: $productoId,
                quantity: '2',
                unitPrice: $precio,
                discount: '0',
            ),
        ],
        payments: [
            new CheckoutPaymentInput(
                paymentMethodId: $creditoId,
                amount: '4.6400',
                paymentMethodType: 'credit',
            ),
        ],
        accounts: [
            'revenue' => '4010000',
            'tax_payable' => '2110000',
            'cash' => '1100000',
            'receivable' => '1200000',
        ],
        issuedAt: $contexto->issuedAt(),
        businessDate: $contexto->businessDate(),
        actorId: null,
    ));

    // La venta se ESCRIBIO: cabecera, linea, total y asiento.
    expect($resultado->grandTotal)->toBe('4.6400')
        ->and(DB::table('sales')->where('id', $resultado->saleId)->count())->toBe(1)
        ->and(DB::table('sale_items')->where('sale_id', $resultado->saleId)->count())->toBe(1)
        ->and((string) DB::table('sale_totals')->where('sale_id', $resultado->saleId)->value('grand_total'))->toBe('4.6400')
        ->and(DB::table('ledger_entries')->where('reference_type', 'sale')->where('reference_id', $resultado->saleId)->count())->toBe(1);

    // Y el stock bajo: el lote se consumio.
    $lotes = DB::table('inventory_lots')
        ->where('product_id', $productoId)
        ->where('store_id', $tienda)
        ->sum('available');

    expect(bccomp((string) $lotes, '22.0000', 4))->toBe(0);
});
