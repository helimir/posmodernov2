<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M39 — la columna `transform` del mapa de traduccion necesita espacio.
 *
 * El mapa de traduccion del sync (`legacy_translation_map`) es DATO
 * sembrado, y una de sus transformaciones es el `cast_enum`: los pares
 * `legacy:destino` que traducen el vocabulario del legacy al del destino.
 *
 * `string(50)` NO CABE: `cast_enum:paid:paid:due:confirmed:partial:confirmed`
 * son 51 caracteres. Con la columna en 50, el seeder del mapa tiene que
 * recortar el conjunto de pares (y entonces un valor del legacy que no este
 * en la lista RECHAZA el lote entero), o no puede sembrar el caso largo.
 *
 * Por eso la columna se ensancha a 255: el mapa es un dato que se audita, y
 * su tamano no es un problema de espacio.
 *
 * M39 va aparte de M28 porque es OTRO motivo: M28 son indices y restricciones
 * del dominio, y esto es el ancho de una columna del mapa. Y va con el 000039
 * porque el 000039 estaba libre y el orden importa: el seeder del mapa corre
 * DESPUES de todas las migraciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE legacy_translation_map ALTER COLUMN transform TYPE varchar(255)');
    }

    public function down(): void
    {
        // Volver a 50 SOLO es posible si no hay ninguna transformacion larga:
        // si la hay, el `ALTER` falla y la migracion se queda sin revertir.
        DB::statement('ALTER TABLE legacy_translation_map ALTER COLUMN transform TYPE varchar(50)');
    }
};
