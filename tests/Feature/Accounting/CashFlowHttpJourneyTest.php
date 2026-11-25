<?php

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * El MODULO DE GASTOS, INGRESOS Y BANCA, por HTTP: las pantallas y la API.
 *
 * Reglas que fija:
 *
 *  - la pagina es Inertia y las escrituras son JSON con el contrato de
 *    siempre (422 con `{"errorMsg": "..."}`);
 *  - el ALTA de un gasto escribe el gasto Y su asiento, y deja constancia en
 *    la auditoria de quien lo registro, cuanto y desde donde se pago;
 *  - un gasto invalido NO se registra: ni el gasto ni su asiento;
 *  - la ANULACION marca el gasto con su motivo, y sin motivo no hay
 *    anulacion;
 *  - QUIEN REGISTRA EL GASTO NO LO ANULA: registrar y anular son permisos
 *    distintos, porque anular es una correccion de supervision.
 */

/**
 * La CATEGORIA de gasto del catalogo de demostracion.
 */
function categoria_de_gasto(string $codigo): int
{
    return (int) DB::table('expense_categories')->where('code', $codigo)->value('id');
}

/**
 * El ORIGEN de ingreso del catalogo de demostracion.
 */
function origen_de_ingreso(string $codigo): int
{
    return (int) DB::table('income_sources')->where('code', $codigo)->value('id');
}

/**
 * La CUENTA BANCARIA de la tienda de demostracion.
 */
function cuenta_bancaria_de_demostracion(): int
{
    return (int) DB::table('bank_accounts')
        ->where('name', 'Cuenta bancaria de la tienda de demostracion')
        ->value('id');
}

/**
 * La PANTALLA de los gastos responde con su contrato de props.
 */
test('test_la_pantalla_de_los_gastos_responde_con_su_contrato_de_props', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/contabilidad/gastos')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('accounting/expenses')
            // Los GASTOS de la tienda: los mas recientes primero.
            ->has('expenses')
            ->where('expenses', fn ($gastos): bool => collect($gastos)->every(
                static fn ($gasto): bool => array_key_exists('amount', $gasto)
                    && array_key_exists('payment_type', $gasto)
                    && array_key_exists('status', $gasto)
            ))
            // Las CATEGORIAS con su cuenta contable.
            ->has('categories')
            ->where('categories', fn ($categorias): bool => collect($categorias)->firstWhere('code', 'GASTO-ADMIN')['account_code'] === '5110000')
            // Las CUENTAS BANCARIAS de la tienda.
            ->has('bank_accounts')
            // Y la FECHA DE NEGOCIO de la tienda.
            ->where('business_date', PosFixture::businessDate((int) $escenario['store_id']))
            ->where('store.id', $escenario['store_id'])
            ->etc());
});

/**
 * El ALTA de un gasto escribe el gasto, su asiento y su auditoria.
 */
test('test_el_alta_de_un_gasto_escribe_el_gasto_su_asiento_y_su_auditoria', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/gastos', [
            'amount' => '25.0000',
            'payment_type' => 'cash',
            'expense_category_id' => categoria_de_gasto('GASTO-ADMIN'),
            'description' => 'Papeleria de la tienda',
            'reference' => 'FAC-0001',
        ]);

    $respuesta->assertCreated()
        ->assertJsonPath('amount', '25.0000')
        ->assertJsonPath('status', 'recorded')
        ->assertJsonPath('payment_type', 'cash');

    $gastoId = (int) $respuesta->json('id');

    // 1. El GASTO esta en `expenses`.
    expect(DB::table('expenses')->where('id', $gastoId)->count())->toBe(1);

    // 2. Y el ASIENTO esta en el libro, con el tipo del catalogo.
    $asiento = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'expense')
        ->where('ledger_entries.reference_id', $gastoId)
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect($asiento)->not->toBeNull()
        ->and((string) $asiento->type_code)->toBe('expense');

    // 3. Y la AUDITORIA dice quien lo registro, cuanto y desde donde se pago.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'expense.created')
        ->where('entity_type', 'expense')
        ->where('entity_id', $gastoId)
        ->first();

    expect($auditoria)->not->toBeNull()
        ->and((int) $auditoria->user_id)->toBe((int) $usuario->id)
        ->and((int) $auditoria->store_id)->toBe((int) $escenario['store_id'])
        ->and(json_decode((string) $auditoria->context, true)['amount'])->toBe('25.0000');
});

/**
 * Un gasto INVALIDO no se registra: ni el gasto ni su asiento.
 */
test('test_un_gasto_invalido_no_se_registra_y_devuelve_422_con_su_mensaje', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // Sin categoria: no hay donde cargarlo.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/gastos', [
            'amount' => '25.0000',
            'payment_type' => 'cash',
        ]);

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    expect($respuesta->json('errorMsg'))->toContain('necesita una categoria');

    // Y un importe que no es un numero decimal.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/gastos', [
            'amount' => 'veinte',
            'payment_type' => 'cash',
            'expense_category_id' => categoria_de_gasto('GASTO-ADMIN'),
        ]);

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // Y NO se escribio nada.
    expect(DB::table('expenses')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * La ANULACION marca el gasto con su motivo, y sin motivo no hay anulacion.
 */
test('test_la_anulacion_de_un_gasto_lo_marca_y_exige_un_motivo', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $alta = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/gastos', [
            'amount' => '25.0000',
            'payment_type' => 'cash',
            'expense_category_id' => categoria_de_gasto('GASTO-ADMIN'),
        ]);

    $gastoId = (int) $alta->json('id');

    // Sin motivo, no se anula.
    $this->actingAs($usuario)
        ->postJson('/api/contabilidad/gastos/'.$gastoId.'/anular', ['reason' => '  '])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    expect((string) DB::table('expenses')->where('id', $gastoId)->value('status'))->toBe('recorded');

    // Con motivo, se anula: el gasto se MARCA y no se borra.
    $this->actingAs($usuario)
        ->postJson('/api/contabilidad/gastos/'.$gastoId.'/anular', ['reason' => 'Se registro por duplicado'])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    $gasto = DB::table('expenses')->where('id', $gastoId)->first();

    expect((string) $gasto->status)->toBe('cancelled')
        ->and((string) $gasto->cancellation_reason)->toBe('Se registro por duplicado')
        ->and($gasto->cancelled_at)->not->toBeNull();

    // Y la auditoria lo dice.
    expect(DB::table('audit_logs')
        ->where('action', 'expense.cancelled')
        ->where('entity_id', $gastoId)
        ->count())->toBe(1);
});

