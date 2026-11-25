<?php

use App\Domain\Accounting\CashDay;
use App\Domain\Accounting\ExpenseService;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El GASTO: su fuente de verdad, su asiento y la regla de la caja.
 *
 * Reglas que fija:
 *
 *  - el GASTO NACE en `expenses` y su asiento es la CONSECUENCIA: el debe va
 *    a la cuenta de gasto de la categoria y el haber a la cuenta que paga;
 *  - el SALDO EN CURSO DE LA CAJA DEL DIA solo se mueve con el EFECTIVO: un
 *    gasto pagado desde el banco acredita la cuenta del banco y NO aparece en
 *    el saldo del cajon, porque el dinero nunca estuvo ahi;
 *  - el gasto ANULADO se MARCA, no se borra, y su ajuste es el ESPEJO de su
 *    asiento: las mismas lineas con el debe y el haber cambiados;
 *  - un gasto SIN CUENTA donde cargarse, o de un dia sin caja abierta, no se
 *    registra: no se asienta a medias.
 */

/**
 * El saldo en curso de la caja del dia, con el dinero de la tienda.
 */
function saldo_de_caja(int $storeId, DateTimeImmutable $negocio): string
{
    return (new CashDay(DB::connection()))->runningBalance($storeId, $negocio, '1100000');
}

/**
 * El GASTO nace en `expenses` y su ASIENTO es la consecuencia.
 */
test('test_el_gasto_nace_en_expenses_y_su_asiento_es_la_consecuencia', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $categoria = (int) DB::table('expense_categories')->where('code', 'GASTO-ADMIN')->value('id');

    $servicio = new ExpenseService(DB::connection());

    $gastoId = $servicio->register(
        storeId: (int) $escenario['store_id'],
        amount: '75.5000',
        businessDate: $negocio,
        paymentType: 'cash',
        expenseCategoryId: $categoria,
        description: 'Papeleria de la tienda',
        reference: 'FAC-0001',
        actorId: (int) $usuario->id,
    );

    // 1. El GASTO esta en `expenses`: el hecho, con su importe y su fecha.
    $gasto = DB::table('expenses')->where('id', $gastoId)->first();

    expect($gasto)->not->toBeNull()
        ->and((string) $gasto->amount)->toBe('75.5000')
        ->and((string) $gasto->business_date)->toBe($negocio->format('Y-m-d'))
        ->and((string) $gasto->status)->toBe('recorded')
        ->and((string) $gasto->payment_type)->toBe('cash')
        ->and((int) $gasto->expense_category_id)->toBe($categoria);

    // 2. Y el ASIENTO es del tipo del catalogo, y referencia el gasto.
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'expense')
        ->where('ledger_entries.reference_id', $gastoId)
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect($asiento)->not->toBeNull()
        ->and((string) $asiento->type_code)->toBe('expense')
        ->and(bccomp((string) $asiento->amount, '75.5000', 4))->toBe(0)
        ->and((string) $asiento->business_date)->toBe($negocio->format('Y-m-d'));

    // 3. Y SUS LINEAS son las que la regla dice: el debe, a la cuenta de
    //    gasto de la categoria; el haber, a la caja (se pago en efectivo).
    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $asiento->id)->orderBy('id')->get();

    expect($lineas)->toHaveCount(2);

    $gastoLinea = $lineas->firstWhere('account_code', '5110000');
    $cajaLinea = $lineas->firstWhere('account_code', '1100000');

    expect((string) $gastoLinea->debit)->toBe('75.5000')
        ->and((string) $gastoLinea->credit)->toBe('0.0000')
        ->and((string) $cajaLinea->credit)->toBe('75.5000')
        ->and((string) $cajaLinea->debit)->toBe('0.0000');
});

/**
 * Un gasto pagado EN EFECTIVO mueve el saldo en curso de la caja del dia.
 */
