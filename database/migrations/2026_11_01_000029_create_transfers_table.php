<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M29 — las transferencias entre almacenes.
 *
 * El legacy generaba una cabecera de compra POR CADA ARTICULO de la
 * transferencia, de modo que el listado de compras se multiplicaba (P0 #17 del
 * mapa funcional). Aqui hay **una sola** cabecera y N lineas.
 *
 * `transfer_items.source_lot_id` apunta al lote de SALIDA: el stock sale de
 * un lote concreto, no de "el mas antiguo disponible" como hacia el legacy, y
 * el lote de destino lo crea el proceso de recepcion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->foreignId('destination_store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->string('reference_no', 100)->nullable();
            $table->date('transfer_date');
            $table->enum('status', ['draft', 'in_transit', 'received', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->index()->constrained('transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained('products')->restrictOnDelete();
            // El lote de SALIDA: el stock sale de un lote concreto.
            $table->foreignId('source_lot_id')->nullable()->index()->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 25, 4)->default(0);
            $table->decimal('unit_cost', 25, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('transfers');
    }
};
