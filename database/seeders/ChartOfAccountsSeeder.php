<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El PLAN DE CUENTAS de DEMOSTRACION: el catalogo de cuentas y su asignacion
 * a la tienda de demostracion.
 *
 * Un plan de cuentas es un DATO del negocio, no una configuracion del
 * despliegue: el negocio decide que cuenta es su caja, cual su cartera y cual
 * su ingreso, y esa decision cambia con el tiempo y es distinta en cada
 * tienda. Por eso el plan vive en la base de datos (`accounts` y
 * `store_accounts`) y no en un fichero de configuracion.
 *
 * Lo que trae:
 *
 *  - El CATALOGO de cuentas: las cuatro que el sistema usa para cobrar, con
 *    su codigo, su nombre y su TIPO contable, y algunas mas para que el plan
 *    sea util de verdad (el banco, el inventario, las cuentas por cobrar a
 *    proveedores, las cuentas por pagar, el capital, el costo de ventas y los
 *    gastos). Los codigos y los nombres son DATOS DE DEMOSTRACION: el
 *    negocio real cambia los suyos en la fila.
 *  - La ASIGNACION a la tienda de demostracion: cada rol con su cuenta.
 *
 * Es IDEMPOTENTE: las cuentas se escriben con `upsert` por su codigo (su
 * clave natural), y los vinculos se resuelven por `(store_id, role)`, que es
 * la clave natural del plan. Sembrar dos veces no duplica ni una cuenta ni un
 * vinculo.
 *
 * Y NO HAY PLAN POR DEFECTO: si a una tienda le falta un rol, el cobro
 * falla con un mensaje util. Esta tienda es la unica que tiene plan.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * La clave natural de la tienda de demostracion.
     */
    private const CODE_NAME = 'DEMO';

    /**
     * El CATALOGO de cuentas: codigo, nombre y tipo contable.
     *
     * @var list<array{code: string, name: string, type: string}>
     */
    private const CUENTAS = [
        ['code' => '1100000', 'name' => 'Efectivo y caja', 'type' => 'asset'],
        ['code' => '1110000', 'name' => 'Banco', 'type' => 'asset'],
        ['code' => '1200000', 'name' => 'Cuentas por cobrar a clientes', 'type' => 'asset'],
        ['code' => '1300000', 'name' => 'Inventario de mercaderia', 'type' => 'asset'],
        ['code' => '1400000', 'name' => 'Cuentas por cobrar a proveedores', 'type' => 'asset'],
        ['code' => '2100000', 'name' => 'Cuentas por pagar a proveedores', 'type' => 'liability'],
        ['code' => '2110000', 'name' => 'Impuesto por pagar', 'type' => 'liability'],
        ['code' => '3100000', 'name' => 'Capital social', 'type' => 'equity'],
        ['code' => '4010000', 'name' => 'Ventas', 'type' => 'income'],
        ['code' => '4020000', 'name' => 'Otros ingresos', 'type' => 'income'],
        ['code' => '5010000', 'name' => 'Costo de ventas', 'type' => 'expense'],
        ['code' => '5110000', 'name' => 'Gastos de administracion', 'type' => 'expense'],
    ];

    /**
     * El PLAN de la tienda de demostracion: rol => codigo de cuenta.
     *
     * @var array<string, string>
     */
    private const PLAN = [
        'cash' => '1100000',
        'receivable' => '1200000',
        'revenue' => '4010000',
        'tax_payable' => '2110000',
    ];

    /**
     * Siembra el catalogo de cuentas y el plan de la tienda de demostracion.
     */
    public function run(): void
    {
        $now = now();

        $tienda = $this->tienda();

        $this->cuentas($now);
        $this->plan($tienda, $now);
    }

    /**
     * La TIENDA de demostracion, que es la unica con plan.
     */
    private function tienda(): int
    {
        $tienda = DB::table('stores')->where('code_name', self::CODE_NAME)->value('id');

        if ($tienda === null) {
            throw new RuntimeException(
                'No hay tienda de demostracion (code_name '.self::CODE_NAME.'): '
                .'el plan de cuentas se siembra DESPUES de la tienda.'
            );
        }

        return (int) $tienda;
    }

    /**
     * El CATALOGO de cuentas.
     *
     * El `upsert` es por el CODIGO, que es la clave natural de la cuenta: el
     * nombre y el tipo se actualizan, y la fila no se duplica.
     *
     * @param  Carbon  $now
     */
    private function cuentas($now): void
    {
        $filas = [];

        foreach (self::CUENTAS as $cuenta) {
            $filas[] = [
                'code' => $cuenta['code'],
                'name' => $cuenta['name'],
                'type' => $cuenta['type'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('accounts')->upsert(
            $filas,
            ['code'],
            ['name', 'type', 'is_active', 'updated_at']
        );
    }

    /**
     * El PLAN de la tienda: cada rol con su cuenta.
     *
     * Los vinculos de los roles que se siembran se REHACEN: el rol es UNO por
     * tienda, y su cuenta puede cambiar entre dos siembras. Los roles que no
     * son de este plan no se tocan.
     *
     * @param  Carbon  $now
     */
    private function plan(int $tienda, $now): void
    {
        DB::table('store_accounts')
            ->where('store_id', $tienda)
            ->whereIn('role', array_keys(self::PLAN))
            ->delete();

        foreach (self::PLAN as $rol => $codigo) {
            $cuenta = (int) DB::table('accounts')->where('code', $codigo)->value('id');

            DB::table('store_accounts')->insert([
                'account_id' => $cuenta,
                'store_id' => $tienda,
                'role' => $rol,
                'status' => 'active',
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
