<?php

use App\Domain\Auth\Enums\Permission;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * F2-01 — el AMBITO POR TIENDA del rol.
 *
 * REGLA que fija: *un usuario cuyo rol tiene ambito en la tienda A no ve la
 * tienda B*. El ambito son las filas de `role_store_scopes`, y donde se
 * aplica es en la resolucion de la tienda activa (`PosContext`): la tienda
 * de la caja, la del catalogo, la del plan de cuentas y la del reporte son
 * la del ambito.
 *
 * Y SI EL ROL NO TIENE NINGUN AMBITO, el alcance son TODAS las tiendas: un
 * rol sin ambito no esta limitado, y ese es el caso del superusuario y del
 * contador.
 *
 * Y EL ALCANCE NO ES SOLO LA TIENDA ACTIVA: un comprobante de una tienda
 * fuera del ambito es un 404, lo mismo que si la tienda no existiera.
 */

/**
 * La TIENDA DE AL LADO: la segunda tienda del escenario, con el perfil
 * fiscal de la primera (y con su moneda).
 */
function tienda_de_al_lado(int $profileId, ?int $countryId): int
{
    return (int) DB::table('stores')->insertGetId([
        'name' => 'Tienda de al lado',
        'code_name' => 'AL-LADO',
        'country_id' => $countryId,
        'fiscal_profile_id' => $profileId,
        'sort_order' => 8,
        'status' => true,
        'feedback_status' => 'ready',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * El ESCENARIO de dos tiendas: la de demostracion (A) y la de al lado (B).
 *
 * @return array<string, int>
 */
function escenario_de_ambito(): array
{
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $profileId = (int) DB::table('stores')
        ->where('id', $escenario['store_id'])
        ->value('fiscal_profile_id');

    $countryId = DB::table('stores')
        ->where('id', $escenario['store_id'])
        ->value('country_id');

    $tiendaB = tienda_de_al_lado($profileId, $countryId === null ? null : (int) $countryId);

    // Un producto con stock en CADA tienda: el de B no puede salir en el
    // reporte de A.
    $productoDeA = PosFixture::productoConStock(
        $escenario['store_id'],
        $escenario['tax_category_id'],
        '1.0000'
    );

    $productoDeB = PosFixture::productoConStock(
        $tiendaB,
        $escenario['tax_category_id'],
        '2.0000'
    );

    return [
        'tienda_a' => (int) $escenario['store_id'],
        'tienda_b' => $tiendaB,
        'producto_a' => $productoDeA,
        'producto_b' => $productoDeB,
    ];
}

/**
 * Un cajero con ambito en la tienda A NO ve los datos de la tienda B.
 */
test('test_un_cajero_con_ambito_en_una_tienda_no_ve_los_datos_de_otra', function () {
    $escenario = escenario_de_ambito();

    // El rol con ambito en A. Y el usuario con el vinculo por A y por B, con
    // B PRIMERO: sin el ambito, la tienda activa seria B.
    $rol = PermissionFixture::rol('cajero-ambito-'.uniqid(), [
        Permission::ReportRead->value,
        Permission::ReceiptRead->value,
    ]);

    PermissionFixture::ambito($rol, [$escenario['tienda_a']]);

    $usuario = PermissionFixture::usuarioEnTiendas($rol, [
        $escenario['tienda_b'],
        $escenario['tienda_a'],
    ]);

    // El REPORTE: el de la tienda del ambito, y no el de la otra.
    $this->actingAs($usuario)
        ->get('/reportes/stock')
        ->assertOk()
        ->assertInertia(fn ($page): mixed => $page
            ->where('store.id', $escenario['tienda_a'])
            ->where('products', function ($productos) use ($escenario): bool {
                $ids = collect($productos)->map(static fn ($producto): int => (int) $producto['id'])->all();

                return in_array($escenario['producto_a'], $ids, true)
                    && ! in_array($escenario['producto_b'], $ids, true);
            })
            ->etc());

    // Y el COMPROBANTE de la tienda de al lado: un 404, lo mismo que si no
    // existiera.
    $documentoDeB = PermissionFixture::documento($escenario['tienda_b']);

    $this->actingAs($usuario)
        ->get('/comprobantes/'.$documentoDeB)
        ->assertNotFound();
});

/**
 * Un rol SIN ambito alcanza todas las tiendas: la tienda activa es la
 * primera del usuario, y su comprobante se sirve.
 */
test('test_un_rol_sin_ambito_ve_todas_las_tiendas', function () {
    $escenario = escenario_de_ambito();

    // El MISMO vinculo por A y por B, con B primero, pero un rol SIN ambito.
    $rol = PermissionFixture::rol('contador-sin-ambito-'.uniqid(), [
        Permission::ReportRead->value,
        Permission::ReceiptRead->value,
    ]);

    $usuario = PermissionFixture::usuarioEnTiendas($rol, [
        $escenario['tienda_b'],
        $escenario['tienda_a'],
    ]);

    // La tienda activa es la primera del usuario: la de al lado.
    $this->actingAs($usuario)
        ->get('/reportes/stock')
        ->assertOk()
        ->assertInertia(fn ($page): mixed => $page
            ->where('store.id', $escenario['tienda_b'])
            ->where('products', function ($productos) use ($escenario): bool {
                $ids = collect($productos)->map(static fn ($producto): int => (int) $producto['id'])->all();

                return in_array($escenario['producto_b'], $ids, true)
                    && ! in_array($escenario['producto_a'], $ids, true);
            })
            ->etc());

    // Y el comprobante de esa tienda se sirve.
    $documentoDeB = PermissionFixture::documento($escenario['tienda_b']);

    $this->actingAs($usuario)
        ->get('/comprobantes/'.$documentoDeB)
        ->assertOk();
});
