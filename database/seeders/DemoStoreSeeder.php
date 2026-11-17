<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La TIENDA DE DEMOSTRACION: la tienda donde el POS trabaja.
 *
 * Sin una tienda, la capa fiscal no tiene donde colgar sus series (la
 * numeracion es POR TIENDA: el correlativo del legacy salia de
 * `selling_info.store_id`) y la caja no tiene donde abrir. Esta tienda es la
 * que hace que el `migrate:fresh --seed` deje el sistema en pie.
 *
 * Lo que trae:
 *
 *  - La TIENDA, con su pais (el venezolano, que es el unico con la
 *    providencia transcrita) y su `code_name`, que es su clave natural.
 *  - La CONFIGURACION (`store_settings`): la zona horaria, el ancho de la
 *    linea de la impresora y los interruptores de interfaz. La zona horaria
 *    es la del PAIS, no la del servidor.
 *  - La INSCRIPCION FISCAL (`store_fiscal_registrations`): el identificativo
 *    fiscal del emisor. Sin ella el comprobante sale con el RIF del emisor en
 *    `null`, y el art. 8.4 lo exige. El identificativo de DEMOSTRACION es
 *    OBVIO: lleva la palabra `DEMO` delante, y nadie lo confunde con un RIF
 *    de verdad ni lo usa por error.
 *  - La CUENTA DE CAJA de la tienda, con su vinculo por tienda
 *    (`bank_account_stores`): es la cuenta cuyo saldo de apertura se siembra
 *    en `OpeningBalancesSeeder`.
 *
 * Los datos de la tienda son de DEMOSTRACION y no afirman nada: el RIF es de
 * ejemplo, no hay direccion y no hay certificado. Lo que el negocio real
 * configure despues se cambia en la fila.
 *
 * Es IDEMPOTENTE: la tienda y su configuracion se apoyan en las restricciones
 * unicas (`code_name`, `store_id`); la inscripcion fiscal, la cuenta de caja y
 * su vinculo no tienen ninguna, asi que se buscan por su clave natural antes
 * de escribirse.
 */
class DemoStoreSeeder extends Seeder
{
    /**
     * La clave natural de la tienda de demostracion.
     */
    private const CODE_NAME = 'DEMO';

    /**
     * El RIF de DEMOSTRACION del emisor.
     *
     * REGLA que fija este literal: *el identificativo de demostracion tiene
     * que delatar que es de ejemplo*. Un RIF con formato verosimil (J-12345678-9)
     * se podria usar por error en una impresion real, y un identificativo
     * equivocado en un comprobante es un problema que no se ve hasta que ya
     * salio. Este lleva la palabra `DEMO` delante, asi que se lee de un
     * vistazo, y conserva la FORMA del RIF (letra, guion, digitos, guion,
     * digito) para que la columna se lea como lo que es.
     *
     * El prefijo `J` es el de una persona juridica, que es lo que es una
     * tienda.
     */
    private const RIF_DEMOSTRACION = 'DEMO-J-000000000-0';

    /**
     * El ESQUEMA del identificativo fiscal de demostracion.
     *
     * Es el que dice de que tipo de identificativo se trata, y de el sale el
     * `fiscal_id_kind` del emisor (`rif`). Es DATO de la tienda, no del
     * codigo.
     */
    private const ESQUEMA_DEMOSTRACION = 'rif';

    /**
     * Siembra la tienda de demostracion con su configuracion, su inscripcion
     * fiscal y su cuenta.
     */
    public function run(): void
    {
        $now = now();

        $tienda = $this->tienda($now);
        $this->configuracion($tienda, $now);
        $this->inscripcionFiscal($tienda, $now);
        $this->cuentaDeCaja($tienda, $now);
    }

