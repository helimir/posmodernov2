<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * La LECTURA de las ventas respeta el AMBITO del rol.
 *
 * REGLA que fija este fichero: *una venta de otra tienda no existe para quien
 * pregunta*. Y no es un `where` que cada pantalla recuerda: es el ALCANCE POR
 * DEFECTO de la tabla de ventas, un scope global de Eloquent, y por eso la
 * LISTA y la FICHA se filtran con el MISMO criterio y no pueden separarse.
 *
 * Y EL CRITERIO ES UNO SOLO: `PosContext::scopeIds()`, el ambito de tiendas
 * del ROL. Un rol con ambito ve las suyas y solo las suyas; un rol SIN ambito
 * ve todas las tiendas, que es el caso del superusuario y del contador.
 *
 * Y LA PRUEBA ES DE COMPORTAMIENTO, no de codigo: con dos tiendas y un
 * cajero con ambito en la primera, la lista NO trae la venta de la segunda,
 * la ficha de la segunda es un 404 y la ficha de la primera se sirve. Un
 * test que leyera el codigo seria fragil: el scope es lo que protege las
 * rutas de manana.
 */

/**
 * El ESCENARIO de dos tiendas: la primera (A) y la de al lado (B), con una
 * venta en cada una y un cajero con ambito en A.
 *
 * @return array{
 *     tienda_a: int,
 *     tienda_b: int,
 *     venta_a: Sale,
 *     venta_b: Sale,
 *     rol: Role,
 *     usuario: User
 * }
 */
function multitienda_escenario(): array
{
    PermissionFixture::catalogo();

    $tiendaA = (int) Store::create(['name' => 'Tienda del cajero'])->id;
    $tiendaB = (int) Store::create(['name' => 'Tienda de al lado'])->id;

    // Las VENTAS: una en cada tienda. Los NUMEROS son distintos, porque la
    // lista se mira por numero y dos numeros iguales no distinguen una venta
    // de la otra.
    $ventaA = Sale::query()->create([
        'store_id' => $tiendaA,
        'invoice_no' => 'MULTI-A-0001',
        'sale_date' => PosFixture::businessDate($tiendaA),
        'status' => 'paid',
    ]);

    $ventaB = Sale::query()->create([
        'store_id' => $tiendaB,
        'invoice_no' => 'MULTI-B-0001',
        'sale_date' => PosFixture::businessDate($tiendaB),
        'status' => 'paid',
    ]);

    // El CAJERO: un rol con `sales.read` y con AMBITO en la tienda A. Y el
    // usuario con su vinculo por A y por B, con B PRIMERO: sin el ambito, la
    // tienda del contexto seria B, y el escenario tiene que demostrar que el
    // ambito del ROL manda sobre el vinculo del usuario.
    $rol = PermissionFixture::rol('cajero-multitienda-'.uniqid(), [
        Permission::SalesRead->value,
    ]);

    PermissionFixture::ambito($rol, [$tiendaA]);

    $usuario = PermissionFixture::usuarioEnTiendas($rol, [$tiendaB, $tiendaA]);

    return [
        'tienda_a' => $tiendaA,
        'tienda_b' => $tiendaB,
        'venta_a' => $ventaA,
        'venta_b' => $ventaB,
        'rol' => $rol,
        'usuario' => $usuario,
    ];
}

/**
 * Los IDENTIFICADORES de las ventas que la pantalla haensenado.
 *
 * @param  mixed  $ventas  El prop `sales` de la pagina.
 * @return list<int>
 */
function multitienda_ids(mixed $ventas): array
{
    return collect($ventas)
        ->map(static fn ($venta): int => (int) $venta['id'])
        ->all();
}

/**
 * La LISTA de ventas no ensena las ventas de OTRA TIENDA.
 *
 * REGLA que fija: *la lista de ventas esta acotada al ambito del rol*. Un
 * cajero con ambito en la tienda A ve las ventas de A y ninguna de la B: una
 * venta de otra tienda no se teach porque no existe para quien pregunta.
 */
test('test_la_lista_de_ventas_no_ensena_las_ventas_de_otra_tienda', function () {
    $escenario = multitienda_escenario();

    $this->actingAs($escenario['usuario'])
        ->get('/ventas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/index')
            ->has('sales', 1)
            ->where('sales.0.id', $escenario['venta_a']->id)
            ->where('sales.0.invoice_no', 'MULTI-A-0001')
            ->where('sales', function ($ventas) use ($escenario): bool {
                $ids = multitienda_ids($ventas);

                return in_array($escenario['venta_a']->id, $ids, true)
                    && ! in_array($escenario['venta_b']->id, $ids, true);
            })
            ->etc());
});