/**
 * La PANTALLA de los ingresos y su ALTA.
 */
test('test_la_pantalla_de_los_ingresos_responde_y_el_alta_escribe_el_ingreso', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/contabilidad/ingresos')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('accounting/incomes')
            ->has('incomes')
            ->has('sources')
            ->where('sources', fn ($origenes): bool => collect($origenes)->firstWhere('code', 'INGRESO-OTROS')['account_code'] === '4020000')
            ->has('bank_accounts')
            ->where('store.id', $escenario['store_id'])
            ->etc());

    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/ingresos', [
            'amount' => '80.0000',
            'payment_type' => 'bank',
            'bank_account_id' => cuenta_bancaria_de_demostracion(),
            'income_source_id' => origen_de_ingreso('INGRESO-OTROS'),
            'description' => 'Alquiler cobrado',
        ]);

    $respuesta->assertCreated()
        ->assertJsonPath('amount', '80.0000')
        ->assertJsonPath('payment_type', 'bank');

    $ingresoId = (int) $respuesta->json('id');

    expect(DB::table('incomes')->where('id', $ingresoId)->count())->toBe(1)
        ->and(DB::table('ledger_entries')
            ->where('reference_type', 'income')
            ->where('reference_id', $ingresoId)
            ->count())->toBe(1);
});

/**
 * La PANTALLA de la banca y su ALTA.
 */
test('test_la_pantalla_de_la_banca_responde_y_el_alta_escribe_el_movimiento', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/contabilidad/banca')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('accounting/banking')
            ->has('movements')
            ->has('bank_accounts')
            // La cuenta de efectivo de la tienda: el rol `cash` de su plan.
            ->where('cash_account.code', '1100000')
            ->where('store.id', $escenario['store_id'])
            ->etc());

    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/banca', [
            'amount' => '60.0000',
            'from_type' => 'cash',
            'to_type' => 'bank',
            'to_bank_account_id' => cuenta_bancaria_de_demostracion(),
            'description' => 'Deposito al banco',
        ]);

    $respuesta->assertCreated()->assertJsonPath('amount', '60.0000');

    $asientoId = (int) $respuesta->json('id');

    expect(DB::table('ledger_entries')->where('id', $asientoId)->count())->toBe(1)
        ->and(DB::table('audit_logs')
            ->where('action', 'bank_movement.created')
            ->where('entity_id', $asientoId)
            ->count())->toBe(1);
});

/**
 * QUIEN REGISTRA EL GASTO NO LO ANULA: son permisos distintos.
 */
test('test_quien_registra_el_gasto_no_lo_anula_y_quien_lo_anula_no_lo_registra', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    // El CAJERO: registra el gasto del dia a dia.
    $cajero = PermissionFixture::usuarioConCaja(
        Role::query()->where('slug', 'cashier')->firstOrFail(),
        (int) $escenario['store_id'],
    );

    // El CONTADOR: supervisa y deshace.
    $contador = PermissionFixture::usuario(
        Role::query()->where('slug', 'accountant')->firstOrFail(),
        (int) $escenario['store_id'],
    );

    expect($cajero->can('expense.create'))->toBeTrue()
        ->and($cajero->can('expense.cancel'))->toBeFalse()
        ->and($contador->can('expense.cancel'))->toBeTrue()
        ->and($contador->can('expense.create'))->toBeFalse();

    $alta = $this->actingAs($cajero)
        ->postJson('/api/contabilidad/gastos', [
            'amount' => '25.0000',
            'payment_type' => 'cash',
            'expense_category_id' => categoria_de_gasto('GASTO-ADMIN'),
        ]);

    $alta->assertCreated();

    $gastoId = (int) $alta->json('id');

    // El cajero NO anula: se lo rechaza en el servidor.
    $this->actingAs($cajero)
        ->postJson('/api/contabilidad/gastos/'.$gastoId.'/anular', ['reason' => 'duplicado'])
        ->assertStatus(403);

    // Y el gasto sigue en pie: el rechazo no anula nada.
    expect((string) DB::table('expenses')->where('id', $gastoId)->value('status'))->toBe('recorded');

    // El contador SI anula.
    $this->actingAs($contador)
        ->postJson('/api/contabilidad/gastos/'.$gastoId.'/anular', ['reason' => 'duplicado'])
        ->assertOk();

    expect((string) DB::table('expenses')->where('id', $gastoId)->value('status'))->toBe('cancelled');
});

/**
 * Sin sesion, las tres pantallas redirigen al login.
 */
test('test_sin_autenticar_las_tres_pantallas_redirigen_al_login', function () {
    $this->get('/contabilidad/gastos')->assertRedirect('/login');
    $this->get('/contabilidad/ingresos')->assertRedirect('/login');
    $this->get('/contabilidad/banca')->assertRedirect('/login');
});
