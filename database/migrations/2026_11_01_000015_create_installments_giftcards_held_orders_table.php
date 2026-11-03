<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M15 — cuotas, tarjetas de regalo y pedidos en espera.
 *
 * `installment_plans.interest_percentage` es `numeric(25,4)`: el legacy lo
 * guardaba como `int`, y un porcentaje entero no puede representar un 2,5 %.
 *
 * `gift_cards.created_by` es FK a `users.id`: una tarjeta la crea una persona
 * del equipo, no el sistema.
 *
 * `holding_*` pasa a `held_orders` + `_items` + `_totals`: el pedido en
 * espera del POS NO es un pedido comercial. Era la misma tabla con un
 * `is_order` que la hacia depender de la tabla de cotizaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->foreignId('customer_id')->index()->constrained('customers')->restrictOnDelete();
            $table->string('reference_no', 100)->nullable();
            $table->decimal('principal', 25, 4)->default(0);
            $table->decimal('interest_percentage', 25, 4)->default(0);
            $table->unsignedSmallInteger('installment_count')->default(1);
            $table->date('date');
            $table->enum('status', ['draft', 'active', 'paid', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('installment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_plan_id')->index()->constrained('installment_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->decimal('amount', 25, 4)->default(0);
            $table->decimal('paid_amount', 25, 4)->default(0);
            $table->enum('status', ['pending', 'paid', 'overdue'])->default('pending');
            $table->timestamps();
            $table->unique(['installment_plan_id', 'number']);
        });

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->string('card_no', 50)->unique();
            $table->foreignId('customer_id')->nullable()->index()->constrained('customers')->nullOnDelete();
            $table->decimal('value', 25, 4)->default(0);
            $table->decimal('balance', 25, 4)->default(0);
            // Quien creo la tarjeta: una persona del equipo.
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->date('expires_at')->nullable();
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->timestamps();
        });

        Schema::create('gift_card_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_card_id')->index()->constrained('gift_cards')->cascadeOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->decimal('amount', 25, 4)->default(0);
            $table->date('date');
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('held_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->index()->constrained('customers')->nullOnDelete();
            $table->string('reference_no', 100)->nullable();
            $table->date('date');
            $table->enum('status', ['held', 'resumed', 'cancelled'])->default('held');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('held_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('held_order_id')->index()->constrained('held_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 25, 4)->default(0);
            $table->decimal('unit_price', 25, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('held_order_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('held_order_id')->unique()->constrained('held_orders')->cascadeOnDelete();
            $table->decimal('sub_total', 25, 4)->default(0);
            $table->decimal('discount', 25, 4)->default(0);
            $table->decimal('tax_total', 25, 4)->default(0);
            $table->decimal('grand_total', 25, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_order_totals');
        Schema::dropIfExists('held_order_items');
        Schema::dropIfExists('held_orders');
        Schema::dropIfExists('gift_card_topups');
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('installment_schedules');
        Schema::dropIfExists('installment_plans');
    }
};
