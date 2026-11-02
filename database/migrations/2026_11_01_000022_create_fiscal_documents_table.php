<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M22 — el comprobante fiscal y sus anexos.
 *
 * La cabecera es `fiscal_documents`: el estado es un enum GENÉRICO y el código
 * nativo del país vive en `native_status_code` (el mapeo, en
 * `fiscal_status_vocabularies`). La referencia de verificación es el par
 * `authority_reference` + `authority_reference_scheme`; **no** hay columna
 * `uuid`.
 *
 * Los impuestos van en `fiscal_document_taxes` con `line_id` NULLABLE: `NULL`
 * es el nodo de cabecera (así lo exige México, donde el mismo nodo de
 * impuestos aparece en comprobante y en concepto) y con `line_id` es el nodo
 * de línea. El IVA retenido **no** es una categoría de línea de un documento de
 * venta: es un documento propio (`document_type='retention_receipt'`) con su
 * propia serie.
 *
 * `fiscal_document_events` es APPEND-ONLY: la auditoría electrónica de toda
 * acción (emisión, modificación, anulación) no se actualiza ni se borra, y un
 * trigger de PostgreSQL lo impide a nivel de base de datos, no de aplicación.
 * Por eso la tabla **no** tiene `updated_at` ni `deleted_at`.
 *
 * El control tiene **una única sede**: `fiscal_document_control_assignments`,
 * 1:1 con el documento. No se duplica en `fiscal_documents`.
 *
 * `fiscal_document_reservations` NO se crea aquí: la crea M21 (ya fusionada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->restrictOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            // El actor y el canal concreto (imprenta, PAC, portal).
            $table->foreignId('provider_id')->nullable()->index()->constrained('fiscal_providers')->nullOnDelete();
            // La serie y el número del documento: la cabecera guarda su propia
            // numeración y la traza (`fiscal_document_reservations`) la vincula.
            $table->foreignId('series_id')->nullable()->index()->constrained('fiscal_series')->restrictOnDelete();
            $table->unsignedBigInteger('number')->nullable();
            $table->foreignId('reservation_id')->nullable()->index()->constrained('fiscal_document_reservations')->restrictOnDelete();
            // Tipo de documento: enumeración GENÉRICA, no un catálogo de país.
            $table->enum('document_type', ['invoice', 'credit_note', 'debit_note', 'retention_receipt']);
            $table->enum('status', ['draft', 'issued', 'void']);
            $table->string('native_status_code', 50)->nullable();
            // La referencia de verificación es el par valor + esquema.
            $table->string('authority_reference', 100)->nullable();
            $table->string('authority_reference_scheme', 50)->nullable();
            // El QR NO figura en la norma: opcional por canal, nunca exigido.
            $table->text('qr_content')->nullable();
            $table->text('qr_url')->nullable();
            // El hash y la firma se PARAMETRIZAN: la norma no fija algoritmo,
            // así que no se asume ninguno por defecto.
            $table->string('hash_algorithm', 50)->nullable();
            $table->string('signature_scheme', 50)->nullable();
            $table->string('hash', 128)->nullable();
            $table->string('printer_validation_status', 50)->nullable();
            // `customers` no existe todavia: FK diferida a M27.
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->foreignId('currency_id')->nullable()->index()->constrained('currencies')->restrictOnDelete();
            // Moneda extranjera: ambas cantidades y el tipo de cambio.
            $table->foreignId('original_currency_id')->nullable()->index()->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 25, 4)->nullable();
            $table->decimal('subtotal', 25, 4)->default(0);
            $table->decimal('tax_total', 25, 4)->default(0);
            $table->decimal('total_amount', 25, 4)->default(0);
            $table->date('issue_date')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            // Periodo de imposicion: propio del comprobante de retencion.
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->timestamps();
            // Un número no se repite dentro de una serie.
            $table->unique(['series_id', 'number']);
        });

        Schema::create('fiscal_document_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_document_id')->index()->constrained('fiscal_documents')->cascadeOnDelete();
            // Las lineas del comprobante fiscal todavia no existen: FK diferida
            // a M27. `NULL` = nodo de cabecera.
            $table->unsignedBigInteger('line_id')->nullable()->index();
            $table->enum('tax_type', ['transfer', 'withholding']);
            $table->foreignId('tax_category_id')->nullable()->index()->constrained('tax_categories')->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->index()->constrained('tax_rates')->nullOnDelete();
            // El porcentaje queda CONGELADO: un cambio posterior de la tasa
            // vigente no altera un documento ya emitido.
            $table->decimal('tax_rate_snapshot', 9, 4)->nullable();
            $table->string('evidence_status', 50)->nullable();
            $table->decimal('base_amount', 25, 4)->default(0);
            $table->decimal('tax_amount', 25, 4)->default(0);
            $table->decimal('rounding_adjustment', 25, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('fiscal_document_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_document_id')->index()->constrained('fiscal_documents')->cascadeOnDelete();
            // Roles por dato, no por columnas: hay paises con tres roles
            // (emisor, receptor y mandatario).
            $table->enum('role', ['issuer', 'receiver', 'third_party']);
            $table->string('name', 255);
            // El RIF del receptor es opcional en persona natural: entonces
            // va la cedula o el pasaporte, y `fiscal_id_kind` lo dice.
            $table->string('fiscal_id', 50)->nullable();
            $table->enum('fiscal_id_kind', ['rif', 'cedula', 'pasaporte', 'other'])->nullable();
            $table->string('address', 255)->nullable();
            $table->timestamps();
            $table->unique(['fiscal_document_id', 'role']);
        });

        Schema::create('fiscal_document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_document_id')->index()->constrained('fiscal_documents')->cascadeOnDelete();
            $table->enum('link_type', ['corrects', 'cancels', 'replaces', 'radian_event']);
            $table->foreignId('parent_document_id')->index()->constrained('fiscal_documents')->restrictOnDelete();
            $table->string('parent_reference', 100)->nullable();
            $table->timestamps();
            $table->unique(['fiscal_document_id', 'link_type', 'parent_document_id']);
        });

        Schema::create('fiscal_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_document_id')->index()->constrained('fiscal_documents')->cascadeOnDelete();
            $table->enum('action', ['issue', 'modify', 'cancel']);
            $table->foreignId('actor')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->jsonb('payload');
            // `created_at` sí (cuándo se insertó la fila); `updated_at` y
            // `deleted_at` NO: la traza es append-only.
            $table->timestampTz('created_at');
        });

        Schema::create('fiscal_document_control_assignments', function (Blueprint $table) {
            $table->id();
            // 1:1 con el documento: la ÚNICA sede del control.
            $table->foreignId('fiscal_document_id')->unique()->constrained('fiscal_documents')->cascadeOnDelete();
            $table->string('control_number', 50);
            // El rango total que se registra e imprime.
            $table->unsignedBigInteger('control_range_from')->nullable();
            $table->unsignedBigInteger('control_range_to')->nullable();
            $table->timestampTz('control_assigned_at')->nullable();
            $table->foreignId('printer_id')->nullable()->index()->constrained('fiscal_providers')->nullOnDelete();
            $table->string('printer_rif', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('fiscal_periodic_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->cascadeOnDelete();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('control_from')->nullable();
            $table->unsignedBigInteger('control_to')->nullable();
            $table->unsignedBigInteger('format_from')->nullable();
            $table->unsignedBigInteger('format_to')->nullable();
            $table->jsonb('document_ids')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fiscal_contingency_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->cascadeOnDelete();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->restrictOnDelete();
            // Al solicitar los talonarios se declara el control y el documento
            // inicial y final.
            $table->unsignedBigInteger('control_from');
            $table->unsignedBigInteger('control_to');
            $table->unsignedBigInteger('document_from');
            $table->unsignedBigInteger('document_to');
            $table->timestampTz('issued_at')->nullable();
            // Al cesar la contingencia se registran y se remiten en PDF al dia
            // siguiente.
            $table->timestampTz('registered_at')->nullable();
            $table->timestampTz('pdf_remitted_at')->nullable();
            $table->timestamps();
        });

        // El append-only se impone en la BASE de datos, no en la aplicacion:
        // un UPDATE o un DELETE sobre la traza abortan la sentencia.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fiscal_document_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'fiscal_document_events es append-only: la operacion % no esta permitida', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER fiscal_document_events_append_only
            BEFORE UPDATE OR DELETE ON fiscal_document_events
            FOR EACH ROW EXECUTE FUNCTION fiscal_document_events_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fiscal_document_events_append_only ON fiscal_document_events');
        DB::statement('DROP FUNCTION IF EXISTS fiscal_document_events_append_only()');

        Schema::dropIfExists('fiscal_contingency_forms');
        Schema::dropIfExists('fiscal_periodic_reports');
        Schema::dropIfExists('fiscal_document_control_assignments');
        Schema::dropIfExists('fiscal_document_events');
        Schema::dropIfExists('fiscal_document_links');
        Schema::dropIfExists('fiscal_document_parties');
        Schema::dropIfExists('fiscal_document_taxes');
        Schema::dropIfExists('fiscal_documents');
    }
};
