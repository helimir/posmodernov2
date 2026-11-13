<?php

namespace Tests\Fixtures;

use App\Domain\Auth\Enums\Permission;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * El ESCENARIO de los permisos: el catalogo, los roles y las personas.
 *
 * REGLA que fija este fixture: *los permisos se prueban con el catalogo DE
 * DEMOSTRACION del repo, no con uno inventado aqui*. El sembrador es el dueno
 * de esos datos (es idempotente) y el escenario sale de el exactamente igual
 * que sale en la aplicacion.
 *
 * Y EL ROL SE CREA CON SUS PERMISOS EXPLICITOS: un rol con un permiso solo
 * es el que permite probar que el permiso es el que abre la puerta, y no
 * otra cosa.
 */
final class PermissionFixture
{
    /**
     * El CATALOGO de permisos y los roles por defecto.
     */
    public static function catalogo(): void
    {
        (new PermissionSeeder)->run();
    }

    /**
     * Un ROL con los permisos que se le pasan.
     *
     * @param  list<string>  $permisos  Los slugs del catalogo.
     */
    public static function rol(string $slug, array $permisos = []): Role
    {
        $rol = Role::query()->create([
            'name' => ucfirst(str_replace('_', ' ', $slug)),
            'slug' => $slug,
            'sort_order' => 0,
            'status' => true,
        ]);

        foreach ($permisos as $permiso) {
            $id = DB::table('permissions')->where('slug', $permiso)->value('id');

            if ($id === null) {
                continue;
            }

            DB::table('role_permissions')->insert([
                'role_id' => $rol->id,
                'permission_id' => (int) $id,
            ]);
        }

        return $rol;
    }

    /**
     * El ROL SUPERUSUARIO: el que lo puede todo.
     */
    public static function superusuario(): Role
    {
        $slug = (string) config('auth.superuser_role_slug');

        $rol = Role::query()->where('slug', $slug)->first();

        if ($rol !== null) {
            return $rol;
        }

        return self::rol($slug, Permission::slugs());
    }

    /**
     * Una PERSONA con un rol y su vinculo por la tienda.
     *
     * NO abre la caja del dia: dos personas no pueden abrir la misma caja, y
     * el escenario de permisos no necesita la caja abierta.
     */
    public static function usuario(Role $rol, int $storeId): User
    {
        $usuario = PosFixture::usuarioSinCaja($storeId);

        $usuario->group_id = $rol->id;
        $usuario->save();

        return $usuario;
    }

    /**
     * Una PERSONA con un rol y su vinculo por VARIAS tiendas.
     *
     * @param  list<int>  $storeIds
     */
    public static function usuarioEnTiendas(Role $rol, array $storeIds): User
    {
        $usuario = User::factory()->create();

        $usuario->group_id = $rol->id;
        $usuario->save();

        $orden = 0;

        foreach ($storeIds as $storeId) {
            DB::table('user_stores')->insert([
                'user_id' => $usuario->id,
                'store_id' => $storeId,
                'status' => 'active',
                'sort_order' => $orden,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $orden++;
        }

        return $usuario;
    }

    /**
     * El AMBITO de tiendas de un rol.
     *
     * @param  list<int>  $storeIds
     */
    public static function ambito(Role $rol, array $storeIds): void
    {
        foreach ($storeIds as $storeId) {
            $vinculo = DB::table('role_store_scopes')
                ->where('role_id', $rol->id)
                ->where('store_id', $storeId)
                ->value('id');

            if ($vinculo !== null) {
                continue;
            }

            DB::table('role_store_scopes')->insert([
                'role_id' => $rol->id,
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Un CLIENTE con su ficha en la tienda.
     */
    public static function cliente(int $storeId): Customer
    {
        $cliente = Customer::query()->create([
            'name' => 'Cliente del escenario de permisos',
            'tax_id' => 'V-PERM-0001',
            'is_active' => true,
        ]);

        DB::table('customer_stores')->insert([
            'customer_id' => $cliente->id,
            'store_id' => $storeId,
            'status' => 'active',
            'sort_order' => 0,
            'balance' => '0.0000',
            'due' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $cliente;
    }

    /**
     * Una VENTA de la tienda.
     */
    public static function venta(int $storeId, ?int $customerId = null): Sale
    {
        return Sale::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'invoice_no' => 'PERM-0001',
            'sale_date' => now()->format('Y-m-d'),
            'status' => 'paid',
        ]);
    }

    /**
     * Una CUENTA del catalogo contable.
     */
    public static function cuenta(): int
    {
        $id = DB::table('accounts')->orderBy('id')->value('id');

        return (int) $id;
    }

    /**
     * Un COMPROBANTE fiscal emitido de la tienda.
     *
     * El documento pertenece al PERFIL de la tienda y a su MONEDA: un
     * comprobante de otra tienda no se sirve, y el numero de control es lo
     * que el papel enseña.
     */
    public static function documento(int $storeId): int
    {
        $profileId = DB::table('stores')->where('id', $storeId)->value('fiscal_profile_id');

        if ($profileId === null) {
            $profileId = DB::table('fiscal_series')
                ->where('store_id', $storeId)
                ->value('fiscal_profile_id');
        }

        $currencyId = DB::table('fiscal_profiles')
            ->where('id', (int) $profileId)
            ->value('default_currency_id');

        $documentId = DB::table('fiscal_documents')->insertGetId([
            'fiscal_profile_id' => (int) $profileId,
            'store_id' => $storeId,
            'document_type' => 'invoice',
            'status' => 'issued',
            'number' => 9001,
            'issue_date' => now()->format('Y-m-d'),
            'issued_at' => now(),
            'currency_id' => $currencyId === null ? null : (int) $currencyId,
            'subtotal' => '50.0000',
            'tax_total' => '8.0000',
            'total_amount' => '58.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fiscal_document_control_assignments')->insert([
            'fiscal_document_id' => $documentId,
            'control_number' => '00-00009001',
            'control_range_from' => 1,
            'control_range_to' => 9999,
            'control_assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $documentId;
    }
}
