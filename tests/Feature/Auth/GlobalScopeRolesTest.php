<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * #163 — el ALCANCE GLOBAL de un rol no se acota.
 *
 * REGLA que fija: *un rol de alcance global no tiene ambito, y no se le
 * puede acotar*. Su alcance son TODAS las tiendas, asi que las filas de
 * `role_store_scopes` de ese rol son CERO.
 *
 * Y POR QUE ES GRAVE: el ambito del rol es el CRITERIO de alcance que
 * consumen la caja, el POS, la cola de trabajos de impresion, la cola de
 * tickets del cajero y la lectura de ventas. Acotar un rol de alcance global
 * recorta el sistema entero: el superusuario dejaria de ver las ventas de
 * las tiendas que le quedaron fuera, y la caja y las colas empezarian a
 * trabajar con un ambito mas estrecho.
 *
 * Y QUE ROLES TIENEN ALCANCE GLOBAL ES UN DATO, NO UN COMENTARIO: la lista
 * vive en el sembrador (`PermissionSeeder::ROLES_DE_ALCANCE_GLOBAL`), que es
 * CODIGO, y el codigo no se edita en caliente. Por eso la proteccion es POR
 * CONSTRUCCION y no por permisos: si la lista fuera un permiso del catalogo o
 * una fila de una tabla, quien tuviera el permiso de editar roles la podria
 * cambiar, y el recorte volveria.
 *
 * Y EL CASO LEGITIMO SIGUE VIVO: un rol CON ambito propio (el cajero, o un
 * contador con un ambito) se acota igual que antes. El arreglo es para los
 * roles de alcance global, no para todos.
 */

/**
 * Los ROLES de alcance global del catalogo: el superusuario y el contador.
 *
 * Son los DOS que el catalogo sembrado declara sin ambito: "el superusuario
 * y el contador no tienen ambito, y su alcance son todas las tiendas".
 *
 * @return list<Role>
 */
function roles_de_alcance_global_del_catalogo(): array
{
    return [
        PermissionFixture::superusuario(),
        Role::query()->where('slug', PermissionSeeder::SLUG_ACCOUNTANT)->firstOrFail(),
    ];
}

/**
 * La TIENDA DE AL LADO: la segunda tienda del escenario, con el perfil
 * fiscal de la primera.
 */
