<?php

namespace Database\Seeders;

use App\Domain\Auth\Enums\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los PERMISOS y los ROLES por defecto.
 *
 * REGLA que fija este seeder: *el CATALOGO de permisos vive en un solo sitio,
 * en codigo*, y este seeder lo siembra. El catalogo es el enum
 * `App\Domain\Auth\Enums\Permission`: un permiso que no esta en el enum no
 * existe, y un permiso que esta existe para todos. El seeder no escribe ni
 * un slug a mano, ni una matriz a mano: los dos salen del enum y de la
 * matriz de abajo.
 *
 * LOS TRES ROLOS POR DEFECTO son un CONTRATO, no una sugerencia:
 *
 *  - EL SUPERUSUARIO lo puede todo. Su poder no viene de la matriz, sino
 *    del `Gate::before`, que lo reconoce por el SLUG de su rol. El slug sale
 *    de la configuracion (`auth.superuser_role_slug`), asi que el nombre del
 *    rol no esta escrito en el codigo de la aplicacion.
 *  - EL CAJERO es el mostrador: la caja, la caja del dia (abrir, ver y
 *    cerrar), las ventas, los clientes y el catalogo en lectura.
 *  - EL CONTADOR lee: las ventas, los clientes, la contabilidad y los
 *    reportes.
 *
 * Y LA MATRIZ SE APLICA, NO SE ANADE: los vinculos de un rol por defecto que
 * no esten en la matriz se BORRAN. Los roles por defecto son un contrato, y
 * un contrato que se puede ampliar con un `db:seed` no es un contrato: por
 * eso sembrar dos veces deja EXACTAMENTE el mismo estado.
 *
 * EL AMBITO DE TIENDAS del cajero es la tienda de demostracion: asi el
 * aislamiento por tienda se puede probar de verdad. El superusuario y el
 * contador no tienen ambito, y su alcance son todas las tiendas.
 *
 * Es IDEMPOTENTE: los permisos y los roles se apoyan en la restriccion unica
 * del `slug`, y los vinculos en la de `(role_id, permission_id)`.
 */
class PermissionSeeder extends Seeder
{
    /**
     * El slug del rol CAJERO.
     */
    public const SLUG_CASHIER = 'cashier';

    /**
     * El slug del rol CONTADOR.
     */
    public const SLUG_ACCOUNTANT = 'accountant';

    /**
     * La clave natural de la tienda de demostracion.
     */
    private const CODE_NAME_TIENDA = 'DEMO';

    /**
     * Siembra el catalogo de permisos, los roles por defecto y su matriz.
     */
    public function run(): void
    {
        $this->permisos();
        $this->roles();
        $this->escribeLaMatriz();
        $this->ambito();
    }

