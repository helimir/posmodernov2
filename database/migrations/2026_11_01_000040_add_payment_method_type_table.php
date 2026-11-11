<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M40 — el TIPO del medio de pago.
 *
 * `payment_methods` NO tenia columna `type`: el legacy separaba el pago en
 * efectivo del pago a credito por el NOMBRE del metodo y por una bandera
 * suelta, y las dos cosas divergen. El nombre es de presentacion: el TIPO es
 * lo que decide si un pago es un COBRO o es DEUDA.
 *
 * VALORES: `cash`, `card`, `transfer` y `credit`. El que importa aqui es
 * `credit`: un pago con un metodo de ese tipo NO es un cobro, es una deuda
 * del cliente, y por eso NO genera movimiento de pago en su libro.
 *
 * Es una COLUMNA, no un codigo: cambiar de medio de pago es insertar filas.
 * Y el `default` es `cash`, que es lo que declara la tienda que no dice nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->string('type', 30)->default('cash')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }
};
