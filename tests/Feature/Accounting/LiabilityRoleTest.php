<?php

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Models\Account;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El ROL DE PASIVO del plan de cuentas.
 *
 * Reglas que fija:
 *
 *  - el rol `liability` EXISTE y es un rol VALIDO: se puede asignar, y su
 *    ausencia NO impide COBRAR (la decision de la F2-13b: ninguna tienda deja
 *    de cobrar). Lo que no puede la tienda sin pasivo es DESHACER una venta
 *    cobrada por error, y eso es peor que no poder cobrar;
 *  - el rol `liability` es OBLIGATORIO del plan COMPLETO: el plan completo
 *    son CINCO roles (la caja, la cartera, los ingresos, el impuesto por pagar
 *    y el pasivo), y el capital sigue siendo opcional;
 *  - el rol EXIGE SU TIPO: una cuenta de pasivo, y solo una. Una cuenta de
 *    activo (la caja) no puede cumplir el rol de pasivo, porque el balance no
 *    cuadra y nadie se entera hasta que cuadra mal. Y la comprobacion es AL
 *    ASIGNAR, no despues, y el error dice que rol, que cuenta y que tipo
 *    esperaba;
 *  - el CONJUNTO CERRADO de los roles del ESQUEMA declara el pasivo: el enum
 *    de PostgreSQL `account_role` es la fuente, y ahi es donde entra el valor
 *    nuevo;
 *  - el plan SEMBRADO lo declara: la tienda de demostracion tiene su cuenta
 *    de pasivo, y esa cuenta es de tipo pasivo.
 */

/**
 * El rol de pasivo EXISTE y exige una cuenta de tipo PASIVO.
 */
test('test_el_rol_de_pasivo_exige_una_cuenta_de_tipo_pasivo', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    // 1. El rol existe y es un rol VALIDO del plan.
    expect(AccountRole::tryFrom('liability'))->not->toBeNull()
        ->and(AccountRole::Liability->value)->toBe('liability')
        ->and(AccountRole::Liability->requiredType())->toBe(AccountType::Liability);

    // 2. La INVARIANTE: el rol exige SU tipo. La caja (un ACTIVO) no puede
    //    cumplir el rol de pasivo.
    $caja = Account::query()->where('code', '1100000')->firstOrFail();

    expect((string) $caja->type)->toBe('asset');

    expect(fn () => $plan->assign((int) $escenario['store_id'], (int) $caja->id, AccountRole::Liability))
        ->toThrow(RuntimeException::class, "El rol 'liability' exige una cuenta de tipo 'liability'");

    // Y el rechazo NO escribe nada: la cuenta sigue sin ese rol.
    expect(DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('account_id', $caja->id)
        ->value('role'))->not->toBe('liability');

    // 3. Y una cuenta de PASIVO si lo puede cumplir.
    $pasivo = Account::query()->where('code', '2100000')->firstOrFail();

    expect((string) $pasivo->type)->toBe('liability');

    $plan->assign((int) $escenario['store_id'], (int) $pasivo->id, AccountRole::Liability);

    expect($plan->roleOf((int) $escenario['store_id'], (int) $pasivo->id))->toBe('liability');
});

/**
 * El rol de pasivo es OBLIGATORIO del plan COMPLETO, y su ausencia NO impide
 * cobrar.
 *
 * REGLA que fija este test: *el plan completo son CINCO roles, y el pasivo es
 * uno de ellos, pero una tienda sin pasivo sigue cobrando*. "Obligatorio"
 * quiere decir que el plan lo declara, NO que sin el rol la tienda no cobra:
 * el pasivo es lo que la tienda DEBE (el credito a favor del cliente de una
 * venta anulada), y su ausencia se ve en el plan, no en el cobro.
 */
