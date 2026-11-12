<?php

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\LedgerWriter;
use App\Models\Account;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El ALTA y la BAJA del CATALOGO de cuentas: entrar una cuenta en el
 * catalogo, apagarla, encenderla y borrarla.
 *
 * Reglas que fija:
 *
 *  - el CODIGO de una cuenta es su clave natural y es UNICO en todo el
 *    catalogo, y se NORMALIZA: sin espacios alrededor y en mayusculas, porque
 *    un codigo contable con espacios es un codigo que se teclea mal, y dos
 *    cuentas tecleadas distinto son la misma cuenta con dos nombres;
 *  - una cuenta con MOVIMIENTOS contables NO se borra: se APAGA. El
 *    historico manda, y `ledger_lines.account_code` es un TEXTO y no una
 *    clave foranea: la cuenta se busca por su codigo, no por su id;
 *  - una cuenta que CUMPLE UN ROL no se borra: hay que quitarle el rol
 *    primero, porque el plan de la tienda depende de ella;
 *  - una cuenta con VINCULOS (aunque no cumpla un rol) tampoco se borra: el
 *    vinculo es el plan de la tienda;
 *  - APAGAR una cuenta la saca de los roles de la tienda, y el plan puede
 *    quedar incompleto: eso se ve. ENCENDERla la devuelve.
 */

/**
 * Una cuenta con UN MOVIMIENTO contable, para probar que el historico la
 * ata.
 */
function movimiento_contable_de_la_cuenta(int $storeId, string $codigo): void
{
    (new LedgerWriter(DB::connection()))->write(
        'sale',
        $storeId,
        new DateTimeImmutable('2026-11-12T10:00:00-04:00'),
        new DateTimeImmutable('2026-11-12'),
        [
            ['account' => $codigo, 'debit' => '10.0000', 'credit' => '0.0000'],
            ['account' => '4010000', 'debit' => '0.0000', 'credit' => '10.0000'],
        ],
    );
}

/**
 * El ALTA crea la cuenta y NORMALIZA su codigo: sin espacios alrededor y en
 * mayusculas.
 */
test('test_el_alta_crea_la_cuenta_y_normaliza_el_codigo', function () {
    $plan = new ChartOfAccounts(DB::connection());

    $id = $plan->create('  550000  ', 'Impuesto de retencion', AccountType::Liability);

    expect($id)->toBeGreaterThan(0);

    $cuenta = Account::query()->findOrFail($id);

    // El CODIGO queda normalizado: sin espacios y en mayusculas.
    expect((string) $cuenta->code)->toBe('550000')
        ->and((string) $cuenta->name)->toBe('Impuesto de retencion')
        ->and((string) $cuenta->type)->toBe('liability')
        // Y la cuenta nace ACTIVA: una cuenta nueva cumple lo que se le asigne.
        ->and((bool) $cuenta->is_active)->toBeTrue();
});

/**
 * El codigo es la clave natural: dos cuentas con el mismo codigo son la misma
 * cuenta, y el dominio lo dice con SUS palabras, no con las de PostgreSQL.
 */
test('test_un_codigo_repetido_falla_con_un_mensaje_del_dominio', function () {
    $plan = new ChartOfAccounts(DB::connection());

    $plan->create('550000', 'Impuesto de retencion', AccountType::Liability);

    $fallo = null;

    try {
        $plan->create('550000', 'Otra cuenta con el mismo codigo', AccountType::Asset);
    } catch (Throwable $e) {
        $fallo = $e;
    }

    expect($fallo)->toBeInstanceOf(RuntimeException::class)
        // Y NO es el error de PostgreSQL: el dominio lo dice con sus palabras.
        ->and($fallo)->not->toBeInstanceOf(QueryException::class)
        ->and($fallo->getMessage())->toContain('Ya existe una cuenta con el código 550000');

    // Y la cuenta NO se duplico: el codigo es UNIQUE en el catalogo.
    expect(Account::query()->where('code', '550000')->count())->toBe(1);
});

/**
 * Un codigo VACIO no es un codigo: es la clave natural de la cuenta, y una
 * clave natural vacia no identifica nada.
 */
test('test_un_codigo_vacio_falla', function () {
    $plan = new ChartOfAccounts(DB::connection());

    expect(fn () => $plan->create('   ', 'Una cuenta sin codigo', AccountType::Asset))
        ->toThrow(RuntimeException::class, 'El código de la cuenta no puede estar vacío');

    // Y no se crea nada.
    expect(Account::query()->count())->toBe(0);
});

/**
 * Un codigo mas largo que la columna no cabe: el dominio lo dice antes de
 * que lo diga PostgreSQL.
 */
test('test_un_codigo_demasiado_largo_falla', function () {
    $plan = new ChartOfAccounts(DB::connection());

    expect(fn () => $plan->create(str_repeat('5', 31), 'Una cuenta con un codigo imposible', AccountType::Asset))
        ->toThrow(RuntimeException::class, 'El código de la cuenta no puede tener más de 30 caracteres');
});

/**
 * Una cuenta sin nombre no se puede distinguir de otra sin nombre.
 */
test('test_un_nombre_vacio_falla', function () {
    $plan = new ChartOfAccounts(DB::connection());

    expect(fn () => $plan->create('550000', '   ', AccountType::Asset))
        ->toThrow(RuntimeException::class, 'El nombre de la cuenta no puede estar vacío');

    expect(Account::query()->count())->toBe(0);
});

