<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Los CATALOGOS del modulo de gastos, ingresos y banca: las categorias de
 * gasto, los origenes de ingreso y la cuenta bancaria de la tienda de
 * demostracion.
 *
 * Y LA CUENTA CONTABLE es lo que estos tres tienen en comun: cada categoria
 * de gasto declara en que cuenta se carga, cada origen de ingreso declara en
 * que cuenta se abona, y la cuenta bancaria declara en que cuenta vive en el
 * libro. Son DATOS del negocio, como los roles del plan de cuentas: el
 * negocio decide en que cuenta asienta sus gastos, y esa decision cambia con
 * el tiempo.
 *
 * Por eso van en un seeder y no en una migracion: el contenido es de
 * DEMOSTRACION, y el negocio real cambia el suyo en la fila.
 *
 * Es IDEMPOTENTE: las categorias y los origenes se escriben con `upsert` por
 * su codigo (su clave natural), y la cuenta bancaria y su vinculo se buscan
 * por su clave natural antes de escribirse.
 */
class AccountingCatalogSeeder extends Seeder
{
    /**
     * La clave natural de la tienda de demostracion.
     */
    private const CODE_NAME = 'DEMO';

    /**
     * Las CATEGORIAS DE GASTO de demostracion: codigo, nombre y la cuenta
     * contable en la que se carga.
     *
     * @var list<array{code: string, name: string, account_code: string}>
     */
    private const CATEGORIAS = [
        ['code' => 'GASTO-ADMIN', 'name' => 'Gastos de administracion', 'account_code' => '5110000'],
        ['code' => 'GASTO-MERCADERIA', 'name' => 'Compras de mercaderia', 'account_code' => '5010000'],
    ];

    /**
     * Los ORIGENES DE INGRESO de demostracion: codigo, nombre y la cuenta
     * contable en la que se abona.
     *
     * @var list<array{code: string, name: string, account_code: string}>
     */
    private const ORIGENES = [
        ['code' => 'INGRESO-OTROS', 'name' => 'Otros ingresos', 'account_code' => '4020000'],
    ];

    /**
     * El nombre de la cuenta bancaria de demostracion.
     */
    private const BANCO = 'Cuenta bancaria de la tienda de demostracion';

    /**
     * La cuenta contable de la cuenta bancaria de demostracion.
     */
    private const CUENTA_DEL_BANCO = '1110000';

    /**
     * Siembra los catalogos del modulo.
     */
    public function run(): void
    {
        $now = now();

        $this->categorias($now);
        $this->origenes($now);
        $this->cuentaBancaria($now);
    }

    /**
     * Las CATEGORIAS DE GASTO, con su cuenta contable.
     */
    private function categorias(Carbon $now): void
    {
        $filas = [];

        foreach (self::CATEGORIAS as $categoria) {
            $filas[] = [
                'code' => $categoria['code'],
                'name' => $categoria['name'],
                'account_code' => $categoria['account_code'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('expense_categories')->upsert(
            $filas,
            ['code'],
            ['name', 'account_code', 'is_active', 'updated_at']
        );
    }

    /**
     * Los ORIGENES DE INGRESO, con su cuenta contable.
     */
    private function origenes(Carbon $now): void
    {
        $filas = [];

        foreach (self::ORIGENES as $origen) {
            $filas[] = [
                'code' => $origen['code'],
                'name' => $origen['name'],
                'account_code' => $origen['account_code'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('income_sources')->upsert(
            $filas,
            ['code'],
            ['name', 'account_code', 'is_active', 'updated_at']
        );
    }

    /**
     * La CUENTA BANCARIA de la tienda de demostracion y su vinculo por
     * tienda, con la cuenta contable que declara.
     *
     * La cuenta y su vinculo no tienen restriccion unica, asi que se buscan
     * por su clave natural antes de escribir: sembrar dos veces no puede
     * dejar dos cuentas bancarias.
     *
     *
     * @throws RuntimeException Cuando no hay tienda de demostracion.
     */
    private function cuentaBancaria(Carbon $now): void
    {
        $tienda = DB::table('stores')->where('code_name', self::CODE_NAME)->value('id');

        if ($tienda === null) {
            throw new RuntimeException(
                'No hay tienda de demostracion (code_name '.self::CODE_NAME.'): '
                .'los catalogos del modulo se siembran DESPUES de la tienda.'
            );
        }

        $cuenta = DB::table('bank_accounts')->where('name', self::BANCO)->value('id');

        if ($cuenta === null) {
            $cuenta = DB::table('bank_accounts')->insertGetId([
                'name' => self::BANCO,
                'number' => null,
                'tax_id' => null,
                'is_active' => true,
                'opening_balance' => '0.0000',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $cuenta = (int) $cuenta;

            DB::table('bank_accounts')->where('id', $cuenta)->update([
                'is_active' => true,
                'updated_at' => $now,
            ]);
        }

        $vinculo = DB::table('bank_account_stores')
            ->where('bank_account_id', $cuenta)
            ->where('store_id', $tienda)
            ->value('id');

        if ($vinculo === null) {
            DB::table('bank_account_stores')->insert([
                'bank_account_id' => $cuenta,
                'store_id' => $tienda,
                'status' => 'active',
                'sort_order' => 1,
                'opening_balance' => '0.0000',
                'account_code' => self::CUENTA_DEL_BANCO,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('bank_account_stores')->where('id', $vinculo)->update([
            'status' => 'active',
            'account_code' => self::CUENTA_DEL_BANCO,
            'updated_at' => $now,
        ]);
    }
}
