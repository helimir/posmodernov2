<?php

use App\Http\Controllers\PrintAgentController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\PrintFixture;

/**
 * 0.8.4-T — el AGENTE LAN tira de la conexión y la tienda sale del
 * `agent_id`.
 *
 * Regla que fija: *el agente siempre inicia la conexión*.
 *
 * El agente se registra, dice que esta vivo y TIRA de los trabajos por
 * long-poll: el servidor nunca alcanza al terminal (el terminal esta en una
 * LAN privada, detras de un NAT). Por eso el token va en la cabecera
 * `Authorization` y el `agent_id` en la cabecera `X-Agent-Id`, y el servidor
 * FUERZA la coincidencia: un token de la tienda A no imprime en la tienda B,
 * y la tienda se resuelve DESDE el `agent_id`, nunca desde el cuerpo.
 *
 * El REGISTRO es publico, asi que exige ademas el SECRETO DE REGISTRO de la
 * tienda (`X-Registration-Secret`): cada tienda siembra el suyo, y el
 * servidor exige que coincida.
 */
test('test_el_agente_trae_trabajos_desde_la_lan_privada_y_la_tienda_se_resuelve_desde_el_agent_id', function () {
    // El canal LAN esta apagado hasta que se valida (`config/print.php`).
    config(['print.lan_agent_enabled' => true]);

    // 1. DOS tiendas, cada una con su trabajo encolado. El registro es
    //    publico, asi que cada tienda tiene su SECRETO DE REGISTRO.
    $tiendaA = PrintFixture::contexto();
    $tiendaB = PrintFixture::contexto();

    $secretoA = PrintFixture::secretoDeRegistro($tiendaA['store_id']);
    $secretoB = PrintFixture::secretoDeRegistro($tiendaB['store_id']);

    $trabajoA1 = PrintFixture::trabajo($tiendaA['store_id'], $tiendaA['target_id']);
    $trabajoA2 = PrintFixture::trabajo($tiendaA['store_id'], $tiendaA['target_id']);
    $trabajoB1 = PrintFixture::trabajo($tiendaB['store_id'], $tiendaB['target_id']);

    // 2. El agente se REGISTRA: el servidor le devuelve su identificador y
    //    su token EN CLARO, una sola vez.
    $registro = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda A',
        'store_id' => $tiendaA['store_id'],
    ], ['X-Registration-Secret' => $secretoA]);

    $registro->assertCreated()->assertJsonPath('status', 'registered');

    $agentId = (int) $registro->json('agent_id');
    $token = (string) $registro->json('token');

    expect($agentId)->toBeGreaterThan(0)
        ->and($token)->not->toBe('');

    // La tabla guarda SOLO el hash: el token en claro no se persiste.
    $hashGuardado = (string) DB::table('print_agents')->where('id', $agentId)->value('token_hash');

    expect($hashGuardado)->not->toBe($token)
        ->and($hashGuardado)->toBe(hash('sha256', $token));

    // 3. El agente TIRA de la conexion: con su token y su `X-Agent-Id`
    //    recibe los trabajos de SU tienda, y solo de su tienda.
    $cabeceras = [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => (string) $agentId,
    ];

    $trabajos = $this->withHeaders($cabeceras)->getJson('/api/print-agents/jobs?wait=0');

    $trabajos->assertOk();

    $ids = array_map(fn (array $trabajo) => (int) $trabajo['job_id'], $trabajos->json('jobs'));

    expect($ids)->toContain($trabajoA1)
        ->and($ids)->toContain($trabajoA2)
        ->and($ids)->not->toContain($trabajoB1);

    // El trabajo de la otra tienda NO se entrega ni se reclama.
    expect(DB::table('print_jobs')->where('id', $trabajoB1)->value('status'))->toBe('queued');

    // Y los bytes viajan en el trabajo, con su hash.
    $primero = collect($trabajos->json('jobs'))->firstWhere('job_id', $trabajoA1);

    expect($primero['payload_sha256'])->toBe(
        DB::table('print_jobs')->where('id', $trabajoA1)->value('payload_sha256')
    );

    // 4. Un `store_id` en el cuerpo NO cambia la tienda: la tienda sale del
    //    `agent_id`, nunca del cuerpo de la peticion.
    $trabajoA3 = PrintFixture::trabajo($tiendaA['store_id'], $tiendaA['target_id']);

    $this->withHeaders($cabeceras)
        ->postJson('/api/print-agents/heartbeat', ['store_id' => $tiendaB['store_id']])
        ->assertOk();

    $conStoreId = $this->withHeaders($cabeceras)
        ->getJson('/api/print-agents/jobs?wait=0&store_id='.$tiendaB['store_id']);

    $conStoreId->assertOk();

    $ids = array_map(fn (array $trabajo) => (int) $trabajo['job_id'], $conStoreId->json('jobs'));

    expect($ids)->toContain($trabajoA3)
        ->and($ids)->not->toContain($trabajoB1)
        ->and(DB::table('print_jobs')->where('id', $trabajoB1)->value('status'))->toBe('queued');

    // 5. El token de la tienda A contra el `X-Agent-Id` de la tienda B: 403,
    //    y el trabajo de B no se entrega.
    $registroB = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda B',
        'store_id' => $tiendaB['store_id'],
    ], ['X-Registration-Secret' => $secretoB]);

    $registroB->assertCreated();

    $agentBId = (int) $registroB->json('agent_id');

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => (string) $agentBId,
    ])->getJson('/api/print-agents/jobs?wait=0')->assertForbidden();

    expect(DB::table('print_jobs')->where('id', $trabajoB1)->value('status'))->toBe('queued');

    // 6. El token equivocado: 401.
    $this->withHeaders([
        'Authorization' => 'Bearer token-que-no-existe',
        'X-Agent-Id' => (string) $agentId,
    ])->getJson('/api/print-agents/jobs?wait=0')->assertUnauthorized();

    // 7. El RESULTADO repetido es IDEMPOTENTE: el segundo no falla y no
    //    cambia el estado.
    $sha = (string) DB::table('print_jobs')->where('id', $trabajoA1)->value('payload_sha256');

    $this->withHeaders($cabeceras)
        ->postJson('/api/print-jobs/'.$trabajoA1.'/result', [
            'status' => 'printed',
            'payload_sha256' => $sha,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    $this->withHeaders($cabeceras)
        ->postJson('/api/print-jobs/'.$trabajoA1.'/result', [
            'status' => 'failed',
            'error' => 'Este resultado repetido no debe cambiar nada',
        ])
        ->assertOk()
        ->assertJsonPath('job_status', 'printed');

    expect(DB::table('print_jobs')->where('id', $trabajoA1)->value('status'))->toBe('printed');

    // 8. La ROTACION invalida el token viejo: el anterior da 401 y el nuevo
    //    funciona.
    $rotacion = $this->withHeaders($cabeceras)->postJson('/api/print-agents/rotate');

    $rotacion->assertOk()->assertJsonPath('status', 'ok');

    $tokenNuevo = (string) $rotacion->json('token');

    expect($tokenNuevo)->not->toBe($token)
        ->and($tokenNuevo)->not->toBe('');

    $this->withHeaders($cabeceras)
        ->getJson('/api/print-agents/jobs?wait=0')
        ->assertUnauthorized();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$tokenNuevo,
        'X-Agent-Id' => (string) $agentId,
    ])->getJson('/api/print-agents/jobs?wait=0')->assertOk();

    // 9. Registrar el MISMO nombre en la MISMA tienda ROTA el token: no crea
    //    un agente duplicado.
    $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda A',
        'store_id' => $tiendaA['store_id'],
    ], ['X-Registration-Secret' => $secretoA])->assertCreated()->assertJsonPath('agent_id', $agentId);

    expect(DB::table('print_agents')->where('store_id', $tiendaA['store_id'])->count())->toBe(1);
});

