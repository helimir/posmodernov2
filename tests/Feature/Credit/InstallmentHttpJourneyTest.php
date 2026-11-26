<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\InstallmentFixture;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * LAS CUOTAS, por HTTP.
 *
 * Reglas que fija:
 *
 *  - las PAGINAS son Inertia y las ESCRITURAS son JSON con el contrato de
 *    siempre (422 con `{"errorMsg": "..."}`);
 *  - el ALTA del plan y el COBRO de la cuota dejan constancia en la
 *    auditoria de quien los hizo;
 *  - un cobro que no se puede hacer NO se registra: ni la cuota, ni el
 *    saldo, ni el asiento;
 *  - QUIEN COBRA UNA CUOTA NO ES QUIEN ACUERDA EL PLAN: son permisos
 *    distintos, porque cobrar es recoger el dinero y acordar es decidir como
 *    se paga.
 */

/**
 * La PANTALLA de las cuotas responde con su contrato de props, y el ALTA y el
 * COBRO son JSON con el contrato de siempre.
 */
test('test_la_pantalla_de_las_cuotas_es_de_la_tienda_y_su_cobro_es_json_con_el_mismo_contrato', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '1000.0000');

    $this->actingAs($usuario)
        ->get('/cuotas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('credit/installments')
            ->has('plans')
            // Las VENTAS A CREDITO sin plan, para acordar uno.
            ->has('sales')
            ->where('business_date', PosFixture::businessDate($storeId))
            ->where('store.id', $storeId)
            ->etc());

    // El ALTA del plan: el calendario, su interes y la auditoria.
    $alta = $this->actingAs($usuario)
        ->postJson('/api/cuotas', [
            'sale_id' => $saleId,
            'interest_percentage' => '2.5000',
            'count' => 3,
            'first_due_date' => '2026-12-01',
            'reference_no' => 'CREDITO-HTTP-1',
        ]);

    $alta->assertCreated()
        ->assertJsonPath('principal', '1000.0000')
        ->assertJsonPath('interest', '25.0000')
        ->assertJsonPath('total', '1025.0000')
        ->assertJsonPath('count', 3)
        ->assertJsonPath('first_due_date', '2026-12-01');

    $planId = (int) $alta->json('plan_id');

    expect(DB::table('installment_plans')->where('id', $planId)->count())->toBe(1)
        ->and(DB::table('installment_schedules')->where('installment_plan_id', $planId)->count())->toBe(3);

    $auditoriaDelPlan = DB::table('audit_logs')
        ->where('action', 'installment.plan_created')
        ->where('entity_type', 'installment_plan')
        ->where('entity_id', $planId)
        ->first();

    expect($auditoriaDelPlan)->not->toBeNull()
        ->and((int) $auditoriaDelPlan->user_id)->toBe((int) $usuario->id)
        ->and((int) $auditoriaDelPlan->store_id)->toBe($storeId)
        ->and(json_decode((string) $auditoriaDelPlan->context, true)['interest'])->toBe('25.0000');

    // El COBRO de la primera cuota: el dinero entra a la caja y la cuota se
    // marca.
    $scheduleId = InstallmentFixture::cuota($planId, 1);

    $cobro = $this->actingAs($usuario)
        ->postJson('/api/cuotas/'.$scheduleId.'/cobro');

    $cobro->assertCreated()
        ->assertJsonPath('schedule_id', $scheduleId)
        ->assertJsonPath('plan_id', $planId)
        ->assertJsonPath('amount', '341.6667')
        ->assertJsonPath('plan_status', 'active');

    expect((string) DB::table('installment_schedules')->where('id', $scheduleId)->value('status'))->toBe('paid')
        ->and((string) DB::table('installment_schedules')->where('id', $scheduleId)->value('paid_amount'))->toBe('341.6667');

    $auditoriaDelCobro = DB::table('audit_logs')
        ->where('action', 'installment.collected')
        ->where('entity_type', 'installment_schedule')
        ->where('entity_id', $scheduleId)
        ->first();

    expect($auditoriaDelCobro)->not->toBeNull()
        ->and((int) $auditoriaDelCobro->user_id)->toBe((int) $usuario->id)
        ->and(json_decode((string) $auditoriaDelCobro->context, true)['amount'])->toBe('341.6667');
});

/**
 * UN COBRO QUE NO SE PUEDE HACER no se registra: ni la cuota, ni el saldo,
 * ni el asiento.
 */
test('test_un_cobro_de_cuota_que_no_se_puede_hacer_no_se_registra_y_devuelve_422_con_su_mensaje', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '600.0000');
    $planId = InstallmentFixture::plan($storeId, $customerId, '600.0000', '0.0000', 2, '2026-12-01', $saleId);

    $scheduleId = InstallmentFixture::cuota($planId, 1);

    // Una cuota que NO EXISTE: el 422 lo dice.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/cuotas/999999/cobro');

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // Y una cuota que ya esta cobrada: tampoco se cobra dos veces.
    $this->actingAs($usuario)
        ->postJson('/api/cuotas/'.$scheduleId.'/cobro')
        ->assertCreated();

    $segundo = $this->actingAs($usuario)
        ->postJson('/api/cuotas/'.$scheduleId.'/cobro');

    $segundo->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // Y SOLO se cobro una vez: un movimiento en el libro del cliente y un
    // asiento.
    expect(DB::table('customer_ledger_entries')->where('type', 'payment')->count())->toBe(1)
        ->and(DB::table('ledger_entries')->where('reference_type', 'customer_payment')->count())->toBe(1);
});

