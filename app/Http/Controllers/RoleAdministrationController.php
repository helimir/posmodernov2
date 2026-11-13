<?php

namespace App\Http\Controllers;

use App\Domain\Auth\Enums\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Support\Audit\AuditWriter;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La ADMINISTRACION de los roles: que permisos tiene cada rol y en que
 * tiendas puede operar.
 *
 * REGLA que fija esta clase: *los permisos de un rol son los del CATALOGO*.
 * El catalogo es el enum `Permission`, y un slug que no esta en el no existe:
 * la pantalla no puede asignar un permiso que el sistema no tiene, y el
 * servidor tampoco lo acepta.
 *
 * Y EL ROL SUPERUSUARIO NO SE EDITA: su poder no viene de la matriz, sino
 * del `Gate::before`, que se lo concede entero. Editar su matriz seria
 * escribir algo que no cambia nada, asi que el servidor lo dice y no lo
 * escribe.
 *
 * Y TODO CAMBIO SE AUDITA: quien lo hizo, sobre que rol, con que lista y
 * desde donde.
 */
class RoleAdministrationController extends Controller
{
    /**
     * La ACCION de la auditoria: los permisos de un rol han cambiado.
     */
    private const ACCION_PERMISOS = 'role.permissions_updated';

    /**
     * La ACCION de la auditoria: el ambito de tiendas de un rol ha cambiado.
     */
    private const ACCION_TIENDAS = 'role.store_scope_updated';

