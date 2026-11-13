<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * F2-01 — la ADMINISTRACION de usuarios y roles.
 *
 * REGLA que fija: *el rol de una persona es DATO, y cambiarlo se AUDITA*.
 * La pantalla lista las personas con su rol y sus tiendas, la escritura
 * asigna el rol, y el cambio queda en `audit_logs` con quien lo hizo, sobre
 * que persona y desde donde.
 *
 * Y LA COMPROBACION ESTA EN EL SERVIDOR: `user.update` protege el cambio y
 * `user.read` protege la vista. El boton de la pantalla se oculta con el
 * MISMO slug.
 */

/**
 * Se puede asignar el rol de una persona, y el cambio queda AUDITADO con el
 * usuario y la IP reales.
 */
test('test_se_puede_asignar_el_rol_a_un_usuario_y_el_cambio_queda_auditado', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    // La persona a la que se le asigna el rol, y el rol nuevo.
    $rolNuevo = PermissionFixture::rol('contador-nuevo-'.uniqid(), [
        Permission::SalesRead->value,
        Permission::AccountingRead->value,
    ]);

    $persona = PermissionFixture::usuario(
        PermissionFixture::rol('sin-rol-'.uniqid()),
        $escenario['store_id'],
    );

    // Quien administra: el superusuario.
    $admin = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    $this->actingAs($admin)
        ->putJson('/api/usuarios/'.$persona->id.'/rol', ['role_id' => $rolNuevo->id])
        ->assertOk()
        ->assertJsonPath('role.id', $rolNuevo->id)
        ->assertJsonPath('role.slug', $rolNuevo->slug);

    // El rol ha CAMBIADO.
    expect((int) $persona->fresh()->group_id)->toBe((int) $rolNuevo->id);

    // Y el cambio esta AUDITADO: quien lo hizo, sobre quien, con que rol y
    // desde donde.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'user.role_assigned')
        ->orderByDesc('id')
        ->first();

    expect($auditoria)->not->toBeNull('El cambio de rol no quedo auditado.')
        ->and((int) $auditoria->user_id)->toBe((int) $admin->id)
        ->and((string) $auditoria->entity_type)->toBe('user')
        ->and((int) $auditoria->entity_id)->toBe((int) $persona->id)
        ->and((string) $auditoria->ip_address)->not->toBe('');

    $contexto = json_decode((string) $auditoria->context, true);

    expect($contexto)->toBeArray()
        ->and((int) $contexto['role_id'])->toBe((int) $rolNuevo->id)
        ->and((string) $contexto['role_slug'])->toBe((string) $rolNuevo->slug);
});

/**
 * Sin el permiso de administrar usuarios, el rol de nadie se cambia.
 */
test('test_sin_el_permiso_de_administrar_usuarios_el_rol_de_nadie_se_cambia', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rolNuevo = PermissionFixture::rol('contador-nuevo-'.uniqid(), [Permission::SalesRead->value]);

    $persona = PermissionFixture::usuario(
        PermissionFixture::rol('sin-rol-'.uniqid()),
        $escenario['store_id'],
    );

    // Un cajero: puede cobrar, no administrar personas.
    $cajero = PermissionFixture::usuario(
        PermissionFixture::rol('cajero-'.uniqid(), [Permission::SalesCreate->value]),
        $escenario['store_id'],
    );

    $this->actingAs($cajero)
        ->putJson('/api/usuarios/'.$persona->id.'/rol', ['role_id' => $rolNuevo->id])
        ->assertStatus(403);

    // El rol no ha cambiado.
    expect((int) $persona->fresh()->group_id)->not->toBe((int) $rolNuevo->id);
});

/**
 * La pantalla de las personas las lista CON su rol y CON sus tiendas.
 */
