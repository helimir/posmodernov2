<?php

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\LedgerWriter;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Credit\CustomerPaymentService;
use App\Domain\Credit\InstallmentCollectionService;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\InstallmentFixture;
use Tests\Fixtures\PosFixture;

/**
 * F3-05 — el COBRO de una cuota.
 *
 * REGLA que fija este fichero: *el cobro de una cuota es un COBRO del libro del
 * cliente, y es UNA ESCRITURA*: la cuota cobrada, el plan, el movimiento del
 * libro del cliente y el asiento contable se escriben juntos o no se escribe
 * ninguno.
 *
 * Y LOS TRES P0 DEL LEGACY QUE ESTA UNIDAD CIERRA:
 *
 *  - **#2**: el legacy hacia `UPDATE installment_orders SET
 *    last_installment_date = ?` **sin `WHERE invoice_id`**, y actualizaba
 *    TODAS las filas de la tabla. Aqui el cobro toca **UNA** cuota y **UN**
 *    plan, y las demas cuotas del mismo plan tampoco se tocan.
 *  - **#3**: el legacy hacia `UPDATE selling_price ... WHERE invoice_id = ?`
 *    **sin `store_id`**, y el pago de una cuota tocaba facturas de OTRAS
 *    tiendas. Aqui el cobro es **de la tienda de la cuota**: una cuota de otra
 *    tienda no se cobra desde esta.
 *  - **#4**: la cascada del legacy corria sobre **8 tablas SIN transaccion**.
 *    Aqui el cobro es **UNA transaccion**: un fallo a mitad no deja la cuota
 *    cobrada ni el saldo descuadrado.
 */

/**
 * El COBRO de una cuota es el COBRO del libro del cliente: el movimiento
 * (`payment`, NEGATIVO) y el asiento `sale_payment` (DEBE caja / HABER
 * cartera).
 *
 * Y EL SALDO del cliente BAJA por lo cobrado, y el cache de su ficha se
 * refresca con el.
 */
test('test_el_cobro_de_una_cuota_es_un_cobro_del_libro_del_cliente_con_su_asiento_sale_payment', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '1000.0000');
    $planId = InstallmentFixture::plan($storeId, $customerId, '1000.0000', '0.0000', 3, '2026-12-01', $saleId);
    $scheduleId = InstallmentFixture::cuota($planId, 1);

    $balances = new CustomerBalanceService(DB::connection());

    expect($balances->balance($customerId, $storeId))->toBe('1000.0000');

    $resultado = (new InstallmentCollectionService(
        DB::connection(),
        $balances,
        new CustomerPaymentService(DB::connection(), $balances, new LedgerWriter(DB::connection())),
    ))->collect(
        scheduleId: $scheduleId,
        storeId: $storeId,
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        actorId: $usuario->id,
    );

    // 1. EL MOVIMIENTO del libro del cliente: un cobro, NEGATIVO, y el saldo
    //    derivado baja.
    $movimiento = DB::table('customer_ledger_entries')
        ->where('customer_id', $customerId)
        ->where('store_id', $storeId)
        ->where('type', 'payment')
        ->first();

    expect($movimiento)->not->toBeNull()
        ->and((string) $movimiento->amount)->toBe('-333.3333')
        ->and((int) $movimiento->ref_invoice_id)->toBe($saleId);

    expect($balances->balance($customerId, $storeId))->toBe('666.6667')
        ->and($balances->due($customerId, $storeId))->toBe('666.6667')
        ->and($balances->holds($customerId, $storeId))->toBeTrue();

    // 2. EL ASIENTO: tipo `sale_payment`, DEBE la caja y HABER la cartera, y
    //    en la TIENDA del cobro.
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'customer_payment')
        ->where('ledger_entries.reference_id', $movimiento->id)
        ->first(['ledger_entries.*', 'ledger_entry_types.code as type_code']);

    expect($asiento)->not->toBeNull()
        ->and((string) $asiento->type_code)->toBe('sale_payment')
        ->and((int) $asiento->store_id)->toBe($storeId)
        ->and((string) $asiento->amount)->toBe('333.3333');

    $lineas = DB::table('ledger_lines')
        ->where('ledger_entry_id', (int) $asiento->id)
        ->orderBy('id')
        ->get();

    expect($lineas)->toHaveCount(2);

    $roles = (new ChartOfAccounts(DB::connection()))->roles($storeId);

    expect((string) $lineas[0]->account_code)->toBe($roles['cash'])
        ->and((string) $lineas[0]->debit)->toBe('333.3333')
        ->and((string) $lineas[0]->credit)->toBe('0.0000')
        ->and((string) $lineas[1]->account_code)->toBe($roles['receivable'])
        ->and((string) $lineas[1]->debit)->toBe('0.0000')
        ->and((string) $lineas[1]->credit)->toBe('333.3333');

    // 3. Y el resultado del cobro dice lo que se cobro y como queda el plan.
    expect($resultado->amount)->toBe('333.3333')
        ->and($resultado->balance)->toBe('666.6667')
        ->and($resultado->planStatus)->toBe('active');
});

