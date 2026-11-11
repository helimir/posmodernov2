<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Los MEDIOS DE PAGO: el maestro y sus vinculos por tienda.
 *
 * Lo que siembra este seeder es el metodo de CREDITO: el unico cuyo TIPO
 * cambia el significado de un pago. Un pago con un metodo de tipo `credit`
 * NO es un cobro, es DEUDA: por eso no genera movimiento de pago en el libro
 * del cliente, y por eso el total de la venta queda como saldo a favor de la
 * tienda.
 *
 * El TIPO es DATO, no codigo (`payment_methods.type`): cambiar de medio de
 * pago es INSERTAR FILAS, no tocar el motor (ADR-0003).
 *
 * POR QUE SOLO EL DE CREDITO: los demas medios de pago los declara cada
 * negocio (el efectivo, la tarjeta, la transferencia), y sembrarlos aqui
 * inventaria una configuracion que el negocio no ha decidido. El de credito
 * si es parte del modelo: sin el, la caja no puede vender a credito, y el
 * libro del cliente no tiene el caso que lo justifica.
 *
 * Es IDEMPOTENTE: el maestro se apoya en la restriccion unica del `code`, y
 * el vinculo por tienda NO tiene ninguna, asi que se busca por su clave
 * natural antes de escribirse. Sembrar dos veces no puede dejar dos vinculos
 * del mismo metodo en la misma tienda.
 */
class PaymentMethodSeeder extends Seeder
{
    /**
     * El codigo del medio de pago de credito.
     */
    public const CODIGO_CREDITO = 'CREDITO';

    /**
     * El TIPO del medio de pago de credito.
     */
    public const TIPO_CREDITO = 'credit';

    /**
     * Siembra el medio de pago de credito y lo vincula a las tiendas activas.
     */
    public function run(): void
    {
        $now = now();

        $medioId = self::medioDeCredito();

        foreach (DB::table('stores')->where('status', true)->orderBy('id')->get() as $tienda) {
            $this->vinculo($medioId, (int) $tienda->id, $now);
        }
    }

    /**
     * El MAESTRO del medio de pago de credito, sembrado o actualizado.
     *
     * @return int El id del medio de pago.
     */
    public static function medioDeCredito(): int
    {
        $now = now();

        DB::table('payment_methods')->upsert([
            [
                'code' => self::CODIGO_CREDITO,
                'name' => 'Credito',
                'type' => self::TIPO_CREDITO,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['name', 'type', 'updated_at']);

        return (int) DB::table('payment_methods')
            ->where('code', self::CODIGO_CREDITO)
            ->value('id');
    }

    /**
     * El VINCULO del medio de pago con la tienda.
     *
     * @param  Carbon  $now
     */
    private function vinculo(int $medioId, int $storeId, $now): void
    {
        $vinculo = DB::table('payment_method_stores')
            ->where('payment_method_id', $medioId)
            ->where('store_id', $storeId)
            ->value('id');

        if ($vinculo === null) {
            DB::table('payment_method_stores')->insert([
                'payment_method_id' => $medioId,
                'store_id' => $storeId,
                'status' => 'active',
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('payment_method_stores')->where('id', $vinculo)->update([
            'status' => 'active',
            'updated_at' => $now,
        ]);
    }
}
