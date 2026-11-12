<?php

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Enums\AccountRole;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El SEMBRADOR del plan de cuentas: el catalogo de cuentas y su asignacion a
 * la tienda de demostracion.
 *
 * Reglas que fija:
 *
 *  - el plan sembrado tiene los CUATRO roles que el sistema necesita, y la
 *    tienda puede COBRAR con el: el plan sembrado no es un adorno, es el que
 *    resuelve el checkout;
 *  - sembrar dos veces NO DUPLICA ni una cuenta ni un vinculo: el sembrador
 *    es idempotente, como los demas del repositorio.
 */

/**
 * El plan sembrado tiene los cuatro roles y la tienda puede cobrar.
 */
test('test_el_plan_sembrado_tiene_los_cuatro_roles_y_la_tienda_puede_cobrar', function () {
    // La emision fiscal APAGADA: este test prueba el PLAN, no el canal
    // fiscal.
    config(['fiscal.emission_enabled' => false]);

    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    // 1. Los CUATRO roles, con el codigo de su cuenta.
    expect($plan->plan((int) $escenario['store_id']))->toBe([
        'cash' => '1100000',
        'receivable' => '1200000',
        'revenue' => '4010000',
        'tax_payable' => '2110000',
    ]);

    expect($plan->missingRoles((int) $escenario['store_id']))->toBe([]);

    // 2. Y el catalogo de cuentas tiene MAS que las cuatro: un plan con solo
    //    lo que el sistema usa no es un plan.
    expect(DB::table('accounts')->count())->toBeGreaterThan(4);

    // 3. Y la tienda COBRA: la venta se escribe con el plan sembrado.
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $escenario['payment_method_id'],
                    'amount' => '58.0000',
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('grand_total', '58.0000');

    // 4. Y el asiento usa las cuentas del PLAN, no unas escritas a mano.
    $cuentas = DB::table('ledger_lines')
        ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
        ->where('ledger_entries.store_id', $escenario['store_id'])
        ->distinct()
        ->pluck('ledger_lines.account_code')
        ->all();

    expect($cuentas)->toContain('1100000')
        ->and($cuentas)->toContain('4010000')
        ->and($cuentas)->toContain('2110000');
});

/**
 * Sembrar dos veces no duplica cuentas ni vinculos.
 */
test('test_sembrar_dos_veces_no_duplica_cuentas_ni_vinculos', function () {
    $escenario = PosFixture::escenario();

    $cuentas = DB::table('accounts')->count();
    $vinculos = DB::table('store_accounts')->count();

    expect($cuentas)->toBeGreaterThan(0)
        ->and($vinculos)->toBe(4);

    // La segunda siembra.
    (new ChartOfAccountsSeeder)->run();

    expect(DB::table('accounts')->count())->toBe($cuentas)
        ->and(DB::table('store_accounts')->count())->toBe($vinculos);

    // Y el plan sigue siendo el MISMO: los roles no se han movido.
    $plan = new ChartOfAccounts(DB::connection());

    expect($plan->plan((int) $escenario['store_id']))->toBe([
        'cash' => '1100000',
        'receivable' => '1200000',
        'revenue' => '4010000',
        'tax_payable' => '2110000',
    ]);

    // Y cada rol lo cumple UNA cuenta.
    foreach (AccountRole::cases() as $rol) {
        expect(DB::table('store_accounts')
            ->where('store_id', $escenario['store_id'])
            ->where('role', $rol->value)
            ->count())->toBe(1);
    }
});
