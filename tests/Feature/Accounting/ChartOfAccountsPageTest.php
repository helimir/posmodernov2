<?php

use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PosFixture;

/**
 * La PANTALLA del plan de cuentas: el listado del catalogo con el rol que
 * cada cuenta cumple en la tienda, y la edicion de ese rol.
 *
 * Reglas que fija:
 *
 *  - el listado entrega TODAS las cuentas del catalogo con su codigo, su
 *    nombre, su tipo y el rol que cumplen en la tienda activa (o `null` si no
 *    cumplen ninguno);
 *  - editar un rol VALIDO funciona, y editar un rol INVALIDO devuelve un 422
 *    con su mensaje: el backend rechaza la asignacion cuyo tipo no sea el que
 *    el rol exige;
 *  - la pagina AVISA cuando al plan de la tienda le falta un rol: el sistema
 *    no podra cobrar, y el hueco se ve ANTES de cobrar, no en el momento de
 *    cobrar;
 *  - sin sesion, la pantalla REDIRIGE al login.
 */

/**
 * El listado del plan de cuentas responde con su contrato de props.
 */
test('test_el_listado_del_plan_de_cuentas_responde_con_su_contrato_de_props', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/contabilidad/cuentas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('accounting/accounts')
            // Las CUATRO cuentas que el sistema usa, con su rol en la tienda.
            ->where('accounts', fn ($cuentas): bool => collect($cuentas)->firstWhere('code', '1100000') !== null
                && collect($cuentas)->firstWhere('code', '1100000')['role'] === 'cash'
                && collect($cuentas)->firstWhere('code', '1200000')['role'] === 'receivable'
                && collect($cuentas)->firstWhere('code', '4010000')['role'] === 'revenue'
                && collect($cuentas)->firstWhere('code', '2110000')['role'] === 'tax_payable')
            // Y una cuenta que NO cumple ningun rol: lo dice con `null`.
            ->where('accounts', fn ($cuentas): bool => collect($cuentas)->firstWhere('code', '1300000')['role'] === null)
            // El TIPO de cada cuenta, que es lo que el rol exige.
            ->where('accounts', fn ($cuentas): bool => collect($cuentas)->firstWhere('code', '4010000')['type'] === 'income')
            // Los CUATRO roles, con el tipo que cada uno exige.
            ->has('roles', 4)
            ->where('roles', fn ($roles): bool => collect($roles)->firstWhere('value', 'cash')['required_type'] === 'asset'
                && collect($roles)->firstWhere('value', 'tax_payable')['required_type'] === 'liability'
                && collect($roles)->firstWhere('value', 'revenue')['required_type'] === 'income')
            // El plan esta COMPLETO: no falta ningun rol.
            ->where('missing_roles', [])
            // Y la tienda del contexto.
            ->where('store.id', $escenario['store_id'])
            ->etc());
});

/**
 * Editar un rol VALIDO funciona, y editar un rol INVALIDO devuelve un 422
 * con su mensaje.
 */
test('test_editar_un_rol_valido_funciona_y_uno_invalido_devuelve_422', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // 1. QUITAR el rol de los ingresos de la cuenta que lo tiene.
    $ingresos = Account::query()->where('code', '4010000')->firstOrFail();

    $this->actingAs($usuario)
        ->putJson('/api/contabilidad/cuentas/'.$ingresos->id.'/rol', ['role' => null])
        ->assertOk()
        ->assertJsonPath('role', null)
        ->assertJsonPath('missing_roles', ['revenue']);

    // 2. ASIGNARLO a otra cuenta de ingresos: el rol va con su tipo.
    $otros = Account::query()->where('code', '4020000')->firstOrFail();

    $this->actingAs($usuario)
        ->putJson('/api/contabilidad/cuentas/'.$otros->id.'/rol', ['role' => 'revenue'])
        ->assertOk()
        ->assertJsonPath('role', 'revenue')
        ->assertJsonPath('roles.revenue', '4020000')
        ->assertJsonPath('missing_roles', []);

    // 3. Y un rol INVALIDO: una cuenta de ingresos no puede ser la caja.
    $this->actingAs($usuario)
        ->putJson('/api/contabilidad/cuentas/'.$otros->id.'/rol', ['role' => 'cash'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y el mensaje dice que rol, que cuenta y que tipo esperaba.
    $fallo = $this->actingAs($usuario)
        ->putJson('/api/contabilidad/cuentas/'.$otros->id.'/rol', ['role' => 'cash'])
        ->assertStatus(422);

    expect($fallo->json('errorMsg'))->toContain("El rol 'cash' exige una cuenta de tipo 'asset'");

    // Y la cuenta NO cambio de rol: el rechazo no escribe nada.
    expect((string) DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('account_id', $otros->id)
        ->value('role'))->toBe('revenue');
});

/**
 * La pagina AVISA cuando al plan de la tienda le falta un rol.
 */
test('test_la_pagina_avisa_cuando_al_plan_le_falta_un_rol', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // Le falta el rol de los ingresos.
    DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('role', 'revenue')
        ->delete();

    $this->actingAs($usuario)
        ->get('/contabilidad/cuentas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('accounting/accounts')
            // El hueco se ve: el sistema no podra cobrar.
            ->where('missing_roles', ['revenue'])
            // Y la cuenta que lo tenia ya no cumple ningun rol.
            ->where('accounts', fn ($cuentas): bool => collect($cuentas)->firstWhere('code', '4010000')['role'] === null)
            ->etc());
});

/**
 * Sin sesion, el plan de cuentas REDIRIGE al login.
 */
test('test_sin_autenticar_el_plan_de_cuentas_redirige_al_login', function () {
    $this->get('/contabilidad/cuentas')->assertRedirect('/login');
});
