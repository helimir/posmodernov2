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
 *    cerrar), las ventas, los clientes y el catalogo en lectura. Y SUS
 *    PROPIOS TICKETS: los trabajos de impresion que el mismo creo (y los
 *    que no tienen actor, que son los del sistema). NO la cola general ni
 *    la configuracion de impresion: eso es de quien administra la tienda.
 *    Y EL DINERO DEL DIA: el gasto que paga, el ingreso que entra en el
 *    cajon y el deposito que lleva al banco.
 *  - EL CONTADOR lee: las ventas, los clientes, la contabilidad, los reportes
 *    y la AUDITORIA FISCAL (las series, los perfiles y los proveedores) en
 *    lectura. Lee, pero NO instala: declarar una numeracion o un regimen es
 *    una instalacion, y de eso se ocupa el superusuario. Y SUPERVISA lo que
 *    el mostrador hizo: reabre un dia cerrado, anula una venta y anula un
 *    gasto, porque deshacer el cierre sellado, dejar sin efecto una venta
 *    emitida y marcar un gasto son correcciones de supervision, no del
 *    cajero que las hizo.
 *
 * Y LA MATRIZ SE APLICA, NO SE ANADE: los vinculos de un rol por defecto que
 * no esten en la matriz se BORRAN. Los roles por defecto son un contrato, y
 * un contrato que se puede ampliar con un `db:seed` no es un contrato: por
 * eso sembrar dos veces deja EXACTAMENTE el mismo estado.
 *
 * EL AMBITO DE TIENDAS del cajero es la tienda de demostracion: asi el
 * aislamiento por tienda se puede probar de verdad. Y LOS ROLES DE ALCANCE
 * GLOBAL NO TIENEN AMBITO: el superusuario y el contador alcanzan todas las
 * tiendas. Que roles son de alcance global esta en la LISTA de abajo, que es
 * un dato y no un comentario.
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
     * El slug del rol SUPERUSUARIO por defecto.
     *
     * Y EL DE LA INSTALACION PUEDE SER OTRO: el de la configuracion
     * (`auth.superuser_role_slug`), que es el que manda. Los dos son de
     * alcance global, y `esDeAlcanceGlobal()` los mira a los dos.
     */
    public const SLUG_SUPERUSER = 'superuser';

    /**
     * Los ROLES de ALCANCE GLOBAL: los que alcanzan TODAS las tiendas.
     *
     * REGLA que fija este seeder: *que roles tienen alcance global es un
     * DATO, y este dato es CODIGO*. La lista esta aqui, al lado de los slugs
     * de los roles por defecto, y NO en un comentario: un comentario no se
     * consulta, y un rol nuevo no sabe si es global.
     *
     * Y LA LISTA ES DE CODIGO, Y POR ESO LA PROTECCION ES POR CONSTRUCCION:
     * no es un permiso del catalogo ni una fila de una tabla, asi que nadie
     * la puede editar desde la aplicacion. Un permiso o una tabla los puede
     * cambiar quien tenga el permiso de editar roles, y el recorte del
     * superusuario volveria.
     *
     * Y LOS DOS SON DE ALCANCE GLOBAL: el superusuario y el contador. Los dos
     * tienen el alcance de todas las tiendas, y por eso sus filas de ambito
     * (`role_store_scopes`) son CERO: escribirlas recorta el alcance de la
     * caja, del POS, de las colas de impresion y de la lectura de ventas.
     *
     * @var list<string>
     */
    public const ROLES_DE_ALCANCE_GLOBAL = [
        self::SLUG_ACCOUNTANT,
        self::SLUG_SUPERUSER,
    ];

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
        $this->limpiaElAmbitoDeAlcanceGlobal();
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
            //
            // Y SUS PROPIOS TICKETS: `printing.own` y NO `printing.read`. El
            // cajero tiene que poder mirar SI SU PAPEL SALIO, y para eso
            // tiene su propia pantalla, con sus propios trabajos. Lo que NO
            // se le da es la cola general (los tickets de sus compañeros) ni
            // la configuracion de impresion (las impresoras y los destinos):
            // el mostrador cobra, no administra la tienda.
            //
            // Y `sales.void_request`: el cajero PIDE la anulacion de una
            // venta, con su motivo. NO `sales.void`: el que hizo la venta no
            // la deshace, la pide, y la ejecuta quien la puede deshacer (el
            // contador). La peticion del cajero es la que explica la
            // anulacion, y por eso lleva su motivo.
            self::SLUG_CASHIER => [
                Permission::DashboardRead,
                Permission::PosAccess,
                Permission::PosBudget,
                Permission::SalesRead,
                Permission::SalesCreate,
                Permission::SalesFiscalRetry,
                Permission::SalesVoidRequest,
                Permission::ReceiptRead,
                Permission::ReceiptPrint,
                Permission::CashDayRead,
                Permission::CashDayOpen,
                Permission::CashDayClose,
                Permission::CashDayPrint,
                Permission::CustomerRead,
                Permission::CustomerCreate,
                Permission::CustomerUpdate,
                Permission::CustomerPayment,
                Permission::CatalogRead,
                Permission::PrintingOwn,
                // El MOSTRADOR tambien lleva el dinero de la tienda: el gasto
                // que paga en el mostrador, el ingreso que entra en el cajon y
                // el deposito que lleva al banco. Son los movimientos del DIA
                // a DIA, y quien esta en el mostrador es quien los hace.
                Permission::ExpenseRead,
                Permission::ExpenseCreate,
                Permission::IncomeRead,
                Permission::IncomeCreate,
                Permission::BankingRead,
                Permission::BankingCreate,
                // Y LAS COMPRAS Y LA MERCADERIA: el mostrador es quien
                // recibe el genero (y por eso quien registra la compra, que
                // hace entrar el stock por sus lotes), quien lo devuelve al
                // proveedor y quien lo recibe de vuelta del cliente. NO
                // anula la compra: esa es una supervision, como anular un
                // gasto.
                Permission::PurchaseRead,
                Permission::PurchaseCreate,
                Permission::PurchaseReturn,
                Permission::SupplierRead,
                Permission::SupplierCreate,
                Permission::SaleReturnRead,
                Permission::SaleReturnCreate,
                // Y LAS CUOTAS: el mostrador es quien hace la venta a credito
                // y quien recoge el dinero de sus cuotas, asi que acuerda el
                // plan y cobra la cuota. El contador lo SUPERVISA (lo ve),
                // pero no cobra: cobrar es una operacion del dia, no una
                // correccion de supervision.
                Permission::InstallmentRead,
                Permission::InstallmentCreate,
                Permission::InstallmentCollect,
            ],

            // EL CONTADOR: lee. Las ventas y los clientes en lectura, la
            // contabilidad en lectura y los reportes. Y REABRE un dia
            // cerrado: deshacer un cierre sellado es una supervision, y el
            // que lo hizo el cajero no es quien lo deshace.
            //
            // Y ANULA UNA VENTA: por la misma regla. La anulacion deshace una
            // venta que HIZO el mostrador, consume un correlativo de la
            // serie (la nota de credito) y deja al cliente con un credito a
            // favor: es una supervision, y el que lo hizo el cajero no es
            // quien lo deshace.
            //
            // Y LA ADMINISTRACION FISCAL, EN LECTURA: el contador ve que
            // numeracion declara cada tienda y con que regimen y con que
            // canal emite, porque es lo que tiene que supervisar. Lo que NO
            // hace es INSTALAR: una serie, un perfil o un proveedor se
            // declaran con el superusuario, y un regimen fiscal no se
            // declara desde el mostrador.
            self::SLUG_ACCOUNTANT => [
                Permission::DashboardRead,
                Permission::SalesRead,
                Permission::SalesVoid,
                Permission::ReceiptRead,
                Permission::CustomerRead,
                Permission::CashDayRead,
                Permission::CashDayReopen,
                Permission::AccountingRead,
                Permission::ReportRead,
                // Y EXPORTA los reportes: el contador es quien responde de
                // los ficheros que salen del sistema (la declaracion, el
                // cierre del mes). El cajero no ve reportes ni los saca.
                Permission::ReportExport,
                Permission::AuditRead,
                Permission::FiscalRead,
                // El CONTADOR ANULA lo que el mostrador registro: el gasto y
                // el ingreso se MARCAN, no se borran, y para eso hace falta
                // alguien que pueda deshacer lo que otro hizo. Y los LEE, para
                // supervisionar el libro. Lo que NO hace es REGISTRARLOS: el
                // contador no lleva el mostrador.
                Permission::ExpenseRead,
                Permission::ExpenseCancel,
                Permission::IncomeRead,
                Permission::IncomeCancel,
                Permission::BankingRead,
                // Y LAS COMPRAS, EN LECTURA Y EN SUPERVISION: el contador ve
                // lo que el mostrador compro (la compra trae el stock por
                // sus lotes y la deuda con el proveedor) y puede ANULARLA,
                // porque anular es una correccion de supervision: la compra se
                // marca, no se borra, y el que la registro no es quien la
                // deshace. Lo que NO hace es devolver mercaderia al
                // proveedor: eso es una operacion fisica del mostrador.
                Permission::PurchaseRead,
                Permission::PurchaseCancel,
                Permission::SupplierRead,
                Permission::SaleReturnRead,
                // Y LAS CUOTAS, EN LECTURA: el contador ve los planes de
                // cuotas de la tienda (el dinero que el mostrador va a
                // cobrar y con que plan) porque es lo que tiene que
                // supervisar. Lo que NO hace es cobrar una cuota: cobrar es
                // una operacion del mostrador.
                Permission::InstallmentRead,
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
     * Y LOS ROLES DE ALCANCE GLOBAL NO TIENEN AMBITO: su alcance son todas
     * las tiendas, y sus filas las quita `limpiaElAmbitoDeAlcanceGlobal()`.
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
     * El AMBITO de los roles de ALCANCE GLOBAL: NINGUNO.
     *
     * Y LAS FILAS QUE TUVIERAN SE QUITAN: el ambito de un rol de alcance
     * global son CERO filas, y las que hubiera recortaban el alcance de la
     * caja, del POS, de las colas de impresion y de la lectura de ventas. Es
     * el MISMO PATRON que la matriz: lo que no esta en el contrato se borra.
     */
    private function limpiaElAmbitoDeAlcanceGlobal(): void
    {
        foreach (self::slugsDeAlcanceGlobal() as $slug) {
            $roleId = $this->idDelRol($slug);

            if ($roleId === null) {
                continue;
            }

            DB::table('role_store_scopes')->where('role_id', $roleId)->delete();
        }
    }

    /**
     * ¿Este rol tiene ALCANCE GLOBAL?
     *
     * REGLA que fija este metodo: *el alcance global se decide con la LISTA
     * de `ROLES_DE_ALCANCE_GLOBAL`, y con nada mas*. Un slug que no esta en
     * la lista no es de alcance global, y por eso su ambito se acorta como
     * el de cualquier otro rol.
     *
     * Y EL SUPERUSUARIO DE LA INSTALACION TAMBIEN: su slug sale de la
     * configuracion, asi que puede no ser el de la lista, y sigue siendo de
     * alcance global.
     */
    public static function esDeAlcanceGlobal(?string $slug): bool
    {
        if ($slug === null || $slug === '') {
            return false;
        }

        return in_array($slug, self::slugsDeAlcanceGlobal(), true);
    }

    /**
     * Los SLUGS de los roles de ALCANCE GLOBAL, con el superusuario de la
     * INSTALACION incluido.
     *
     * @return list<string>
     */
    public static function slugsDeAlcanceGlobal(): array
    {
        $slugs = self::ROLES_DE_ALCANCE_GLOBAL;

        $superusuario = self::slugSuperuser();

        if (! in_array($superusuario, $slugs, true)) {
            $slugs[] = $superusuario;
        }

        return $slugs;
    }

    /**
     * El SLUG del rol superusuario: el de la configuracion.
     */
    public static function slugSuperuser(): string
    {
        return (string) config('auth.superuser_role_slug', self::SLUG_SUPERUSER);
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