test('test_el_rol_de_pasivo_es_obligatorio_del_plan_completo_y_su_ausencia_no_impide_cobrar', function () {
    $tienda = Store::create(['name' => 'Tienda con plan sin pasivo']);

    // Los CUATRO roles del COBRO, y ninguno mas.
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

        DB::table('store_accounts')->insert([
            'account_id' => $cuentaId,
            'store_id' => $tienda->id,
            'role' => $rol,
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $plan = new ChartOfAccounts(DB::connection());

    // El plan DECLARA el hueco: el pasivo falta, y el hueco se ve.
    expect($plan->missingRoles((int) $tienda->id))->toBe(['liability']);

    // Y el plan RESUELVE igual: una tienda sin pasivo NO deja de cobrar.
    expect($plan->plan((int) $tienda->id))->not->toHaveKey('liability')
        ->and($plan->plan((int) $tienda->id))->toHaveKey('cash')
        ->and($plan->plan((int) $tienda->id))->toHaveKey('tax_payable');

    // Y el pasivo es OBLIGATORIO del plan COMPLETO: son CINCO roles, y el
    // capital sigue siendo opcional.
    expect(AccountRole::Liability->esObligatorio())->toBeTrue()
        ->and(AccountRole::Equity->esObligatorio())->toBeFalse()
        ->and(AccountRole::obligatorios())->toHaveCount(5);
});

/**
 * Una tienda SIN cuenta de pasivo SIGUE COBRANDO.
 *
 * REGLA que fija este test: *ninguna tienda deja de cobrar* (la decision de la
 * F2-13b). El pasivo es obligatorio del plan COMPLETO, no del COBRO: la
 * tienda cobra, y lo que no puede es deshacer una venta cobrada por error.
 */
test('test_una_tienda_sin_cuenta_de_pasivo_sigue_cobrando', function () {
    config(['fiscal.emission_enabled' => false]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // La tienda se queda SIN cuenta de pasivo: el hueco se ve en el plan.
    DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('role', 'liability')
        ->delete();

    $plan = new ChartOfAccounts(DB::connection());

    expect($plan->missingRoles((int) $escenario['store_id']))->toBe(['liability']);

    // Y COBRA: la venta se escribe con su asiento y con su numero.
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

    // Y la caja del dia tiene el importe: el cobro no se vio afectado.
    expect((string) DB::table('ledger_lines')
        ->where('account_code', '1100000')
        ->value('debit'))->toBe('58.0000');
});

/**
 * El CONJUNTO CERRADO de los roles del ESQUEMA declara el pasivo.
 */
test('test_el_esquema_declara_el_rol_de_pasivo_en_el_conjunto_cerrado_de_los_roles', function () {
    $tipos = DB::select('SELECT unnest(enum_range(NULL::account_role))::text AS code');

    $codigos = array_map(static fn (object $fila): string => (string) $fila->code, $tipos);

    expect($codigos)->toContain('liability')
        ->and($codigos)->toContain('cash')
        ->and($codigos)->toContain('receivable')
        ->and($codigos)->toContain('revenue')
        ->and($codigos)->toContain('tax_payable')
        ->and($codigos)->toContain('equity');

    // Y el conjunto es el MISMO que el del dominio: el esquema y el codigo no
    // pueden separarse, porque el rol que el codigo ofrece tiene que existir
    // en la base. El ORDEN no se compara: el enum de PostgreSQL es un
    // conjunto ordenado por su declaracion, y el codigo es otro.
    $delDominio = array_map(
        static fn (AccountRole $rol): string => $rol->value,
        AccountRole::cases(),
    );

    sort($codigos);
    sort($delDominio);

    expect($codigos)->toBe($delDominio);
});

/**
 * El plan SEMBRADO declara el rol de pasivo, con una cuenta de tipo pasivo.
 */
test('test_el_plan_sembrado_declara_el_rol_de_pasivo_con_su_cuenta_de_pasivo', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    $resuelto = $plan->plan((int) $escenario['store_id']);

    expect($resuelto)->toHaveKey('liability');

    $codigo = $resuelto['liability'];

    // Y la cuenta que lo cumple es de TIPO PASIVO: la invariante, en el dato.
    $cuenta = Account::query()->where('code', $codigo)->firstOrFail();

    expect((string) $cuenta->type)->toBe('liability')
        ->and($plan->missingRoles((int) $escenario['store_id']))->toBe([]);
});
