<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M28 — tres huecos reales del esquema.
 *
 * 1. LA FK `stores.country_id` -> `countries.id`. M04 declaro la columna SIN
 *    `constrained()` y su comentario decia que la restriccion se anadia en
 *    M27, pero M27 resulto ser la de `opening_balances`: la restriccion
 *    NUNCA se cerro. La spec `esquema-dominio` exige que toda relacion tenga
 *    FK real, y una FK a una tabla que ya existe se puede cerrar ya.
 *
 * 2. LOS CHECK DE COHERENCIA de `opening_balances`. La tabla tiene `kind`
 *    (`stock` | `customer_balance` | `supplier_balance` | `cash`) y cuatro FKs
 *    de entidad, pero nadie impedia que `kind = 'stock'` viniera SIN
 *    `product_id`, ni que trajera las cuatro. Un CHECK por `kind`: el `kind`
 *    exige SU FK y prohibe las otras tres.
 *
 * 3. LOS CUATRO INDICES UNICOS PARCIALES, uno por `kind`. Hoy se pueden
 *    duplicar saldos de apertura del mismo par, y un saldo duplicado es un
 *    saldo que se cuenta dos veces.
 *
 * Por que PARCIALES y no uno normal sobre las cuatro columnas: en
 * PostgreSQL un UNIQUE normal trata los NULL como DISTINTOS entre si, de
 * modo que dos filas con la misma tienda y el mismo producto y el resto a
 * NULL NO se considerarian duplicadas, y el indice no evitaria nada. Con un
 * indice PARCIAL (`WHERE kind = 'stock'`) las columnas del indice son
 * exactamente las dos que identifican el saldo, y las dos son NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. La tienda apunta a un pais que EXISTE.
        Schema::table('stores', function (Blueprint $table): void {
            $table->foreign('country_id')
                ->references('id')
                ->on('countries')
                ->nullOnDelete();
        });

        // 2. Cada `kind` exige SU FK y prohibe las otras tres.
        DB::statement(
            "ALTER TABLE opening_balances ADD CONSTRAINT opening_balances_kind_stock_coherence CHECK (kind <> 'stock' OR (product_id IS NOT NULL AND customer_id IS NULL AND supplier_id IS NULL AND bank_account_id IS NULL))"
        );
        DB::statement(
            "ALTER TABLE opening_balances ADD CONSTRAINT opening_balances_kind_customer_balance_coherence CHECK (kind <> 'customer_balance' OR (customer_id IS NOT NULL AND product_id IS NULL AND supplier_id IS NULL AND bank_account_id IS NULL))"
        );
        DB::statement(
            "ALTER TABLE opening_balances ADD CONSTRAINT opening_balances_kind_supplier_balance_coherence CHECK (kind <> 'supplier_balance' OR (supplier_id IS NOT NULL AND product_id IS NULL AND customer_id IS NULL AND bank_account_id IS NULL))"
        );
        DB::statement(
            "ALTER TABLE opening_balances ADD CONSTRAINT opening_balances_kind_cash_coherence CHECK (kind <> 'cash' OR (bank_account_id IS NOT NULL AND product_id IS NULL AND customer_id IS NULL AND supplier_id IS NULL))"
        );

        // 3. Un saldo de apertura por par (tienda, entidad) y por `kind`.
        DB::statement(
            "CREATE UNIQUE INDEX opening_balances_kind_stock_unique ON opening_balances (store_id, product_id) WHERE kind = 'stock'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX opening_balances_kind_customer_balance_unique ON opening_balances (store_id, customer_id) WHERE kind = 'customer_balance'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX opening_balances_kind_supplier_balance_unique ON opening_balances (store_id, supplier_id) WHERE kind = 'supplier_balance'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX opening_balances_kind_cash_unique ON opening_balances (store_id, bank_account_id) WHERE kind = 'cash'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS opening_balances_kind_cash_unique');
        DB::statement('DROP INDEX IF EXISTS opening_balances_kind_supplier_balance_unique');
        DB::statement('DROP INDEX IF EXISTS opening_balances_kind_customer_balance_unique');
        DB::statement('DROP INDEX IF EXISTS opening_balances_kind_stock_unique');

        DB::statement('ALTER TABLE opening_balances DROP CONSTRAINT IF EXISTS opening_balances_kind_cash_coherence');
        DB::statement('ALTER TABLE opening_balances DROP CONSTRAINT IF EXISTS opening_balances_kind_supplier_balance_coherence');
        DB::statement('ALTER TABLE opening_balances DROP CONSTRAINT IF EXISTS opening_balances_kind_customer_balance_coherence');
        DB::statement('ALTER TABLE opening_balances DROP CONSTRAINT IF EXISTS opening_balances_kind_stock_coherence');

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropForeign(['country_id']);
        });
    }
};
