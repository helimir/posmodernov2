<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M49 — la SOLICITUD de anulacion en la cabecera de la venta.
 *
 * `sales.void_requested_at`, `sales.void_request_reason` y
 * `sales.void_requested_by` son la peticion de anulacion que hace el cajero
 * para que quien puede anular deshaga la venta.
 *
 * POR QUE EN LA VENTA Y NO EN UNA TABLA PROPIA: *una venta tiene UNA
 * solicitud, y la solicitud es un dato de ESA venta*. No hay un tramite con
 * dos pasos (no existe un estado "aprobada"): la solicitud esta PENDIENTE
 * mientras la venta sigue en pie, y esta HECHA cuando la venta queda
 * anulada. Sus tres datos son de la venta (cuando se pidio, por que y quien
 * la pidio), asi que viven en su cabecera: una tabla aparte seria una
 * segunda fuente de verdad sobre la vida de la misma venta, y una segunda
 * que se puede desincronizar de ella.
 *
 * Y LA COLUMNA ES NULLABLE: una venta corriente no tiene solicitud, y el
 * hueco se ve (no se inventa un valor).
 *
 * `void_requested_by` es `nullOnDelete`: si el usuario desaparece, la
 * solicitud se queda (que alguien pidio la anulacion es un hecho) y la
 * auditoria conserva el rastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            // CUANDO se pidio la anulacion.
            $table->timestampTz('void_requested_at')->nullable();

            // POR QUE se pidio: el motivo lo escribe quien pide, y es el
            // motivo que lleva la anulacion.
            $table->text('void_request_reason')->nullable();

            // QUIEN la pidio.
            $table->foreignId('void_requested_by')
                ->nullable()
                ->index()
                ->constrained('users')
                ->nullOnDelete();
        });

        // El CAMINO DE ACCESO de la lista de solicitudes pendientes: es una
        // consulta por tienda, y el indice lo pone el ESQUEMA.
        Schema::table('sales', function (Blueprint $table): void {
            $table->index(
                ['store_id', 'void_requested_at'],
                'sales_store_id_void_requested_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex('sales_store_id_void_requested_at_index');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign(['void_requested_by']);
            $table->dropColumn(['void_requested_at', 'void_request_reason', 'void_requested_by']);
        });
    }
};