    /**
     * El CATALOGO de permisos, sembrado desde el enum.
     *
     * El `upsert` es por `slug`, que es la clave natural: sembrar dos veces
     * actualiza la fila, no la duplica.
     */
    private function permisos(): void
    {
        $now = now();

        $filas = [];

        foreach (Permission::cases() as $permiso) {
            $filas[] = [
                'slug' => $permiso->value,
                'module' => $permiso->module(),
                'action' => $permiso->action(),
                'label' => $permiso->label(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('permissions')->upsert(
            $filas,
            ['slug'],
            ['module', 'action', 'label', 'updated_at'],
        );
    }

    /**
     * Los TRES roles por defecto.
     *
     * El superusuario se siembra con el slug de la configuracion: el nombre
     * del rol no esta escrito en dos sitios.
     */
    private function roles(): void
    {
        $now = now();

        DB::table('roles')->upsert([
            [
                'name' => 'Superuser',
                'slug' => self::slugSuperuser(),
                'sort_order' => 0,
                'status' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Cashier',
                'slug' => self::SLUG_CASHIER,
                'sort_order' => 10,
                'status' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Accountant',
                'slug' => self::SLUG_ACCOUNTANT,
                'sort_order' => 20,
                'status' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug'], ['name', 'sort_order', 'status', 'updated_at']);
    }

    /**
     * La MATRIZ de permisos por rol: lo que cada rol puede hacer.
     *
     * @return array<string, list<Permission>>
     */
    public static function matriz(): array
    {
        return [
            // EL SUPERUSUARIO lo puede todo: la matriz lo dice explicitamente
            // (la tabla es la verdad de lo que el rol tiene) y, ademas, el
            // `Gate::before` se lo concede entero.
            self::slugSuperuser() => Permission::cases(),

            // EL CAJERO: el mostrador.
            self::SLUG_CASHIER => [
                Permission::DashboardRead,
                Permission::PosAccess,
                Permission::PosBudget,
                Permission::SalesRead,
                Permission::SalesCreate,
                Permission::SalesFiscalRetry,
                Permission::ReceiptRead,
                Permission::ReceiptPrint,
                Permission::CashDayRead,
                Permission::CashDayOpen,
                Permission::CashDayClose,
                Permission::CustomerRead,
                Permission::CustomerCreate,
                Permission::CustomerUpdate,
                Permission::CustomerPayment,
                Permission::CatalogRead,
            ],

            // EL CONTADOR: lee. Las ventas y los clientes en lectura, la
            // contabilidad en lectura y los reportes.
            self::SLUG_ACCOUNTANT => [
                Permission::DashboardRead,
                Permission::SalesRead,
                Permission::ReceiptRead,
                Permission::CustomerRead,
                Permission::CashDayRead,
                Permission::AccountingRead,
                Permission::ReportRead,
            ],
        ];
    }

    /**
     * Los vinculos de la matriz, aplicados.
     *
     * Los que faltan se insertan y los que sobran se borran: el estado del
     * rol por defecto es EXACTAMENTE el de la matriz, sembrar dos veces.
     */
    private function escribeLaMatriz(): void
    {
        foreach (self::matriz() as $slug => $permisos) {
            $roleId = $this->idDelRol($slug);

            if ($roleId === null) {
                continue;
            }

            $ids = $this->idsDeLosPermisos($permisos);

            $filas = [];

            foreach ($ids as $permissionId) {
                $filas[] = [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ];
            }

            if ($filas !== []) {
                DB::table('role_permissions')->insertOrIgnore($filas);
            }

            // Los vinculos que sobran se BORRAN: el rol por defecto es
            // exactamente el de la matriz.
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->when(
                    $ids !== [],
                    fn ($consulta) => $consulta->whereNotIn('permission_id', $ids),
                )
                ->delete();
        }
    }

    /**
     * El AMBITO DE TIENDAS del rol cajero: la tienda de demostracion.
     *
     * El superusuario y el contador no tienen ambito, y su alcance son todas
     * las tiendas.
     */
    private function ambito(): void
    {
        $storeId = (int) DB::table('stores')
            ->where('code_name', self::CODE_NAME_TIENDA)
            ->value('id');

        if ($storeId === 0) {
            return;
        }

        $roleId = $this->idDelRol(self::SLUG_CASHIER);

        if ($roleId === null) {
            return;
        }

        $now = now();

        $vinculo = DB::table('role_store_scopes')
            ->where('role_id', $roleId)
            ->where('store_id', $storeId)
            ->value('id');

        if ($vinculo === null) {
            DB::table('role_store_scopes')->insert([
                'role_id' => $roleId,
                'store_id' => $storeId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('role_store_scopes')->where('id', $vinculo)->update([
            'updated_at' => $now,
        ]);
    }

    /**
     * El SLUG del rol superusuario: el de la configuracion.
     */
    public static function slugSuperuser(): string
    {
        return (string) config('auth.superuser_role_slug', 'superuser');
    }

    /**
     * El ID del rol con este slug, si existe.
     */
    private function idDelRol(string $slug): ?int
    {
        $id = DB::table('roles')->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Los IDs de los permisos de la matriz.
     *
     * @param  list<Permission>  $permisos
     * @return list<int>
     */
    private function idsDeLosPermisos(array $permisos): array
    {
        if ($permisos === []) {
            return [];
        }

        $slugs = array_map(
            static fn (Permission $permiso): string => $permiso->value,
            $permisos,
        );

        $ids = DB::table('permissions')
            ->whereIn('slug', $slugs)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_values(array_map('intval', $ids));
    }
}
