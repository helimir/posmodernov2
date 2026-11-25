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
 *    ausencia NO es un plan incompleto (el pasivo no hace falta para cobrar);
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
 * El rol de pasivo es OPCIONAL: su ausencia no es un plan incompleto.
 */
test('test_el_rol_de_pasivo_es_opcional_y_su_ausencia_no_impide_cobrar', function () {
    $tienda = Store::create(['name' => 'Tienda con plan sin pasivo']);

    // Los CUATRO roles obligatorios, y ninguno mas.
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

    // El plan NO le falta ningun rol: el pasivo no impide cobrar.
    expect($plan->missingRoles((int) $tienda->id))->toBe([])
        ->and($plan->plan((int) $tienda->id))->not->toHaveKey('liability');

    // Y el rol sigue siendo VALIDO y ASIGNABLE: es un rol, no un rol prohibido.
    expect(AccountRole::Liability->esObligatorio())->toBeFalse()
        ->and(AccountRole::obligatorios())->toHaveCount(4);
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
