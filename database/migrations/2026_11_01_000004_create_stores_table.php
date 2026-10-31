<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M04 — `stores` sin blob de preferencias y con el registro fiscal aparte.
 * Legacy → destino: `preference` → store_settings · `vat_reg_no` →
 * store_fiscal_registrations.tax_id · `country` → country_id (FK, countries =
 * M01) · `currency` → currency_stores (M09) · columnas de impresora →
 * print_targets (M23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code_name', 55)->nullable()->unique();
            $table->string('mobile', 14)->nullable();
            $table->string('email', 100)->nullable();
            // FK a `countries` (M01): la restricción se añade en M27, que es
            // la migración de FKs tardías, porque `countries` aterriza más
            // tarde en la cadena de PRs.
            $table->foreignId('country_id')->nullable()->index();
            $table->string('zip_code', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('logo')->nullable();
            $table->string('favicon', 250)->nullable();
            $table->string('thumbnail', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->string('feedback_status', 100)->default('ready');
            $table->timestamp('feedback_at')->nullable();
            $table->timestamps();
        });

        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained('stores')->cascadeOnDelete();
            $table->string('timezone')->nullable();
            $table->string('base_color')->nullable();
            $table->string('reference_format')->nullable();
            $table->string('sales_reference_prefix')->nullable();
            $table->string('invoice_view')->nullable();
            $table->text('invoice_footer_text')->nullable();
            $table->unsignedInteger('invoice_edit_lifespan')->nullable();
            $table->string('invoice_edit_lifespan_unit')->nullable();
            $table->unsignedInteger('invoice_delete_lifespan')->nullable();
            $table->string('invoice_delete_lifespan_unit')->nullable();
            $table->boolean('auto_print')->default(false);
            $table->boolean('remote_printing')->default(true);
            $table->unsignedSmallInteger('char_per_line')->default(42);
            $table->boolean('sound_effect')->default(true);
            $table->boolean('expiry_alert_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('store_fiscal_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->cascadeOnDelete();
            // La referencia del país es un par valor + esquema, nunca una
            // columna fija: el esquema vive en `tax_id_scheme`.
            $table->string('tax_id', 250);
            $table->string('tax_id_scheme', 50)->nullable();
            $table->date('issued_at')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_fiscal_registrations');
        Schema::dropIfExists('store_settings');
        Schema::dropIfExists('stores');
    }
};
