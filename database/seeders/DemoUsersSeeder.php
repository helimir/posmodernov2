<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los USUARIOS de demostracion: con su rol y con su tienda.
 *
 * REGLA que fija este seeder: *un usuario sin rol no puede hacer nada, y un
 * usuario de demostracion tiene que poder hacer algo*. Por eso se siembran
 * las personas con su rol: el superusuario (todo) y el cajero (el mostrador,
 * con la ambito en la tienda de demostracion).
 *
 * POR QUE EL CAJERO TIENE AMBITO: para que el aislamiento por tienda se
 * pueda PROBAR de verdad. Un cajero sin ambito ve todas las tiendas, y un
 * aislamiento que no se puede comprobar es un aislamiento que no existe.
 *
 * Es IDEMPOTENTE: los usuarios se buscan por su correo (que es unico) antes
 * de escribirse, y su vinculo por tienda se busca por su clave natural.
 */
class DemoUsersSeeder extends Seeder
{
    /**
     * El correo del superusuario de demostracion.
     */
    public const CORREO_SUPERUSER = 'test@example.com';

    /**
     * El correo del cajero de demostracion.
     */
    public const CORREO_CASHIER = 'cashier@example.com';

    /**
     * El nombre de usuario del cajero de demostracion.
     */
    public const USUARIO_CASHIER = 'cashier';

    /**
     * La clave natural de la tienda de demostracion.
     */
    private const CODE_NAME_TIENDA = 'DEMO';

    /**
     * Siembra los usuarios de demostracion con su rol y su tienda.
     */
    public function run(): void
    {
        $this->superusuario();
        $this->cajero();
    }

    /**
     * El SUPERUSUARIO de demostracion.
     *
     * Es el mismo correo que el de siempre: el que se documenta en el
     * README y el que usan las pruebas. Lo que cambia es que ahora tiene
     * ROL, y por eso puede hacer lo que antes hacia cualquiera que se
     * autenticara.
     */
    private function superusuario(): void
    {
        $roleId = $this->idDelRol(PermissionSeeder::slugSuperuser());

        if ($roleId === null) {
            return;
        }

        $usuario = User::where('email', self::CORREO_SUPERUSER)->first();

        if ($usuario === null) {
            $usuario = User::factory()->create([
                'name' => 'Test User',
                'email' => self::CORREO_SUPERUSER,
                'group_id' => $roleId,
            ]);

            return;
        }

        if ((int) $usuario->group_id !== $roleId) {
            $usuario->group_id = $roleId;
            $usuario->save();
        }
    }

    /**
     * El CAJERO de demostracion, con su vinculo por la tienda.
     */
    private function cajero(): void
    {
        $roleId = $this->idDelRol(PermissionSeeder::SLUG_CASHIER);

        if ($roleId === null) {
            return;
        }

        $storeId = (int) DB::table('stores')
            ->where('code_name', self::CODE_NAME_TIENDA)
            ->value('id');

        if ($storeId === 0) {
            return;
        }

        $usuario = User::where('email', self::CORREO_CASHIER)->first();

        if ($usuario === null) {
            $usuario = User::factory()->create([
                'name' => 'Cashier',
                'username' => self::USUARIO_CASHIER,
                'email' => self::CORREO_CASHIER,
                'group_id' => $roleId,
            ]);
        } elseif ((int) $usuario->group_id !== $roleId) {
            $usuario->group_id = $roleId;
            $usuario->save();
        }

        $this->vinculoPorTienda((int) $usuario->id, $storeId);
    }

    /**
     * El VINCULO del usuario con la tienda.
     *
     * La tabla `user_stores` no tiene restriccion unica, asi que el vinculo
     * se busca por su clave natural antes de escribirse: sembrar dos veces
     * no puede dejar dos vinculos del mismo usuario en la misma tienda.
     */
    private function vinculoPorTienda(int $userId, int $storeId): void
    {
        $now = now();

        $vinculo = DB::table('user_stores')
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->value('id');

        if ($vinculo === null) {
            DB::table('user_stores')->insert([
                'user_id' => $userId,
                'store_id' => $storeId,
                'status' => 'active',
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('user_stores')->where('id', $vinculo)->update([
            'status' => 'active',
            'updated_at' => $now,
        ]);
    }

    /**
     * El ID del rol con este slug, si existe.
     */
    private function idDelRol(string $slug): ?int
    {
        $id = Role::query()->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }
}
