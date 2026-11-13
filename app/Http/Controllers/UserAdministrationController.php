<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditWriter;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La ADMINISTRACION de las personas: quien es, que rol tiene y en que
 * tiendas opera.
 *
 * REGLA que fija esta clase: *el rol de una persona es DATO, y cambiarlo se
 * AUDITA*. La pantalla lista las personas con su rol y sus tiendas, y la
 * escritura asigna el rol de una persona y deja la nota de quien lo hizo y
 * desde donde.
 *
 * Y LA COMPROBACION ESTA EN EL SERVIDOR: el permiso de la ruta
 * (`user.read` para ver, `user.update` para cambiar) lo aplica el
 * middleware, y el boton de la pantalla lo oculta con el MISMO slug.
 *
 * EL GUARDA DE AUTOCIERRE: una persona no puede quitarse a si misma el rol
 * de superusuario. Sin ese rol no se puede volver a asignar (el permiso de
 * administrar usuarios es del superusuario), asi que quitarselo es dejar la
 * instalacion sin administracion.
 */
class UserAdministrationController extends Controller
{
    /**
     * La ACCION de la auditoria: el rol de una persona ha cambiado.
     */
    private const ACCION = 'user.role_assigned';

    /**
     * La ENTIDAD de la auditoria.
     */
    private const ENTIDAD = 'user';

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditWriter $audit,
    ) {}

    /**
     * La PANTALLA de las personas.
     *
     * EL CONTRATO de los props es el de
     * `resources/js/pages/users/index.tsx`:
     *
     *  - `users`: lista de `{ id, name, email, role, stores }`. El rol es
     *    `{ id, name, slug }` o `null`, y las tiendas son
     *    `{ id, name, code_name }`.
     *  - `roles`: los roles del sistema, para el selector.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): Response
    {
        return Inertia::render('users/index', [
            'users' => $this->usuarios(),
            'roles' => $this->roles(),
        ]);
    }

    /**
     * El ROL de una persona: se asigna o se quita.
     *
     * Un rol `null` deja a la persona SIN rol, y una persona sin rol no puede
     * hacer nada: los permisos vienen del rol.
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $datos = $request->validate([
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
        ]);

        $roleId = $datos['role_id'] ?? null;

        $rol = $roleId === null
            ? null
            : Role::query()->find((int) $roleId);

        if ($roleId !== null && $rol === null) {
            return response()->json([
                'errorMsg' => 'El rol '.$roleId.' no existe.',
            ], 422);
        }

        $anterior = $user->group_id === null ? null : (int) $user->group_id;

        // EL GUARDA DE AUTOCIERRE: nadie se quita su propio rol de
        // superusuario. Ese rol es el unico que puede administrar usuarios, y
        // sin el la instalacion se queda sin administracion.
        if ($anterior === $this->idDelSuperusuario()
            && (int) $request->user()?->id === (int) $user->id
            && ! $this->esSuperusuario($rol)) {
            return response()->json([
                'errorMsg' => 'No puedes quitarte tu propio rol de superusuario: sin el no se puede '
                    .'volver a asignar ni administrar a los usuarios.',
            ], 422);
        }

        $user->group_id = $roleId === null ? null : (int) $roleId;
        $user->save();

        $this->audit->record(
            $request,
            self::ACCION,
            self::ENTIDAD,
            (int) $user->id,
            [
                'role_id' => $roleId === null ? null : (int) $roleId,
                'role_slug' => $rol?->slug,
                'previous_role_id' => $anterior,
            ],
        );

        return response()->json([
            'id' => (int) $user->id,
            'role' => $rol === null ? null : [
                'id' => (int) $rol->id,
                'name' => (string) $rol->name,
                'slug' => (string) $rol->slug,
            ],
        ]);
    }

    /**
     * LAS PERSONAS, con su rol y sus tiendas.
     *
     * @return list<array<string, mixed>>
     */
    private function usuarios(): array
    {
        $filas = $this->connection->table('users')
            ->leftJoin('roles', 'roles.id', '=', 'users.group_id')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get([
                'users.id as id',
                'users.name as name',
                'users.email as email',
                'users.group_id as group_id',
                'roles.name as role_name',
                'roles.slug as role_slug',
            ]);

        $tiendas = $this->tiendasPorUsuario();

        $usuarios = [];

        foreach ($filas as $fila) {
            $usuarios[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                'email' => (string) $fila->email,
                'role' => $fila->group_id === null ? null : [
                    'id' => (int) $fila->group_id,
                    'name' => (string) $fila->role_name,
                    'slug' => (string) $fila->role_slug,
                ],
                'stores' => $tiendas[(int) $fila->id] ?? [],
            ];
        }

        return $usuarios;
    }

    /**
     * Las TIENDAS de cada persona: las ACTIVO de su vinculo.
     *
     * @return array<int, list<array{id: int, name: string, code_name: string|null}>>
     */
    private function tiendasPorUsuario(): array
    {
        $filas = $this->connection->table('user_stores')
            ->join('stores', 'stores.id', '=', 'user_stores.store_id')
            ->where('user_stores.status', 'active')
            ->orderBy('stores.name')
            ->orderBy('stores.id')
            ->get([
                'user_stores.user_id as user_id',
                'stores.id as store_id',
                'stores.name as store_name',
                'stores.code_name as store_code_name',
            ]);

        $tiendas = [];

        foreach ($filas as $fila) {
            $tiendas[(int) $fila->user_id][] = [
                'id' => (int) $fila->store_id,
                'name' => (string) $fila->store_name,
                'code_name' => $fila->store_code_name,
            ];
        }

        return $tiendas;
    }

    /**
     * Los ROLES del sistema, para el selector.
     *
     * @return list<array{id: int, name: string, slug: string}>
     */
    private function roles(): array
    {
        return Role::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Role $rol): array => [
                'id' => (int) $rol->id,
                'name' => (string) $rol->name,
                'slug' => (string) $rol->slug,
            ])
            ->all();
    }

    /**
     * El ID del rol superusuario, si existe.
     */
    private function idDelSuperusuario(): ?int
    {
        $id = Role::query()
            ->where('slug', (string) config('auth.superuser_role_slug'))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * ¿Es este rol el del superusuario?
     */
    private function esSuperusuario(?Role $rol): bool
    {
        if ($rol === null) {
            return false;
        }

        return (string) $rol->slug === (string) config('auth.superuser_role_slug');
    }
}
