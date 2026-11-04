<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M30 — los importes y la evidencia que el documento CONGELA.
 *
 * `design.md` §C.2 exige que el documento persista `tax_rate_snapshot`,
 * `tax_category_id`, `tax_rate_id` y `evidence_status` POR LINEA Y POR
 * CABECERA. M11 solo persistia el porcentaje congelado por linea
 * (`sale_items.tax_rate_snapshot`) y el importe del impuesto
 * (`sale_items.tax_amount`); la evidencia vivia SOLO en
 * `fiscal_document_taxes`, que es la tabla del documento fiscal (T2), no la
 * de la venta (T1). Una venta emitida sin documento fiscal se quedaba sin
 * la evidencia de su tasa.
 *
 * `sale_items.base_amount` es la BASE IMPONIBLE de la linea: sin ella, el
 * importe del impuesto no se puede Auditar contra la base sobre la que se
 * calculo.
 *
 * `sale_items.line_total` es el total REDONDEADO de la linea. Sin el, ni el
 * total de la venta ni el `rounding_adjustment` son reconstruibles: habria
 * que volver a ejecutar el motor con el perfil vigente, y el resultado
 * dependeria de un perfil que pudo cambiar despues de la venta.
 *
 * `sale_items.evidence_status` y `sale_totals.evidence_status` congelan el
 * estado de la evidencia de la tasa. ES UN DATO POR LINEA Y OTRO POR
 * CABECERA: la cabecera solo puede afirmar una evidencia si todas sus lineas
 * la comparten; si las lineas discrepan, la cabecera queda en `null` en vez
 * de afirmar una sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            // La base imponible de la linea: sobre ella se calculo el
            // impuesto.
            $table->decimal('base_amount', 25, 4)->default(0);
            // El total REDONDEADO de la linea. Es lo que hace reconstruible
            // el `rounding_adjustment` sin volver a ejecutar el motor.
            $table->decimal('line_total', 25, 4)->default(0);
            // El estado de la evidencia de la tasa, congelado por linea.
            $table->string('evidence_status', 30)->nullable();
        });

        Schema::table('sale_totals', function (Blueprint $table): void {
            // La evidencia congelada en la cabecera.
            $table->string('evidence_status', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_totals', function (Blueprint $table): void {
            $table->dropColumn('evidence_status');
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn(['base_amount', 'line_total', 'evidence_status']);
        });
    }
};
