<?php

use App\Domain\Accounting\LedgerWriter;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PosFixture;

/**
 * La PANTALLA del plan de cuentas con el ALTA y la BAJA: la cuenta entra en
 * el catalogo desde la propia aplicacion, y sale de ella con su motivo.
 *
 * Reglas que fija:
 *
 *  - la pantalla sirve el CONTRATO del alta y de la baja: los tipos de cuenta
 *    que el alta puede elegir, y el estado de cada cuenta que la baja y el
 *    apagado necesitan;
 *  - el ALTA por la API crea la cuenta y responde 201, y un codigo repetido
 *    responde 422 con su mensaje;
 *  - APAGAR una cuenta que cumple un rol deja el plan INCOMPLETO, y la
 *    pantalla lo avisa: el sistema no podra cobrar y el hueco se ve ANTES de
 *    cobrar;
 *  - BORRAR una cuenta con movimientos contables se rechaza con 422 y su
 *    mensaje, y una cuenta nunca usada se borra de verdad.
 */

/**
 * La pantalla del plan de cuentas responde con su CONTRATO: el catalogo, los
 * roles, los TIPOS del alta y la tienda.
 */
test('test_la_pantalla_del_plan_de_cuentas_responde_con_su_contrato', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/contabilidad/cuentas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('accounting/accounts')
            // El CATALOGO, con el rol que cada cuenta cumple en la tienda.
            ->has('accounts')
            ->where('accounts', fn ($cuentas): bool => collect($cuentas)->firstWhere('code', '1100000')['role'] === 'cash')
            // Los CUATRO roles, con el tipo que cada uno exige.
            ->has('roles', 4)
            // Los CINCO TIPOS: el ALTA ofrece el conjunto cerrado del dominio,
            // y no una lista escrita a mano en la pantalla.
            ->has('types', 5)
            ->where('types', fn ($tipos): bool => collect($tipos)->pluck('value')->all() === [
                'asset',
                'liability',
                'equity',
                'income',
                'expense',
            ])
            // Y cada cuenta dice si esta ACTIVA: la BAJA y el apagado lo
            // necesitan para saber que ofrecer.
            ->where('accounts', fn ($cuentas): bool => collect($cuentas)->firstWhere('code', '1100000')['is_active'] === true)
            ->where('missing_roles', [])
            ->where('store.id', $escenario['store_id'])
            ->etc());
});

/**
 * El ALTA por la API crea la cuenta y responde 201.
 */
test('test_el_alta_por_la_api_crea_la_cuenta_y_devuelve_201', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/cuentas', [
            'code' => '  550000  ',
            'name' => 'Impuesto de retencion',
            'type' => 'liability',
        ]);

    $respuesta->assertCreated()
        ->assertJsonPath('code', '550000')
        ->assertJsonPath('name', 'Impuesto de retencion')
        ->assertJsonPath('type', 'liability')
        ->assertJsonPath('is_active', true);

    // Y la cuenta EXISTE, con su codigo normalizado.
    $cuenta = Account::query()->where('code', '550000')->firstOrFail();

    expect((string) $cuenta->name)->toBe('Impuesto de retencion')
        ->and((string) $cuenta->type)->toBe('liability');
});

/**
 * Un codigo REPETIDO devuelve 422 con su mensaje, y no crea una segunda
 * cuenta.
 */
test('test_un_codigo_repetido_devuelve_422_con_su_mensaje', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/api/contabilidad/cuentas', [
            'code' => '550000',
            'name' => 'Impuesto de retencion',
            'type' => 'liability',
        ])
        ->assertCreated();

    $repetido = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/cuentas', [
            'code' => '550000',
            'name' => 'Otra cuenta con el mismo codigo',
            'type' => 'asset',
        ]);

    $repetido->assertStatus(422)->assertJsonStructure(['errorMsg']);

    expect($repetido->json('errorMsg'))->toContain('Ya existe una cuenta con el código 550000')
        ->and(Account::query()->where('code', '550000')->count())->toBe(1);
});

