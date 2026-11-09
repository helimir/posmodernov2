<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.9.2 — la SEMILLA COMPLETA entrega el esquema con sus catalogos.
 *
 * Regla que fija: *el esquema se entrega por migraciones y datos sembrados,
 * no por scripts*.
 *
 * `migrate:fresh --seed` es el FLUJO de la instalacion: las migraciones dan
 * el ESQUEMA y los seeders dan los DATOS. Un catalogo que vive en una
 * migracion es un dato disfrazado de esquema: se audita en el git y no en la
 * base de datos, y no se puede ajustar sin desplegar.
 *
 * Este test comprueba el FLUJO ENTERO (migraciones + seeders) y no una parte:
 * es el camino que la instalacion recorre.
 *
 * Los tests van SIN transaccion envolvente (`WithoutWrappingTransaction`): un
 * `migrate:fresh` DENTRO de una transaccion la aborta entera, porque el DDL de
 * PostgreSQL no vive dentro de la transaccion que la prueba abrio.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    DB::table('legacy_translation_map')->delete();
    DB::table('users')->where('email', 'test@example.com')->delete();

    // Y la CAPA DE PAIS Y FISCAL que siembran los seeders: se borra para que
    // el test siguiente no encuentre datos que no son suyos. Una moneda o un
    // pais sembrado choca con el fixture de otro test (el `VES` del mapa de
    // traduccion, por ejemplo), y una fila sembrada que sobrevive al test
    // hace que el test siguiente mida sobre una base que no es la suya.
    DB::statement(
        'TRUNCATE customer_ledger_entries, sales, customers, stores, fiscal_profiles,'
        .' countries, currencies RESTART IDENTITY CASCADE'
    );
});

test('test_la_semilla_deja_el_catalogo_de_tipos_de_asiento_y_el_mapa_de_traduccion_sembrados', function () {
    // 1. El FLUJO: migraciones y seeders, en ese orden.
    Artisan::call('migrate:fresh', ['--seed' => true]);

    // 2. El CATALOGO de tipos de asiento: los SIETE base, todos activos.
    $tipos = DB::table('ledger_entry_types')->orderBy('code')->get();

    expect($tipos->pluck('code')->all())->toBe([
        'adjustment',
        'bank_movement',
        'expense',
        'opening_balance',
        'refund',
        'sale',
        'sale_payment',
    ]);

    expect($tipos->where('is_active', false)->count())->toBe(0)
        ->and($tipos->whereNull('name')->count())->toBe(0);

    // 3. El MAPA de traduccion: las DIECISIETE tablas del nucleo del
    //    terminal, sembradas por el seeder y no por una migracion.
    $tablas = DB::table('legacy_translation_map')
        ->distinct()
        ->orderBy('legacy_table')
        ->pluck('legacy_table')
        ->all();

    expect($tablas)->toBe([
        'bank_account_to_store',
        'bank_accounts',
        'bank_transaction_info',
        'bank_transaction_price',
        'customer_to_store',
        'customer_transactions',
        'gift_cards',
        'installment_orders',
        'installment_payments',
        'payments',
        'pos_register',
        'product_to_store',
        'purchase_item',
        'sell_logs',
        'selling_info',
        'selling_item',
        'selling_price',
    ]);

    // 4. Y el mapa DICE la direccion: cada fila con destino apunta a una
    //    tabla que existe en el esquema recien migrado.
    $destinos = DB::table('legacy_translation_map')
        ->whereNotNull('new_table')
        ->distinct()
        ->pluck('new_table')
        ->all();

    foreach ($destinos as $destino) {
        expect(DB::getSchemaBuilder()->hasTable($destino))->toBeTrue(
            "El mapa sembrado apunta a la tabla '{$destino}', que no existe."
        );
    }
});

test('test_la_semilla_siembra_el_perfil_fiscal_el_catalogo_la_tienda_y_los_saldos_de_apertura', function () {
    // 1. El FLUJO: migraciones y seeders, en ese orden.
    Artisan::call('migrate:fresh', ['--seed' => true]);

    // 2. `TaxonomySeeder`: los MONEDAS, los PAISES y los FORMATOS.
    expect(DB::table('currencies')->count())->toBe(5)
        ->and(DB::table('countries')->count())->toBe(5)
        ->and(DB::table('number_formats')->count())->toBe(5);

    // 3. `FiscalProfileSeeder`: un perfil por pais, con sus tipos de documento,
    //    su vocabulario de estados y sus dos series.
    expect(DB::table('fiscal_profiles')->count())->toBe(5)
        ->and(DB::table('fiscal_profile_document_types')->count())->toBe(20)
        ->and(DB::table('fiscal_status_vocabularies')->count())->toBe(15)
        ->and(DB::table('fiscal_series')->count())->toBe(10);

    // 4. Y las categorias de IVA con sus tasas: solo las del perfil
    //    venezolano, que es el unico con su tratamiento transcrito.
    expect(DB::table('tax_categories')->count())->toBe(5)
        ->and(DB::table('tax_rates')->count())->toBe(4);

    // 5. `DemoStoreSeeder`: la tienda de demostracion, con su configuracion y
    //    su cuenta de caja.
    $tienda = DB::table('stores')->where('code_name', 'DEMO')->first();

    expect($tienda)->not->toBeNull('La semilla no siembra la tienda de demostracion.')
        ->and((bool) $tienda->status)->toBeTrue()
        ->and(DB::table('countries')->where('id', $tienda->country_id)->value('iso_code'))->toBe('VE');

    expect(DB::table('store_settings')->where('store_id', $tienda->id)->value('timezone'))->not->toBeNull()
        ->and(DB::table('bank_accounts')->count())->toBe(1)
        ->and(DB::table('bank_account_stores')->where('store_id', $tienda->id)->count())->toBe(1);

    // 6. `OpeningBalancesSeeder`: el saldo de caja de la tienda, SIN
    //    certificar (la certificacion es un acto humano).
    $saldo = DB::table('opening_balances')->where('store_id', $tienda->id)->first();

    expect($saldo)->not->toBeNull('La semilla no siembra el saldo de apertura de caja.')
        ->and((string) $saldo->kind)->toBe('cash')
        ->and($saldo->certified_at)->toBeNull()
        ->and($saldo->certified_by)->toBeNull();
});

test('test_sembrar_dos_veces_no_duplica_una_fila', function () {
    // 1. La primera siembra.
    Artisan::call('migrate:fresh', ['--seed' => true]);

    $tablas = [
        'currencies', 'countries', 'number_formats', 'fiscal_profiles',
        'fiscal_profile_document_types', 'fiscal_status_vocabularies',
        'fiscal_profile_fields', 'tax_categories', 'tax_rates', 'fiscal_series',
        'stores', 'store_settings', 'bank_accounts', 'bank_account_stores',
        'opening_balances',
    ];

    $antes = [];

    foreach ($tablas as $tabla) {
        $antes[$tabla] = DB::table($tabla)->count();
    }

    // 2. La segunda siembra: el `DatabaseSeeder` entero, otra vez.
    Artisan::call('db:seed', ['--force' => true]);

    // 3. Ni una fila de mas: sembrar dos veces no duplica nada.
    foreach ($antes as $tabla => $conteo) {
        expect(DB::table($tabla)->count())->toBe(
            $conteo,
            "La tabla {$tabla} duplico filas al sembrar dos veces."
        );
    }
});
