<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M38 — el ANCHO de linea del destino es un DATO que se DECLARA.
 *
 * REGLA que fija esta migracion: *un destino de impresion declara su ancho
 * de linea, y el sistema no lo adivina*.
 *
 * La columna nace con un valor por defecto (32), y ese valor es una ADIVINANZA
 * del esquema: un destino que no declara su ancho se queda con 32 caracteres
 * por linea, y sus tickets salen cortados en silencio. La unidad F2-07 exige
 * lo contrario: el destino tiene que DECLARAR el ancho, y un destino sin
 * ancho declarado no puede componer un ticket (lo dice el renderer, con su
 * mensaje).
 *
 * Y POR QUE SE QUITA EL VALOR POR DEFECTO Y NO SOLO EL `NOT NULL`: con el
 * valor por defecto, `char_per_line` NUNCA seria nulo, y la regla del destino
 * sin ancho declarado no tendria con que dispararse. Sin valor por defecto,
 * un destino se nace con el ancho que declara la tienda, y el que no lo
 * declara se queda sin ancho: que es lo que dice.
 *
 * NO se tocan las demas columnas: el resto de las capacidades del destino
 * (perfil, codepagina, soporte de QR) ya son datos que se declaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_targets', function (Blueprint $table): void {
            $table->integer('char_per_line')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('print_targets', function (Blueprint $table): void {
            $table->integer('char_per_line')->nullable(false)->default(32)->change();
        });
    }
};
