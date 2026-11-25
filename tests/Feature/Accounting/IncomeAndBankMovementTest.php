<?php

use App\Domain\Accounting\BankMovementService;
use App\Domain\Accounting\CashDay;
use App\Domain\Accounting\IncomeService;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El INGRESO y el MOVIMIENTO BANCARIO: su asiento y la regla de la caja.
 *
 * Reglas que fija:
 *
 *  - el INGRESO nace en `incomes` y su asiento ABONA la cuenta de ingreso que
 *    declara el origen, y DEBITA la cuenta que recibe el dinero (la caja si
 *    entra en el cajon, la cuenta bancaria si entra en el banco);
 *  - un MOVIMIENTO BANCARIO mueve dinero entre las cuentas de la tesoreria de
 *    la tienda: el debe, a la cuenta que recibe; el haber, a la que cede;
 *  - el saldo en curso de la caja del dia solo se mueve con el EFECTIVO: un
 *    traspaso entre dos bancos no lo mueve, y uno que toca la caja si, porque
 *    la caja es una de las dos cuentas del movimiento;
 *  - un ingreso ANULADO se MARCA, no se borra, y su ajuste es el espejo de su
 *    asiento.
 */

/**
 * El saldo en curso de la caja del dia, con el dinero de la tienda.
 */
function saldo_de_caja_del_ingreso(int $storeId, DateTimeImmutable $negocio): string
{
    return (new CashDay(DB::connection()))->runningBalance($storeId, $negocio, '1100000');
}

/**
 * La cuenta bancaria de demostracion, con su id.
 */
function banco_de_demostracion(): int
{
    return (int) DB::table('bank_accounts')
        ->where('name', 'Cuenta bancaria de la tienda de demostracion')
        ->value('id');
}

/**
 * El INGRESO nace en `incomes` y su asiento abona la cuenta del origen.
 */
test('test_el_ingreso_nace_en_incomes_y_su_asiento_abona_la_cuenta_del_origen', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $origen = (int) DB::table('income_sources')->where('code', 'INGRESO-OTROS')->value('id');

    $ingresoId = (new IncomeService(DB::connection()))->register(
        storeId: (int) $escenario['store_id'],
        amount: '50.0000',
        businessDate: $negocio,
        paymentType: 'cash',
        incomeSourceId: $origen,
        description: 'Alquiler cobrado',
        actorId: (int) $usuario->id,
    );

    // 1. El INGRESO esta en `incomes`: el hecho, con su importe y su origen.
    $ingreso = DB::table('incomes')->where('id', $ingresoId)->first();

    expect($ingreso)->not->toBeNull()
        ->and((string) $ingreso->amount)->toBe('50.0000')
        ->and((int) $ingreso->income_source_id)->toBe($origen)
        ->and((string) $ingreso->status)->toBe('recorded')
        ->and((string) $ingreso->business_date)->toBe($negocio->format('Y-m-d'));

    // 2. Y el ASIENTо es del tipo del catalogo y referencia el ingreso.
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'income')
        ->where('ledger_entries.reference_id', $ingresoId)
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect($asiento)->not->toBeNull()
        ->and((string) $asiento->type_code)->toBe('bank_movement')
        ->and(bccomp((string) $asiento->amount, '50.0000', 4))->toBe(0);

    // 3. Y SUS LINEAS: el debe, a la caja que recibe; el haber, a la cuenta
    //    de ingreso del origen.
    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $asiento->id)->orderBy('id')->get();

    $cajaLinea = $lineas->firstWhere('account_code', '1100000');
    $ingresoLinea = $lineas->firstWhere('account_code', '4020000');

    expect((string) $cajaLinea->debit)->toBe('50.0000')
        ->and((string) $cajaLinea->credit)->toBe('0.0000')
        ->and((string) $ingresoLinea->credit)->toBe('50.0000')
        ->and((string) $ingresoLinea->debit)->toBe('0.0000');
});

/**
 * Un ingreso en EFECTIVO aumenta el saldo de la caja del dia, y uno del BANCO
 * no lo toca.
 */
test('test_un_ingreso_en_efectivo_aumenta_el_saldo_de_la_caja_y_uno_del_banco_no', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $origen = (int) DB::table('income_sources')->where('code', 'INGRESO-OTROS')->value('id');
    $servicio = new IncomeService(DB::connection());

    $antes = saldo_de_caja_del_ingreso((int) $escenario['store_id'], $negocio);

    // 1. El ingreso que entra en el CAJON: la caja sube.
    $servicio->register(
        storeId: (int) $escenario['store_id'],
        amount: '25.0000',
        businessDate: $negocio,
        paymentType: 'cash',
        incomeSourceId: $origen,
    );

    $despuesDelEfectivo = saldo_de_caja_del_ingreso((int) $escenario['store_id'], $negocio);

    expect(bccomp(bcadd($antes, '25.0000', 4), $despuesDelEfectivo, 4))->toBe(0);

    // 2. Y el ingreso que entra en el BANCO: la caja no se mueve.
    $servicio->register(
        storeId: (int) $escenario['store_id'],
        amount: '80.0000',
        businessDate: $negocio,
        paymentType: 'bank',
        bankAccountId: banco_de_demostracion(),
        incomeSourceId: $origen,
    );

    expect(bccomp($despuesDelEfectivo, saldo_de_caja_del_ingreso((int) $escenario['store_id'], $negocio), 4))->toBe(0);
});

/**
 * Un MOVIMIENTO BANCARIO entre dos cuentas bancarias mueve el dinero entre
 * ellas y NO toca la caja del dia.
 */
