<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M33 — quien ABRE la caja, no solo quien la cierra.
 *
 * `cash_registers` (M32) tiene `closed_by` pero NO tiene `opened_by`: la
 * caja se abre y se cierra, y quien las abre es tan responsable como quien
 * las cierra. Con `closed_by` y sin `opened_by`, una caja abierta no tiene
 * responsable nominal: es MEDIA CONFIANZA, y el hueco se ve porque el
 * responsable de la apertura no se puede registrar en ninguna parte.
 *
 * `opened_by` es una FK REAL a `users.id`, NULLABLE (una caja puede quedar
 * abierta sin actor conocido: entonces la columna queda en `null` y el hueco
 * se ve, en vez de inventarse un valor) y con `nullOnDelete` (borrar el
 * usuario no borra la caja: el hecho economico sigue existiendo).
 *
 * La columna NO anade una relacion con el movimiento: la asociacion del
 * movimiento con la caja sigue siendo por la CLAVE NATURAL
 * (`store_id`, `business_date`). `opened_by` y `closed_by` son los
 * RESPONSABLES de la apertura y del cierre, no un vinculo contable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->foreignId('opened_by')->nullable()->index()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->dropForeign(['opened_by']);
            $table->dropIndex(['opened_by']);
            $table->dropColumn('opened_by');
        });
    }
};
