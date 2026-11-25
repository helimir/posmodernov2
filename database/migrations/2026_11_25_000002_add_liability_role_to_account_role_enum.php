<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M57 — el rol 'liability' del plan de cuentas.
 *
 * EL PASIVO es lo que la tienda DEBE, y hasta ahora el plan de cuentas no
 * tenia donde asentar lo que debe: el credito a favor del cliente (la reversa
 * de una venta anulada deja al cliente con un saldo a favor, y ese saldo es una
 * deuda de la tienda) y las deudas a proveedores no tenian cuenta.
 *
 * LA CONTRAPARTIDA es un ROL DEL PLAN, no un codigo escrito en el codigo: el
 * plan de cuentas de una tienda es un dato del negocio (design D7/D8), y el
 * pasivo de cada tienda puede ser una cuenta distinta. Por eso el rol
 * 'liability' entra en el enum de Postgres de los roles: el mismo conjunto
 * cerrado que ya usan 'cash', 'receivable', 'revenue', 'tax_payable' y
 * 'equity'.
 *
 * Y EL ROL ES OBLIGATORIO DEL PLAN COMPLETO (y no del COBRO): el plan
 * completo son CINCO roles, y el pasivo es el que declara lo que la tienda
 * DEBE. Su ausencia NO impide cobrar (la decision de la F2-13b: ninguna
 * tienda deja de cobrar), y lo que impide es deshacer una venta cobrada por
 * error. Lo que no se puede es ASIGNARLO a una cuenta que no sea de pasivo:
 * esa invariante la cumple `ChartOfAccounts::assign`, y el enum es la parte
 * del esquema que la hace posible (un rol que no existe en el conjunto
 * cerrado no se puede ni escribir).
 *
 * EL `down()` QUITA el valor, y para eso RECREA el tipo: en PostgreSQL un
 * valor de un enum no se puede quitar (`ALTER TYPE ... DROP VALUE` no existe
 * antes de la version 12 y no se puede ejecutar dentro de un bloque de
 * transaccion), asi que el tipo se renombra, se crea uno nuevo sin el pasivo,
 * la columna se pasa al nuevo y el viejo se tira. Los vinculos que usan el rol
 * se borran antes: un vinculo con un rol que el conjunto cerrado ya no
 * declara es un vinculo colgado, y el plan de cuentas los vuelve a sembrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El `IF NOT EXISTS` hace la migracion idempotente: volver a correrla
        // sobre una base que ya lo tiene no falla.
        DB::statement("ALTER TYPE account_role ADD VALUE IF NOT EXISTS 'liability'");
    }

    public function down(): void
    {
        // Los VINCULOS que usan el rol se van primero: sin ellos, el cambio de
        // tipo de la columna no tendria nada que convertir.
        DB::statement("DELETE FROM store_accounts WHERE role = 'liability'");

        // El tipo viejo se aparta con su nombre.
        DB::statement('ALTER TYPE account_role RENAME TO account_role_old');

        // Y el tipo nuevo se crea SIN el pasivo: el conjunto cerrado vuelve a
        // ser el de antes.
        DB::statement(
            "CREATE TYPE account_role AS ENUM ('cash', 'receivable', 'revenue', 'tax_payable', 'equity')"
        );

        // La columna pasa al tipo nuevo. El `USING` es la conversion: el texto
        // del rol se castea al enum nuevo.
        DB::statement(
            'ALTER TABLE store_accounts ALTER COLUMN role TYPE account_role USING role::text::account_role'
        );

        // Y el tipo viejo, ya sin usar, se va.
        DB::statement('DROP TYPE account_role_old');
    }
};