test('test_un_movimiento_bancario_mueve_el_dinero_entre_las_cuentas_y_no_toca_la_caja', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $antes = saldo_de_caja_del_ingreso((int) $escenario['store_id'], $negocio);

    // Una SEGUNDA cuenta bancaria de la tienda, con SU PROPIA cuenta
    // contable: dos cuentas bancarias que comparten la cuenta del libro no
    // son dos cuentas, y mover dinero de una a si misma no es un movimiento.
    $cuentaDelOtro = DB::table('accounts')->insertGetId([
        'code' => '1120000',
        'name' => 'Banco secundario de demostracion',
        'type' => 'asset',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otroBanco = (int) DB::table('bank_accounts')->insertGetId([
        'name' => 'Cuenta bancaria secundaria de demostracion',
        'number' => null,
        'tax_id' => null,
        'is_active' => true,
        'opening_balance' => '0.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('bank_account_stores')->insert([
        'bank_account_id' => $otroBanco,
        'store_id' => $escenario['store_id'],
        'status' => 'active',
        'sort_order' => 2,
        'opening_balance' => '0.0000',
        'account_code' => '1120000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $asientoId = (new BankMovementService(DB::connection()))->move(
        storeId: (int) $escenario['store_id'],
        amount: '100.0000',
        businessDate: $negocio,
        fromType: 'bank',
        fromBankAccountId: banco_de_demostracion(),
        toType: 'bank',
        toBankAccountId: $otroBanco,
        actorId: (int) $usuario->id,
    );

    // El asiento es del tipo del catalogo y mueve el dinero entre las dos
    // cuentas bancarias.
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.id', $asientoId)
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect((string) $asiento->type_code)->toBe('bank_movement')
        ->and((string) $asiento->reference_type)->toBe('bank_movement');

    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $asientoId)->get();

    expect($lineas)->toHaveCount(2)
        ->and((string) $lineas->firstWhere('debit', '100.0000')->account_code)->toBe('1120000')
        ->and((string) $lineas->firstWhere('credit', '100.0000')->account_code)->toBe('1110000');

    // Y la CAJA del dia no se ha movido: no hay caja en el medio.
    expect(bccomp($antes, saldo_de_caja_del_ingreso((int) $escenario['store_id'], $negocio), 4))->toBe(0);
});

/**
 * Un MOVIMIENTO BANCARIO entre la CAJA y el BANCO mueve el saldo de la caja.
 */
test('test_un_movimiento_bancario_entre_la_caja_y_el_banco_mueve_el_saldo_de_la_caja', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $antes = saldo_de_caja_del_ingreso((int) $escenario['store_id'], $negocio);

    // El DEPOSITO del cajon al banco: la caja baja.
    (new BankMovementService(DB::connection()))->move(
        storeId: (int) $escenario['store_id'],
        amount: '60.0000',
        businessDate: $negocio,
        fromType: 'cash',
        toType: 'bank',
        toBankAccountId: banco_de_demostracion(),
        description: 'Deposito al banco',
        actorId: (int) $usuario->id,
    );

    $despues = saldo_de_caja_del_ingreso((int) $escenario['store_id'], $negocio);

    expect(bccomp($antes, $despues, 4))->toBe(1)
        ->and(bccomp(bcsub($antes, '60.0000', 4), $despues, 4))->toBe(0);
});

/**
 * Un movimiento a la MISMA cuenta no es un movimiento.
 */
test('test_un_movimiento_a_la_misma_cuenta_no_se_registra', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $servicio = new BankMovementService(DB::connection());

    expect(fn () => $servicio->move(
        storeId: (int) $escenario['store_id'],
        amount: '10.0000',
        businessDate: $negocio,
        fromType: 'bank',
        fromBankAccountId: banco_de_demostracion(),
        toType: 'bank',
        toBankAccountId: banco_de_demostracion(),
    ))->toThrow(RuntimeException::class, 'mover dinero de una cuenta a si misma no es un movimiento');

    expect(DB::table('ledger_entries')->where('reference_type', 'bank_movement')->count())->toBe(0);
});

/**
 * Un ingreso ANULADO se MARCA, no se borra, y su ajuste es el espejo.
 */
test('test_un_ingreso_anulado_se_marca_y_no_se_borra_y_su_asiento_es_el_espejo', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $origen = (int) DB::table('income_sources')->where('code', 'INGRESO-OTROS')->value('id');
    $servicio = new IncomeService(DB::connection());

    $antes = saldo_de_caja_del_ingreso((int) $escenario['store_id'], $negocio);

    $ingresoId = $servicio->register(
        storeId: (int) $escenario['store_id'],
        amount: '35.0000',
        businessDate: $negocio,
        paymentType: 'cash',
        incomeSourceId: $origen,
    );

    $servicio->cancel($ingresoId, 'Se registro por duplicado', (int) $usuario->id);

    // El ingreso NO se borra: se MARCA, con su motivo.
    $ingreso = DB::table('incomes')->where('id', $ingresoId)->first();

    expect($ingreso)->not->toBeNull()
        ->and((string) $ingreso->status)->toBe('cancelled')
        ->and((string) $ingreso->cancellation_reason)->toBe('Se registro por duplicado');

    // Y su ajuste es el espejo: el dinero sale de la caja.
    $ajuste = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'income')
        ->where('ledger_entries.reference_id', $ingresoId)
        ->where('ledger_entry_types.code', 'adjustment')
        ->select('ledger_entries.id as id')
        ->first();

    expect($ajuste)->not->toBeNull();

    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $ajuste->id)->get();

    expect((string) $lineas->firstWhere('account_code', '1100000')->credit)->toBe('35.0000')
        ->and((string) $lineas->firstWhere('account_code', '4020000')->debit)->toBe('35.0000');

    // Y la caja del dia vuelve a estar como estaba.
    expect(bccomp($antes, saldo_de_caja_del_ingreso((int) $escenario['store_id'], $negocio), 4))->toBe(0);
});
