<?php

use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.5.2-T — el payable del préstamo se calcula en el servidor y no se guarda
 * desnormalizado.
 *
 * Regla que fija: *los importes derivados se calculan, no se duplican*. Si el
 * `payable` fuera una columna, se desincronizaría del interés que lo produce
 * en cuanto cambiara el tipo de interés, y nadie sabría cuál de los dos manda.
 */
uses(WithoutWrappingTransaction::class);

test('test_el_payable_del_prestamo_se_calcula_en_el_servidor_y_no_se_guarda_desnormalizado', function () {
    $loans = Schema::getColumnListing('loans');

    // 1. El payable NO es una columna.
    expect($loans)->not->toContain('payable')
        ->and($loans)->toContain('principal')
        ->and($loans)->toContain('interest_percentage');

    // 2. `loan_from` es una FK real, no el texto libre del legacy.
    $foreignTables = collect(Schema::getForeignKeys('loans'))->pluck('foreign_table');

    expect($foreignTables)->toContain('users')
        ->and($foreignTables)->toContain('customers');

    // 3. Y el porcentaje admite decimales: el legacy lo guardaba como int, y
    //    un 2,5 % no cabe en un entero.
    expect(Schema::getColumnType('loans', 'interest_percentage'))->toBe('numeric')
        ->and(Schema::getColumnType('installment_plans', 'interest_percentage'))->toBe('numeric');
});