    /**
     * La TIENDA.
     *
     * @param  Carbon  $now
     */
    private function tienda($now): int
    {
        $pais = DB::table('countries')->where('iso_code', 'VE')->value('id');

        DB::table('stores')->upsert([
            [
                'name' => 'Tienda de demostracion',
                'code_name' => self::CODE_NAME,
                'country_id' => $pais,
                'sort_order' => 0,
                'status' => true,
                'feedback_status' => 'ready',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code_name'], ['name', 'country_id', 'sort_order', 'status', 'updated_at']);

        return (int) DB::table('stores')->where('code_name', self::CODE_NAME)->value('id');
    }

    /**
     * La CONFIGURACION de la tienda.
     *
     * @param  Carbon  $now
     */
    private function configuracion(int $tienda, $now): void
    {
        DB::table('store_settings')->upsert([
            [
                'store_id' => $tienda,
                'timezone' => 'America/Caracas',
                'base_color' => 'skin-black',
                'char_per_line' => 42,
                'auto_print' => false,
                'remote_printing' => true,
                'sound_effect' => true,
                'expiry_alert_enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['store_id'], [
            'timezone', 'base_color', 'char_per_line', 'auto_print', 'remote_printing',
            'sound_effect', 'expiry_alert_enabled', 'updated_at',
        ]);
    }

    /**
     * La INSCRIPCION FISCAL de la tienda: su identificativo fiscal de
     * emisor.
     *
     * REGLA que fija este metodo: *el emisor tiene identificativo fiscal*. El
     * art. 8.4 lo exige ("numero de Registro Unico de Informacion Fiscal
     * (RIF) del emisor"), y sin inscripcion el comprobante sale con el RIF en
     * `null`: un dato que falta, no un dato que se pueda rellenar con un
     * literal.
     *
     * Y ES DE DEMOSTRACION: el RIF lleva la palabra `DEMO` delante, para que
     * nadie lo confunda con el de un negocio real ni lo use por error.
     *
     * La tabla NO tiene restriccion unica (una tienda puede tener mas de una
     * inscripcion), asi que la inscripcion PRIMARIA se busca por su clave
     * natural antes de escribirse: sembrar dos veces no puede dejar dos
     * inscripciones primarias.
     *
     * La FECHA DE EMISION queda en NULL: no hay inscripcion real, y una fecha
     * sembrada seria inventar un acto administrativo que nadie ha verificado.
     *
     * @param  Carbon  $now
     */
    private function inscripcionFiscal(int $tienda, $now): void
    {
        $existente = DB::table('store_fiscal_registrations')
            ->where('store_id', $tienda)
            ->where('is_primary', true)
            ->orderBy('id')
            ->value('id');

        $fila = [
            'store_id' => $tienda,
            'tax_id' => self::RIF_DEMOSTRACION,
            'tax_id_scheme' => self::ESQUEMA_DEMOSTRACION,
            'issued_at' => null,
            'is_primary' => true,
            'updated_at' => $now,
        ];

        if ($existente === null) {
            DB::table('store_fiscal_registrations')->insert(array_merge($fila, ['created_at' => $now]));

            return;
        }

        DB::table('store_fiscal_registrations')->where('id', $existente)->update($fila);
    }

    /**
     * La CUENTA DE CAJA de la tienda y su vinculo por tienda.
     *
     * La cuenta no tiene restriccion unica (ni su vinculo), asi que se busca
     * por su nombre y por su par (cuenta, tienda) antes de escribir: sembrar
     * dos veces no puede dejar dos cuentas de caja.
     *
     * @param  Carbon  $now
     */
    private function cuentaDeCaja(int $tienda, $now): void
    {
        $nombre = 'Cuenta de caja de la tienda de demostracion';

        $cuenta = DB::table('bank_accounts')->where('name', $nombre)->value('id');

        if ($cuenta === null) {
            $cuenta = DB::table('bank_accounts')->insertGetId([
                'name' => $nombre,
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
                'sort_order' => 0,
                'opening_balance' => '0.0000',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('bank_account_stores')->where('id', $vinculo)->update([
            'status' => 'active',
            'updated_at' => $now,
        ]);
    }
}
