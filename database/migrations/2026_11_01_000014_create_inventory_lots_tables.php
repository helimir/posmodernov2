<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M14 — el stock por lotes.
 *
 * `inventory_lots` NO tiene columna `status`. El legacy codificaba la
 * disponibilidad con un enum (`stock` / `active` / `sold`) MAS dos contadores
 * que hay que mantener sincronizados, y su `UPDATE` de consumo no filtraba por
 * el id del lote: tocaba TODOS los lotes activos del producto (defecto H1 del
 * mapa funcional, ver ADR-0004).
 *
 * Aqui la disponibilidad es ARITMETICA: `available` es una columna GENERATED,
 * `quantity_received - quantity_consumed + quantity_returned`. No hay estado
 * que pueda quedar desincronizado, y el consumo filtra por el id del lote.
 *
 * `sale_item_lot_consumptions` es la TRAZA de que lote salio cada linea de
 * venta: sin ella, una devolucion no sabria a que lote reponer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->index()->constrained('products')->restrictOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            // 1:1 con la linea de compra que abrio el lote.
            $table->foreignId('purchase_item_id')->unique()->constrained('purchase_items')->cascadeOnDelete();
            $table->decimal('quantity_received', 25, 4)->default(0);
            $table->decimal('quantity_consumed', 25, 4)->default(0);
            $table->decimal('quantity_returned', 25, 4)->default(0);
            $table->decimal('unit_cost', 25, 4)->default(0);
            $table->timestampTz('received_at');
            $table->timestamps();

            // El FIFO ordena por antiguedad y luego por id: el desempate es
            // determinista.
            $table->index(['product_id', 'store_id', 'received_at', 'id'], 'inventory_lots_fifo_index');
        });

        // La disponibilidad es ARITMETICA, no un estado: la columna es
        // GENERATED, asi que nadie la puede escribir a mano ni dejarla
        // desincronizada de los tres contadores.
        DB::statement(
            'ALTER TABLE inventory_lots ADD COLUMN available numeric(25,4)'
            .' GENERATED ALWAYS AS (quantity_received - quantity_consumed + quantity_returned) STORED'
        );

        Schema::create('sale_item_lot_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->index()->constrained('sale_items')->cascadeOnDelete();
            $table->foreignId('lot_id')->index()->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignId('product_id')->index()->constrained('products')->restrictOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->decimal('quantity', 25, 4)->default(0);
            $table->decimal('unit_cost', 25, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_lot_consumptions');
        Schema::dropIfExists('inventory_lots');
    }
};