/**
 * QUIEN COBRA UNA CUOTA NO ES QUIEN ACUERDA EL PLAN: son permisos
 * distintos.
 */
test('test_quien_cobra_una_cuota_no_es_quien_acuerda_el_plan_y_quien_solo_mira_no_cobra', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];

    PermissionFixture::catalogo();

    // El CAJERO: el mostrador acuerda el plan y cobra la cuota.
    $cajero = PermissionFixture::usuarioConCaja(
        Role::query()->where('slug', 'cashier')->firstOrFail(),
        $storeId,
    );

    // El que SOLO MIRA: tiene el permiso de lectura y nada mas.
    $lector = PermissionFixture::usuario(
        PermissionFixture::rol('lector-de-cuotas', [Permission::InstallmentRead->value]),
        $storeId,
    );

    expect($cajero->can('installment.read'))->toBeTrue()
        ->and($cajero->can('installment.create'))->toBeTrue()
        ->and($cajero->can('installment.collect'))->toBeTrue()
        ->and($lector->can('installment.read'))->toBeTrue()
        ->and($lector->can('installment.collect'))->toBeFalse()
        ->and($lector->can('installment.create'))->toBeFalse();

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '600.0000');

    // El cajero ACUERDA el plan.
    $alta = $this->actingAs($cajero)
        ->postJson('/api/cuotas', [
            'sale_id' => $saleId,
            'interest_percentage' => '0.0000',
            'count' => 2,
            'first_due_date' => '2026-12-01',
        ]);

    $alta->assertCreated();

    $planId = (int) $alta->json('plan_id');
    $scheduleId = InstallmentFixture::cuota($planId, 1);

    // El que SOLO MIRA ve la pantalla...
    $this->actingAs($lector)
        ->get('/cuotas')
        ->assertOk();

    // ...pero NO cobra: se lo rechaza en el servidor.
    $this->actingAs($lector)
        ->postJson('/api/cuotas/'.$scheduleId.'/cobro')
        ->assertStatus(403);

    // Y la cuota sigue pendiente: el rechazo no cobra nada.
    expect((string) DB::table('installment_schedules')->where('id', $scheduleId)->value('status'))->toBe('pending')
        ->and(DB::table('customer_ledger_entries')->where('type', 'payment')->count())->toBe(0);

    // El cajero SI cobra.
    $this->actingAs($cajero)
        ->postJson('/api/cuotas/'.$scheduleId.'/cobro')
        ->assertCreated();
});

/**
 * La pantalla de las cuotas de un cliente es el EXTRACTO de sus planes, con
 * la deuda que dice el aging.
 */
test('test_la_pantalla_de_las_cuotas_de_un_cliente_muestra_su_extracto_y_la_deuda_del_aging', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId, 'Cliente con cuotas', 'V-CUO-0001');
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '1000.0000');
    $planId = InstallmentFixture::plan($storeId, $customerId, '1000.0000', '0.0000', 3, '2026-12-01', $saleId);

    $otroCliente = CreditFixture::customer($storeId, 'Cliente sin cuotas');
    $otraVenta = InstallmentFixture::ventaACredito($storeId, $otroCliente, '500.0000');
    InstallmentFixture::plan($storeId, $otroCliente, '500.0000', '0.0000', 2, '2026-12-01', $otraVenta);

    $this->actingAs($usuario)
        ->get('/clientes/'.$customerId.'/cuotas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('credit/customer-installments')
            ->where('customer.id', $customerId)
            ->where('customer.name', 'Cliente con cuotas')
            // SOLO sus planes: el extracto es de SU.
            ->has('plans', 1)
            ->where('plans.0.id', $planId)
            ->has('plans.0.quotas', 3)
            // Y la deuda, del AGING que ya existe.
            ->where('aging.due', '1000.0000')
            ->where('aging.balance', '1000.0000')
            ->where('store.id', $storeId)
            ->etc());
});

/**
 * UNA CUOTA VENCIDA se ensña vencida: es un HECHO (una fecha), no una regla.
 */
test('test_la_cuota_vencida_y_no_cobrada_se_ensena_vencida', function () {
    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];
    $usuario = PosFixture::usuario($storeId);

    $customerId = CreditFixture::customer($storeId);
    $saleId = InstallmentFixture::ventaACredito($storeId, $customerId, '300.0000');

    // Un plan cuyo primer vencimiento ya paso.
    $planId = InstallmentFixture::plan($storeId, $customerId, '300.0000', '0.0000', 2, '2020-01-01', $saleId);

    $this->actingAs($usuario)
        ->get('/cuotas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('credit/installments')
            ->has('plans', 1)
            // La cuota vencida: la columna dice `pending` y la VENCIDA se
            // deriva de su fecha.
            ->where('plans.0.quotas.0.status', 'pending')
            ->where('plans.0.quotas.0.overdue', true)
            ->etc());
});

/**
 * Sin sesion, la pantalla de las cuotas redirige al login.
 */
test('test_sin_autenticar_la_pantalla_de_cuotas_redirige_al_login', function () {
    $this->get('/cuotas')->assertRedirect('/login');
});
