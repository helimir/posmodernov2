<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M19 — el gasto: `expense_categories` + `expenses`.
 *
 * `expenses` es la FUENTE DE VERDAD del gasto: el gasto NACE aqui y el
 * asiento contable se deriva de el. El legacy hacia al reves (el movimiento
 * bancario creaba el gasto), y por eso un gasto registrado sin movimiento
 * bancario se perdia y un movimiento bancario sin gasto inventaba uno
 * (design D19).
 *
 * `expense_categories` NO lleva los diez flags contables del legacy
 * (`expense_categorys`): la clasificacion contable vive en
 * `ledger_entry_types` (M18) y se CONSULTA. Aqui solo queda `is_active`,
 * que es de INTERFAZ (permitir ocultar una categoria), no un flag contable.
 *
 * `business_date` es la FECHA DE NEGOCIO y es un DATO que recibe el llamante:
 * el legacy filtra por `DAY()/MONTH()/YEAR()` calculados (trampa 62).
 *
 * NOTA: `cash_registers` e `income_sources` (y `opening_balances`, que ya
 * creo M27) NO se crean aqui: las deliver sus propias work units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            // De interfaz, no contable: el tipo del movimiento contable lo
            // decide `ledger_entry_types`.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            // La categoria es opcional: el gasto existe aunque todavia no se
            // haya clasificado.
            $table->foreignId('expense_category_id')->nullable()->index()->constrained('expense_categories')->nullOnDelete();
            $table->decimal('amount', 25, 4);
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            // La fecha de negocio viaja EXPLICITA: es un dato del llamante.
            $table->date('business_date');
            $table->timestampTz('incurred_at');
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // El corte por dia de negocio es un indice, no un `DAY()` en el
            // SQL: el filtro de caja va por este par.
            $table->index(['store_id', 'business_date'], 'expenses_store_business_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
