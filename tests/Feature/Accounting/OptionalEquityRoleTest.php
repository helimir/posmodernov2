<?php

use App\Domain\Accounting\CashRegisterService;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Enums\AccountRole;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El ROL DEL CAPITAL, OPCIONAL.
 *
 * Reglas que fija:
 *
 *  - el plan COMPLETO son CUATRO roles (la caja, la cartera, los ingresos y el
 *    impuesto por pagar): una tienda con un plan de cuatro roles NO deja de
 *    cobrar. El rol `equity` sigue existiendo y siendo asignable, pero su
 *    ausencia ya no es un plan incompleto;
 *  - al ABRIR la caja con una PARTIDA DISTINTA DE CERO hace falta una cuenta
 *    de capital, porque ahi es donde el asiento de apertura necesita la
 *    contrapartida. Y el fallo lo dice: que cuenta falta y que hay que hacer;
 *  - con una PARTIDA CERO no hace falta capital: una partida cero no se
 *    asienta, y sin asiento no hace falta contrapartida.
 */

/**
 * El PLAN de una tienda SIN cuenta de capital: los cuatro roles obligatorios.
 */
function plan_sin_capital(int $storeId): void
{
    $cuentas = [
        ['1100000', 'Efectivo y caja', 'asset', 'cash'],
        ['1200000', 'Cuentas por cobrar', 'asset', 'receivable'],
        ['4010000', 'Ventas', 'income', 'revenue'],
        ['2110000', 'Impuesto por pagar', 'liability', 'tax_payable'],
    ];

    foreach ($cuentas as [$codigo, $nombre, $tipo, $rol]) {
        $cuentaId = DB::table('accounts')->where('code', $codigo)->value('id');

        if ($cuentaId === null) {
            $cuentaId = DB::table('accounts')->insertGetId([
                'code' => $codigo,
                'name' => $nombre,
                'type' => $tipo,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $vinculo = DB::table('store_accounts')
            ->where('store_id', $storeId)
            ->where('role', $rol)
            ->value('id');

        if ($vinculo === null) {
            DB::table('store_accounts')->insert([
                'account_id' => $cuentaId,
                'store_id' => $storeId,
                'role' => $rol,
                'status' => 'active',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

/**
 * El plan COMPLETO son CUATRO roles, y el del capital es OPCIONAL.
 *
 * REGLA que fija este test: *una tienda con un plan de cuatro roles cobra*.
 * El rol `equity` sigue existiendo (es un rol valido y asignable), pero su
 * ausencia ya no es un plan incompleto.
 */
test('test_el_plan_completo_son_cuatro_roles_y_el_del_capital_es_opcional', function () {
    $tienda = Store::create(['name' => 'Tienda con plan de cuatro roles']);

    plan_sin_capital((int) $tienda->id);

    $plan = new ChartOfAccounts(DB::connection());

    // El plan NO le falta ningun rol: los cuatro obligatorios estan.
    expect($plan->missingRoles((int) $tienda->id))->toBe([]);

    // Y el plan RESUELVE: una tienda con cuatro roles no deja de cobrar.
    $resuelto = $plan->plan((int) $tienda->id);

    expect($resuelto)->toHaveKey('cash')
        ->and($resuelto)->toHaveKey('receivable')
        ->and($resuelto)->toHaveKey('revenue')
        ->and($resuelto)->toHaveKey('tax_payable');

    // El rol del capital SIGUE existiendo y es asignable: es un rol valido.
    expect(AccountRole::tryFrom('equity'))->not->toBeNull()
        ->and(AccountRole::Equity->esObligatorio())->toBeFalse()
        ->and(AccountRole::Cash->esObligatorio())->toBeTrue()
        ->and(AccountRole::obligatorios())->toHaveCount(4);
});

/**
 * Abrir la caja con PARTIDA y SIN cuenta de capital falla con un mensaje
 * util.
 *
 * REGLA que fija este test: *con partida, la contrapartida hace falta, y el
 * fallo dice que cuenta falta y que hay que hacer*.
 */
test('test_abrir_la_caja_con_partida_sin_cuenta_de_capital_falla_con_el_mensaje_util', function () {
    $servicio = new CashRegisterService(DB::connection());
    $tienda = Store::create(['name' => 'Tienda que abre con partida y sin capital']);

    plan_sin_capital((int) $tienda->id);

    expect(fn () => $servicio->open($tienda->id, new DateTimeImmutable('2026-11-04'), '100.0000'))
        ->toThrow(RuntimeException::class, 'Para asentar la partida hace falta una cuenta de capital');

    // Y el mensaje dice QUE hay que hacer.
    expect(fn () => $servicio->open($tienda->id, new DateTimeImmutable('2026-11-04'), '100.0000'))
        ->toThrow(RuntimeException::class, 'asigne el rol equity a una cuenta de tipo equity');

    // Y la caja NO queda abierta: el fallo es antes de escribir.
    expect(DB::table('cash_registers')->where('store_id', $tienda->id)->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * Abrir la caja con PARTIDA CERO y sin cuenta de capital FUNCIONA.
 *
 * REGLA que fija este test: *una partida cero no se asienta, y sin asiento no
 * hace falta contrapartida*.
 */
test('test_abrir_la_caja_con_partida_cero_funciona_sin_cuenta_de_capital', function () {
    $servicio = new CashRegisterService(DB::connection());
    $tienda = Store::create(['name' => 'Tienda que abre en cero y sin capital']);

    plan_sin_capital((int) $tienda->id);

    $caja = $servicio->open($tienda->id, new DateTimeImmutable('2026-11-04'), '0.0000');

    expect($caja)->toBeInt()
        ->and(DB::table('cash_registers')->where('id', $caja)->value('status'))->toBe('open');

    // Y no se asienta nada: una partida cero no es un asiento.
    expect(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * Abrir la caja con PARTIDA y CON cuenta de capital FUNCIONA.
 *
 * REGLA que fija este test: *con partida y con capital, el asiento se
 * escribe*: la contrapartida de la partida es la cuenta de capital.
 */
test('test_abrir_la_caja_con_partida_y_con_cuenta_de_capital_funciona', function () {
    $servicio = new CashRegisterService(DB::connection());
    $tienda = Store::create(['name' => 'Tienda que abre con partida y con capital']);

    // El plan de cuatro roles MAS la cuenta de capital.
    plan_sin_capital((int) $tienda->id);
    PosFixture::planDeCaja((int) $tienda->id);

    $caja = $servicio->open($tienda->id, new DateTimeImmutable('2026-11-04'), '100.0000');

    expect($caja)->toBeInt();

    // El ASIENTO de apertura esta, con la contrapartida en la cuenta de
    // capital.
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'cash_register')
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect($asiento)->not->toBeNull()
        ->and((string) $asiento->type_code)->toBe('opening_balance');

    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $asiento->id)->orderBy('id')->get();

    expect($lineas)->toHaveCount(2);

    $capital = $lineas->firstWhere('account_code', '3100000');

    expect($capital)->not->toBeNull()
        ->and((string) $capital->credit)->toBe('100.0000')
        ->and((string) $capital->debit)->toBe('0.0000');
});