/**
 * P0 #2 — el cobro toca UNA cuota y UN plan.
 *
 * El legacy actualizaba `installment_orders` SIN `WHERE invoice_id`, y
 * actualizaba TODAS las filas de la tabla. Aqui el cobro marca SOLO la cuota
 * cobrada: las demas cuotas del MISMO plan y las cuotas de OTRO plan siguen
 * como estaban.
 */
test('test_el_cobro_de_una_cuota_marca_solo_esa_cuota_y_no_toca_las_demas', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '900.0000');
    $planId = InstallmentFixture::plan($storeId, $customerId, '900.0000', '0.0000', 3, '2026-12-01', $saleId);

    $otroCliente = CreditFixture::customer($storeId, 'Cliente del otro plan');
    $otraVenta = InstallmentFixture::ventaACredito($storeId, $otroCliente, '600.0000');
    $otroPlanId = InstallmentFixture::plan($storeId, $otroCliente, '600.0000', '0.0000', 2, '2026-12-01', $otraVenta);

    $scheduleId = InstallmentFixture::cuota($planId, 1);

    (new InstallmentCollectionService(
        DB::connection(),
        new CustomerBalanceService(DB::connection()),
        new CustomerPaymentService(
            DB::connection(),
            new CustomerBalanceService(DB::connection()),
            new LedgerWriter(DB::connection())
        ),
    ))->collect(
        scheduleId: $scheduleId,
        storeId: $storeId,
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        actorId: $usuario->id,
    );

    // 1. LA CUOTA COBRADA: pagada, con lo aplicado.
    $cobrada = DB::table('installment_schedules')->where('id', $scheduleId)->first();

    expect((string) $cobrada->status)->toBe('paid')
        ->and((string) $cobrada->paid_amount)->toBe('300.0000');

    // 2. LAS DEMAS CUOTAS DEL MISMO PLAN: intactas.
    $lasDeMas = DB::table('installment_schedules')
        ->where('installment_plan_id', $planId)
        ->where('id', '!=', $scheduleId)
        ->orderBy('number')
        ->get();

    expect($lasDeMas)->toHaveCount(2);

    foreach ($lasDeMas as $cuota) {
        expect((string) $cuota->status)->toBe('pending')
            ->and((string) $cuota->paid_amount)->toBe('0.0000');
    }

    // 3. Y EL OTRO PLAN: sus cuotas y su cabecera siguen como estaban. El
    //    legacy las habria tocado TODAS.
    $cuotasDelOtro = DB::table('installment_schedules')
        ->where('installment_plan_id', $otroPlanId)
        ->get();

    expect($cuotasDelOtro)->toHaveCount(2);

    foreach ($cuotasDelOtro as $cuota) {
        expect((string) $cuota->status)->toBe('pending')
            ->and((string) $cuota->paid_amount)->toBe('0.0000');
    }

    expect((string) DB::table('installment_plans')->where('id', $otroPlanId)->value('status'))->toBe('active');
});

