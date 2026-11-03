<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10 — clientes y proveedores, y las FKs que los bridges dejaban
 * diferidas.
 *
 * `customers` NO lleva `credit_balance`: duplicaba el saldo del bridge
 * (`customer_stores.balance`) y los dos se desincronizaban. El saldo vive en
 * un sitio, el bridge. Aqui el limite de credito si es un dato: es un limite,
 * no un saldo.
 *
 * `customer_country` pasa a ser FK real a `countries` y `gtin` se llama
 * `tax_id`: el identificativo fiscal no es un GTIN.
 *
 * Esta migracion cierra ademas las FKs diferidas de `customer_stores`,
 * `supplier_stores` y `bank_account_stores`. El plan NO asignaba migracion al
 * maestro `bank_accounts`: se crea aqui, que es donde su bridge lo necesita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('zip_code', 20)->nullable();
            // El pais es una FK real, no un texto.
            $table->foreignId('customer_country')->nullable()->index()->constrained('countries')->nullOnDelete();
            $table->string('customer_state', 100)->nullable();
            // El identificativo fiscal: RIF, RUC o NIT segun el pais. No es un
            // GTIN.
            $table->string('tax_id', 50)->nullable();
            // Un LIMITE, no un saldo.
            $table->decimal('credit_limit', 25, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->foreignId('country_id')->nullable()->index()->constrained('countries')->nullOnDelete();
            $table->string('state', 100)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // El maestro de cuentas bancarias: el plan no le asignaba migracion y
        // su bridge lo necesitaba.
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->foreignId('account_type_id')->nullable()->index();
            $table->string('number', 50)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Las FKs que M09 dejo diferidas, ahora que los maestros existen.
        Schema::table('customer_stores', function (Blueprint $table): void {
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        Schema::table('supplier_stores', function (Blueprint $table): void {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
        });

        Schema::table('bank_account_stores', function (Blueprint $table): void {
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_account_stores', function (Blueprint $table): void {
            $table->dropForeign(['bank_account_id']);
        });
        Schema::table('supplier_stores', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
        });
        Schema::table('customer_stores', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
        });

        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
    }
};
