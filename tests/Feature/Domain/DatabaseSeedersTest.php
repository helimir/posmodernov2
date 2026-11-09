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
