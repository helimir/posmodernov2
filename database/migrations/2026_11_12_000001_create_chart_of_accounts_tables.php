<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M41 — el plan de cuentas por tienda: `accounts` + `store_accounts`.
 *
 * Un plan de cuentas es un DATO DEL NEGOCIO, no una configuracion del
 * despliegue: el negocio decide que cuenta es su caja, cual su cartera y cual
 * su ingreso, y esa decision cambia con el tiempo y es distinta en cada
 * tienda. Por eso vive en la base de datos y no en `config/pos.php`.
 *
 * El PATRON es el maestro/bridge del repositorio: `accounts` guarda la
 * IDENTIDAD de la cuenta (su codigo, que es su clave natural, su nombre y su
 * tipo contable), y `store_accounts` guarda el ROL que la cuenta cumple en
 * cada tienda.
 *
 * `accounts.type` y `store_accounts.role` son ENUMES DE POSTGRES: los dos
 * conjuntos son cerrados, y la base hace cumplir el conjunto en lugar de una
 * lista de codigos repetida en el codigo. Los valores son los del dominio
 * (`AccountType` y `AccountRole`), y no se anade ninguno mas sin justificarlo.
 *
 * LAS DOS UNICIDADES del bridge son la regla del plan:
 *
 *  - `UNIQUE (store_id, role)`: una tienda tiene UNA cuenta por rol. Un rol
 *    que cupsieran dos cuentas es un plan que no sabe cual manda;
 *  - `UNIQUE (store_id, account_id)`: una cuenta cumple UN rol en la tienda.
 *    Una cuenta que cumpliera dos roles es una cuenta con dos terminos.
 *
 * `store_accounts.status` y `sort_order` son del vinculo, como en los demas
 * bridges: el rol de una cuenta en una tienda es de ESA tienda.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Los DOS ENUMES DE POSTGRES: el tipo contable de la cuenta y el rol
        // que cumple en la tienda.
        //
        // El `DROP TYPE IF EXISTS` va primero porque `migrate:fresh` borra las
        // TABLAS pero no los TIPOS: sin el, la segunda instalacion fallaria
        // con "type account_type already exists".
        DB::statement('DROP TYPE IF EXISTS account_role');
        DB::statement('DROP TYPE IF EXISTS account_type');

        DB::statement(
            "CREATE TYPE account_type AS ENUM ('asset', 'liability', 'equity', 'income', 'expense')"
        );

        DB::statement(
            "CREATE TYPE account_role AS ENUM ('cash', 'receivable', 'revenue', 'tax_payable')"
        );

        // El CATALOGO de cuentas: la identidad de la cuenta. El codigo es su
        // clave natural y es UNIQUE.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            // El TIPO CONTABLE va en su propia sentencia: es un enum de
            // Postgres, no un varchar con un CHECK.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE accounts ADD COLUMN type account_type NOT NULL');

        // El PLAN de la tienda: el vinculo entre la cuenta y la tienda, con el
        // ROL que la cuenta cumple en ella.
        Schema::create('store_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->index()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->cascadeOnDelete();
            // El ROL va en su propia sentencia: es un enum de Postgres.
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE store_accounts ADD COLUMN role account_role NOT NULL');

        // Las DOS unicidades del plan: un rol por tienda, y una cuenta por
        // rol en la tienda.
        DB::statement('ALTER TABLE store_accounts ADD CONSTRAINT store_accounts_store_role_unique UNIQUE (store_id, role)');
        DB::statement('ALTER TABLE store_accounts ADD CONSTRAINT store_accounts_store_account_unique UNIQUE (store_id, account_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('store_accounts');
        Schema::dropIfExists('accounts');

        // Los TIPOS tambien se van: un enum que sobrevive a su tabla es un
        // tipo que nadie puede volver a crear.
        DB::statement('DROP TYPE IF EXISTS account_role');
        DB::statement('DROP TYPE IF EXISTS account_type');
    }
};
