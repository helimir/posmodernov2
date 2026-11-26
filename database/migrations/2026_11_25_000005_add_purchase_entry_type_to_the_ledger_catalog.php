<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M45 — el tipo de asiento `purchase`: la COMPRA en el catalogo del libro.
 *
 * POR QUE UN TIPO NUEVO:
 *
 * El catalogo (`ledger_entry_types`) es el conjunto CERRADO de los tipos de
 * asiento, y el tipo lo CONSULTA el `LedgerWriter`: un asiento sin tipo no se
 * escribe. Los SIETE tipos base no incluyen la compra, y una compra NO es
 * ninguno de ellos:
 *
 *  - no es un `expense`: una compra NO es un gasto, es existencias y deuda;
 *  - no es un `bank_movement`: no hay dinero que se mueva;
 *  - no es un `adjustment`: no corrige otro movimiento;
 *  - no es un `refund`: ese es el de la devolucion.
 *
 * Y la compra es un concepto UNIVERSAL, igual que los otros siete: no depende
 * del pais ni del regimen fiscal, asi que es DATO y no codigo. Por eso su
 * fila va aqui (la M18 sembro los siete base) y en el seeder autoritativo
 * (`LedgerEntryTypeSeeder`), que hace `upsert` de los mismos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('ledger_entry_types')->insertOrIgnore([
            [
                'code' => 'purchase',
                'name' => 'Compra',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        // El tipo es DATO y el `LedgerWriter` lo consulta: borrarlo dejaria
        // asientos huerfanos. La vuelta atras es la de apagar el tipo, no la de
        // destruir su historia.
        DB::table('ledger_entry_types')->where('code', 'purchase')->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
    }
};
