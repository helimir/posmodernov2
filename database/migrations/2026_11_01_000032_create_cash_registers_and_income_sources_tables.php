<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M32 — la caja por fecha de negocio y el catalogo de ingresos.
 *
 * `cash_registers` — la apertura y el cierre de caja por TIENDA y por FECHA
 * DE NEGOCIO.
 *
 * `opening_balance` es el saldo de PARTIDA: un DATO congelado, el que se
 * carga a mano antes de arrancar (B1). El saldo EN CURNO se DERIVA de los
 * asientos (`ledger_entries` de esa `business_date`), nunca de un contador
 * almacenado. `closing_balance` es lo que se OBSERVO al cerrar: la foto del
 * momento del cierre, no un saldo derivado. Por eso los dos son datos y el
 * saldo de verdad no esta en ningun sitio: esta en los asientos.
 *
 * `business_date` es un DATO que recibe el llamante. El legacy filtraba por
 * `DAY()/MONTH()/YEAR()` calculados (trampa 62), que parten el dia a la
 * medianoche del servidor y no del negocio: una operacion de las 23:50
 * caia en el dia que le tocaba, no en el dia que fue.
 *
 * Una tienda tiene UN registro de caja por fecha de negocio: es lo que hace
 * que "el cierre de caja del dia" sea un hecho y no una suma.
 *
 * `income_sources` — el CATALOGO de origenes de ingreso. Las ONCE banderas
 * booleanas del legacy (`for_sell`, `for_purchase_return`, `for_due_collection`,
 * `for_loan`, `for_giftcard_sell`, `for_topup`, `for_stock_transfer`,
 * `for_purchase_delete`, `for_expense_delete`, `profitable` y
 * `show_in_income`) desaparecen: un ingreso no se puede consultar ("¿este
 * ingreso es de venta?": si, porque el booleano existe) y dos banderas
 * activas a la vez no significan nada.
 *
 * El "tipo de movimiento" del legacy (`credit` / `debit`) NO se guarda aqui:
 * se consulta en el catalogo `ledger_entry_types`, que es el UNICO sitio
 * donde se clasifica un movimiento. El ingreso se enlaza a su asiento por
 * `ledger_entries.reference_type` / `reference_id`.
 *
 * `is_active` es de INTERFAZ (permitir ocultar un origen del catalogo), NO un
 * flag contable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();

            // La FECHA DE NEGOCIO: un dato del llamante, nunca un `DAY()`
            // calculado.
            $table->date('business_date');

            // La cuenta que sostiene la caja.
            $table->foreignId('bank_account_id')->nullable()->index()->constrained('bank_accounts')->nullOnDelete();

            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();

            // El saldo de PARTIDA (congelado) y el saldo OBSERVADO al cerrar.
            $table->decimal('opening_balance', 25, 4)->default(0);
            $table->decimal('closing_balance', 25, 4)->nullable();

            $table->enum('status', ['open', 'closed'])->default('open');

            // Quien cerro la caja.
            $table->foreignId('closed_by')->nullable()->index()->constrained('users')->nullOnDelete();

            $table->string('note', 255)->nullable();
            $table->timestamps();

            // Una tienda, un registro de caja, una fecha de negocio.
            $table->unique(['store_id', 'business_date'], 'cash_registers_store_business_date_unique');
        });

        Schema::create('income_sources', function (Blueprint $table) {
            $table->id();

            // El codigo es la clave del catalogo.
            $table->string('code', 50)->unique();
            $table->string('name', 120);

            // El arbol de origenes: un origen puede colgar de otro.
            $table->foreignId('parent_id')->nullable()->index()->constrained('income_sources')->nullOnDelete();

            // De INTERFAZ, no contable: el tipo del movimiento contable lo
            // decide `ledger_entry_types`.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_sources');
        Schema::dropIfExists('cash_registers');
    }
};