    /**
     * La ENTIDAD de la auditoria.
     */
    private const ENTIDAD = 'role';

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditWriter $audit,
    ) {}

    /**
     * La PANTALLA de los roles.
     *
     * EL CONTRATO de los props es el de `resources/js/pages/roles/index.tsx`:
     *
     *  - `roles`: lista de `{ id, name, slug, sort_order, status,
     *    permissions_count, stores_count, is_superuser }`.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): Response
    {
        return Inertia::render('roles/index', [
            'roles' => $this->roles(),
        ]);
    }

    /**
     * La PANTALLA de un rol: sus permisos y su ambito de tiendas.
     *
     * EL CONTRATO de los props es el de `resources/js/pages/roles/show.tsx`:
     *
     *  - `role`: `{ id, name, slug, sort_order, status, is_superuser }`.
     *  - `permissions`: el CATALOGO entero, con `{ slug, module, action,
     *    label, granted }`: lo que tiene el rol y lo que no, para que la
     *    pantalla enseñe el conjunto y no solo lo seleccionado.
     *  - `stores`: las tiendas del sistema, con `{ id, name, code_name,
     *    in_scope }`.
     *
     * @return array<string, mixed>
     */
    public function show(Request $request, Role $role): Response
    {
        $concedidos = $this->permisosDelRol((int) $role->id);
        $ambito = $this->tiendasDelRol((int) $role->id);

        return Inertia::render('roles/show', [
            'role' => [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'slug' => (string) $role->slug,
                'sort_order' => (int) $role->sort_order,
                'status' => (bool) $role->status,
                'is_superuser' => $this->esSuperusuario($role),
            ],
            'permissions' => $this->catalogo($concedidos),
            'stores' => $this->tiendas($ambito),
        ]);
    }

    /**
     * Los PERMISOS de un rol: se ponen o se quitan.
     *
     * La lista que llega es la COMPLETA: lo que se quita se quita porque no
     * esta en la lista, no porque se mande a quitar.
     */
    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        $datos = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::slugs())],
        ]);

        if ($this->esSuperusuario($role)) {
            return response()->json([
                'errorMsg' => 'The superuser role always has every permission: its matrix cannot be edited.',
            ], 422);
        }

        $slugs = $datos['permissions'];

        $ids = $this->idsDeLosPermisos($slugs);

        $this->escribeLosPermisos((int) $role->id, $ids);

        $this->audit->record(
            $request,
            self::ACCION_PERMISOS,
            self::ENTIDAD,
            (int) $role->id,
            [
                'role_slug' => (string) $role->slug,
                'permissions' => array_values($slugs),
            ],
        );

        return response()->json([
            'role_id' => (int) $role->id,
            'permissions' => array_values($slugs),
        ]);
    }

    /**
     * El AMBITO DE TIENDAS de un rol: las tiendas donde puede operar.
     *
     * Una lista VACIA significa que el alcance son todas las tiendas: es la
     * regla del ambito, y por eso una lista vacia no es "ninguna tienda" sino
     * "todas".
     */
    public function updateStores(Request $request, Role $role): JsonResponse
    {
        $datos = $request->validate([
            'stores' => ['present', 'array'],
            'stores.*' => ['integer', Rule::exists('stores', 'id')],
        ]);

        $ids = array_values(array_unique(array_map('intval', $datos['stores'])));

        $this->escribeElAmbito((int) $role->id, $ids);

        $this->audit->record(
            $request,
            self::ACCION_TIENDAS,
            self::ENTIDAD,
            (int) $role->id,
            [
                'role_slug' => (string) $role->slug,
                'stores' => $ids,
            ],
        );

        return response()->json([
            'role_id' => (int) $role->id,
            'stores' => $ids,
        ]);
    }

    /**
     * Los ROLES del sistema, con lo que tienen.
     *
     * @return list<array<string, mixed>>
     */
    private function roles(): array
    {
        $roles = Role::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'sort_order', 'status'])
            ->all();

        $concedidos = $this->conteoDePermisos();
        $tiendas = $this->conteoDeTiendas();

        $lista = [];

        foreach ($roles as $rol) {
            $id = (int) $rol->id;

            $lista[] = [
                'id' => $id,
                'name' => (string) $rol->name,
                'slug' => (string) $rol->slug,
                'sort_order' => (int) $rol->sort_order,
                'status' => (bool) $rol->status,
                'permissions_count' => $concedidos[$id] ?? 0,
                'stores_count' => $tiendas[$id] ?? 0,
                'is_superuser' => $this->esSuperusuario($rol),
            ];
        }

        return $lista;
    }

    /**
     * El CATALOGO de permisos con lo que el rol tiene de cada uno.
     *
     * @param  list<string>  $concedidos
     * @return list<array<string, mixed>>
     */
    private function catalogo(array $concedidos): array
    {
        $catalogo = [];

        foreach (Permission::cases() as $permiso) {
            $catalogo[] = [
                'slug' => $permiso->value,
                'module' => $permiso->module(),
                'action' => $permiso->action(),
                'label' => $permiso->label(),
                'granted' => in_array($permiso->value, $concedidos, true),
            ];
        }

        return $catalogo;
    }

    /**
     * Las TIENDAS del sistema, con las que estan en el ambito del rol.
     *
     * @param  list<int>  $ambito
     * @return list<array<string, mixed>>
     */
    private function tiendas(array $ambito): array
    {
        return Store::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'code_name'])
            ->map(fn (Store $tienda): array => [
                'id' => (int) $tienda->id,
                'name' => (string) $tienda->name,
                'code_name' => $tienda->code_name,
                'in_scope' => in_array((int) $tienda->id, $ambito, true),
            ])
            ->all();
    }

    /**
     * Los SLUGS de los permisos de un rol.
     *
     * @return list<string>
     */
    private function permisosDelRol(int $roleId): array
    {
        return $this->connection->table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->orderBy('permissions.slug')
            ->pluck('permissions.slug')
            ->map(static fn ($slug): string => (string) $slug)
            ->all();
    }

    /**
     * Los IDs de las tiendas del ambito de un rol.
     *
     * @return list<int>
     */
    private function tiendasDelRol(int $roleId): array
    {
        return $this->connection->table('role_store_scopes')
            ->where('role_id', $roleId)
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Los IDs de los permisos de una lista de slugs.
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function idsDeLosPermisos(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $ids = $this->connection->table('permissions')
            ->whereIn('slug', $slugs)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_values(array_map('intval', $ids));
    }

    /**
     * ESCRIBE los permisos de un rol: los que faltan se ponen y los que
     * sobran se quitan.
     *
     * @param  list<int>  $ids
     */
    private function escribeLosPermisos(int $roleId, array $ids): void
    {
        $this->connection->transaction(function () use ($roleId, $ids): void {
            $actuales = $this->connection->table('role_permissions')
                ->where('role_id', $roleId)
                ->pluck('permission_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            foreach (array_diff($actuales, $ids) as $sobra) {
                $this->connection->table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $sobra)
                    ->delete();
            }

            foreach (array_diff($ids, $actuales) as $falta) {
                $this->connection->table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $falta,
                ]);
            }
        });
    }

    /**
     * ESCRIBE el ambito de tiendas de un rol.
     *
     * @param  list<int>  $ids
     */
    private function escribeElAmbito(int $roleId, array $ids): void
    {
        $this->connection->transaction(function () use ($roleId, $ids): void {
            $actuales = $this->connection->table('role_store_scopes')
                ->where('role_id', $roleId)
                ->pluck('store_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            foreach (array_diff($actuales, $ids) as $sobra) {
                $this->connection->table('role_store_scopes')
                    ->where('role_id', $roleId)
                    ->where('store_id', $sobra)
                    ->delete();
            }

            $now = now();

            foreach (array_diff($ids, $actuales) as $falta) {
                $this->connection->table('role_store_scopes')->insert([
                    'role_id' => $roleId,
                    'store_id' => $falta,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * Cuantos permisos tiene cada rol.
     *
     * @return array<int, int>
     */
    private function conteoDePermisos(): array
    {
        $conteo = $this->connection->table('role_permissions')
            ->selectRaw('role_id, COUNT(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id')
            ->all();

        return array_map(static fn ($total): int => (int) $total, $conteo);
    }

    /**
     * Cuantas tiendas ambienta cada rol.
     *
     * @return array<int, int>
     */
    private function conteoDeTiendas(): array
    {
        $conteo = $this->connection->table('role_store_scopes')
            ->selectRaw('role_id, COUNT(*) as total')
            ->groupBy('role_id')
            ->pluck('total', 'role_id')
            ->all();

        return array_map(static fn ($total): int => (int) $total, $conteo);
    }

    /**
     * ¿Es este rol el del superusuario?
     */
    private function esSuperusuario(Role $rol): bool
    {
        return (string) $rol->slug === (string) config('auth.superuser_role_slug');
    }
}
