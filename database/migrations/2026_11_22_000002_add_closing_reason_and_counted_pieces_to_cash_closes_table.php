<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M45 - el Z SELLADO guarda POR QUE se cerro asi, y COMO se conto.
 *
 * `close_reason` es el MOTIVO del descuadre: un descuadre por encima del
 * umbral no se cierra sin explicacion, y la explicacion tiene que quedar
 * junto al cierre, no en un papel. La caja (`cash_registers.note`) la
 * guarda tambien, pero el Z es el HECHO: si la caja se reabre y se vuelve a
 * cerrar, el Z ANTERIOR conserva su motivo, marcado, y el nuevo Z trae el
 * suyo.
 *
 * `counted_pieces` es el CONTEO por denominacion: las piezas que el cajero
 * conto. Es un json porque es una lista VARIABLE de filas (una por
 * denominacion que el cajero toco), y porque el criterio del Z es que su
 * contenido se CONSULTE, no que se deserialice para saber cuanto se conto.
 *
 * Y ESTAS COLUMNAS SON OPCIONALES EN LA FORMA CANONICA: un Z sellado antes
 * de esta migracion no las tiene, y su FIRMA (`content_hash`) se calcula
 * sobre una forma canonica que no las incluye. Por eso la forma canonica
 * SOLO las anade cuando tienen valor: un Z viejo sigue cuadrando, y uno nuevo
 * cubre tambien las piezas y el motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_closes', function (Blueprint $table): void {
            // El MOTIVO del descuadre: POR QUE se cerro asi.
            $table->string('close_reason', 255)->nullable()->after('difference');

            // El CONTEO por denominacion: las piezas que el cajero conto.
            $table->jsonb('counted_pieces')->nullable()->after('close_reason');
        });
    }

    public function down(): void
    {
        Schema::table('cash_closes', function (Blueprint $table): void {
            $table->dropColumn(['close_reason', 'counted_pieces']);
        });
    }
};
