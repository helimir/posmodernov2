<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M08 — el maestro del catalogo.
 *
 * `products` NO guarda un porcentaje de impuesto: guarda la CATEGORIA
 * (`tax_category_id`), y la tasa vigente se resuelve con la categoria. El
 * legacy guardaba el porcentaje, que duplicaba el dato y quedaba viejo
 * cuando la tasa cambiaba.
 *
 * `pos_templates.template_content` es `jsonb`, no el blob `ucs2` del legacy, y
 * `payment_methods.country_rules` es `jsonb`: las reglas por pais son DATOS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->foreignId('parent_id')->nullable()->index()->constrained('categories')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->timestamps();
        });

        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 255);
            $table->foreignId('category_id')->nullable()->index()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->index()->constrained('brands')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->index()->constrained('units')->nullOnDelete();
            $table->foreignId('box_id')->nullable()->index()->constrained('boxes')->nullOnDelete();
            // La CATEGORIA del impuesto, nunca el porcentaje.
            $table->foreignId('tax_category_id')->nullable()->index()->constrained('tax_categories')->nullOnDelete();
            $table->enum('type', ['product', 'service'])->default('product');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->index()->constrained('products')->cascadeOnDelete();
            $table->string('path', 255);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->jsonb('template_content')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            // Las reglas por pais son datos, no codigo.
            $table->jsonb('country_rules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('pos_templates');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('boxes');
        Schema::dropIfExists('units');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
