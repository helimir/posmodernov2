<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El CATALOGO de tipos de asiento (`ledger_entry_types`).
 *
 * El legacy esparcia la clasificacion de un movimiento en VEINTE Y UNA
 * banderas booleanas: once en `income_sources` y diez en `expense_categorys`.
 * Una bandera no se puede consultar ("¿este gasto es un gasto?": si, porque
 * el booleano existe), y dos banderas activas a la vez no significan nada.
 *
 * Aqui el movimiento contable tiene UN TIPO, y el tipo se CONSULTA en el
 * catalogo. La fila del catalogo es DATO, no esquema: se audita en la base
 * de datos y se ajusta sin desplegar (design D7/D8).
 *
 * Los SIETE tipos base son conceptos universales: no dependen del pais ni del
 * regimen fiscal, asi que son datos, no codigo.
 *
 * Y EL TIPO DE LA COMPRA (`purchase`) es el OCTAVO, y es de la misma clase: una
 * compra es un concepto universal, y NO es ninguno de los otros siete (no es
 * un gasto, no es un movimiento de dinero, no es un ajuste y no es una
 * devolucion). Por eso vive en el mismo catalogo y se siembra desde aqui.
 *
 * M18 tambien deja los siete base en la migracion, y la M45 deja el de la
 * compra, y el motivo es que hay pruebas que se apoyan en el catalogo SIN
 * sembrar (`RefreshDatabase` migra pero no siembra): el 0.7.1-T comprueba que
 * el catalogo esta sembrado despues de migrar. El seeder es la lista
 * AUTORITATIVA y hace `upsert` de los mismos ocho: siembra y migracion no
 * pueden discrepar, y volver a sembrar no duplica nada.
 */
class LedgerEntryTypeSeeder extends Seeder
{
    /**
     * Siembra el catalogo.
     *
     * Es IDEMPOTENTE: el `upsert` se apoya en la restriccion unica del
     * `code`, asi que sembrar dos veces actualiza las mismas filas.
     */
    public function run(): void
    {
        $now = now();

        $tipos = [];

        foreach ($this->catalogo() as $tipo) {
            $tipos[] = [
                'code' => $tipo[0],
                'name' => $tipo[1],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('ledger_entry_types')->upsert(
            $tipos,
            ['code'],
            ['name', 'is_active', 'updated_at']
        );
    }

    /**
     * Los OCHO tipos base: `code` y `name`.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function catalogo(): array
    {
        return [
            ['sale', 'Venta'],
            ['sale_payment', 'Cobro de venta'],
            ['expense', 'Gasto'],
            ['bank_movement', 'Movimiento bancario'],
            ['refund', 'Devolucion'],
            ['adjustment', 'Ajuste'],
            ['opening_balance', 'Saldo de apertura'],
            ['purchase', 'Compra'],
        ];
    }
}
