<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M21 — series fiscales: las DOS numeraciones (la del documento, del emisor, y
 * el Número de Control, de la imprenta digital) y la traza de reservas.
 *
 * `fiscal_providers` NO se crea aquí: la crea M02 (decisión de PR3, ya
 * fusionada en `main`). Esta migración sólo la referencia. El plan nombraba
 * `2026_11_01_000021_create_fiscal_providers_and_series_table.php`, pero esa
 * parte ya no aplica: recrear la tabla sería un error de migración.
 *
 * `series_role` es el nombre canónico (`document` | `control` |
 * `retention_receipt`) y `assigned_by` distingue la autoridad (`issuer` |
 * `authority` | `printer` | `psp`). `series_kind` queda como ALIAS
 * documentado: no existe ninguna columna con ese nombre (design §F.2, C2).
 *
 * La serie de control guarda el ESPEJO LOCAL del rango autorizado de la
 * imprenta: `from_number`, `to_number` y `credential_ref` (design §D.1, C3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->restrictOnDelete();
            // El correlativo es por tienda (exploration §5.4, P7).
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            // La imprenta que autoriza el rango de control (actor intermedio).
            $table->foreignId('provider_id')->nullable()->index()->constrained('fiscal_providers')->nullOnDelete();
            // Rol canónico de la serie: el conjunto cerrado vive en el esquema.
            $table->enum('series_role', ['document', 'control', 'retention_receipt']);
            // Autoridad que asigna el número: issuer | authority | printer | psp.
            $table->enum('assigned_by', ['issuer', 'authority', 'printer', 'psp']);
            $table->string('prefix', 30)->nullable();
            // El siguiente número a repartir y el rango autorizado que lo acota.
            $table->bigInteger('next_number');
            $table->bigInteger('from_number');
            $table->bigInteger('to_number');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            // Referencia de la credencial de la imprenta: una REFERENCIA, nunca
            // el secreto (misma regla que `sms_providers.credential_env_prefix`).
            $table->string('credential_ref', 255)->nullable();
            // Identificador de 2 dígitos del control; la primera asignación es
            // `00` con secuencial `1` (art. 9.1 y 27).
            $table->string('control_identifier', 10)->nullable();
            // Secuencial del control: hasta 8 dígitos.
            $table->unsignedSmallInteger('sequence_length')->nullable();
            $table->timestamps();
        });

        Schema::create('fiscal_document_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->index()->constrained('fiscal_series')->restrictOnDelete();
            $table->bigInteger('number');
            // Copia del rol de la serie en el momento de la reserva: la traza
            // no depende de que la serie cambie después.
            $table->enum('series_role', ['document', 'control', 'retention_receipt']);
            $table->foreignId('reserved_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('reserved_at');
            $table->timestampTz('voided_at')->nullable();
            $table->timestamps();
            // Un número no se repite dentro de una serie: última línea de
            // defensa contra la colisión del legacy.
            $table->unique(['series_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_reservations');
        Schema::dropIfExists('fiscal_series');
    }
};