/**
 * P0 #3 — el cobro es DE LA TIENDA de la cuota.
 *
 * El legacy hacia `UPDATE selling_price ... WHERE invoice_id = ?` SIN
 * `store_id`, y el pago de una cuota tocaba facturas de OTRAS tiendas. Aqui una
 * cuota de otra tienda no se cobra desde esta: el cobro se rechaza y no se
 * escribe nada.
 */
test('test_el_cobro_de_una_cuota_de_otra_tienda_no_se_registra', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $otraTienda = (int) DB::table('stores')->insertGetId([
        'name' => 'Tienda de al lado',
        'code_name' => 'AL-LADO-CUOTAS',
        'sort_order' => 9,
        'status' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '500.0000');
    $planId = InstallmentFixture::plan($storeId, $customerId, '500.0000', '0.0000', 2, '2026-12-01', $saleId);
    $scheduleId = InstallmentFixture::cuota($planId, 1);

    $servicio = new InstallmentCollectionService(
        DB::connection(),
        new CustomerBalanceService(DB::connection()),
        new CustomerPaymentService(
            DB::connection(),
            new CustomerBalanceService(DB::connection()),
            new LedgerWriter(DB::connection())
        ),
    );

    // El cobro desde la OTRA tienda se rechaza.
    expect(fn () => $servicio->collect(
        scheduleId: $scheduleId,
        storeId: $otraTienda,
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        actorId: $usuario->id,
    ))->toThrow(RuntimeException::class);

    // Y no se escribio NADA: ni la cuota, ni el libro del cliente, ni el
    // asiento.
    $cuota = DB::table('installment_schedules')->where('id', $scheduleId)->first();

    expect((string) $cuota->status)->toBe('pending')
        ->and((string) $cuota->paid_amount)->toBe('0.0000')
        ->and(DB::table('customer_ledger_entries')->where('customer_id', $customerId)->count())->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * P0 #4 — el cobro es UNA transaccion.
 *
 * Un fallo a mitad (el tipo de asiento apagado) no deja la cuota cobrada, ni
 * el plan pagado, ni el saldo del cliente movido: el cobro entero vuelve.
 */
test('test_un_cobro_de_cuota_a_mitad_de_camino_no_deja_la_cuota_cobrada_ni_el_saldo_descuadrado', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '900.0000');
    $planId = InstallmentFixture::plan($storeId, $customerId, '900.0000', '0.0000', 3, '2026-12-01', $saleId);
    $scheduleId = InstallmentFixture::cuota($planId, 1);

    $balances = new CustomerBalanceService(DB::connection());

    // EL FALLO A MITAD: el tipo de asiento del cobro no esta activo, asi que
    // el asiento no se puede escribir.
    DB::table('ledger_entry_types')->where('code', 'sale_payment')->update(['is_active' => false]);

    $servicio = new InstallmentCollectionService(
        DB::connection(),
        $balances,
        new CustomerPaymentService(
            DB::connection(),
            $balances,
            new LedgerWriter(DB::connection())
        ),
    );

    expect(fn () => $servicio->collect(
        scheduleId: $scheduleId,
        storeId: $storeId,
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        actorId: $usuario->id,
    ))->toThrow(RuntimeException::class);

    // Y NO QUEDA NADA: la cuota sigue pendiente, el plan sigue activo, el
    // saldo del cliente es el de antes y no hay ningun asiento.
    $cuota = DB::table('installment_schedules')->where('id', $scheduleId)->first();

    expect((string) $cuota->status)->toBe('pending')
        ->and((string) $cuota->paid_amount)->toBe('0.0000')
        ->and((string) DB::table('installment_plans')->where('id', $planId)->value('status'))->toBe('active')
        ->and($balances->balance($customerId, $storeId))->toBe('900.0000')
        ->and(DB::table('customer_ledger_entries')->where('type', 'payment')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * EL DINERO DE LA CUOTA ENTRA A LA CAJA: el cobro exige la caja del dia
 * ABIERTA, como el gasto en efectivo de la F3-07.
 */
test('test_el_cobro_de_una_cuota_exige_la_caja_del_dia_abierta', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];

    // SIN CAJA ABIERTA: el escenario NO la abre (a proposito).
    $usuario = PosFixture::usuarioSinCaja($storeId);
    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '400.0000');
    $planId = InstallmentFixture::plan($storeId, $customerId, '400.0000', '0.0000', 2, '2026-12-01', $saleId);
    $scheduleId = InstallmentFixture::cuota($planId, 1);

    $balances = new CustomerBalanceService(DB::connection());

    $servicio = new InstallmentCollectionService(
        DB::connection(),
        $balances,
        new CustomerPaymentService(
            DB::connection(),
            $balances,
            new LedgerWriter(DB::connection())
        ),
    );

    // SIN CAJA ABIERTA: el cobro no se registra.
    expect(fn () => $servicio->collect(
        scheduleId: $scheduleId,
        storeId: $storeId,
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        actorId: $usuario->id,
    ))->toThrow(RuntimeException::class);

    expect((string) DB::table('installment_schedules')->where('id', $scheduleId)->value('status'))->toBe('pending')
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * UNA CUOTA NO SE COBRA DOS VECES: el cobro es un HECHO, y un hecho repetido
 * es un cobro repetido.
 */
test('test_una_cuota_ya_cobrada_no_se_cobra_dos_veces', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '600.0000');
    $planId = InstallmentFixture::plan($storeId, $customerId, '600.0000', '0.0000', 2, '2026-12-01', $saleId);
    $scheduleId = InstallmentFixture::cuota($planId, 1);

    $balances = new CustomerBalanceService(DB::connection());

    $servicio = new InstallmentCollectionService(
        DB::connection(),
        $balances,
        new CustomerPaymentService(
            DB::connection(),
            $balances,
            new LedgerWriter(DB::connection())
        ),
    );

    $cobrar = fn () => $servicio->collect(
        scheduleId: $scheduleId,
        storeId: $storeId,
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        actorId: $usuario->id,
    );

    $cobrar();

    expect($balances->balance($customerId, $storeId))->toBe('300.0000');

    // El SEGUNDO cobro se rechaza.
    expect(fn () => $cobrar())->toThrow(RuntimeException::class);

    // Y el saldo no se ha movido otra vez.
    expect($balances->balance($customerId, $storeId))->toBe('300.0000')
        ->and(DB::table('customer_ledger_entries')->where('type', 'payment')->count())->toBe(1);
});

/**
 * EL LIMITE DE CREDITO NO BLOQUEA EL COBRO: el limite bloquea lo que AUMENTA
 * la deuda, y un cobro la BAJA.
 */
test('test_el_cobro_de_una_cuota_no_lo_bloquea_el_limite_de_credito_del_cliente', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    // El cliente debe MAS de su limite: paga igual.
    $customerId = CreditFixture::customer($storeId, 'Cliente encima de su limite', null, '100.0000');
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '900.0000');
    $planId = InstallmentFixture::plan($storeId, $customerId, '900.0000', '0.0000', 3, '2026-12-01', $saleId);
    $scheduleId = InstallmentFixture::cuota($planId, 1);

    $balances = new CustomerBalanceService(DB::connection());

    $resultado = (new InstallmentCollectionService(
        DB::connection(),
        $balances,
        new CustomerPaymentService(
            DB::connection(),
            $balances,
            new LedgerWriter(DB::connection())
        ),
    ))->collect(
        scheduleId: $scheduleId,
        storeId: $storeId,
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        actorId: $usuario->id,
    );

    expect($resultado->amount)->toBe('300.0000')
        ->and($balances->balance($customerId, $storeId))->toBe('600.0000');
});
