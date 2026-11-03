<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M27 — los saldos de apertura.
 *
 * El proyecto es VERDE (mapa funcional §10.3): no hay migracion de datos
 * historicos, asi que los saldos de apertura (stock por tienda y producto,
 * saldo y deuda por cliente y proveedor, saldo de caja por cuenta y tienda)
 * se CARGAN A MANO. Esta tabla es donde viven, y por eso lleva la
 * CERTIFICACION: `certified_at` y `certified_by`.
 *
 * La certificacion no es un adorno: `ops:check-opening-balances` (0.7.6)
 * falla mientras haya saldos sin certificar. Nadie los certifica solo:
 * los certifica una persona de direccion financiera, con fecha y responsable
 * nominal, porque un saldo contable no lo aprueba un script.
 *
 * El plan no asignaba migracion a esta tabla: se crea aqui, y los indices y
 * constraints de M27 pasan a ser M28.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();

            // De que saldo se trata: stock, credito de cliente, deuda de
            // proveedor o caja.
            $table->enum('kind', ['stock', 'customer_balance', 'supplier_balance', 'cash']);

            // A que entidad se aplica el saldo. Segun el `kind`, unos applies
            // y otros no.
            $table->foreignId('product_id')->nullable()->index()->constrained('products')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->index()->constrained('customers')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->index()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->index()->constrained('bank_accounts')->restrictOnDelete();

            $table->decimal('amount', 25, 4)->default(0);
            $table->date('as_of');

            // LA CERTIFICACION. Sin `certified_at` y `certified_by`, el saldo
            // no vale: el comando de arranque lo rechaza.
            $table->timestampTz('certified_at')->nullable();
            $table->foreignId('certified_by')->nullable()->index()->constrained('users')->nullOnDelete();

            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
