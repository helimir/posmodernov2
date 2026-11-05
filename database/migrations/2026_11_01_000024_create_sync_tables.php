<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M24 — la sincronizacion del terminal legacy: `sync_batches` +
 * `legacy_translation_map`.
 *
 * El terminal legacy sigue siendo legacy durante la transicion (ADR-0006):
 * empuja SQL crudo de MySQL con los nombres de SU esquema. El maestro es
 * PostgreSQL con otro esquema, asi que hacen falta dos cosas y NINGUNA es
 * codigo:
 *
 * - `sync_batches` es la traza de los lotes aplicados y, sobre todo, la
 *   CLAVE DE IDEMPOTENCIA: `UNIQUE (client_id, payload_sha256)`. El
 *   terminal legacy trunca su log SOLO cuando la respuesta fue exitosa,
 *   asi que una respuesta perdida provoca un REENVIO IDENTICO del mismo
 *   lote. Con la restriccion unica, el maestro reconoce el reenvio y
 *   responde con EXITO sin volver a aplicarlo: sin esto, el lote falla por
 *   la clave primaria y el terminal se queda congelado en la ventana de
 *   corte (el hallazgo S1 del ADR-0006).
 *
 * - `legacy_translation_map` es el MAPA DE TRADUCCION SEMBRADO. La
 *   direccion es LEGACY -> DESTINO: cada fila dice "esta tabla y esta
 *   columna del legacy son esta tabla y esta columna de aqui". El mapa es
 *   DATO AUDITABLE: un `if ($tabla === 'selling_info')` en el codigo seria
 *   un defecto, porque el mapa se ajusta sin desplegar.
 *
 * El `payload_sha256` es el hash del LOTE (el string `data` del contrato),
 * NO el del cuerpo HTTP completo: el terminal reenvia el mismo lote con un
 * `nonce` y un `timestamp` nuevos, de modo que el cuerpo HTTP cambia en
 * cada intento y no serviria como clave de idempotencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La TRAZA de los lotes aplicados.
        Schema::create('sync_batches', function (Blueprint $table): void {
            $table->id();

            // El CLIENTE que empuja el lote: el `username` del protocolo v2.
            $table->string('client_id', 100);

            // El hash del LOTE (los bytes exactos del string `data`). Junto
            // con `client_id`, es la clave de idempotencia.
            $table->string('payload_sha256', 64);

            // Cuantas sentencias traia el lote.
            $table->integer('statement_count')->default(0);

            // Cuando se aplico. NULL mientras el lote no esta aplicado.
            $table->timestampTz('applied_at')->nullable();

            $table->timestamps();

            // La RESTRICCION que hace idempotente al maestro.
            $table->unique(['client_id', 'payload_sha256']);
        });

        // El hash es un digest SHA-256 y el conteo de sentencias no es
        // negativo: el error lo da el esquema, no la disciplina de quien
        // escribe.
        DB::statement(
            "ALTER TABLE sync_batches ADD CONSTRAINT sync_batches_payload_sha256_hex CHECK (payload_sha256 ~ '^[0-9a-f]{64}$')"
        );

        DB::statement(
            'ALTER TABLE sync_batches ADD CONSTRAINT sync_batches_statement_count_positive CHECK (statement_count >= 0)'
        );

        // El MAPA DE TRADUCCION SEMBRADO.
        Schema::create('legacy_translation_map', function (Blueprint $table): void {
            $table->id();

            // La TABLA del legacy: su nombre tecnico, tal cual lo escribe el
            // terminal.
            $table->string('legacy_table', 100);

            // La COLUMNA del legacy. `'*'` para una regla de TABLA.
            $table->string('legacy_column', 100);

            // La tabla de DESTINO. NULL significa "esta columna NO se
            // traslada": el dato se deriva en el destino o no existe alli.
            $table->string('new_table', 100)->nullable();

            // La columna de DESTINO.
            $table->string('new_column', 100)->nullable();

            // La TRANSFORMACION del valor. Por defecto `rename`: el valor
            // viaja tal cual.
            $table->string('transform', 50)->nullable()->default('rename');

            $table->timestamps();

            // Una fila por columna del legacy: el mapa no se contradice.
            $table->unique(['legacy_table', 'legacy_column']);
        });

        DB::statement(
            "ALTER TABLE legacy_translation_map ADD CONSTRAINT legacy_translation_map_identifiers_present CHECK (legacy_table <> '' AND legacy_column <> '')"
        );

        // La tabla de destino y la columna de destino van juntas: o no se
        // traslada la columna, o se traslada a una columna concreta.
        DB::statement(
            'ALTER TABLE legacy_translation_map ADD CONSTRAINT legacy_translation_map_destination_paired CHECK ((new_table IS NULL AND new_column IS NULL) OR (new_table IS NOT NULL AND new_column IS NOT NULL))'
        );

        DB::statement(<<<'SQL'
            COMMENT ON TABLE sync_batches IS
            'Traza de los lotes de sincronizacion aplicados. UNIQUE (client_id, payload_sha256) es la clave de idempotencia: un reenvio identico del mismo lote responde con exito y NO se vuelve a aplicar. payload_sha256 es el hash del LOTE (el string data del contrato), no el del cuerpo HTTP: el terminal reenvia el mismo lote con un nonce y un timestamp nuevos.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN sync_batches.payload_sha256 IS
            'Hash SHA-256 del LOTE: los bytes exactos del string data del contrato. NO es el hash del cuerpo HTTP, porque el reenvio del terminal lleva un nonce y un timestamp nuevos.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON TABLE legacy_translation_map IS
            'Mapa de traduccion SEMBRADO del esquema legacy al esquema destino. Es DATO auditable, no codigo: la direccion es legacy -> destino y cada fila dice que columna del legacy es que columna de aqui. new_table IS NULL significa que la columna NO se traslada.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN legacy_translation_map.legacy_column IS
            'Columna del legacy. El valor "*" marca una regla de TABLA (la fila aplica a la tabla entera, no a una columna).'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN legacy_translation_map.transform IS
            'Nombre de la transformacion del valor. Por defecto "rename": el valor viaja tal cual. NULL con new_table NULL significa que la columna no se traslada.'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_translation_map');
        Schema::dropIfExists('sync_batches');
    }
};
