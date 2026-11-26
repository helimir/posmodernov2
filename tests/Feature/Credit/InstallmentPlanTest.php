<?php

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\LedgerWriter;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Credit\InstallmentPlanService;
use App\Domain\Credit\InstallmentService;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\InstallmentFixture;
use Tests\Fixtures\PosFixture;

/**
 * F3-05 — el PLAN DE CUOTAS de una venta a credito.
 *
 * REGLA que fija este fichero: *el plan de cuotas es el CALENDARIO de una
 * venta a credito, y el interes que el plan cobra es DEUDA del cliente e
 * INGRESO de la tienda*.
 *
 * Y LA VENTA A CREDITO YA EXISTE: el checkout cobra con un medio de pago de
 * tipo `credit` (eso es deuda, no dinero) y el libro del cliente registra la
 * venta por su TOTAL. El plan no inventa la venta a credito: la calendariza.
 *
 * Y EL INTERES NO SE INVENTA: el plan reparte el `total = payable + interes`
 * entre sus cuotas (el dominio ya lo hacia: `InstallmentService`), y aqui se
 * REGISTRA lo que el plan genera: la deuda del cliente sube por el interes y
 * la tienda lo reconoce como ingreso. Sin ese asiento, el cobro de las
 * cuotas dejaria al cliente con un credito a favor por el interes, y el
 * cliente habria pagado justo.
 */

/**
 * El PLAN nace de una VENTA A CREDITO: su principal es lo que la venta dejo
 * SIN COBRAR, y su calendario lo reparte el dominio.
 */
test('test_el_plan_de_cuotas_nace_de_una_venta_a_credito_con_su_principal_y_su_calendario', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '1000.0000');

    $resultado = (new InstallmentPlanService(
        DB::connection(),
        new InstallmentService(DB::connection()),
        new CustomerBalanceService(DB::connection()),
        new LedgerWriter(DB::connection()),
    ))->create(
        saleId: $saleId,
        storeId: $storeId,
        interestPercentage: '0.0000',
        count: 3,
        firstDueDate: new DateTimeImmutable('2026-12-01'),
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        referenceNo: null,
        actorId: $usuario->id,
    );

    // 1. EL PLAN: es de la venta, del cliente y de la tienda.
    $plan = DB::table('installment_plans')->where('id', $resultado->planId)->first();

    expect($plan)->not->toBeNull()
        ->and((int) $plan->sale_id)->toBe($saleId)
        ->and((int) $plan->customer_id)->toBe($customerId)
        ->and((int) $plan->store_id)->toBe($storeId)
        ->and((string) $plan->principal)->toBe('1000.0000')
        ->and((string) $plan->interest_percentage)->toBe('0.0000')
        ->and((int) $plan->installment_count)->toBe(3)
        ->and((string) $plan->date)->toBe('2026-12-01')
        ->and((string) $plan->status)->toBe('active');

    // 2. EL CALENDARIO: tres cuotas, la primera el 1 de diciembre y la
    //    siguiente un mes despues.
    $cuotas = InstallmentFixture::cuotas($resultado->planId);

    expect($cuotas)->toHaveCount(3)
        ->and((string) $cuotas[0]['due_date'])->toBe('2026-12-01')
        ->and((string) $cuotas[1]['due_date'])->toBe('2027-01-01')
        ->and((string) $cuotas[2]['due_date'])->toBe('2027-02-01')
        ->and((string) $cuotas[0]['status'])->toBe('pending');

    // 3. Y EL RESULTADO: el principal es lo que la venta dejo sin cobrar, y
    //    el total es la suma de las cuotas.
    expect($resultado->principal)->toBe('1000.0000')
        ->and($resultado->interest)->toBe('0.0000')
        ->and($resultado->total)->toBe('1000.0000');
});

/**
 * EL INTERES DEL PLAN es deuda del cliente e ingreso de la tienda.
 *
 * Y LA DEUDA DEL CLIENTE SUBE por el interes: el plan dice que el cliente
 * paga el `total`, y el total es el principal MAS el interes. Sin ese
 * movimiento, el cobro de las cuotas lo dejaria con un credito a favor.
 */