test('test_un_gasto_pagado_en_efectivo_mueve_el_saldo_de_la_caja_del_dia', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $antes = saldo_de_caja((int) $escenario['store_id'], $negocio);

    $categoria = (int) DB::table('expense_categories')->where('code', 'GASTO-ADMIN')->value('id');

    (new ExpenseService(DB::connection()))->register(
        storeId: (int) $escenario['store_id'],
        amount: '20.0000',
        businessDate: $negocio,
        paymentType: 'cash',
        expenseCategoryId: $categoria,
        actorId: (int) $usuario->id,
    );

    $despues = saldo_de_caja((int) $escenario['store_id'], $negocio);

    // El efectivo SALIO del cajon: el saldo baja exactamente lo que gasto.
    expect(bccomp($antes, $despues, 4))->not->toBe(0)
        ->and(bccomp($antes, $despues, 4))->toBe(1)
        ->and(bccomp(bcsub($antes, '20.0000', 4), $despues, 4))->toBe(0);
});

/**
 * Un gasto pagado DESDE EL BANCO NO mueve el saldo de la caja del dia.
 */
test('test_un_gasto_pagado_desde_el_banco_no_mueve_el_saldo_de_la_caja_del_dia', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $antes = saldo_de_caja((int) $escenario['store_id'], $negocio);

    $categoria = (int) DB::table('expense_categories')->where('code', 'GASTO-ADMIN')->value('id');
    $banco = (int) DB::table('bank_accounts')->where('name', 'Cuenta bancaria de la tienda de demostracion')->value('id');

    (new ExpenseService(DB::connection()))->register(
        storeId: (int) $escenario['store_id'],
        amount: '30.0000',
        businessDate: $negocio,
        paymentType: 'bank',
        bankAccountId: $banco,
        expenseCategoryId: $categoria,
        actorId: (int) $usuario->id,
    );

    // El saldo de la CAJA no se mueve: el dinero nunca estuvo en el cajon.
    expect(bccomp($antes, saldo_de_caja((int) $escenario['store_id'], $negocio), 4))->toBe(0);

    // Y el asiento acredita la cuenta del BANCO, no la de la caja.
    $lineas = DB::table('ledger_lines')
        ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
        ->where('ledger_entries.reference_type', 'expense')
        ->where('ledger_entries.store_id', $escenario['store_id'])
        ->get();

    expect($lineas->pluck('account_code')->all())->toContain('1110000')
        ->and($lineas->pluck('account_code')->all())->not->toContain('1100000');
});

/**
 * Un gasto ANULADO se MARCA, no se borra, y su ajuste es el espejo.
 */
test('test_un_gasto_anulado_se_marca_y_no_se_borra_y_su_asiento_es_el_espejo', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $categoria = (int) DB::table('expense_categories')->where('code', 'GASTO-ADMIN')->value('id');
    $servicio = new ExpenseService(DB::connection());

    // El saldo ANTES del gasto: es el que tiene que volver.
    $antes = saldo_de_caja((int) $escenario['store_id'], $negocio);

    $gastoId = $servicio->register(
        storeId: (int) $escenario['store_id'],
        amount: '40.0000',
        businessDate: $negocio,
        paymentType: 'cash',
        expenseCategoryId: $categoria,
        actorId: (int) $usuario->id,
    );

    // Con el gasto registrado, la caja ya no es la de antes.
    expect(bccomp($antes, saldo_de_caja((int) $escenario['store_id'], $negocio), 4))->not->toBe(0);

    $servicio->cancel($gastoId, 'Se registro por duplicado', (int) $usuario->id);

    // 1. El gasto NO se borra: se MARCA, con su motivo y su responsable.
    $gasto = DB::table('expenses')->where('id', $gastoId)->first();

    expect($gasto)->not->toBeNull()
        ->and((string) $gasto->status)->toBe('cancelled')
        ->and((string) $gasto->cancellation_reason)->toBe('Se registro por duplicado')
        ->and((int) $gasto->cancelled_by)->toBe((int) $usuario->id)
        ->and($gasto->cancelled_at)->not->toBeNull();

    // 2. Y su AJUSTE es el espejo del asiento del gasto: el dinero vuelve a
    //    la cuenta que lo pago.
    $ajuste = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'expense')
        ->where('ledger_entries.reference_id', $gastoId)
        ->where('ledger_entry_types.code', 'adjustment')
        ->select('ledger_entries.*')
        ->first();

    expect($ajuste)->not->toBeNull();

    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $ajuste->id)->orderBy('id')->get();

    $gastoLinea = $lineas->firstWhere('account_code', '5110000');
    $cajaLinea = $lineas->firstWhere('account_code', '1100000');

    expect((string) $gastoLinea->credit)->toBe('40.0000')
        ->and((string) $gastoLinea->debit)->toBe('0.0000')
        ->and((string) $cajaLinea->debit)->toBe('40.0000')
        ->and((string) $cajaLinea->credit)->toBe('0.0000');

    // 3. Y la caja del dia vuelve a estar como estaba: el dinero vuelve al
    //    cajon.
    expect(bccomp($antes, saldo_de_caja((int) $escenario['store_id'], $negocio), 4))->toBe(0);
});

