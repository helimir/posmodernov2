<?php

use App\Domain\Accounting\LedgerWriter;
use App\Models\Store;
use Database\Seeders\LedgerEntryTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.9.11 — el tipo de movimiento sustituye a las banderas booleanas.
 *
 * Regla que fija: *el tipo de movimiento sustituye a las banderas booleanas*.
 * Los saldos y los totales SE DERIVAN (de los apuntes y de las lineas), y los
 * catálogos no exponen banderas contables: el legacy distinguia un movimiento
 * con once banderas en la cabecera (`is_sale`, `is_expense`, `is_income`…), y
 * el destino lo distingue con un TIPO, que es una fila del catálogo.
 *
 * `is_active` sí existe en los catálogos, pero es de INTERFAZ (el tipo está
 * vivo o no), no de contabilidad: no dice si el movimiento es una venta o un
 * gasto.
 */
test('test_los_saldos_y_totales_se_derivan_y_los_catalogos_no_exponen_banderas_contables', function () {
    // 1. El movimiento contable es un TIPO: la cabecera no tiene banderas.
    $columnas = Schema::getColumnListing('ledger_entries');

    expect($columnas)->toContain('entry_type_id')
        ->and(array_values(preg_grep('/^is_/', $columnas)))->toBe([]);

    // 2. Y los CATALOGOS no exponen banderas contables: su único booleano es
    //    `is_active`, que es de interfaz.
    foreach (['ledger_entry_types', 'income_sources', 'expense_categories'] as $catalogo) {
        $booleanas = array_map(
            static fn (object $columna): string => (string) $columna->column_name,
            DB::select(
                "SELECT column_name FROM information_schema.columns
                  WHERE table_schema = 'public' AND table_name = ? AND data_type = 'boolean'
                  ORDER BY column_name",
                [$catalogo]
            )
        );

        expect($booleanas)->toBe(['is_active'], "El catálogo '{$catalogo}' expone banderas contables.");
    }

    // 3. El TIPO es una fila del catálogo, y cubre los movimientos que el
    //    legacy distinguía con once banderas.
    $this->seed(LedgerEntryTypeSeeder::class);

    $tipos = DB::table('ledger_entry_types')->pluck('code')->sort()->values()->all();

    expect($tipos)->toContain(
        'sale',
        'sale_payment',
        'expense',
        'bank_movement',
        'refund',
        'adjustment',
        'opening_balance'
    );

    // 4. Los SALDOS no se guardan: se derivan de los apuntes. Ni la cuenta
    //    bancaria, ni su vinculo con la tienda, ni el asiento, ni la linea
    //    guardan un saldo.
    foreach (['bank_accounts', 'bank_account_stores', 'ledger_entries', 'ledger_lines'] as $tabla) {
        expect(Schema::getColumnListing($tabla))->not->toContain('balance');
    }

    // 5. Y se derivan DE VERDAD: el saldo de una cuenta es la suma de sus
    //    apuntes, y no hay ninguna columna donde se guarde.
    $tienda = Store::create(['name' => 'Tienda de saldos derivados']);

    $escritor = new LedgerWriter(DB::connection());

    $escritor->write(
        'sale',
        $tienda->id,
        new DateTimeImmutable('2026-11-04T10:00:00-04:00'),
        new DateTimeImmutable('2026-11-04T00:00:00-04:00'),
        [
            ['account' => '1100000', 'debit' => '40.0000', 'credit' => '0'],
            ['account' => '2100000', 'debit' => '0', 'credit' => '40.0000'],
        ],
    );

    $debe = '0';
    $haber = '0';

    foreach (DB::table('ledger_lines')->where('account_code', '1100000')->get() as $linea) {
        $debe = bcadd($debe, (string) $linea->debit, 4);
        $haber = bcadd($haber, (string) $linea->credit, 4);
    }

    expect(bcsub($debe, $haber, 4))->toBe('40.0000');

    // 6. Y los TOTALES de la venta se derivan en la cabecera: la linea no
    //    lleva el total de la venta, y la cabecera tiene UNA fila por venta.
    expect(Schema::getColumnListing('sale_items'))->not->toContain('grand_total')
        ->and(Schema::getColumnListing('sale_items'))->not->toContain('sub_total');

    $indices = Schema::getIndexes('sale_totals');

    $unicos = array_values(array_filter(
        $indices,
        static fn (array $indice): bool => ($indice['unique'] ?? false) === true
            && ($indice['columns'] ?? []) === ['sale_id']
    ));

    expect($unicos)->not->toBeEmpty();
});
