<?php

use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.4.2-T — los bridges de tienda no guardan contadores derivados.
 *
 * Regla que fija: *los saldos se derivan, no se cuentan dos veces*. El legacy
 * llevaba contadores globales por cuenta (`deposit`, `withdraw`,
 * `transfer_*`) que duplicaban el saldo y lo cambiaban al borrar una
 * operación, y `credit_balance` en el cliente, que duplicaba el balance de su
 * bridge.
 */
uses(WithoutWrappingTransaction::class);

test('test_los_bridges_de_tienda_no_guardan_contadores_derivados', function () {
    // Los doce bridges del patron maestro/bridge.
    $bridges = [
        'product_stores', 'customer_stores', 'supplier_stores', 'brand_stores',
        'category_stores', 'box_stores', 'unit_stores', 'currency_stores',
        'payment_method_stores', 'pos_template_stores', 'bank_account_stores',
        'user_stores',
    ];

    foreach ($bridges as $bridge) {
        expect(Schema::hasTable($bridge))->toBeTrue("Falta el bridge {$bridge}.");

        $columns = Schema::getColumnListing($bridge);

        // Todos con FK real a la tienda, estado y orden.
        expect($columns)->toContain('store_id')
            ->and($columns)->toContain('status')
            ->and($columns)->toContain('sort_order');

        // Y NINGUN contador derivado: el saldo se deriva de los movimientos.
        foreach (['deposit', 'withdraw', 'transfer_in', 'transfer_out', 'credit_balance', 'balance_total'] as $derived) {
            expect($columns)->not->toContain($derived, "El bridge {$bridge} guarda el contador derivado {$derived}.");
        }
    }

    // La cuenta bancaria NO lleva los contadores globales del legacy.
    expect(Schema::getColumnListing('bank_account_stores'))
        ->not->toContain('deposit')
        ->not->toContain('withdraw')
        ->not->toContain('transfer_in')
        ->not->toContain('transfer_out');
});
