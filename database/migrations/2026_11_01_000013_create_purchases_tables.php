<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M13 — compras. Renombra el legacy: ``purchase_info`` -> ``purchases``,
``purchase_item`` -> ``purchase_items``, ``purchase_price`` -> ``purchase_totals``.

``purchase_items`` NO lleva ``tax_method``: el metodo lo decide el perfil
fiscal (``fiscal_profiles.tax_inclusive_default``), no cada linea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->index()->constrained('suppliers')->nullOnDelete();
            $table->string('invoice_no', 100)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->date('purchase_date');
            $table->enum('status', ['draft', 'received', 'paid', 'cancelled'])->default('draft');
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->index()->constrained('purchases')->cascadeOnDelete();
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

        Schema::create('purchase_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->unique()->constrained('purchases')->cascadeOnDelete();
            $table->decimal('sub_total', 25, 4)->default(0);
            $table->decimal('discount', 25, 4)->default(0);
            $table->decimal('tax_total', 25, 4)->default(0);
            $table->decimal('rounding_adjustment', 25, 4)->default(0);
            $table->decimal('grand_total', 25, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->index()->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->index()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->index()->constrained('sales')->nullOnDelete();
            $table->decimal('amount', 25, 4)->default(0);
            $table->jsonb('details')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->index()->constrained('purchases')->restrictOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->string('reference_no', 100)->nullable();
            $table->date('return_date');
            $table->decimal('total', 25, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->index()->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->index()->constrained('purchase_items')->restrictOnDelete();
            $table->foreignId('product_id')->index()->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 25, 4)->default(0);
            $table->decimal('amount', 25, 4)->default(0);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_totals');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