/**
 * La FICHA de una venta de OTRA TIENDA no existe, y la de la propia si.
 *
 * REGLA que fija: *el filtro de la lista y el de un elemento son el MISMO*.
 * La ficha de una venta ajena es un 404, no un 403: un 403 le diria al
 * cajero que la venta EXISTE, y eso ya es informacion de otra tienda.
 */
test('test_la_ficha_de_una_venta_de_otra_tienda_no_existe', function () {
    $escenario = multitienda_escenario();

    $this->actingAs($escenario['usuario'])
        ->get('/ventas/'.$escenario['venta_b']->id)
        ->assertNotFound();

    $this->actingAs($escenario['usuario'])
        ->get('/ventas/'.$escenario['venta_a']->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/show')
            ->where('sale.id', $escenario['venta_a']->id)
            ->where('sale.invoice_no', 'MULTI-A-0001')
            ->etc());
});

/**
 * El ALCANCE de las ventas es el del ambito del rol, y no un `where` de cada
 * pantalla.
 *
 * REGLA que fija: *el filtro es el ALCANCE de la tabla, y por eso no se
 * puede saltar por descuido*. Un `Sale::find()` de una venta de otra tienda
 * NO la encuentra, porque el alcance esta en la propia tabla y no en la
 * pantalla que la pregunta.
 */
test('test_el_scope_de_ventas_no_deja_leer_una_venta_de_otra_tienda', function () {
    $escenario = multitienda_escenario();

    $this->actingAs($escenario['usuario']);

    expect(Sale::query()->find($escenario['venta_b']->id))->toBeNull()
        ->and(Sale::query()->find($escenario['venta_a']->id))->not->toBeNull();
});

/**
 * Un ROL SIN ambito ve las ventas de TODAS las tiendas.
 *
 * REGLA que fija: *el ambito es del ROL, y un rol sin ambito alcanza todas
 * las tiendas*. Es el caso del contador, que supervisa todas las tiendas sin
 * estar atado a ninguna.
 */
test('test_un_rol_sin_ambito_ve_las_ventas_de_todas_las_tiendas', function () {
    $escenario = multitienda_escenario();

    // El MISMO vinculo por A y por B, pero un rol SIN ambito.
    $rol = PermissionFixture::rol('contador-multitienda-'.uniqid(), [
        Permission::SalesRead->value,
    ]);

    $usuario = PermissionFixture::usuarioEnTiendas($rol, [
        $escenario['tienda_b'],
        $escenario['tienda_a'],
    ]);

    $this->actingAs($usuario)
        ->get('/ventas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/index')
            ->has('sales', 2)
            ->where('sales', function ($ventas) use ($escenario): bool {
                $ids = multitienda_ids($ventas);

                return in_array($escenario['venta_a']->id, $ids, true)
                    && in_array($escenario['venta_b']->id, $ids, true);
            })
            ->etc());

    // Y la ficha de la venta de la otra tienda se sirve: la lista y el
    // elemento tienen el mismo criterio.
    $this->actingAs($usuario)
        ->get('/ventas/'.$escenario['venta_b']->id)
        ->assertOk();
});

/**
 * El SUPERUSUARIO ve las ventas de TODAS las tiendas.
 *
 * REGLA que fija: *el superusuario ve todas las tiendas por definicion*. Su
 * rol no tiene ambito, asi que su alcance son todas, y el ambito no le
 * recorta lo que ve.
 */
test('test_el_superusuario_ve_las_ventas_de_todas_las_tiendas', function () {
    $escenario = multitienda_escenario();

    // La factory crea al superusuario: su rol no tiene ambito.
    $superusuario = User::factory()->create();

    $this->actingAs($superusuario)
        ->get('/ventas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/index')
            ->has('sales', 2)
            ->where('sales', function ($ventas) use ($escenario): bool {
                $ids = multitienda_ids($ventas);

                return in_array($escenario['venta_a']->id, $ids, true)
                    && in_array($escenario['venta_b']->id, $ids, true);
            })
            ->etc());

    // Y la ficha de la venta de la otra tienda se sirve.
    $this->actingAs($superusuario)
        ->get('/ventas/'.$escenario['venta_b']->id)
        ->assertOk();
});
