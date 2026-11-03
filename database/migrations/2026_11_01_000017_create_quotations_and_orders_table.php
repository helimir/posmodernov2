<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M17 — cotizaciones y pedidos, como entidades DISTINTAS.
 *
 * El legacy tenia una sola tabla `quotation_info` con un `is_order` que
 * decidia que era, y los pedidos_POINTER__ aparecian en los listados de
 * cotizaciones. Aqui son dos tablas con la misma forma.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([['quotations', 'quotation'], ['orders', 'order']] as [$tabla, $singular]) {
            Schema::create($tabla, function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
                $table->foreignId('customer_id')->nullable()->index()->constrained('customers')->nullOnDelete();
                $table->string('reference_no', 100)->nullable();
                $table->date('date');
                $table->enum('status', ['draft', 'confirmed', 'cancelled'])->default('draft');
                $table->text('notes')->nullable();
                $table->timestamps();
            });

            Schema::create($singular.'_items', function (Blueprint $table) use ($tabla, $singular) {
                $table->id();
                $table->foreignId($singular.'_id')->index()->constrained($tabla)->cascadeOnDelete();
                $table->foreignId('product_id')->index()->constrained('products')->restrictOnDelete();
                $table->decimal('quantity', 25, 4)->default(0);
                $table->decimal('unit_price', 25, 4)->default(0);
                $table->decimal('discount', 25, 4)->default(0);
                $table->timestamps();
            });

            Schema::create($singular.'_totals', function (Blueprint $table) use ($tabla, $singular) {
                $table->id();
                $table->foreignId($singular.'_id')->unique()->constrained($tabla)->cascadeOnDelete();
                $table->decimal('sub_total', 25, 4)->default(0);
                $table->decimal('discount', 25, 4)->default(0);
                $table->decimal('tax_total', 25, 4)->default(0);
                $table->decimal('grand_total', 25, 4)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['orders', 'quotations'] as $tabla) {
            $singular = rtrim($tabla, 's');
            Schema::dropIfExists($singular.'_totals');
            Schema::dropIfExists($singular.'_items');
            Schema::dropIfExists($tabla);
        }
    }
};
