<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.9.11 — el esquema se entrega por migraciones, no por scripts.
 *
 * Regla que fija: *el esquema se entrega por migraciones, no por scripts*. El
 * destino se construye con migraciones versionadas, y sus tres garantías —no
 * `float`, no `blob` de PHP serializado, y una columna por dato— se
 * comprueban contra el esquema REAL, que es el que importa.
 *
 * El legacy guardaba las preferencias y los permisos en BLOBS serializados de
 * PHP (`stores.preference`, `users.preference`, `user_group.permission`) y sus
 * importes en `float`. El destino no tiene nada de eso: los datos blob del
 * legacy son columnas tipadas, y los importes son `numeric(25,4)`.
 */
test('test_el_esquema_destino_se_construye_solo_por_migraciones_sin_float_ni_blobs_php', function () {
    // 1. El esquema lo construyen las MIGRACIONES: estan registradas y son
    //    las del dominio, no un script suelto.
    $migraciones = DB::table('migrations')->pluck('migration')->all();

    expect($migraciones)->not->toBeEmpty()
        ->and($migraciones)->toContain('2026_11_01_000022_create_fiscal_documents_table')
        ->and($migraciones)->toContain('2026_11_01_000021_create_fiscal_series_table')
        ->and($migraciones)->toContain('2026_11_01_000037_add_domain_indexes_and_constraints');

    // 2. NINGUN importe es flotante: ni `real`, ni `double precision`, ni
    //    `money`. Un flotante no es dinero.
    $flotantes = DB::select(
        "SELECT table_name, column_name FROM information_schema.columns
          WHERE table_schema = 'public'
            AND data_type IN ('real', 'double precision', 'float4', 'float8', 'money')
          ORDER BY table_name, column_name"
    );

    expect($flotantes)->toBe([]);

    // 3. NINGUN blob de PHP serializado. El unico binario del destino es el
    //    de los BYTES ESC/POS congelados del trabajo de impresion, que es un
    //    fichero, no un `serialize()`.
    $binarios = array_map(
        static fn (object $columna): array => [$columna->table_name, $columna->column_name],
        DB::select(
            "SELECT table_name, column_name FROM information_schema.columns
              WHERE table_schema = 'public' AND data_type IN ('bytea', 'blob', 'oid')
              ORDER BY table_name, column_name"
        )
    );

    expect($binarios)->toBe([['print_jobs', 'payload_bytes']]);

    // 4. Y las preferencias y los permisos del legacy NO existen: son datos
    //    tipados, no blobs.
    $preferencias = DB::select(
        "SELECT table_name, column_name FROM information_schema.columns
          WHERE table_schema = 'public'
            AND column_name IN ('preference', 'preferences', 'serialized', 'serialized_data')
          ORDER BY table_name, column_name"
    );

    expect($preferencias)->toBe([])
        ->and(Schema::hasColumn('stores', 'preference'))->toBeFalse()
        ->and(Schema::hasTable('user_group'))->toBeFalse();

    // 5. Y las tablas del destino estan: las de la capa fiscal, las del
    //    inventario por lotes y las de la contabilidad.
    foreach ([
        'countries', 'currencies', 'number_formats', 'fiscal_profiles', 'fiscal_series',
        'fiscal_documents', 'fiscal_document_reservations', 'fiscal_document_events',
        'inventory_lots', 'sale_item_lot_consumptions', 'ledger_entries', 'ledger_lines',
        'ledger_entry_types', 'cash_registers', 'opening_balances', 'print_jobs',
    ] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue("La tabla '{$tabla}' no existe en el destino.");
    }

    // 6. Y los importes son DECIMAL: `numeric(25,4)`, no `float`.
    foreach ([
        'sale_items.unit_price', 'sale_items.tax_amount', 'sale_totals.grand_total',
        'purchase_totals.grand_total', 'ledger_lines.debit', 'ledger_lines.credit',
        'expenses.amount', 'cash_registers.opening_balance',
    ] as $columna) {
        [$tabla, $nombre] = explode('.', $columna);

        expect(Schema::getColumnType($tabla, $nombre))->toStartWith('numeric');
    }
});
