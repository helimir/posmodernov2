<?php

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Models\Account;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El PLAN DE CUENTAS POR TIENDA: el rol que cumple cada cuenta en la tienda y
 * el TIPO que ese rol exige.
 *
 * Reglas que fija:
 *
 *  - el TIPO de la cuenta es el que el ROL exige: una cuenta de ingresos no
 *    puede cumplir el rol de la caja, porque el balance no cuadra y nadie se
 *    entera hasta que cuadra mal. La invariante se comprueba AL ASIGNAR, no
 *    despues, y el error dice que rol, que cuenta y que tipo esperaba;
 *  - el plan de una tienda se RESUELVE de sus vinculos: rol => codigo. Y si
 *    le falta un rol, la resolucion FALLA con un mensaje que nombra la tienda,
 *    el rol que falta y los que si tiene. NO hay un plan por defecto;
 *  - una cuenta NO puede cumplir dos roles en la misma tienda, y un rol NO
 *    puede tener dos cuentas en la misma tienda.
 */

/**
 * Una cuenta NUEVA del catalogo, con su tipo.
 */
function cuenta_de_prueba(string $codigo, AccountType $tipo): Account
{
    return Account::create([
        'code' => $codigo,
        'name' => 'Cuenta de prueba '.$codigo,
        'type' => $tipo->value,
        'is_active' => true,
    ]);
}

/**
 * El rol exige SU tipo: una cuenta de ingresos no puede cumplir el rol de la
 * caja.
 */
test('test_el_rol_exige_su_tipo_y_una_cuenta_de_ingresos_no_puede_cumplir_el_rol_de_caja', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    // La cuenta de INGRESOS del plan sembrado.
    $ingresos = Account::query()->where('code', '4010000')->firstOrFail();

    expect((string) $ingresos->type)->toBe('income');

    // El rol 'cash' exige una cuenta de TIPO ACTIVO.
    expect(AccountRole::Cash->requiredType())->toBe(AccountType::Asset);

    // Asignarle el rol de la caja a una cuenta de ingresos FALLA, y el
    // mensaje dice el rol, la cuenta y el tipo que esperaba.
    expect(fn () => $plan->assign((int) $escenario['store_id'], (int) $ingresos->id, AccountRole::Cash))
        ->toThrow(RuntimeException::class, "El rol 'cash' exige una cuenta de tipo 'asset'");

    expect(fn () => $plan->assign((int) $escenario['store_id'], (int) $ingresos->id, AccountRole::Cash))
        ->toThrow(RuntimeException::class, '4010000');

    // Y la cuenta NO cambio de rol: la invariante se comprueba AL ASIGNAR,
    // no despues.
    expect((string) DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('account_id', $ingresos->id)
        ->value('role'))->toBe('revenue');
});

/**
 * La RESOLUCION devuelve el mapa de rol a codigo de la tienda.
 */
test('test_la_resolucion_devuelve_el_mapa_de_rol_a_codigo_de_la_tienda', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    expect($plan->plan((int) $escenario['store_id']))->toBe([
        'cash' => '1100000',
        'receivable' => '1200000',
        'revenue' => '4010000',
        'tax_payable' => '2110000',
    ]);
});

/**
 * Una tienda SIN plan falla: no hay un plan por defecto.
 */
test('test_una_tienda_sin_plan_falla_con_un_mensaje_que_la_nombra', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    // La tienda de demostracion se queda sin plan.
    DB::table('store_accounts')->where('store_id', $escenario['store_id'])->delete();

    expect(fn () => $plan->plan((int) $escenario['store_id']))
        ->toThrow(RuntimeException::class, 'La tienda '.$escenario['store_id'].' no tiene un plan de cuentas');

    // Y el mensaje dice que roles le faltan.
    expect(fn () => $plan->plan((int) $escenario['store_id']))
        ->toThrow(RuntimeException::class, 'cash');
});

/**
 * Una tienda con un plan INCOMPLETO falla tambien: no hay un default que
 * tape el hueco.
 */
test('test_una_tienda_con_un_plan_incompleto_falla_tambien', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    // Le falta el rol de los ingresos.
    DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('role', 'revenue')
        ->delete();

    expect(fn () => $plan->plan((int) $escenario['store_id']))
        ->toThrow(RuntimeException::class, "le falta el rol 'revenue'");

    // Y el mensaje dice los roles que SI tiene.
    expect(fn () => $plan->plan((int) $escenario['store_id']))
        ->toThrow(RuntimeException::class, 'cash');

    // Los roles que le faltan se ven: es el hueco a la vista.
    expect($plan->missingRoles((int) $escenario['store_id']))->toBe(['revenue']);
});

/**
 * Una cuenta NO puede cumplir dos roles en la misma tienda.
 *
 * El INSERT que viola la unicidad es el ULTIMO: en PostgreSQL un error
 * dentro de una transaccion la ABORTA entera, y una consulta despues del
 * error falla con "current transaction is aborted" en vez de con el motivo
 * real.
 */