/**
 * APAGAR una cuenta que cumple un rol deja el plan INCOMPLETO, y la pantalla
 * lo AVISA: el sistema no podra cobrar, y el hueco se ve antes de cobrar.
 */
test('test_la_pantalla_avisa_que_el_plan_quedo_incompleto_al_apagar_una_cuenta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $efectivo = Account::query()->where('code', '1100000')->firstOrFail();

    $apagada = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/cuentas/'.$efectivo->id.'/desactivar');

    $apagada->assertOk()
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('missing_roles', ['cash']);

    // Y AVISA: el plan de la tienda puede quedarse sin ese rol.
    expect($apagada->json('warnings'))->not->toBeEmpty();

    // Y la pantalla lo dice: el hueco se ve ANTES de cobrar.
    $this->actingAs($usuario)
        ->get('/contabilidad/cuentas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('accounting/accounts')
            ->where('missing_roles', ['cash'])
            ->where('accounts', fn ($cuentas): bool => collect($cuentas)->firstWhere('code', '1100000')['is_active'] === false)
            ->etc());
});

/**
 * ENCENDER la cuenta devuelve el plan a su COMPLETIDAD.
 */
test('test_encender_una_cuenta_devuelve_el_plan_a_su_completitud', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $efectivo = Account::query()->where('code', '1100000')->firstOrFail();

    $this->actingAs($usuario)
        ->postJson('/api/contabilidad/cuentas/'.$efectivo->id.'/desactivar')
        ->assertOk();

    $encendida = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/cuentas/'.$efectivo->id.'/encender');

    $encendida->assertOk()
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('missing_roles', []);

    $this->actingAs($usuario)
        ->get('/contabilidad/cuentas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('accounting/accounts')
            ->where('missing_roles', [])
            ->etc());
});

/**
 * BORRAR una cuenta con MOVIMIENTOS contables se rechaza con 422 y su
 * mensaje, y la cuenta sigue en el catalogo.
 */
test('test_borrar_una_cuenta_con_movimientos_contables_devuelve_422_con_su_mensaje', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $id = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/cuentas', [
            'code' => '550001',
            'name' => 'Cuenta con movimientos',
            'type' => 'expense',
        ])
        ->assertCreated()
        ->json('id');

    (new LedgerWriter(DB::connection()))->write(
        'sale',
        (int) $escenario['store_id'],
        new DateTimeImmutable('2026-11-12T10:00:00-04:00'),
        new DateTimeImmutable('2026-11-12'),
        [
            ['account' => '550001', 'debit' => '10.0000', 'credit' => '0.0000'],
            ['account' => '4010000', 'debit' => '0.0000', 'credit' => '10.0000'],
        ],
    );

    $rechazo = $this->actingAs($usuario)
        ->deleteJson('/api/contabilidad/cuentas/'.$id);

    $rechazo->assertStatus(422)->assertJsonStructure(['errorMsg']);

    expect($rechazo->json('errorMsg'))
        ->toContain('La cuenta 550001 tiene movimientos contables: no se puede eliminar, solo se puede desactivar.');

    // Y la cuenta SIGUE: el historico la necesita.
    expect(Account::query()->where('id', $id)->exists())->toBeTrue();
});

/**
 * BORRAR una cuenta NUNCA USADA la borra de verdad.
 */
test('test_borrar_una_cuenta_nunca_usada_devuelve_200_y_la_borra', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $id = $this->actingAs($usuario)
        ->postJson('/api/contabilidad/cuentas', [
            'code' => '550002',
            'name' => 'Cuenta que no se usa',
            'type' => 'expense',
        ])
        ->assertCreated()
        ->json('id');

    $this->actingAs($usuario)
        ->deleteJson('/api/contabilidad/cuentas/'.$id)
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(Account::query()->where('id', $id)->exists())->toBeFalse();
});