function tienda_de_al_lado_del_ambito(int $profileId, ?int $countryId): int
{
    return (int) DB::table('stores')->insertGetId([
        'name' => 'Tienda de al lado del ambito',
        'code_name' => 'AL-LADO-AMBITO',
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
 * Un rol de ALCANCE GLOBAL no tiene ambito y no se le puede acotar.
 *
 * Y EL 422 DICE POR QUE: el que intenta acotar tiene que saber que el rol
 * no se acota, y no que el servidor le ha rehusado por sorpresa.
 */
test('test_un_rol_de_alcance_global_no_tiene_ambito_y_no_se_le_puede_acotar', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    // Quien administra: el superusuario, que lo puede todo.
    $admin = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    foreach (roles_de_alcance_global_del_catalogo() as $rol) {
        // 1. El rol de alcance global NO TIENE ambito: el catalogo sembrado
        //    no le da ninguna fila, y su alcance son todas las tiendas.
        $ambito = DB::table('role_store_scopes')
            ->where('role_id', $rol->id)
            ->pluck('store_id')
            ->all();

        expect($ambito)->toBe([], 'El rol de alcance global '.$rol->slug.' ya tiene ambito.');

        // 2. Y NO SE LE PUEDE ACOTAR: el servidor lo rechaza.
        $respuesta = $this->actingAs($admin)
            ->putJson('/api/roles/'.$rol->id.'/tiendas', ['stores' => [$escenario['store_id']]]);

        $respuesta->assertStatus(422);

        // 3. Y EL MOTIVO ESTA EN EL 422: el rechazo dice POR QUE.
        $motivo = (string) $respuesta->json('errorMsg');

        expect($motivo)->not->toBe('El rechazo no dice por que se recusa el ambito.')
            ->and(strtolower($motivo))->toContain('global scope');

        // 4. Y el ambito SIGUE VACIO: el rechazo no acota nada.
        expect(DB::table('role_store_scopes')->where('role_id', $rol->id)->count())->toBe(
            0,
            'El rechazo dejo ambito en el rol de alcance global '.$rol->slug.'.'
        );
    }
});

/**
 * El AMBITO que un rol de alcance global TENIA se limpia.
 *
 * Y POR QUE SE LIMPIA Y NO SE DEJA: un rol de alcance global con filas esta
 * recortado, y su recorte lo consumen la caja, el POS, las colas de
 * impresion y la lectura de ventas. Las filas sobrantes son un ambito
 * invalido, y el servidor las quita.
 */
test('test_el_ambito_que_tenia_un_rol_de_alcance_global_se_limpia', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $admin = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    $contador = Role::query()->where('slug', PermissionSeeder::SLUG_ACCOUNTANT)->firstOrFail();

    // El DANO: un ambito escrito por la via que sea (la de antes del
    // arreglo, o la base de datos).
    PermissionFixture::ambito($contador, [$escenario['store_id']]);

    expect(DB::table('role_store_scopes')->where('role_id', $contador->id)->count())->toBe(1);

    // Y el intento de acotarlo lo deja SIN ambito.
    $this->actingAs($admin)
        ->putJson('/api/roles/'.$contador->id.'/tiendas', ['stores' => [$escenario['store_id']]])
        ->assertStatus(422);

    expect(DB::table('role_store_scopes')->where('role_id', $contador->id)->count())->toBe(
        0,
        'El rol de alcance global se quedo con el ambito que tenia.'
    );

    // Y la limpieza ESTA AUDITADA: un cambio de estado sin auditoria es un
    // hueco, y el hueco no se ve.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'role.store_scope_updated')
        ->where('entity_id', $contador->id)
        ->orderByDesc('id')
        ->first();

    expect($auditoria)->not->toBeNull('La limpieza del ambito no quedo auditada.')
        ->and((int) $auditoria->user_id)->toBe((int) $admin->id);
});

/**
 * Un rol NORMAL si puede acotar su ambito: el arreglo es para los roles de
 * alcance global, no para todos.
 */
test('test_un_rol_normal_si_puede_acotar_su_ambito_de_tiendas', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    // La SEGUNDA tienda: la acotacion tiene que poder cambiar de tienda.
    $perfilId = (int) DB::table('stores')
        ->where('id', $escenario['store_id'])
        ->value('fiscal_profile_id');

    $paisId = DB::table('stores')
        ->where('id', $escenario['store_id'])
        ->value('country_id');

    $tiendaB = tienda_de_al_lado_del_ambito($perfilId, $paisId === null ? null : (int) $paisId);

    // Un rol NORMAL con ambito propio: un contador de una tienda.
    $rol = PermissionFixture::rol('contador-con-ambito-'.uniqid(), [
        Permission::SalesRead->value,
    ]);

    PermissionFixture::ambito($rol, [$escenario['store_id']]);

    $admin = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    // 1. SE ACOTA: la lista que llega es la que queda.
    $this->actingAs($admin)
        ->putJson('/api/roles/'.$rol->id.'/tiendas', ['stores' => [$tiendaB]])
        ->assertOk()
        ->assertJsonPath('stores', [$tiendaB]);

    expect(DB::table('role_store_scopes')->where('role_id', $rol->id)->pluck('store_id')->all())
        ->toBe([$tiendaB]);

    // 2. Y SE PUEDE DEJAR SIN AMBITO: una lista vacia son todas las tiendas.
    $this->actingAs($admin)
        ->putJson('/api/roles/'.$rol->id.'/tiendas', ['stores' => []])
        ->assertOk();

    expect(DB::table('role_store_scopes')->where('role_id', $rol->id)->count())->toBe(
        0,
        'Un rol normal no puede dejar su ambito.'
    );
});

/**
 * Los roles de ALCANCE GLOBAL son el superusuario y el contador, y la lista
 * es un DATO del sembrador.
 *
 * Y POR QUE EN EL SEMBRADOR: la lista es CODIGO, y el codigo no se edita en
 * caliente. Si fuera un permiso del catalogo o una fila de una tabla,
 * quien tuviera el permiso de editar roles la podria cambiar, y el recorte
 * volveria. Por eso la proteccion es POR CONSTRUCCION.
 */
test('test_el_superusuario_y_el_contador_son_los_roles_de_alcance_global', function () {
    PermissionFixture::catalogo();

    $slugSuperusuario = (string) config('auth.superuser_role_slug');

    // La lista es un DATO: una constante del sembrador, no un comentario.
    expect(PermissionSeeder::ROLES_DE_ALCANCE_GLOBAL)
        ->toContain(PermissionSeeder::SLUG_ACCOUNTANT)
        ->toContain(PermissionSeeder::SLUG_SUPERUSER);

    // Y LOS DOS SON DE ALCANCE GLOBAL.
    expect(PermissionSeeder::esDeAlcanceGlobal($slugSuperusuario))->toBeTrue()
        ->and(PermissionSeeder::esDeAlcanceGlobal(PermissionSeeder::SLUG_ACCOUNTANT))->toBeTrue();

    // Y EL CAJERO NO: tiene ambito propio, y un rol normal se acorta.
    expect(PermissionSeeder::esDeAlcanceGlobal(PermissionSeeder::SLUG_CASHIER))->toBeFalse();
});
