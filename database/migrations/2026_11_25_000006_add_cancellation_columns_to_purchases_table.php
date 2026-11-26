<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M59 — la ANULACION de la compra: se MARCA, no se borra.
 *
 * `purchases` es la FUENTE DE VERDAD de la compra, y una fuente de verdad que
 * se borra deja al libro contable con un movimiento sin hecho que lo respalde.
 * Por eso la compra anulada se MARCA, con su motivo y su responsable, como
 * el gasto (M58) y como la venta.
 *
 * Y EL ESTADO ya lo admite el esquema: `purchases.status` es
 * `('draft', 'received', 'paid', 'cancelled')`, y el estado de una compra
 * registrada es `received` (la mercaderia entra con ella).
 *
 * ES ADITIVA Y REVERSIBLE: solo anade columnas, y su `down()` las quita. No
 * toca ninguna migracion anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
        });

        // El CHECK es lo que el dominio no puede prometer: una compra anulada
        // tiene MOTIVO y tiene CUANDO.
        DB::statement(
            'ALTER TABLE purchases ADD CONSTRAINT purchases_cancellation_reason_present CHECK ('
            ."status <> 'cancelled' OR (cancellation_reason IS NOT NULL AND cancelled_at IS NOT NULL))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchases DROP CONSTRAINT IF EXISTS purchases_cancellation_reason_present');

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn([
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
            ]);
        });
    }
};
