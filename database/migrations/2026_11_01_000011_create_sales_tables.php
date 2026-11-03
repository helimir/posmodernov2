<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 — ventas. Renombra el legacy: ``selling_info`` -> ``sales``,
``selling_item`` -> ``sale_items``, ``selling_price`` -> ``sale_totals``.

``sales.status`` admite ``fiscal_failed``: una venta cuyo documento
fiscal no salio queda en ese estado y su correlativo NO se libera.
``sale_items`` separa el PORCENTAJE congelado (``tax_rate_snapshot``)
del IMPORTE (``tax_amount``): el legacy guardaba el % en ``tax`` y el
importe en ``item_tax``, y no se podia leer uno sin el otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->index()->constrained('customers')->nullOnDelete();
            // La FK al documento fiscal es TARDIA: la cierra M27, para no crear
            // un ciclo entre M11 y M22.
            $table->foreignId('fiscal_document_id')->nullable()->index();
            $table->string('invoice_no', 100)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->date('sale_date');
            $table->enum('status', ['draft', 'confirmed', 'paid', 'cancelled', 'fiscal_failed'])->default('draft');
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->index()->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained('products')->restrictOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->decimal('quantity', 25, 4)->default(0);
            $table->decimal('unit_price', 25, 4)->default(0);
            $table->decimal('discount', 25, 4)->default(0);
            $table->foreignId('tax_category_id')->nullable()->index()->constrained('tax_categories')->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->index()->constrained('tax_rates')->nullOnDelete();
            $table->decimal('tax_rate_snapshot', 9, 4)->nullable();
            $table->decimal('tax_amount', 25, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('sale_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained('sales')->cascadeOnDelete();
            $table->decimal('sub_total', 25, 4)->default(0);
            $table->decimal('discount', 25, 4)->default(0);
            $table->decimal('tax_total', 25, 4)->default(0);
            $table->decimal('rounding_adjustment', 25, 4)->default(0);
            $table->decimal('grand_total', 25, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->index()->constrained('sales')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->index()->constrained('payment_methods')->nullOnDelete();
            $table->decimal('amount', 25, 4)->default(0);
            $table->jsonb('details')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->index()->constrained('sales')->restrictOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->string('reference_no', 100)->nullable();
            $table->date('return_date');
            $table->decimal('total', 25, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->index()->constrained('sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->index()->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('product_id')->index()->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 25, 4)->default(0);
            $table->decimal('amount', 25, 4)->default(0);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_totals');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
