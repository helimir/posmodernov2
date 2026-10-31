<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M02 — capa fiscal: perfil, tipos de documento, ambientes, catálogo de claves,
 * esquemas de impuesto y vocabulario de estados (nativo → genérico).
 *
 * `fiscal_providers` se crea aquí: es el actor intermedio y la tabla de la
 * capa fiscal (el plan no asignaba su migración). `hash_algorithm` y
 * `signature_scheme` NO tienen valor por defecto: la norma no fija algoritmo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->index()->constrained('countries')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('document_standard', 50)->nullable();
            $table->string('schema_version', 20)->nullable();
            $table->string('schema_reference_url', 255)->nullable();
            $table->string('xml_namespace', 255)->nullable();
            $table->string('xsd_url', 255)->nullable();
            $table->string('id_scheme_code', 50)->nullable();
            $table->string('id_scheme_pattern', 255)->nullable();
            // La referencia de verificación es un par valor + esquema: el valor
            // viaja con el documento (M22) y el esquema es del perfil. Nunca
            // una columna `uuid`.
            $table->string('authority_reference_scheme', 50)->nullable();
            $table->string('numbering_scheme', 50)->nullable();
            $table->boolean('requires_control_number')->default(false);
            $table->boolean('authorized_range_required')->default(false);
            $table->boolean('holes_allowed')->default(true);
            $table->string('signer_model', 30);
            // Sin valor por defecto asumido: la norma no fija algoritmo.
            $table->string('hash_algorithm', 50)->nullable();
            $table->string('signature_scheme', 50)->nullable();
            $table->string('rounding_rule', 20)->nullable();
            $table->boolean('tax_inclusive_default')->default(false);
            $table->foreignId('default_currency_id')->nullable()->index()->constrained('currencies')->nullOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['country_id', 'code']);
        });

        Schema::create('fiscal_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            // printer | pac | portal
            $table->string('kind', 20);
            $table->string('base_url', 255)->nullable();
            $table->string('auth_scheme', 50)->nullable();
            $table->string('credentials_ref', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['fiscal_profile_id', 'code']);
        });

        Schema::create('fiscal_status_vocabularies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->cascadeOnDelete();
            $table->string('native_code', 50);
            $table->string('native_label', 100)->nullable();
            // El estado fiscal no es un enum universal: el código nativo se
            // mapea a un estado genérico.
            $table->string('generic_status', 30);
            $table->boolean('is_final')->default(false);
            $table->boolean('is_cancellation')->default(false);
            $table->timestamps();
            $table->unique(['fiscal_profile_id', 'native_code']);
        });

        Schema::create('fiscal_profile_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->string('direction', 30);
            $table->string('tax_treatment', 30);
            $table->boolean('requires_authorized_range')->default(false);
            $table->boolean('allows_cancellation')->default(true);
            $table->boolean('requires_parent_reference')->default(false);
            $table->string('exemption_marker', 30)->nullable();
            $table->timestamps();
            $table->unique(['fiscal_profile_id', 'code']);
        });

        Schema::create('fiscal_profile_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('label', 100)->nullable();
            // issuer | printer
            $table->string('role', 20);
            $table->foreignId('provider_id')->nullable()->index()->constrained('fiscal_providers')->nullOnDelete();
            $table->string('base_url', 255)->nullable();
            $table->string('auth_scheme', 50)->nullable();
            $table->boolean('is_testing')->nullable();
            $table->boolean('is_production')->nullable();
            $table->timestamps();
            $table->unique(['fiscal_profile_id', 'code']);
        });

        Schema::create('fiscal_profile_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->cascadeOnDelete();
            // Catálogo de las claves del `jsonb`: el token obligatorio del prefijo
            // de serie (`series_prefix_required_token`) es un dato, no un literal.
            $table->string('key', 100);
            $table->string('data_type', 30);
            $table->string('label', 150);
            $table->text('value')->nullable();
            $table->boolean('required')->default(false);
            $table->string('applies_to', 20);
            $table->string('evidence_status', 30);
            $table->string('source_ref', 255)->nullable();
            $table->timestamps();
            $table->unique(['fiscal_profile_id', 'key']);
        });

        Schema::create('fiscal_tax_schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->boolean('is_withholding')->default(false);
            $table->timestamps();
            $table->unique(['fiscal_profile_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_tax_schemes');
        Schema::dropIfExists('fiscal_profile_fields');
        Schema::dropIfExists('fiscal_profile_environments');
        Schema::dropIfExists('fiscal_profile_document_types');
        Schema::dropIfExists('fiscal_profiles');
        Schema::dropIfExists('fiscal_status_vocabularies');
        Schema::dropIfExists('fiscal_providers');
    }
};
