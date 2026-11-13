<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * F2-01 — el CATALOGO de permisos y la tabla no pueden divergir.
 *
 * REGLA que fija: *el conjunto de permisos es CERRADO y vive en UN solo
 * sitio, en codigo*. Un permiso que no esta en el catalogo no existe, y uno
 * que esta existe para todos.
 *
 * Y EL CATALOGO Y LA TABLA SON LA MISMA COSA: el enum es la fuente, el
 * sembrador siembra desde el enum, y por eso no pueden separarse. Si la
 * tabla tuviera un permiso que el enum no tiene, ese permiso no lo aplica
 * nadie y no lo puede comprobar nadie; y si al enum le faltara un permiso
 * que la tabla tiene, la aplicacion concede algo que no esta en su
 * catalogo.
 *
 * Y SIEMBRAR DOS VECES NO CAMBIA NADA: los roles por defecto son un
 * CONTRATO, y un contrato que se duplica no es un contrato.
 */

/**
 * El catalogo y la tabla tienen los MISMOS permisos: ni uno de mas, ni uno
 * de menos.
 */
test('test_el_catalogo_de_permisos_y_la_tabla_no_pueden_divergir', function () {
    PermissionFixture::catalogo();

    $deLaTabla = DB::table('permissions')->pluck('slug')->all();

    $faltan = array_diff(Permission::slugs(), $deLaTabla);
    $sobran = array_diff($deLaTabla, Permission::slugs());

    expect($faltan)->toBe([], 'El catalogo tiene permisos que la tabla no: '.implode(', ', $faltan))
        ->and($sobran)->toBe([], 'La tabla tiene permisos que el catalogo no: '.implode(', ', $sobran));
});

/**
 * Cada permiso de la tabla guarda su modulo, su accion y su etiqueta: las
 * tres columnas salen del enum, no se escriben a mano.
 */
test('test_cada_permiso_de_la_tabla_guarda_su_modulo_su_accion_y_su_etiqueta', function () {
    PermissionFixture::catalogo();

    foreach (Permission::cases() as $permiso) {
        $fila = DB::table('permissions')->where('slug', $permiso->value)->first();

        expect($fila)->not->toBeNull("El permiso {$permiso->value} no esta sembrado.")
            ->and((string) $fila->module)->toBe($permiso->module())
            ->and((string) $fila->action)->toBe($permiso->action())
            ->and((string) $fila->label)->toBe($permiso->label());
    }
});

/**
 * Los slugs del catalogo tienen la FORMA del catalogo: `{modulo}.{accion}`.
 */
test('test_cada_slug_del_catalogo_es_un_modulo_y_una_accion', function () {
    foreach (Permission::cases() as $permiso) {
        expect($permiso->value)->toContain('.')
            ->and($permiso->module())->not->toBe('')
            ->and($permiso->action())->not->toBe('')
            ->and($permiso->module().'.'.$permiso->action())->toBe($permiso->value);
    }

    // Y el catalogo tiene MODULOS: una lista de permisos sin modulo no
    // agrupa nada, y la pantalla de un rol necesita los grupos.
    expect(Permission::modulos())->not->toBeEmpty()
        ->and(count(Permission::modulos()))->toBeLessThan(count(Permission::cases()));
});

/**
 * Sembrar dos veces no duplica NI UNA fila: ni permisos, ni roles, ni
 * vinculos.
 */
test('test_sembrar_dos_veces_no_duplica_permisos_ni_roles_ni_vinculos', function () {
    PermissionFixture::catalogo();

    $tablas = ['permissions', 'roles', 'role_permissions', 'role_store_scopes'];

    $antes = [];

    foreach ($tablas as $tabla) {
        $antes[$tabla] = DB::table($tabla)->count();
    }

    PermissionFixture::catalogo();

    foreach ($antes as $tabla => $conteo) {
        expect(DB::table($tabla)->count())->toBe(
            $conteo,
            "La tabla {$tabla} duplico filas al sembrar dos veces."
        );
    }
});

/**
 * Los TRES roles por defecto tienen EXACTAMENTE el reparto del contrato.
 */
test('test_los_tres_roles_por_defecto_tienen_el_reparto_del_contrato', function () {
    PermissionFixture::catalogo();

    foreach (PermissionSeeder::matriz() as $slug => $permisos) {
        $rol = DB::table('roles')->where('slug', $slug)->first();

        expect($rol)->not->toBeNull("El rol {$slug} no esta sembrado.");

        $slugsDelRol = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $rol->id)
            ->orderBy('permissions.slug')
            ->pluck('permissions.slug')
            ->all();

        $esperados = array_map(
            static fn (Permission $permiso): string => $permiso->value,
            $permisos,
        );

        sort($esperados);

        expect($slugsDelRol)->toBe($esperados, "El rol {$slug} no tiene el reparto del contrato.");
    }
});

/**
 * Los usuarios de demostracion tienen ROL: el superusuario lo puede todo y
 * el cajero opera en la tienda de demostracion.
 */
test('test_el_superusuario_de_demostracion_lo_puede_todo_y_el_cajero_tiene_ambito', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    (new DemoUsersSeeder)->run();

    $superusuario = User::where('email', DemoUsersSeeder::CORREO_SUPERUSER)->first();

    expect($superusuario)->not->toBeNull('La semilla no siembra el superusuario de demostracion.')
        ->and($superusuario->isSuperuser())->toBeTrue()
        ->and($superusuario->permissions())->toBe(Permission::slugs());

    $cajero = User::where('email', DemoUsersSeeder::CORREO_CASHIER)->first();

    expect($cajero)->not->toBeNull('La semilla no siembra el cajero de demostracion.')
        ->and($cajero->isSuperuser())->toBeFalse()
        ->and($cajero->hasPermission('pos.access'))->toBeTrue()
        ->and($cajero->hasPermission('accounting.update'))->toBeFalse();

    // Y el ambito del cajero es la tienda de demostracion.
    $ambito = DB::table('role_store_scopes')
        ->where('role_id', $cajero->group_id)
        ->pluck('store_id')
        ->all();

    expect($ambito)->toBe([$escenario['store_id']]);
});
