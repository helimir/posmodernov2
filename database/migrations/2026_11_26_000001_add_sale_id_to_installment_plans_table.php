<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M58 — `installment_plans.sale_id`: el plan de cuotas pertenece a una VENTA.
 *
 * EL PLAN DE CUOTAS es el CALENDARIO de una venta a credito, y sin la venta
 * no se sabe de quien es la deuda ni cuanto se cobro: el plan habria que
 * buscarlo por un texto libre (`reference_no`), y un texto libre no es una
 * clave.
 *
 * Y LA FK LA PIDE EL DISENO: `exploration.md` §3.2 dice, de `installment_orders`
 * a `installment_plans`, *"FK `sales.id`"*. La M15 creo la tabla sin ella y
 * nadie la anadio: el plan existia, pero huerfano, como el resto del dominio
 * de las cuotas.
 *
 * LA FK ES NULLABLE: un plan puede existir sin venta (un anticipo, un acuerdo
 * de pago), y una FK NOT NULL obligaria a inventar una venta para poder
 * registrar un plan. Lo que NO es opcional es el PLAN: sin `store_id` y sin
 * `customer_id` el plan no pertenece a nadie, y esas dos columnas ya son NOT
 * NULL desde la M15.
 *
 * Y EL BORRADO DE UNA VENTA NO ARRASTRA EL PLAN: la restriccion es
 * `nullOnDelete`, como la de `sales.customer_id`: una venta anulada no se
 * borra (se marca), asi que el caso real es el de una venta que se elimina
 * por una restauracion, y en ese caso el plan se queda sin venta en vez de
 * desaparecer con su historial de cobros.
 *
 * Y UNA VENTA TIENE UN SOLO PLAN, y lo dice el ESQUEMA: el indice UNIQUE
 * sobre `sale_id` es la misma defensa que el UNIQUE de `cash_registers` sobre
 * `(store_id, business_date)`. El dominio tambien lo comprueba (una venta con
 * dos planes cobraría el interes dos veces), pero la comprobacion del dominio
 * es una LECTURA y dos peticiones a la vez se la saltan: el indice es lo que
 * lo vuelve imposible. Los planes SIN venta no se chocan entre si (en
 * PostgreSQL un indice UNIQUE admite varios `NULL`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->foreignId('sale_id')
                ->nullable()
                ->index()
                ->after('customer_id')
                ->constrained('sales')
                ->nullOnDelete();

            $table->unique('sale_id', 'installment_plans_sale_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            // El UNIQUE de PostgreSQL es una RESTRICCION (su indice es el de
            // la restriccion), y se tira con `DROP CONSTRAINT`.
            $table->dropUnique('installment_plans_sale_id_unique');
            $table->dropForeign(['sale_id']);
            $table->dropColumn('sale_id');
        });
    }
};
