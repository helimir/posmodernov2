<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Los SALDOS DE APERTURA: el punto de partida del negocio.
 *
 * REGLA que fija este seeder: *un saldo sin certificar NO abre el arranque;
 * lo abre el comando `ops:check-opening-balances`*.
 *
 * Los saldos de apertura (stock por tienda y producto, saldo y deuda de
 * cliente y proveedor, saldo de caja por cuenta y tienda) se CARGAN A MANO
 * (B1) y se CERTIFICAN con fecha (`certified_at`) y responsable nominal
 * (`certified_by`). La certificacion NO es un adorno: el comando de arranque
 * falla mientras haya saldos sin certificar.
 *
 * Y POR QUE ESTE SEEDER NO CERTIFICA NADA: porque la certificacion es un ACTO
 * HUMANO, de la direccion financiera. Un saldo contable no lo aprueba un
 * script: un script que se auto-certificara vaciaria de contenido la unica
 * barrera que separa "arrancamos con cifras defendibles" de "arrancamos con
 * numeros inventados". El seeder siembra el saldo que el negocio necesita para
 * arrancar (el saldo de caja de la tienda de demostracion) y lo deja
 * EXPRESAMENTE SIN CERTIFICAR.
 *
 * El comando `ops:check-opening-balances` es de SOLO LECTURA: comprueba, no
 * certifica.
 *
 * Es IDEMPOTENTE: `opening_balances` no tiene una restriccion unica normal
 * (su indice es PARCIAL: un saldo por par (tienda, entidad) y por `kind`), asi
 * que el saldo se busca por su clave natural antes de escribirse. Y al
 * re-sembrar NO se tocan `certified_at` ni `certified_by`: una certificacion
 * es un hecho humano, y una segunda siembra no puede borrarla.
 */
class OpeningBalancesSeeder extends Seeder
{
    /**
     * La fecha de referencia de la semilla.
     *
     * Es FIJA a proposito: un saldo de apertura se CONGELA, y una semilla que
     * tomara la fecha del reloj sembraria una partida distinta cada dia.
     */
    private const AS_OF = '2026-11-01';

    /**
     * Siembra el saldo de apertura de caja de la tienda de demostracion.
     */
    public function run(): void
    {
        $now = now();

        $tienda = (int) DB::table('stores')->where('code_name', 'DEMO')->value('id');
        $cuenta = DB::table('bank_account_stores')
            ->where('store_id', $tienda)
            ->value('bank_account_id');

        if ($cuenta === null) {
            return;
        }

        $saldo = [
            'store_id' => $tienda,
            'kind' => 'cash',
            'product_id' => null,
            'customer_id' => null,
            'supplier_id' => null,
            'bank_account_id' => $cuenta,
            // El IMPORTE entra como TEXTO: un `float` no es un dinero.
            'amount' => '0.0000',
            'as_of' => self::AS_OF,
            'note' => 'Saldo de caja de la tienda de demostracion. SIN CERTIFICAR: lo certifica la direccion financiera (ops:check-opening-balances).',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $existente = DB::table('opening_balances')
            ->where('store_id', $tienda)
            ->where('kind', 'cash')
            ->where('bank_account_id', $cuenta)
            ->value('id');

        if ($existente === null) {
            DB::table('opening_balances')->insert($saldo + [
                // La certificacion se queda a NULL: la hace una persona.
                'certified_at' => null,
                'certified_by' => null,
            ]);

            return;
        }

        // La CERTIFICACION NO SE TOCA: es un hecho humano, y una segunda
        // siembra no puede borrarlo.
        DB::table('opening_balances')->where('id', $existente)->update([
            'amount' => $saldo['amount'],
            'as_of' => $saldo['as_of'],
            'note' => $saldo['note'],
            'updated_at' => $now,
        ]);
    }
}