test('test_el_interes_del_plan_de_cuotas_es_deuda_del_cliente_e_ingreso_de_la_tienda', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '1000.0000');

    $balances = new CustomerBalanceService(DB::connection());

    expect($balances->balance($customerId, $storeId))->toBe('1000.0000');

    $resultado = (new InstallmentPlanService(
        DB::connection(),
        new InstallmentService(DB::connection()),
        $balances,
        new LedgerWriter(DB::connection()),
    ))->create(
        saleId: $saleId,
        storeId: $storeId,
        interestPercentage: '2.5000',
        count: 3,
        firstDueDate: new DateTimeImmutable('2026-12-01'),
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        referenceNo: 'CREDITO-1',
        actorId: $usuario->id,
    );

    // 1. EL INTERES: el 2,5 % de 1000 son 25, y el total a financiar son
    //    1025.
    expect($resultado->interest)->toBe('25.0000')
        ->and($resultado->total)->toBe('1025.0000');

    // 2. LA DEUDA DEL CLIENTE sube por el interes: ahora debe el total del
    //    plan, no solo el principal.
    expect($balances->balance($customerId, $storeId))->toBe('1025.0000')
        ->and($balances->due($customerId, $storeId))->toBe('1025.0000')
        ->and($balances->holds($customerId, $storeId))->toBeTrue();

    $movimiento = DB::table('customer_ledger_entries')
        ->where('customer_id', $customerId)
        ->where('type', 'adjustment')
        ->first();

    expect($movimiento)->not->toBeNull()
        ->and((string) $movimiento->amount)->toBe('25.0000')
        ->and((int) $movimiento->ref_invoice_id)->toBe($saleId);

    // 3. Y EL ASIENTO: el interes debita la cartera (la deuda del cliente
    //    es real) y acredita el ingreso de la tienda.
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'installment_plan')
        ->where('ledger_entries.reference_id', $resultado->planId)
        ->first(['ledger_entries.*', 'ledger_entry_types.code as type_code']);

    expect($asiento)->not->toBeNull()
        ->and((string) $asiento->type_code)->toBe('adjustment')
        ->and((int) $asiento->store_id)->toBe($storeId)
        ->and((string) $asiento->amount)->toBe('25.0000');

    $roles = (new ChartOfAccounts(DB::connection()))->roles($storeId);

    $lineas = DB::table('ledger_lines')
        ->where('ledger_entry_id', (int) $asiento->id)
        ->orderBy('id')
        ->get();

    expect($lineas)->toHaveCount(2)
        ->and((string) $lineas[0]->account_code)->toBe($roles['receivable'])
        ->and((string) $lineas[0]->debit)->toBe('25.0000')
        ->and((string) $lineas[1]->account_code)->toBe($roles['revenue'])
        ->and((string) $lineas[1]->credit)->toBe('25.0000');
});

/**
 * P0 #3 — el plan es DE LA TIENDA de la venta.
 */
test('test_el_plan_de_cuotas_de_una_venta_de_otra_tienda_no_se_crea', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];

    $otraTienda = (int) DB::table('stores')->insertGetId([
        'name' => 'Tienda de al lado',
        'code_name' => 'AL-LADO-PLAN',
        'sort_order' => 9,
        'status' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '1000.0000');

    $servicio = new InstallmentPlanService(
        DB::connection(),
        new InstallmentService(DB::connection()),
        new CustomerBalanceService(DB::connection()),
        new LedgerWriter(DB::connection()),
    );

    expect(fn () => $servicio->create(
        saleId: $saleId,
        storeId: $otraTienda,
        interestPercentage: '2.5000',
        count: 3,
        firstDueDate: new DateTimeImmutable('2026-12-01'),
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        referenceNo: null,
        actorId: null,
    ))->toThrow(RuntimeException::class);

    // Y no queda ningun plan a medias.
    expect(DB::table('installment_plans')->count())->toBe(0)
        ->and(DB::table('installment_schedules')->count())->toBe(0);
});

/**
 * UNA VENTA SIN DEUDA no tiene plan: un plan sobre una venta cobrada seria un
 * plan de nada.
 */
