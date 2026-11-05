<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PrintFixture;

/**
 * 0.8.4-T — el SECRETO DE REGISTRO por tienda.
 *
 * Regla que fija: *el registro de un agente exige el secreto de SU tienda*.
 *
 * `POST /api/print-agents/register` es el unico endpoint PUBLICO de la API
 * del agente LAN: no hay token todavia, porque el token es lo que el registro
 * ENTREGA. Su unica defensa era el interruptor `PRINT_LAN_AGENT_ENABLED`, y
 * con el interruptor encendido cualquiera con acceso de red a la API podria
 * registrar un agente para CUALQUIER tienda y tirar de sus tickets, que
 * llevan BYTES FISCALES (el comprobante y su QR).
 *
 * El secreto de registro es la SEGUNDA CAPA: lo emite quien instala el
 * agente (`php artisan ops:print-agent-registration-secret {store}`) y el
 * servidor lo exige en la cabecera `X-Registration-Secret`. La tabla guarda
 * SOLO su hash, asi que el secreto en claro se ve una vez y no se vuelve a
 * mostrar.
 */
test('test_el_registro_de_un_agente_exige_el_secreto_de_la_tienda', function () {
    // El canal LAN esta ENCENDIDO: el interruptor es la puerta maestra y el
    // secreto es la segunda capa.
    config(['print.lan_agent_enabled' => true]);

    $tiendaA = PrintFixture::contexto();
    $tiendaB = PrintFixture::contexto();
    $tiendaSinSecreto = PrintFixture::contexto();

    $secretoA = PrintFixture::secretoDeRegistro($tiendaA['store_id']);
    $secretoB = PrintFixture::secretoDeRegistro($tiendaB['store_id']);

    // 1. SIN la cabecera del secreto: 403, y no se registra NINGUN agente.
    $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda A',
        'store_id' => $tiendaA['store_id'],
    ])->assertForbidden();

    expect(DB::table('print_agents')->where('store_id', $tiendaA['store_id'])->count())->toBe(0);

    // 2. Con el secreto de OTRA tienda: 403. El secreto es POR TIENDA.
    $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda A',
        'store_id' => $tiendaA['store_id'],
    ], ['X-Registration-Secret' => $secretoB])->assertForbidden();

    expect(DB::table('print_agents')->where('store_id', $tiendaA['store_id'])->count())->toBe(0);

    // 3. Con el secreto CORRECTO: 201, con su token y su identificador.
    $registro = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda A',
        'store_id' => $tiendaA['store_id'],
    ], ['X-Registration-Secret' => $secretoA]);

    $registro->assertCreated()->assertJsonPath('status', 'registered');

    expect((string) $registro->json('token'))->not->toBe('')
        ->and((int) $registro->json('agent_id'))->toBeGreaterThan(0);

    expect(DB::table('print_agents')->where('store_id', $tiendaA['store_id'])->count())->toBe(1);

    // 4. Una tienda SIN secreto configurado: 403, y el mensaje dice COMO
    //    configurarlo.
    $sinSecreto = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja sin secreto',
        'store_id' => $tiendaSinSecreto['store_id'],
    ], ['X-Registration-Secret' => $secretoA]);

    $sinSecreto->assertForbidden();

    expect((string) $sinSecreto->json('errorMsg'))
        ->toContain('ops:print-agent-registration-secret');
});

/**
 * El secreto de registro se EMITE una vez y en la base de datos solo queda su
 * hash.
 *
 * Regla que fija: *el secreto en claro no se persiste y cada emision lo
 * ROTA*.
 */
test('test_el_secreto_de_registro_se_guarda_solo_como_hash_y_se_muestra_una_sola_vez', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();

    // 1. El comando EMITE el secreto y lo MUESTRA, con un aviso explicito de
    //    que no se vuelve a mostrar.
    $codigo = Artisan::call('ops:print-agent-registration-secret', [
        'store' => $tienda['store_id'],
    ]);

    expect($codigo)->toBe(0);

    $salida = Artisan::output();

    expect($salida)->toContain('UNA SOLA VEZ');

    preg_match('/Secreto de registro:\s*(\S+)/', $salida, $coincidencias);

    $secreto = (string) ($coincidencias[1] ?? '');

    expect($secreto)->not->toBe('');

    // 2. En la base de datos SOLO queda el hash: el secreto en claro no esta
    //    en NINGUNA fila.
    $hash = (string) DB::table('stores')
        ->where('id', $tienda['store_id'])
        ->value('print_agent_registration_secret');

    expect($hash)->toBe(hash('sha256', $secreto))
        ->and($hash)->not->toBe($secreto);

    expect(DB::table('stores')->where('print_agent_registration_secret', $secreto)->count())->toBe(0);

    // 3. Ese secreto sirve para registrar el agente.
    $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $tienda['store_id'],
    ], ['X-Registration-Secret' => $secreto])->assertCreated();

    // 4. El comando ROTA: el secreto anterior deja de valer y el nuevo vale.
    Artisan::call('ops:print-agent-registration-secret', [
        'store' => $tienda['store_id'],
    ]);

    preg_match('/Secreto de registro:\s*(\S+)/', Artisan::output(), $coincidencias);

    $secretoNuevo = (string) ($coincidencias[1] ?? '');

    expect($secretoNuevo)->not->toBe($secreto);

    $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $tienda['store_id'],
    ], ['X-Registration-Secret' => $secreto])->assertForbidden();

    $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $tienda['store_id'],
    ], ['X-Registration-Secret' => $secretoNuevo])->assertCreated();

    // 5. Una tienda que NO existe: error claro y codigo de salida distinto de
    //    cero.
    $codigo = Artisan::call('ops:print-agent-registration-secret', ['store' => 999999]);

    expect($codigo)->not->toBe(0);

    expect(Artisan::output())->toContain('no existe');
});
