<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M03 — categorías de IVA y tasas.
 *
 * Las cinco categorías son el TRATAMIENTO fiscal del producto: el producto
 * apunta a una categoría, nunca a un porcentaje (design §A.1, exploration §4.3).
 * El porcentaje vive en `tax_rates`, con vigencia, regla de redondeo, estado de
 * evidencia y fuente.
 *
 * `is_withholding` marca el IVA retenido: es una categoría con retención, y
 * además un DOCUMENTO PROPIO (numeración de 14 caracteres, período de imposición
 * y referencia al documento de origen). No es una categoría de línea de una
 * factura: el documento llega con las series fiscales (M21/M22).
 *
 * La regla de redondeo por línea con ajuste visible en el total es una DECISIÓN
 * DE PRODUCTO, no un requisito de la providencia (research senat-venezuela T8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_profile_id')->index()->constrained('fiscal_profiles')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->boolean('is_withholding')->default(false);
            // sale | purchase | both
            $table->string('applies_to', 20)->default('both');
            // Un dato no verificado se marca como pendiente, no se afirma.
            $table->string('evidence_status', 30);
            $table->string('source_ref', 255)->nullable();
            $table->timestamps();
            $table->unique(['fiscal_profile_id', 'code']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_category_id')->index()->constrained('tax_categories')->cascadeOnDelete();
            // Porcentaje de tasa: numeric(25,6) (design §A.2).
            $table->decimal('percentage', 25, 6);
            // line | total. Nulo = se hereda del perfil fiscal.
            $table->string('rounding_rule', 20)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->integer('priority')->default(0);
            $table->string('evidence_status', 30);
            $table->string('source_ref', 255)->nullable();
            $table->timestamps();
            $table->unique(['tax_category_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_categories');
    }
};
