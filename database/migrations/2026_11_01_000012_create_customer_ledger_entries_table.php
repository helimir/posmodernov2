<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12 — el extracto del cliente. Renombra el legacy
``customer_transactions`` -> ``customer_ledger_entries``.

``type`` tiene valores CANONICOS: el legacy mezclaba el tipo del legacy
con el signo, y el signo va en ``amount``.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->index()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->enum('type', ['sale', 'payment', 'return', 'adjustment']);
            $table->string('reference_no', 100)->nullable();
            $table->foreignId('ref_invoice_id')->nullable()->index()->constrained('sales')->nullOnDelete();
            $table->decimal('amount', 25, 4)->default(0);
            $table->decimal('balance_after', 25, 4)->nullable();
            $table->date('entry_date');
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledger_entries');
    }
};
