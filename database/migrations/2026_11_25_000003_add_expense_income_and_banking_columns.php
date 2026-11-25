<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M58 — el modulo de gastos, ingresos y banca: lo que hace falta para que
 * los cuatro tipos de asiento que nunca se escribian se escriban.
 *
 * LO QUE TRAE, Y POR QUE CADA COSA:
 *
 *  - `expenses.status`, `cancelled_at`, `cancelled_by` y
 *    `cancellation_reason`: el gasto se ANULA, no se borra. `expenses` es la
 *    FUENTE DE VERDAD del gasto (design D19), y una fuente de verdad que se
 *    borra deja al libro contable con un movimiento que ya no tiene hecho que
 *    lo respalde. Por eso el gasto cancelado se MARCA, con su motivo y su
 *    responsable, y el asiento del ajuste lo deja todo como estaba.
 *
 *  - `expenses.payment_type` y `expenses.bank_account_id`: de donde se
 *    pago el gasto. Es lo que decide QUE cuenta se acredita en el asiento, y
 *    por eso es la regla que separa el gasto de la caja del gasto del banco:
 *    un gasto pagado en EFECTIVO mueve el saldo en curso de la caja del dia, y
 *    uno pagado desde el BANCO no lo mueve, porque el dinero nunca estuvo en
 *    el cajon.
 *
 *  - `expense_categories.account_code` e `income_sources.account_code`: la
 *    cuenta contable a la que se carga el gasto y a la que se abona el
 *    ingreso. Son DATOS, como los roles del plan: el negocio decide en que
 *    cuenta se asienta su gasto, y esa decision cambia con el tiempo.
 *
 *  - `bank_account_stores.account_code`: la cuenta contable de la cuenta
 *    bancaria EN ESA TIENDA. El saldo de una cuenta bancaria es por cuenta Y
 *    tienda (el modelo lo dice), asi que su cuenta en el libro tambien lo es:
 *    la cuenta vive en el vinculo, no en el maestro.
 *
 *  - `incomes`: la fuente de verdad del ingreso. Es la SIMETRIA de `expenses`:
 *    un gasto registrado sin movimiento bancario sigue siendo gasto, y un
 *    ingreso registrado sin movimiento bancario sigue siendo ingreso. El
 *    asiento es la consecuencia, no el hecho.
 *
 * LOS CHECK del esquema son los que el dominio NO puede prometer: un importe
 * de gasto es POSITIVO (una linea a cero no es una linea contable), un gasto
 * pagado desde el banco TIENE la cuenta que lo paga, un gasto pagado en
 * efectivo NO la tiene, y un gasto cancelado tiene MOTIVO.
 *
 * ES ADITIVA Y REVERSIBLE: solo anade columnas y una tabla, y su `down()` las
 * quita. No toca ninguna migracion anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. EL GASTO: la anulacion (marcar, no borrar) y DE DONDE se pago.
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('status', 20)->default('recorded');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            // DE DONDE se pago: la caja o una cuenta bancaria.
            $table->string('payment_type', 20)->default('cash');
            $table->foreignId('bank_account_id')->nullable()->index()->constrained('bank_accounts')->nullOnDelete();
        });

        // 2. LA CUENTA contable de la categoria de gasto y del origen de
        //    ingreso: son DATOS, no codigo.
        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->string('account_code', 30)->nullable()->index();
        });

        Schema::table('income_sources', function (Blueprint $table): void {
            $table->string('account_code', 30)->nullable()->index();
        });

        // 3. LA CUENTA contable de la cuenta bancaria EN ESA TIENDA: el saldo
        //    es por cuenta y tienda, y su cuenta en el libro tambien.
        Schema::table('bank_account_stores', function (Blueprint $table): void {
            $table->string('account_code', 30)->nullable()->index();
        });

        // 4. EL INGRESO: la fuente de verdad, la simetrica de `expenses`.
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            // El origen es opcional: el ingreso existe aunque todavia no se
            // haya clasificado (igual que el gasto).
            $table->foreignId('income_source_id')->nullable()->index()->constrained('income_sources')->nullOnDelete();
            $table->decimal('amount', 25, 4);
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->date('business_date');
            $table->timestampTz('incurred_at');
            // DE DONDE entra el dinero: la caja o una cuenta bancaria.
            $table->string('payment_type', 20)->default('cash');
            $table->foreignId('bank_account_id')->nullable()->index()->constrained('bank_accounts')->nullOnDelete();
            $table->string('status', 20)->default('recorded');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'business_date'], 'incomes_store_business_date_index');
        });

        // 5. LOS CHECK: lo que el dominio no puede prometer.
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_admitted CHECK (status IN ('recorded', 'cancelled'))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_payment_type_admitted CHECK (payment_type IN ('cash', 'bank'))");
        DB::statement(
            'ALTER TABLE expenses ADD CONSTRAINT expenses_payment_route_coherent CHECK ('
            .'(payment_type = \'bank\' AND bank_account_id IS NOT NULL) '
            .'OR (payment_type = \'cash\' AND bank_account_id IS NULL))'
        );
        DB::statement(
            'ALTER TABLE expenses ADD CONSTRAINT expenses_cancellation_reason_present CHECK ('
            ."status <> 'cancelled' OR (cancellation_reason IS NOT NULL AND cancelled_at IS NOT NULL))"
        );
        DB::statement('ALTER TABLE incomes ADD CONSTRAINT incomes_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE incomes ADD CONSTRAINT incomes_status_admitted CHECK (status IN ('recorded', 'cancelled'))");
        DB::statement("ALTER TABLE incomes ADD CONSTRAINT incomes_payment_type_admitted CHECK (payment_type IN ('cash', 'bank'))");
        DB::statement(
            'ALTER TABLE incomes ADD CONSTRAINT incomes_payment_route_coherent CHECK ('
            .'(payment_type = \'bank\' AND bank_account_id IS NOT NULL) '
            .'OR (payment_type = \'cash\' AND bank_account_id IS NULL))'
        );
        DB::statement(
            'ALTER TABLE incomes ADD CONSTRAINT incomes_cancellation_reason_present CHECK ('
            ."status <> 'cancelled' OR (cancellation_reason IS NOT NULL AND cancelled_at IS NOT NULL))"
        );
    }

    public function down(): void
    {
        // Los CHECK se van PRIMERO: son restricciones de la TABLA, no de la
        // columna, y quitar la columna no se las lleva.
        foreach ([
            'expenses_amount_positive',
            'expenses_status_admitted',
            'expenses_payment_type_admitted',
            'expenses_payment_route_coherent',
            'expenses_cancellation_reason_present',
        ] as $restriccion) {
            DB::statement("ALTER TABLE expenses DROP CONSTRAINT IF EXISTS {$restriccion}");
        }

        Schema::dropIfExists('incomes');

        Schema::table('bank_account_stores', function (Blueprint $table): void {
            $table->dropIndex(['account_code']);
            $table->dropColumn('account_code');
        });

        Schema::table('income_sources', function (Blueprint $table): void {
            $table->dropIndex(['account_code']);
            $table->dropColumn('account_code');
        });

        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->dropIndex(['account_code']);
            $table->dropColumn('account_code');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['bank_account_id']);
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn([
                'status',
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
                'payment_type',
                'bank_account_id',
            ]);
        });
    }
};
