<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M48 — el DOCUMENTO del CONSUMIDOR FINAL en la cabecera de la venta.
 *
 * Cuando la venta NO tiene cliente, el receptor del comprobante fiscal es el
 * CONSUMIDOR FINAL. Su NOMBRE es un token del perfil fiscal
 * (`final_consumer_name`), pero su IDENTIFICATIVO (la cedula o el pasaporte)
 * lo DECLARA quien cobra: el sistema no lo deduce del formato, porque deducir
 * seria adivinar.
 *
 * Y EL DATO TIENE QUE VIVIR EN LA VENTA: el comprobante fiscal se emite
 * DESPUES (T2), y hasta la parte que lo emite tiene que saber quien es el
 * receptor. Sin estas dos columnas, el documento del consumidor final se
 * perderia entre el cobro y la emision, y el reintento de la emision
 * (que puede ser de otro dia) ya no tendria con que escribir el receptor.
 *
 * `final_consumer_document_kind` es el TIPO que declara el llamante. Los
 * tipos admitidos son los del ESQUEMA (la restriccion de
 * `fiscal_document_parties.fiscal_id_kind`), no una lista escrita aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            // El IDENTIFICATIVO del consumidor final: la cedula o el pasaporte.
            $table->string('final_consumer_document', 50)->nullable()->after('status');

            // El TIPO de ese identificativo, tal como lo declara el llamante.
            $table->string('final_consumer_document_kind', 20)->nullable()->after('final_consumer_document');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['final_consumer_document', 'final_consumer_document_kind']);
        });
    }
};
