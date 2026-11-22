<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M44 - el CATALOGO de DENOMINACIONES de efectivo.
 *
 * `cash_denominations` son las PIEZAS de la moneda de una tienda: el billete
 * de 50, la moneda de 0.10. Es DATO DEL NEGOCIO, y por eso vive en una tabla
 * y no en el codigo: el catalogo de una moneda es del pais que la emite, y
 * un literal en `app/` seria un fork por pais.
 *
 * POR QUE HACE FALTA: el cajero cuenta PIEZAS, no importes. Un arqueo que
 * solo acepta una cifra le pide al cajero que haga la suma mentalmente y le
 * quite el trabajo de decir QUE falta. Con el catalogo, el sistema reparte el
 * importe esperado en piezas y puede decir "faltan 3 monedas de 0.10".
 *
 * `value` es el VALOR de la pieza (un decimal), `kind` es si la pieza es un
 * billete o una moneda, e `is_active` es de INTERFAZ: permite retirar una
 * denominacion del catalogo sin borrarla (un billete que deja de circular
 * sigue siendo un hecho).
 *
 * Y `currency_id` ata la pieza a una MONEDA: una tienda que opera en dos
 * monedas no puede contar con el mismo catalogo, porque 50 no son lo mismo en
 * una que en otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_denominations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();

            // La MONEDA de la pieza: el catalogo es de una moneda, no de una
            // tienda abstracta.
            $table->foreignId('currency_id')->index()->constrained('currencies')->restrictOnDelete();

            // El VALOR de la pieza.
            $table->decimal('value', 25, 4);

            // BILLETE o MONEDA: el cajero los cuenta distinto, y el mensaje
            // del descuadre los nombra distinto.
            $table->enum('kind', ['billete', 'moneda']);

            // De INTERFAZ, no contable: una denominacion que deja de circular
            // se retira del catalogo, no se borra.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Una tienda, una moneda, un valor: el catalogo no se repite.
            $table->unique(['store_id', 'currency_id', 'value'], 'cash_denominations_store_currency_value_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_denominations');
    }
};
