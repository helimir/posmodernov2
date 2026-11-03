<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M16 — prestamos.
 *
 * `loan_from` es una FK a `users.id`, no texto libre: el legacy guardaba ahi
 * un nombre que no se podia relacionar con nadie.
 *
 * Y `payable` NO es una columna: se calcula en el servidor a partir del
 * principal y del interes. Almacenarlo era duplicar un importe derivado, y
 * se desincronizaba del interes que lo produce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->foreignId('customer_id')->index()->constrained('customers')->restrictOnDelete();
            // Quien concede el prestamo: una persona, no un texto.
            $table->foreignId('loan_from')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('reference_no', 100)->nullable();
            $table->decimal('principal', 25, 4)->default(0);
            $table->decimal('interest_percentage', 25, 4)->default(0);
            $table->unsignedSmallInteger('installment_count')->default(1);
            $table->date('date');
            $table->enum('status', ['draft', 'active', 'paid', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->index()->constrained('loans')->cascadeOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->decimal('amount', 25, 4)->default(0);
            $table->date('date');
            $table->string('reference_no', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('loans');
    }
};