/**
 * APAGAR una cuenta la saca de los roles de la tienda: deja de cumplir NINGUN
 * rol, y el plan puede quedarse incompleto. Eso se ve.
 *
 * Y sus vinculos NO se tocan: el rol sigue declarado, pero la cuenta apagada
 * no lo cumple.
 */
test('test_apagar_una_cuenta_la_saca_de_los_roles_de_la_tienda', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    $efectivo = Account::query()->where('code', '1100000')->firstOrFail();

    // Antes de apagarla, la tienda la tiene como caja.
    expect($plan->roles((int) $escenario['store_id']))->toHaveKey('cash', '1100000');

    $rolesDejados = $plan->deactivate((int) $efectivo->id);

    // La cuenta deja de cumplir el rol, y el hueco se ve.
    expect($plan->roles((int) $escenario['store_id']))->not->toHaveKey('cash')
        ->and($plan->missingRoles((int) $escenario['store_id']))->toContain('cash');

    // Y el dominio dice que roles ha dejado de cumplir: el plan de esa
    // tienda puede quedarse incompleto.
    expect($rolesDejados)->toBe([(int) $escenario['store_id'] => 'cash']);

    // El VINCULO sigue: el rol sigue declarado en el plan de la tienda.
    expect((string) DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('account_id', $efectivo->id)
        ->value('role'))->toBe('cash');
});

/**
 * ENCENDER es lo inverso: la cuenta vuelve a cumplir su rol y el plan se
 * completa.
 */
test('test_encender_una_cuenta_la_devuelve_a_los_roles_de_la_tienda', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    $efectivo = Account::query()->where('code', '1100000')->firstOrFail();

    $plan->deactivate((int) $efectivo->id);

    expect($plan->missingRoles((int) $escenario['store_id']))->toContain('cash');

    $rolesCumplidos = $plan->activate((int) $efectivo->id);

    expect($plan->roles((int) $escenario['store_id']))->toHaveKey('cash', '1100000')
        ->and($plan->missingRoles((int) $escenario['store_id']))->toBe([])
        ->and($rolesCumplidos)->toBe([(int) $escenario['store_id'] => 'cash']);
});

/**
 * Una cuenta con MOVIMIENTOS contables no se borra: se apaga. El historico
 * la necesita, y `ledger_lines.account_code` es un TEXTO: la cuenta se busca
 * por su codigo, no por su id.
 */
test('test_una_cuenta_con_movimientos_contables_no_se_puede_borrar', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    $id = $plan->create('550001', 'Cuenta con movimientos', AccountType::Expense);

    movimiento_contable_de_la_cuenta((int) $escenario['store_id'], '550001');

    expect(fn () => $plan->delete($id))
        ->toThrow(RuntimeException::class, 'La cuenta 550001 tiene movimientos contables: no se puede eliminar, solo se puede desactivar.');

    // Y la cuenta SIGUE: el historico la necesita.
    expect(Account::query()->where('id', $id)->exists())->toBeTrue();
});

/**
 * Una cuenta que CUMPLE UN ROL no se borra: el plan de la tienda depende de
 * ella, y hay que quitarle el rol primero.
 */
test('test_una_cuenta_que_cumple_un_rol_no_se_puede_borrar', function () {
    $escenario = PosFixture::escenario();
    $plan = new ChartOfAccounts(DB::connection());

    $efectivo = Account::query()->where('code', '1100000')->firstOrFail();

    expect(fn () => $plan->delete((int) $efectivo->id))
        ->toThrow(
            RuntimeException::class,
            "La cuenta 1100000 cumple el rol 'cash' en la tienda {$escenario['store_id']}: quítale el rol antes de eliminarla."
        );

    // Quitada del plan, la cuenta ya no ata a nadie y se puede borrar.
    $plan->detach((int) $escenario['store_id'], (int) $efectivo->id);

    $plan->delete((int) $efectivo->id);

    expect(Account::query()->where('id', $efectivo->id)->exists())->toBeFalse();
});

/**
 * Una cuenta con VINCULOS, aunque no cumpla un rol activo, tampoco se borra:
 * el vinculo es el plan de la tienda.
 */
test('test_una_cuenta_con_vinculos_sin_rol_activo_no_se_puede_borrar', function () {
    $plan = new ChartOfAccounts(DB::connection());

    $tienda = Store::create(['name' => 'Tienda del vinculo que no cumple']);

    $id = $plan->create('550002', 'Cuenta con un vinculo', AccountType::Asset);

    DB::table('store_accounts')->insert([
        'account_id' => $id,
        'store_id' => $tienda->id,
        'role' => 'cash',
        'status' => 'inactive',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $plan->delete($id))
        ->toThrow(
            RuntimeException::class,
            "La cuenta 550002 tiene un plan en la tienda {$tienda->id}: quítale el rol antes de eliminarla."
        );

    expect(Account::query()->where('id', $id)->exists())->toBeTrue();
});

/**
 * Una cuenta NUNCA USADA se borra de verdad: no tiene movimientos, no tiene
 * vinculos y no cumple ningun rol.
 */
test('test_una_cuenta_nunca_usada_se_borra_de_verdad', function () {
    $plan = new ChartOfAccounts(DB::connection());

    $id = $plan->create('550003', 'Cuenta que no se usa', AccountType::Expense);

    expect(Account::query()->where('id', $id)->exists())->toBeTrue();

    $plan->delete($id);

    expect(Account::query()->where('id', $id)->exists())->toBeFalse();
});