test('test_un_plan_sobre_una_venta_sin_deuda_no_se_crea', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '500.0000');

    // La venta queda COBRADA: su pago aplica el total.
    DB::table('sale_payments')->where('sale_id', $saleId)->update([
        'applied' => '500.0000',
        'updated_at' => now(),
    ]);

    $servicio = new InstallmentPlanService(
        DB::connection(),
        new InstallmentService(DB::connection()),
        new CustomerBalanceService(DB::connection()),
        new LedgerWriter(DB::connection()),
    );

    expect(fn () => $servicio->create(
        saleId: $saleId,
        storeId: $storeId,
        interestPercentage: '2.5000',
        count: 3,
        firstDueDate: new DateTimeImmutable('2026-12-01'),
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        referenceNo: null,
        actorId: null,
    ))->toThrow(RuntimeException::class);

    expect(DB::table('installment_plans')->count())->toBe(0);
});

/**
 * UNA VENTA NO TIENE DOS PLANES: dos planes sobre la misma venta cobrarian el
 * interes dos veces.
 */
test('test_una_venta_no_tiene_dos_planes_de_cuotas', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '1000.0000');

    $servicio = new InstallmentPlanService(
        DB::connection(),
        new InstallmentService(DB::connection()),
        new CustomerBalanceService(DB::connection()),
        new LedgerWriter(DB::connection()),
    );

    $crear = fn () => $servicio->create(
        saleId: $saleId,
        storeId: $storeId,
        interestPercentage: '2.5000',
        count: 3,
        firstDueDate: new DateTimeImmutable('2026-12-01'),
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        referenceNo: null,
        actorId: null,
    );

    $crear();

    expect(fn () => $crear())->toThrow(RuntimeException::class);

    expect(DB::table('installment_plans')->count())->toBe(1);

    // Y EL ESQUEMA lo prohibe tambien: el indice UNIQUE sobre `sale_id` es la
    // defensa contra dos peticiones a la vez (la comprobacion del dominio es
    // una LECTURA, y dos peticiones se la saltan).
    $indice = DB::selectOne(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'installment_plans' "
        ."AND indexname = 'installment_plans_sale_id_unique'"
    );

    expect($indice)->not->toBeNull();
});

/**
 * P0 #4 — el plan, su calendario y el interes se escriben en UNA
 * transaccion: un fallo a mitad no deja un plan sin su interes.
 */
test('test_un_plan_de_cuotas_a_mitad_de_camino_no_deja_el_plan_sin_su_interes', function () {
    $escenario = PosFixture::escenario();
    $storeId = $escenario['store_id'];

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '1000.0000');

    $balances = new CustomerBalanceService(DB::connection());

    // EL FALLO A MITAD: el tipo de asiento del interes no esta activo.
    DB::table('ledger_entry_types')->where('code', 'adjustment')->update(['is_active' => false]);

    $servicio = new InstallmentPlanService(
        DB::connection(),
        new InstallmentService(DB::connection()),
        $balances,
        new LedgerWriter(DB::connection()),
    );

    expect(fn () => $servicio->create(
        saleId: $saleId,
        storeId: $storeId,
        interestPercentage: '2.5000',
        count: 3,
        firstDueDate: new DateTimeImmutable('2026-12-01'),
        businessDate: new DateTimeImmutable(PosFixture::businessDate($storeId)),
        occurredAt: new DateTimeImmutable(PosFixture::businessDate($storeId).' 10:00:00'),
        accounts: (new PosContext(DB::connection()))->accounts(),
        referenceNo: null,
        actorId: null,
    ))->toThrow(RuntimeException::class);

    // Y NO QUEDA NADA: ni plan, ni cuotas, ni movimiento, ni deuda movida.
    expect(DB::table('installment_plans')->count())->toBe(0)
        ->and(DB::table('installment_schedules')->count())->toBe(0)
        ->and(DB::table('customer_ledger_entries')->where('type', 'adjustment')->count())->toBe(0)
        ->and($balances->balance($customerId, $storeId))->toBe('1000.0000')
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});