/**
 * 0.8.4 — el LONG-POLL del agente LAN.
 *
 * Regla que fija: *el agente tira de la conexion y espera al trabajo*.
 *
 * Con `wait=0` la respuesta es inmediata; con `wait=N` el servidor espera
 * hasta N segundos a que haya trabajo, y el tope son 30 segundos: un agente
 * detras de un NAT no puede dejar una peticion colgada para siempre.
 */
test('test_el_long_poll_espera_al_trabajo_y_tiene_un_tope_de_treinta_segundos', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();
    $agente = PrintFixture::agente($tienda['store_id']);

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    // 1. Sin trabajo encolado, el long-poll ESPERA lo que le piden.
    $inicio = microtime(true);

    $this->withHeaders($cabeceras)
        ->getJson('/api/print-agents/jobs?wait=1')
        ->assertOk()
        ->assertJsonPath('jobs', []);

    $esperado = microtime(true) - $inicio;

    expect($esperado)->toBeGreaterThanOrEqual(0.9)
        ->and($esperado)->toBeLessThan(5.0);

    // 2. Y el tope del long-poll son 30 segundos, no mas.
    expect(PrintAgentController::ESPERA_MAXIMA_SEGUNDOS)->toBe(30);
});

/**
 * 0.8.9-T — el TOKEN del agente NO se persiste en claro.
 *
 * Regla que fija: *una fuga de token acota a una sola caja*.
 *
 * El token del agente se entrega EN CLARO una sola vez (en el registro) y en
 * la base de datos solo queda su HASH. Un token en claro persistido se
 * puede robar de un volcado y usar para imprimir desde cualquier sitio, y
 * la fila robada es de UNA caja: con el hash, la fuga obliga a ROTAR ese
 * token y no compromete el resto de la tienda.
 *
 * El ESQUEMA lo dice antes que el codigo: `print_agents` tiene una columna
 * `token_hash` y NO tiene ninguna columna de token en claro. Y el valor
 * guardado es el SHA-256 del token entregado, no el token.
 */
