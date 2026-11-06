<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M33 — la traza del consumo de una TRANSFERENCIA.
 *
 * `sale_item_lot_consumptions` es la traza de las VENTAS: su `sale_item_id` es
 * NOT NULL. Una transferencia NO tiene venta, asi que no puede usar esa traza:
 * el consumo de un lote que sale hacia otro almacen no queda registrado en
 * ningun sitio, y el stock movido deja de ser rastreable.
 *
 * Esta tabla es el ESPEJO de `sale_item_lot_consumptions` para las
 * transferencias: que lote salio, de que producto, de que tienda y a que
 * coste. Es lo que permite saber de que lote sale cada linea de la
 * transferencia (`transfer_items.source_lot_id`) sin ambiguidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_item_lot_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_item_id')->index()->constrained('transfer_items')->cascadeOnDelete();
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
        Schema::dropIfExists('transfer_item_lot_consumptions');
    }
};
