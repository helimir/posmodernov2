<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M42 — el rol 'equity' del plan de cuentas.
 *
 * El SALDO DE PARTIDA de una caja es un hecho contable: el dinero que hay en
 * el cajon al empezar. Si solo vive en `cash_registers.opening_balance` es un
 * dato suelto, y la cuenta de efectivo del LIBRO no cuadra con la CAJA. Al
 * abrir la caja se asienta, y un asiento tiene DOS lados: el cargo a la cuenta
 * de efectivo y el ABONO a la cuenta de capital.
 *
 * La contrapartida es un ROL DEL PLAN, no un codigo escrito en el codigo: el
 * plan de cuentas de una tienda es un dato del negocio (design D7/D8), y el
 * capital de cada tienda puede ser una cuenta distinta. Por eso el rol
 * 'equity' entra en el enum de Postgres de los roles: el mismo conjunto
 * cerrado que ya usan 'cash', 'receivable', 'revenue' y 'tax_payable'.
 *
 * Y ES UN ROL MAS DEL PLAN COMPLETO: sin el, el saldo de partida no tiene
 * donde abonarse, y una caja sin cuenta donde asentar no es una caja.
 *
 * El `down()` no quita el valor: un valor de un enum de PostgreSQL no se
 * puede quitar. El rol se deja de usar, y el tipo lo conserva.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El `IF NOT EXISTS` hace la migracion idempotente: volver a correrla
        // sobre una base que ya lo tiene no falla.
        DB::statement("ALTER TYPE account_role ADD VALUE IF NOT EXISTS 'equity'");
    }

    public function down(): void
    {
        // Un enum de PostgreSQL no pierde valores: la operacion seria
        // recrear el tipo, y eso se lleva por delante los datos.
    }
};
