<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M40 — la tienda DECLARA su perfil fiscal.
 *
 * EL PROBLEMA que esta migracion arregla: *la tienda no tiene forma de
 * decir cual es su regimen fiscal*. El perfil se deducía del pais, y con
 * eso una tienda en un regimen especial (o en una version distinta de su
 * regimen) no lo podia expresar: su serie salia de otra fila.
 *
 * LA COLUMNA: `stores.fiscal_profile_id`, ANULABLE y con FK a
 * `fiscal_profiles`. Es ANULABLE a proposito: una tienda que no declara
 * perfil usa el de su PAIS (el vigente), que es el caso normal. Y la FK es
 * `RESTRICT`: un perfil que una tienda declara NO se puede borrar, porque
 * borrarlo dejaria a esa tienda apuntando a un regimen que ya no existe.
 *
 * LA FK se puede cerrar YA: `fiscal_profiles` es de M02 y `stores` es de M04,
 * asi que las dos tablas existen cuando esta migracion corre.
 *
 * Y NO SE ANADE NADA MAS: el perfil NO lleva el nombre del pais ni el del
 * regimen. Es una RELACION, y la relacion se resuelve con DATOS (la tabla
 * `fiscal_profiles`), no con codigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->foreignId('fiscal_profile_id')
                ->nullable()
                ->index()
                ->constrained('fiscal_profiles')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fiscal_profile_id');
        });
    }
};
