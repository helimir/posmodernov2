<?php

use App\Domain\Credit\CustomerBalanceService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * Las PANTALLAS de los clientes: el listado, el formulario y la ficha.
 *
 * Reglas que fija:
 *
 *  - las tres pantallas exigen sesion: sin autenticar, REDIRIGEN (no
 *    responden 200 con una pantalla vacia);
 *  - el LISTADO ensena los clientes de la TIENDA ACTIVA, con su saldo
 *    DERIVADO, su deuda, su limite de credito y si estan sobre el limite;
 *  - la FICHA ensena el saldo derivado, la deuda, el limite, el CACHE y el
 *    EXTRACTO, y AVISA cuando el cache no coincide con el derivado y cuando
 *    la deuda supera el limite;
 *  - el formulario de ALTA y el de EDICION son la MISMA pantalla, con el
 *    cliente o sin el;
 *  - la API JSON de clientes sigue en `/api/clientes` y las PAGINAS en
 *    `/clientes`: una ruta no puede servir las dos cosas.
 *
 * El LIMITE DE CREDITO se MUESTRA y se AVISA si la deuda lo supera, pero NO
 * bloquea la venta: bloquear es una decision de negocio que todavia no esta
 * tomada.
 */

/**
 * El escenario de las pantallas: la tienda de demostracion, su cajero y tres
 * clientes con su ficha en la tienda.
 *
 * @return array<string, mixed>
 */
function escenario_de_las_paginas_de_clientes(): array
{
    $escenario = PosFixture::escenario();

    return [
        'escenario' => $escenario,
        'usuario' => PosFixture::usuario($escenario['store_id']),
        'ana' => CreditFixture::customer($escenario['store_id'], 'Ana Perez', 'V-12345678', '500.0000'),
        'andres' => CreditFixture::customer($escenario['store_id'], 'Andres Ruiz', 'V-87654321'),
        'brenda' => CreditFixture::customer($escenario['store_id'], 'Brenda Diaz', 'J-11111111'),
    ];
}

/**
 * El servicio de credito del cliente en la tienda.
 */
function servicio_de_credito_de_las_paginas(): CustomerBalanceService
{
    return new CustomerBalanceService(DB::connection());
}

/**
 * El LISTADO ensena los clientes de la TIENDA ACTIVA, con su saldo DERIVADO,
 * su deuda, su limite y si estan sobre el limite.
 */
test('test_la_pagina_de_clientes_lista_los_de_la_tienda_activa_con_saldo_deuda_y_limite', function () {
    $datos = escenario_de_las_paginas_de_clientes();
    $escenario = $datos['escenario'];

    // Ana DEBE 100: su saldo derivado y su deuda lo dicen, no el cache.
    servicio_de_credito_de_las_paginas()->registerSale(
        customerId: $datos['ana'],
        storeId: $escenario['store_id'],
        amount: '100.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    // Y un cliente de OTRA tienda, que no aparece: el credito es por tienda.
    CreditFixture::customer(CreditFixture::store(), 'Carlos Fuera', 'V-00000000');

    $this->actingAs($datos['usuario'])
        ->get('/clientes')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('customers/index')
            ->has('customers', 3)
            ->where('customers.0.id', $datos['ana'])
            ->where('customers.0.name', 'Ana Perez')
            ->where('customers.0.tax_id', 'V-12345678')
            // El saldo DERIVADO y la deuda, como texto decimal.
            ->where('customers.0.balance', '100.0000')
            ->where('customers.0.due', '100.0000')
            // Y el LIMITE, con la respuesta a si esta sobre el.
            ->where('customers.0.credit_limit', '500.0000')
            ->where('customers.0.over_limit', false)
            ->where('customers.1.id', $datos['andres'])
            ->where('customers.1.balance', '0.0000')
            ->where('customers.1.due', '0.0000')
            ->where('customers.1.credit_limit', null)
            ->where('customers.1.over_limit', false)
            // La PAGINACION: 25 por pagina.
            ->where('pagination.page', 1)
            ->where('pagination.per_page', 25)
            ->where('pagination.total', 3)
            ->where('pagination.last_page', 1)
            ->where('filters.q', '')
            // La TIENDA ACTIVA y su MONEDA: un importe sin el simbolo no se
            // lee.
            ->where('store.id', $escenario['store_id'])
            ->where('store.name', 'Tienda de demostracion')
            ->where('currency.code', 'VES')
            ->where('currency.symbol', 'Bs.')
            ->etc());
});

/**
 * El listado BUSCA por nombre (como prefijo) o por documento (exacto), y la
 * pagina que viene es la que corresponde a la busqueda.
 */
