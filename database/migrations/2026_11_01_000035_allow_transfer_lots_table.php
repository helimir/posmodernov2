<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M35 — el lote de una transferencia no viene de una compra.
 *
 * `inventory_lots.purchase_item_id` era NOT NULL y UNIQUE (1:1 con la linea de
 * compra que abrio el lote). Un lote que llega de una transferencia no lo
 * abre ninguna compra: no hay linea de compra que lo origine, y ademas el M29
 * deja la creacion del lote de DESTINO para la RECEPCION, no para el
 * despacho. Con la columna NOT NULL, el lote de destino no se podria crear.
 *
 * Por eso la columna pasa a ser NULLABLE: un lote puede venir de una compra
 * (con su linea) o de una transferencia (sin ella). El UNIQUE se mantiene:
 * en PostgreSQL los NULL no chocan entre si, asi que puede haber muchos
 * lotes de transferencia y sigue habiendo como mucho un lote por linea de
 * compra.
 *
 * `source_lot_id` es la TRAZABILIDAD: el lote de destino sabe de que lote de
 * SALIDA vino. Sin el, el destino solo sabria "que hay stock", pero no "de
 * donde salio", y una devolucion de la transferencia no podria reponer el
 * lote exacto (la misma regla que fija la devolucion de la venta, ADR-0004).
 */
return new class extends Migration
{
    public function up(): void
    {
        // La compra deja de ser la unica ORIGEN del lote. Se altera la
        // nulabilidad con SQL directo: `change()` volveria a declarar la FK y
        // chocaria con la que ya existe.
        DB::statement('ALTER TABLE inventory_lots ALTER COLUMN purchase_item_id DROP NOT NULL');

        Schema::table('inventory_lots', function (Blueprint $table) {
            // De que lote salio este lote. `RESTRICT`: no se borra un lote que
            // tiene un lote derivado.
            $table->foreignId('source_lot_id')->nullable()->index()->constrained('inventory_lots')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Volver a hacer `purchase_item_id` NOT NULL solo es posible si NO hay
        // lotes de transferencia: son los que no tienen linea de compra. Si los
        // hay, la vuelta se detiene con un error claro en vez de perder stock.
        $lotes = DB::table('inventory_lots')->whereNull('purchase_item_id')->count();

        if ($lotes > 0) {
            throw new RuntimeException(
                "Hay {$lotes} lotes sin linea de compra (lotes de transferencia): "
                .'no se puede volver a hacer `purchase_item_id` NOT NULL sin perderlos.'
            );
        }

        Schema::table('inventory_lots', function (Blueprint $table) {
            $table->dropForeign(['source_lot_id']);
            $table->dropIndex(['source_lot_id']);
            $table->dropColumn('source_lot_id');
        });

        DB::statement('ALTER TABLE inventory_lots ALTER COLUMN purchase_item_id SET NOT NULL');
    }
};
