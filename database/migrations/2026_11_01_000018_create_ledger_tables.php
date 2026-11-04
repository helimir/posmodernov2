<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M18 — el libro contable: `ledger_entry_types` + `ledger_entries` +
 * `ledger_lines`.
 *
 * El legacy esparcia la clasificacion de un movimiento en VEINTE Y UNA
 * banderas booleanas: once en `income_sources` y diez en `expense_categorys`.
 * Una bandera no se puede consultar ("¿este gasto es un gasto?": si, porque
 * el booleano existe), y dos banderas activas a la vez no significan nada.
 *
 * Aqui el movimiento contable tiene UN TIPO, y el tipo se CONSULTA en el
 * catalogo `ledger_entry_types`. La clasificacion es un dato, no una forma de
 * la tabla (design D7/D8).
 *
 * `ledger_lines` separa las CUENTAS del asiento: un movimiento se puede
 * repartir entre varias cuentas sin inventar un tipo por combinacion.
 * `bank_transaction_info`/`bank_transaction_price` del legacy se disuelven
 * aqui: `ledger_entries.amount` es el importe del asiento.
 *
 * `business_date` es la FECHA DE NEGOCIO y es un DATO que recibe el llamante:
 * el legacy filtra por `DAY()/MONTH()/YEAR()` calculados (trampa 62), que
 * parten el dia a la medianoche del servidor y no del negocio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entry_types', function (Blueprint $table) {
            $table->id();
            // El codigo es la clave del catalogo: se consulta, no se escribe
            // como literal en el asiento.
            $table->string('code', 50)->unique();
            $table->string('name', 120);
            // `is_active` es de INTERFAZ (permitir ocultar un tipo), no un
            // flag contable: el tipo del movimiento es la FK, no este bit.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            // El tipo es NOT NULL: ningun asiento se crea sin tipo.
            $table->foreignId('entry_type_id')->index()->constrained('ledger_entry_types')->restrictOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            // La fecha de negocio viaja EXPLICITA: es un dato del llamante.
            $table->date('business_date');
            // Que HECHO origina el movimiento. `reference_id` no lleva FK: el
            // hecho puede ser un gasto, una venta o un movimiento bancario.
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            // El importe del asiento: la suma de los debitos de sus lineas.
            $table->decimal('amount', 25, 4)->default(0);
            $table->timestampTz('occurred_at');
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // El corte por dia de negocio es un indice, no un `DAY()` en el
            // SQL: el filtro de caja va por este par.
            $table->index(['store_id', 'business_date'], 'ledger_entries_store_business_date_index');
        });

        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_entry_id')->index()->constrained('ledger_entries')->cascadeOnDelete();
            $table->string('account_code', 30);
            $table->decimal('debit', 25, 4)->default(0);
            $table->decimal('credit', 25, 4)->default(0);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        // Una linea es de UNA cuenta: no puede ser debito y credito a la vez,
        // no puede estar a cero en los dos, y no puede ser negativa. El
        // esquema lo garantiza, no la confianza en el codigo.
        DB::statement(
            'ALTER TABLE ledger_lines ADD CONSTRAINT ledger_lines_debit_credit_non_negative CHECK (debit >= 0 AND credit >= 0)'
        );
        DB::statement(
            'ALTER TABLE ledger_lines ADD CONSTRAINT ledger_lines_debit_credit_not_both CHECK (NOT (debit > 0 AND credit > 0))'
        );
        DB::statement(
            'ALTER TABLE ledger_lines ADD CONSTRAINT ledger_lines_debit_credit_at_least_one CHECK (debit > 0 OR credit > 0)'
        );
        DB::statement(
            'ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_non_negative CHECK (amount >= 0)'
        );

        // El catalogo BASE de tipos de asiento. Son conceptos universales:
        // no dependen del pais ni del regimen fiscal, asi que son datos, no
        // codigo.
        $now = now();

        DB::table('ledger_entry_types')->insert([
            ['code' => 'sale', 'name' => 'Venta', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'sale_payment', 'name' => 'Cobro de venta', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'expense', 'name' => 'Gasto', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'bank_movement', 'name' => 'Movimiento bancario', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'refund', 'name' => 'Devolucion', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'adjustment', 'name' => 'Ajuste', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'opening_balance', 'name' => 'Saldo de apertura', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_lines');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_entry_types');
    }
};
