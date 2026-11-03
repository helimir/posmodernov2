<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.4.3-T — el saldo del cliente no está duplicado en dos tablas.
 *
 * Regla que fija: *una sola fuente de verdad por dato*. El legacy guardaba el
 * `credit_balance` en el cliente Y el `balance` en su bridge de tienda: dos
 * copias del mismo saldo que se desincronizaban.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    Schema::disableForeignKeyConstraints();
    DB::statement('TRUNCATE customer_stores, customers RESTART IDENTITY CASCADE');
    Schema::enableForeignKeyConstraints();
});

test('test_el_saldo_del_cliente_no_esta_duplicado_en_dos_tablas', function () {
    // El cliente NO lleva saldo: el saldo vive en su bridge.
    $customerColumns = Schema::getColumnListing('customers');

    expect($customerColumns)->not->toContain('credit_balance')
        ->and($customerColumns)->not->toContain('balance')
        ->and($customerColumns)->not->toContain('due')
        ->and($customerColumns)->toContain('credit_limit')
        ->and($customerColumns)->toContain('tax_id')
        ->and($customerColumns)->not->toContain('gtin');

    // Y el bridge sí: es la única sede.
    $bridgeColumns = Schema::getColumnListing('customer_stores');

    expect($bridgeColumns)->toContain('balance')
        ->and($bridgeColumns)->toContain('due')
        ->and($bridgeColumns)->toContain('store_id');

    // El pais es una FK real y el identificativo fiscal se llama asi.
    expect(Schema::getColumnType('customers', 'customer_country'))->not->toBeNull()
        ->and($customerColumns)->toContain('customer_country')
        ->and($customerColumns)->toContain('customer_state');

    // Y las FKs diferidas en M09 ya estan declaradas: el bridge apunta a su
    // maestro de verdad.
    $foreignKeys = Schema::getForeignKeys('customer_stores');

    expect(collect($foreignKeys)->pluck('foreign_table')->all())->toContain('customers');
});
