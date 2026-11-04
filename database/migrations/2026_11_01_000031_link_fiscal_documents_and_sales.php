<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M31 — los enlaces que quedaron abiertos, y la traza de la reserva.
 *
 * Esta migracion cierra CUATRO huecos reales del esquema. Ninguno es
 * cosmetico: son enlaces de los que depende el reintento de la emision
 * fiscal (0.6.6).
 *
 * 1. `sales.fiscal_document_id` -> `fiscal_documents.id`.
 *    M11 declaro la columna SIN `constrained()` y apuntando a una migracion
 *    futura ("la cierra M27") para no crear un ciclo entre M11 y M22. M27
 *    resulto ser la de `opening_balances` y el enlace NUNCA se cerro: la
 *    venta y su comprobante fiscal no estaban garantizados por la base de
 *    datos, solo por el codigo. Ahora la FK los ata. Es NULLABLE porque una
 *    venta recien cobrada todavia no tiene comprobante: T1 lo deja en `null`
 *    y T2 lo cierra.
 *
 * 2. `fiscal_documents.customer_id` -> `customers.id`.
 *    M22 hizo lo mismo que M11: difirio la FK "a M27" y M27 no la cerro. El
 *    receptor del comprobante es un dato del documento, y su integridad la
 *    vigila la base de datos. `nullOnDelete`: un cliente borrado deja el
 *    comprobante sin receptor, pero NO borra el comprobante ya emitido.
 *
 * 3. `fiscal_document_reservations.reference_type` / `reference_id`.
 *    Son las columnas que ENLAZAN la reserva con el hecho que la origino
 *    (la venta). Sin ellas, el reintento de la emision no puede encontrar la
 *    reserva que ya tiene: tendria que reservar un numero NUEVO y quemarlo.
 *    El par es POLIMORFICO y NO lleva FK, exactamente como
 *    `ledger_entries.reference_type` / `reference_id`: el hecho puede ser una
 *    venta, una compra o una nota. El indice sobre el par es lo que hace
 *    que esa busqueda sea la del camino caliente.
 *
 * 4. `fiscal_document_taxes.line_id` NO lleva FK, y NO es un olvido.
 *    La linea del impuesto es POLIMORFICA: apunta a `sale_items` en una
 *    venta o a `purchase_items` en una compra, segun el `document_type` del
 *    documento. Una clave foranea no puede apuntar a dos tablas, asi que
 *    cualquier FK que se pusiera aqui seria incorrecta para la mitad de los
 *    documentos. La columna lleva un comentario en el catalogo de PostgreSQL
 *    para que su ausencia de FK se lea como una DECISION y no como un
 *    olvido.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. La venta y su comprobante fiscal: el enlace que M11 difirio.
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreign('fiscal_document_id')
                ->references('id')
                ->on('fiscal_documents')
                ->nullOnDelete();
        });

        // 2. El receptor del comprobante: el enlace que M22 difirio.
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });

        // 3. La reserva sabe a que hecho pertenece. Sin este par, el reintento
        //    de la emision no encuentra la reserva y quema un numero nuevo.
        Schema::table('fiscal_document_reservations', function (Blueprint $table): void {
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->index(['reference_type', 'reference_id']);
        });

        // 4. `fiscal_document_taxes.line_id` NO lleva FK: es polimorfico
        //    (una linea de venta o una linea de compra). Se DECLARA en la
        //    columna para que la ausencia no se lea como un olvido.
        DB::statement(<<<'SQL'
            COMMENT ON COLUMN fiscal_document_taxes.line_id IS
            'Linea del impuesto. POLIMORFICA a proposito: apunta a sale_items en una venta o a purchase_items en una compra, segun el document_type. Por eso NO lleva clave foranea: una FK no puede apuntar a dos tablas.'
        SQL);
    }

    public function down(): void
    {
        DB::statement('COMMENT ON COLUMN fiscal_document_taxes.line_id IS NULL');

        Schema::table('fiscal_document_reservations', function (Blueprint $table): void {
            $table->dropIndex(['reference_type', 'reference_id']);
            $table->dropColumn(['reference_type', 'reference_id']);
        });

        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign(['fiscal_document_id']);
        });
    }
};