/**
 * Un gasto SIN CUENTA donde cargarse no se registra.
 */
test('test_un_gasto_sin_categoria_con_cuenta_contable_no_se_registra', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    // Una categoria SIN cuenta contable: no hay donde cargarlo.
    $categoria = (int) DB::table('expense_categories')->insertGetId([
        'code' => 'GASTO-SIN-CUENTA',
        'name' => 'Gasto sin cuenta',
        'is_active' => true,
        'account_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $servicio = new ExpenseService(DB::connection());

    expect(fn () => $servicio->register(
        storeId: (int) $escenario['store_id'],
        amount: '10.0000',
        businessDate: $negocio,
        paymentType: 'cash',
        expenseCategoryId: $categoria,
    ))->toThrow(RuntimeException::class, 'no tiene cuenta contable');

    // Y NO se escribio nada: ni gasto ni asiento.
    expect(DB::table('expenses')->where('expense_category_id', $categoria)->count())->toBe(0)
        ->and(DB::table('ledger_entries')->where('reference_type', 'expense')->count())->toBe(0);
});

/**
 * Un gasto EN EFECTIVO de un dia SIN CAJA ABIERTA no se registra.
 */
test('test_un_gasto_en_efectivo_de_un_dia_sin_caja_abierta_no_se_registra', function () {
    $escenario = PosFixture::escenario();

    // Un usuario SIN caja abierta: el escenario del gasto sin caja.
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $categoria = (int) DB::table('expense_categories')->where('code', 'GASTO-ADMIN')->value('id');

    $servicio = new ExpenseService(DB::connection());

    expect(fn () => $servicio->register(
        storeId: (int) $escenario['store_id'],
        amount: '10.0000',
        businessDate: $negocio,
        paymentType: 'cash',
        expenseCategoryId: $categoria,
    ))->toThrow(RuntimeException::class, 'no tiene caja para la fecha de negocio');

    expect(DB::table('expenses')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * Un gasto se ANULA con un MOTIVO, y una sola vez.
 */
test('test_un_gasto_se_anula_con_un_motivo_y_una_sola_vez', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $negocio = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate();

    $categoria = (int) DB::table('expense_categories')->where('code', 'GASTO-ADMIN')->value('id');
    $servicio = new ExpenseService(DB::connection());

    $gastoId = $servicio->register(
        storeId: (int) $escenario['store_id'],
        amount: '12.0000',
        businessDate: $negocio,
        paymentType: 'cash',
        expenseCategoryId: $categoria,
    );

    // Sin motivo, no se anula.
    expect(fn () => $servicio->cancel($gastoId, '   '))
        ->toThrow(RuntimeException::class, 'se anula con un MOTIVO');

    // Y el gasto sigue en pie: el rechazo no escribe nada.
    expect((string) DB::table('expenses')->where('id', $gastoId)->value('status'))->toBe('recorded');

    $servicio->cancel($gastoId, 'Error de tecleo', (int) $usuario->id);

    // Y anular dos veces es un error: la anulacion es un HECHO.
    expect(fn () => $servicio->cancel($gastoId, 'otra vez'))
        ->toThrow(RuntimeException::class, 'ya esta anulado');
});