test('test_el_token_del_agente_se_guarda_solo_como_hash', function () {
    // El canal LAN esta apagado hasta que se valida (`config/print.php`).
    config(['print.lan_agent_enabled' => true]);

    // 1. DOS agentes, en dos tiendas: el registro es publico, asi que cada
    //    tienda tiene su SECRETO DE REGISTRO.
    $tiendaA = PrintFixture::contexto();
    $tiendaB = PrintFixture::contexto();

    $secretoA = PrintFixture::secretoDeRegistro($tiendaA['store_id']);
    $secretoB = PrintFixture::secretoDeRegistro($tiendaB['store_id']);

    $registroA = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda A',
        'store_id' => $tiendaA['store_id'],
    ], ['X-Registration-Secret' => $secretoA]);

    $registroA->assertCreated()->assertJsonPath('status', 'registered');

    $registroB = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda B',
        'store_id' => $tiendaB['store_id'],
    ], ['X-Registration-Secret' => $secretoB]);

    $registroB->assertCreated();

    $agentId = (int) $registroA->json('agent_id');
    $token = (string) $registroA->json('token');

    expect($agentId)->toBeGreaterThan(0)
        ->and($token)->not->toBe('');

    // 2. El ESQUEMA: hay una columna de HASH y NO hay ninguna columna de
    //    token en claro.
    $columnas = Schema::getColumnListing('print_agents');

    expect($columnas)->toContain('token_hash')
        ->and($columnas)->not->toContain('token');

    // 3. El valor guardado es el HASH del token entregado, no el token.
    $hashGuardado = (string) DB::table('print_agents')->where('id', $agentId)->value('token_hash');

    expect($hashGuardado)->not->toBe($token)
        ->and($hashGuardado)->toBe(hash('sha256', $token));

    // 4. Y el token en claro NO aparece en NINGUN valor de NINGUNA fila de
    //    la tabla: ni en la del agente que lo recibio, ni en la del otro.
    foreach (DB::table('print_agents')->get() as $fila) {
        foreach ((array) $fila as $valor) {
            expect((string) $valor)->not->toContain($token);
        }
    }
});