test('test_la_pagina_de_clientes_busca_por_nombre_por_documento_y_pagina', function () {
    $datos = escenario_de_las_paginas_de_clientes();

    // 1. POR NOMBRE, como PREFIJO.
    $this->actingAs($datos['usuario'])
        ->get('/clientes?q=An')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('customers/index')
            ->has('customers', 2)
            ->where('filters.q', 'An')
            ->etc());

    // 2. POR DOCUMENTO, exacto.
    $this->actingAs($datos['usuario'])
        ->get('/clientes?q=V-87654321')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers', 1)
            ->where('customers.0.id', $datos['andres'])
            ->where('pagination.total', 1)
            ->etc());

    // 3. Y un documento que es PREFIJO de otro NO los trae a los dos.
    $this->actingAs($datos['usuario'])
        ->get('/clientes?q=V-1234')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('customers', 0)
            ->where('pagination.total', 0)
            ->etc());
});

/**
 * La FICHA ensena el saldo DERIVADO, la deuda, el limite, el cache y el
 * EXTRACTO de los movimientos, del mas nuevo al mas viejo.
 */
test('test_la_ficha_del_cliente_trae_el_saldo_la_deuda_el_limite_y_el_extracto', function () {
    $datos = escenario_de_las_paginas_de_clientes();
    $escenario = $datos['escenario'];
    $servicio = servicio_de_credito_de_las_paginas();

    $servicio->registerSale(
        customerId: $datos['ana'],
        storeId: $escenario['store_id'],
        amount: '100.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    $servicio->registerPayment(
        customerId: $datos['ana'],
        storeId: $escenario['store_id'],
        amount: '40.0000',
        referenceNo: 'RECIBO-1',
        entryDate: new DateTimeImmutable('2026-11-05'),
    );

    $this->actingAs($datos['usuario'])
        ->get('/clientes/'.$datos['ana'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('customers/show')
            ->where('customer.id', $datos['ana'])
            ->where('customer.name', 'Ana Perez')
            ->where('customer.tax_id', 'V-12345678')
            // Las TRES cifras que importan.
            ->where('balance', '60.0000')
            ->where('due', '60.0000')
            ->where('credit_limit', '500.0000')
            // El CACHE, que coincide con el derivado.
            ->where('cache.balance', '60.0000')
            ->where('cache.due', '60.0000')
            ->where('cache_matches', true)
            ->where('over_limit', false)
            // El EXTRACTO: del mas nuevo al mas viejo.
            ->has('entries', 2)
            ->where('entries.0.type', 'payment')
            ->where('entries.0.reference_no', 'RECIBO-1')
            ->where('entries.0.amount', '-40.0000')
            ->where('entries.0.balance_after', '60.0000')
            ->where('entries.0.entry_date', '2026-11-05')
            ->where('entries.1.type', 'sale')
            ->where('entries.1.amount', '100.0000')
            ->where('entries.1.balance_after', '100.0000')
            ->where('pagination.page', 1)
            ->where('pagination.per_page', 25)
            ->where('pagination.total', 2)
            ->where('store.id', $escenario['store_id'])
            ->where('currency.code', 'VES')
            ->etc());
});

/**
 * La ficha AVISA cuando el CACHE no coincide con el saldo derivado: el cache
 * puede desfasarse, la verdad no, y la pantalla tiene que saberlo.
 */
test('test_la_ficha_del_cliente_avisa_cuando_el_cache_no_coincide_con_el_saldo_derivado', function () {
    $datos = escenario_de_las_paginas_de_clientes();
    $escenario = $datos['escenario'];

    // Un movimiento escrito FUERA del servicio: el cache se queda atras.
    DB::table('customer_ledger_entries')->insert([
        'customer_id' => $datos['ana'],
        'store_id' => $escenario['store_id'],
        'type' => 'sale',
        'reference_no' => 'VENTA-FUERA',
        'amount' => '100.0000',
        'entry_date' => '2026-11-04',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($datos['usuario'])
        ->get('/clientes/'.$datos['ana'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('customers/show')
            // El DERIVADO es la verdad.
            ->where('balance', '100.0000')
            ->where('due', '100.0000')
            // Y el cache dice 0,0000: esta DESFASADO.
            ->where('cache.balance', '0.0000')
            ->where('cache_matches', false)
            ->etc());
});

/**
 * La ficha AVISA cuando la DEUDA supera el LIMITE de credito del cliente.
 */
test('test_la_ficha_del_cliente_avisa_cuando_la_deuda_supera_el_limite_de_credito', function () {
    $datos = escenario_de_las_paginas_de_clientes();
    $escenario = $datos['escenario'];

    // Andres DEBE 100 con un limite de 50: esta sobre el limite.
    $brenda = CreditFixture::customer($escenario['store_id'], 'Brenda Diaz', 'J-11111111', '50.0000');

    servicio_de_credito_de_las_paginas()->registerSale(
        customerId: $brenda,
        storeId: $escenario['store_id'],
        amount: '100.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    $this->actingAs($datos['usuario'])
        ->get('/clientes/'.$brenda)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('customers/show')
            ->where('due', '100.0000')
            ->where('credit_limit', '50.0000')
            ->where('over_limit', true)
            ->etc());
});

/**
 * El formulario de ALTA y el de EDICION son la MISMA pantalla: con el cliente
 * o sin el.
 */
test('test_el_formulario_de_alta_y_el_de_edicion_entregan_los_datos_del_cliente', function () {
    $datos = escenario_de_las_paginas_de_clientes();
    $escenario = $datos['escenario'];

    // 1. El ALTA: sin cliente, con los selectores.
    //
    // El PAIS del selector sale de la FILA: no hay ningun literal de pais en
    // la pantalla, y el nombre que se espera es el de la fila del pais de la
    // tienda.
    $paisDeLaTienda = DB::table('countries')
        ->where('id', DB::table('stores')->where('id', $escenario['store_id'])->value('country_id'))
        ->value('name');

    $this->actingAs($datos['usuario'])
        ->get('/clientes/nuevo')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('customers/form')
            ->where('customer', null)
            // Los PAISES: son FILAS, no un literal de la interfaz.
            ->has('countries', 5)
            ->where('countries', fn ($paises): bool => collect($paises)
                ->contains('name', $paisDeLaTienda))
            ->where('store.id', $escenario['store_id'])
            ->where('currency.code', 'VES')
            ->etc());

    // 2. La EDICION: con el cliente y sus datos.
    $this->actingAs($datos['usuario'])
        ->get('/clientes/'.$datos['ana'].'/editar')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('customers/form')
            ->where('customer.id', $datos['ana'])
            ->where('customer.name', 'Ana Perez')
            ->where('customer.tax_id', 'V-12345678')
            ->where('customer.credit_limit', '500.0000')
            ->where('customer.is_active', true)
            ->etc());
});

/**
 * Sin sesion, las pantallas de clientes REDIRIGEN: no se sirve una pantalla de
 * clientes a quien no ha entrado.
 */
test('test_sin_autenticar_las_paginas_de_clientes_redirigen_al_login', function () {
    $this->get('/clientes')->assertRedirect('/login');
    $this->get('/clientes/nuevo')->assertRedirect('/login');
    $this->get('/clientes/1')->assertRedirect('/login');
    $this->get('/clientes/1/editar')->assertRedirect('/login');
});

/**
 * La API JSON de clientes SIGUE en `/api/clientes`, y las PAGINAS en
 * `/clientes`: una ruta no puede servir las dos cosas.
 *
 * Este test es el que BLINDA la separacion: mientras `/clientes` devuelva la
 * pagina de Inertia, la API no puede vivir ahi; y mientras la API viva en
 * `/api/clientes`, la pagina no puede estar en la misma ruta.
 */
test('test_la_api_de_clientes_sigue_en_una_ruta_de_api_y_las_paginas_en_la_suya', function () {
    $datos = escenario_de_las_paginas_de_clientes();
    $usuario = $datos['usuario'];

    // 1. La LISTA: en `/api/clientes` es JSON, y en `/clientes` es la pagina.
    $api = $this->actingAs($usuario)->getJson('/api/clientes');

    $api->assertOk()->assertJsonStructure(['customers', 'pagination', 'filters', 'store']);

    $this->actingAs($usuario)
        ->get('/clientes')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('customers/index')
            ->etc());

    // 2. La FICHA: en `/api/clientes/{id}` es JSON, y en `/clientes/{id}` es la
    //    pagina.
    $ficha = $this->actingAs($usuario)->getJson('/api/clientes/'.$datos['ana']);

    $ficha->assertOk()->assertJsonStructure(['customer', 'balance', 'due', 'credit_limit', 'cache', 'cache_matches']);

    $this->actingAs($usuario)
        ->get('/clientes/'.$datos['ana'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('customers/show')
            ->etc());

    // 3. Y el selector de la caja tambien se movio a la API: en la ruta
    //    vieja ya no hay nada.
    $this->actingAs($usuario)->getJson('/caja/clientes')->assertNotFound();
    $this->actingAs($usuario)->getJson('/api/caja/clientes')->assertOk()->assertJsonStructure(['customers']);
});