test('test_una_cuenta_no_puede_cumplir_dos_roles_en_la_misma_tienda', function () {
    // Una tienda SUYA: la de demostracion tiene los cuatro roles del plan, y
    // aqui se prueba el vinculo, no el plan.
    $tienda = Store::create(['name' => 'Tienda de una cuenta y dos roles']);

    $cuenta = cuenta_de_prueba('9900000', AccountType::Asset);

    DB::table('store_accounts')->insert([
        'account_id' => $cuenta->id,
        'store_id' => $tienda->id,
        'role' => 'cash',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('store_accounts')
        ->where('store_id', $tienda->id)
        ->where('account_id', $cuenta->id)
        ->count())->toBe(1);

    // La MISMA cuenta con OTRO rol: la base lo rechaza.
    expect(fn () => DB::table('store_accounts')->insert([
        'account_id' => $cuenta->id,
        'store_id' => $tienda->id,
        'role' => 'receivable',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/**
 * Un rol NO puede tener dos cuentas en la misma tienda.
 *
 * El INSERT que viola la unicidad es el ULTIMO: en PostgreSQL un error
 * dentro de una transaccion la ABORTA entera.
 */
test('test_un_rol_no_puede_tener_dos_cuentas_en_la_misma_tienda', function () {
    // Una tienda SUYA: la de demostracion tiene los cuatro roles del plan, y
    // aqui se prueba el vinculo, no el plan.
    $tienda = Store::create(['name' => 'Tienda de un rol y dos cuentas']);

    $primera = cuenta_de_prueba('9900001', AccountType::Asset);
    $segunda = cuenta_de_prueba('9900003', AccountType::Asset);

    DB::table('store_accounts')->insert([
        'account_id' => $primera->id,
        'store_id' => $tienda->id,
        'role' => 'cash',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('store_accounts')
        ->where('store_id', $tienda->id)
        ->where('role', 'cash')
        ->count())->toBe(1);

    expect(fn () => DB::table('store_accounts')->insert([
        'account_id' => $segunda->id,
        'store_id' => $tienda->id,
        'role' => 'cash',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/**
 * La tienda que cambia de rol a una cuenta: la invariante del tipo lo
 * prohibe.
 *
 * La cuenta 1100000 (efectivo) es un ACTIVO. Si la tienda quiere que deje de
 * ser la caja y pase a ser el ingreso (o el inventario, que no es un rol),
 * la invariante lo prohibe: el rol de los ingresos exige una cuenta de tipo
 * ingreso, y 1100000 es un activo.
 */
test('test_la_tienda_no_puede_cambiar_el_rol_de_una_cuenta_a_un_rol_de_otro_tipo', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    $efectivo = Account::query()->where('code', '1100000')->firstOrFail();

    expect((string) $efectivo->type)->toBe('asset');

    // Dejar de ser la caja para ser el ingreso: la invariante lo prohibe.
    expect(fn () => $plan->assign((int) $escenario['store_id'], (int) $efectivo->id, AccountRole::Revenue))
        ->toThrow(RuntimeException::class, "El rol 'revenue' exige una cuenta de tipo 'income'");

    // Y la cuenta sigue siendo la caja: el intento no escribio nada.
    expect((string) DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('account_id', $efectivo->id)
        ->value('role'))->toBe('cash');
});

/**
 * Un rol que ya cumple otra cuenta no se puede volver a asignar: un rol es UNO
 * por tienda.
 */
test('test_un_rol_que_ya_cumple_otra_cuenta_no_se_puede_volver_a_asignar', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    $banco = cuenta_de_prueba('9900002', AccountType::Asset);

    expect(fn () => $plan->assign((int) $escenario['store_id'], (int) $banco->id, AccountRole::Cash))
        ->toThrow(RuntimeException::class, "El rol 'cash' ya lo cumple la cuenta 1100000");

    // Y la caja sigue siendo la que era.
    expect((string) DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('role', 'cash')
        ->value('account_id'))->toBe(
            (string) Account::query()->where('code', '1100000')->value('id')
        );
});

/**
 * El COBRO falla con un mensaje util si al plan de la tienda le falta un rol.
 *
 * REGLA que fija este test: *un plan incompleto es un plan que hay que ver*.
 * El cobro no se improvisa con las cuentas que falten, y el fallo dice que
 * tienda es y que rol le falta.
 */
test('test_el_cobro_falla_con_un_mensaje_util_si_al_plan_de_la_tienda_le_falta_un_rol', function () {
    config(['fiscal.emission_enabled' => false]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // Le falta el rol de los ingresos.
    DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('role', 'revenue')
        ->delete();

    $respuesta = $this->actingAs($usuario)
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
        ]);

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    expect($respuesta->json('errorMsg'))->toContain('no tiene un plan de cuentas completo')
        ->and($respuesta->json('errorMsg'))->toContain("le falta el rol 'revenue'");

    // Y NO se cobro nada: la venta no existe.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * La CONFIGURACION no puede ser una segunda fuente de la verdad: los codigos
 * de las cuentas salen de la base de datos, no de un fichero.
 *
 * Este test es un META-TEST: lee el fichero, porque la regla es sobre el
 * TEXTO de la configuracion y no sobre su resultado.
 */
test('test_la_configuracion_no_tiene_los_codigos_de_las_cuentas', function () {
    $fuente = (string) file_get_contents(config_path('pos.php'));

    // Sin los COMENTARIOS: la regla es sobre el TEXTO del fichero.
    $codigo = preg_replace('#/\*.*?\*/#s', '', $fuente);
    $codigo = preg_replace('#//[^\n]*#', '', (string) $codigo);

    // 1. La seccion `accounts` YA NO EXISTE: el plan sale de la base.
    expect($codigo)->not->toContain("'accounts'");

    // 2. Y NINGUN codigo de cuenta vive en la configuracion.
    foreach (['1100000', '1200000', '2110000', '4010000'] as $codigoDeCuenta) {
        expect($codigo)->not->toContain($codigoDeCuenta);
    }

    // 3. Y el mapa de rol a cuenta tampoco: los ROLES son del dominio.
    foreach (['revenue', 'tax_payable', 'receivable'] as $rol) {
        expect($codigo)->not->toContain("'{$rol}' =>");
    }
});