test('test_la_pantalla_de_usuarios_lista_el_rol_y_las_tiendas_de_cada_persona', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('cajero-de-pantalla-'.uniqid(), [Permission::SalesCreate->value]);

    $persona = PermissionFixture::usuario($rol, $escenario['store_id']);

    $admin = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    $this->actingAs($admin)
        ->get('/usuarios')
        ->assertOk()
        ->assertInertia(fn ($page): mixed => $page
            ->component('users/index')
            ->has('roles')
            ->where('users', function ($usuarios) use ($persona, $rol, $escenario): bool {
                $fila = null;

                foreach ($usuarios as $usuario) {
                    if ((int) $usuario['id'] === (int) $persona->id) {
                        $fila = $usuario;
                    }
                }

                if ($fila === null) {
                    return false;
                }

                $tiendas = array_map(
                    static fn (array $tienda): int => (int) $tienda['id'],
                    $fila['stores'],
                );

                return (int) $fila['role']['id'] === (int) $rol->id
                    && in_array($escenario['store_id'], $tiendas, true);
            })
            ->etc());
});

/**
 * Los PERMISOS de un rol se pueden cambiar, y el cambio queda auditado.
 */
test('test_los_permisos_de_un_rol_se_pueden_cambiar_y_queda_auditado', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('rol-editable-'.uniqid(), [Permission::SalesRead->value]);

    $admin = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    $this->actingAs($admin)
        ->putJson('/api/roles/'.$rol->id.'/permisos', [
            'permissions' => [
                Permission::SalesRead->value,
                Permission::CatalogRead->value,
            ],
        ])
        ->assertOk();

    $slugs = DB::table('role_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
        ->where('role_permissions.role_id', $rol->id)
        ->orderBy('permissions.slug')
        ->pluck('permissions.slug')
        ->all();

    expect($slugs)->toBe([
        Permission::CatalogRead->value,
        Permission::SalesRead->value,
    ]);

    // Y el cambio esta auditado.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'role.permissions_updated')
        ->orderByDesc('id')
        ->first();

    expect($auditoria)->not->toBeNull('El cambio de permisos no quedo auditado.')
        ->and((int) $auditoria->user_id)->toBe((int) $admin->id)
        ->and((int) $auditoria->entity_id)->toBe((int) $rol->id);
});

/**
 * El AMBITO DE TIENDAS de un rol se puede cambiar, y el cambio queda
 * auditado.
 */
test('test_el_ambito_de_tiendas_de_un_rol_se_puede_cambiar_y_queda_auditado', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('rol-con-ambito-'.uniqid(), [Permission::SalesRead->value]);

    $admin = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    $this->actingAs($admin)
        ->putJson('/api/roles/'.$rol->id.'/tiendas', ['stores' => [$escenario['store_id']]])
        ->assertOk();

    $tiendas = DB::table('role_store_scopes')
        ->where('role_id', $rol->id)
        ->pluck('store_id')
        ->all();

    expect($tiendas)->toBe([$escenario['store_id']]);

    // Y el cambio esta auditado.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'role.store_scope_updated')
        ->orderByDesc('id')
        ->first();

    expect($auditoria)->not->toBeNull('El cambio de ambito no quedo auditado.')
        ->and((int) $auditoria->user_id)->toBe((int) $admin->id)
        ->and((int) $auditoria->entity_id)->toBe((int) $rol->id);
});

/**
 * Un permiso que NO esta en el catalogo no se puede asignar a un rol: el
 * servidor lo rechaza.
 */
test('test_un_permiso_que_no_esta_en_el_catalogo_no_se_puede_asignar_a_un_rol', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('rol-que-no-acepta-inventos-'.uniqid());

    $admin = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    $this->actingAs($admin)
        ->putJson('/api/roles/'.$rol->id.'/permisos', ['permissions' => ['sales.inventadas']])
        ->assertStatus(422);

    $slugs = DB::table('role_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
        ->where('role_permissions.role_id', $rol->id)
        ->pluck('permissions.slug')
        ->all();

    expect($slugs)->toBe([]);
});

/**
 * La matriz del rol SUPERUSUARIO no se puede editar: su poder no viene de
 * la matriz, sino del `Gate::before`.
 */
test('test_la_matriz_del_rol_superusuario_no_se_puede_editar', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $superusuario = PermissionFixture::superusuario();

    $admin = PermissionFixture::usuario(PermissionFixture::superusuario(), $escenario['store_id']);

    $this->actingAs($admin)
        ->putJson('/api/roles/'.$superusuario->id.'/permisos', ['permissions' => []])
        ->assertStatus(422);

    // Y el superusuario lo puede todo de todos modos.
    expect($admin->permissions())->toBe(Permission::slugs());
});
