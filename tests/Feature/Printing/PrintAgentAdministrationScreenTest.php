<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F3-02 — la PANTALLA de los agentes de impresion.
 *
 * REGLA que fija: *la revocacion de un agente es DEFINITIVA, y la pantalla
 * del agente es donde se levanta*.
 *
 * La revocacion y su levantada YA EXISTEN por API (`print-agents/{agent}/
 * revoke` y `/restore`), pero NINGUNA pantalla las llamaba: el agente
 * aparecia solo como una lista de lectura dentro de la pantalla de destinos,
 * y una revocacion que nadie puede levantar desde la aplicacion es una
 * revocacion que se queda para siempre.
 *
 * Y LA PANTALLA ES POR TIENDA: enseña los agentes de la tienda del contexto,
 * la misma que usa la caja, y revocar a un agente de otra tienda no es una
 * operacion.
 *
 * Y LA REVOCACION NO RESUCITA EL TOKEN: al levantar la revocacion, el token
 * que hubiera queda invalidado, y el agente se vuelve a registrar con su
 * `instance_id` para recibir uno nuevo. Un token que se revoco porque pudo
 * filtrarse no puede volver a la vida por levantar la revocacion.
 *
 * Y TODO CAMBIO SE AUDITA: la revocacion y su levantada quedan escritas, con
 * quien las hizo y desde donde. Aqui no se duplica la auditoria: la escribe
 * el controlador.
 */

/**
 * Quien ADMINISTRA los agentes de impresion: puede verlos y puede revocarlos.
 */
function admin_de_agentes(int $storeId): User
{
    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('agentes-'.uniqid(), [
        Permission::PrintingRead->value,
        Permission::PrintingUpdate->value,
    ]);

    return PermissionFixture::usuario($rol, $storeId);
}

/**
 * La PANTALLA de los agentes enseña los agentes de la tienda, con su estado.
 *
 * REGLA que fija: *el agente se ve con su estado, y su estado dice si habla*.
 *
 * El `revoked` es la SENTENCE y el `is_active` es el INTERRUPTOR: son dos
 * cosas distintas, y la pantalla las enseña distintas. Un agente revocado no
 * habla, aunque su token sea correcto.
 */
test('test_la_pantalla_de_agentes_enseña_los_agentes_de_la_tienda_con_su_estado', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_agentes($escenario['store_id']);

    // Un destino del AGENTE: es el que su inventario tiene que declarar.
    $destino = PrintFixture::destino($escenario['store_id'], 'lan_agent', [
        'name' => 'Caja de la tienda',
    ]);

    $codigo = (string) DB::table('printers')
        ->where('id', $destino['printer_id'])
        ->value('code');

    // Un agente VIVO, con su inventario declarado.
    $vivo = PrintFixture::agente($escenario['store_id'], 'Caja viva', [
        'printers' => json_encode([['code' => $codigo, 'name' => 'Impresora de la caja']]),
        'capabilities' => json_encode(['escpos', 'qr']),
        'last_heartbeat_at' => now(),
    ]);

    // Y uno REVOCADO: no habla, aunque su token sea correcto.
    $revocado = PrintFixture::agente($escenario['store_id'], 'Caja revocada', [
        'revoked_at' => now(),
        'is_active' => false,
    ]);

    // Y uno de OTRA tienda, que NO se ve.
    $otra = PrintFixture::contexto();
    $ajeno = PrintFixture::agente($otra['store_id'], 'Caja de otra tienda');

    $respuesta = $this->actingAs($admin)->get('/impresion/agentes');

    $respuesta->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('printing/agents')
            ->where('store.id', $escenario['store_id'])
            // SOLO los agentes de la tienda: dos, ni tres.
            ->where('agents', fn ($agentes): bool => count($agentes) === 2)
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $vivo['agent_id']) !== null)
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $revocado['agent_id']) !== null)
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $ajeno['agent_id']) === null)
            // El VIVO: activo, no revocado, con su inventario y su latido.
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $vivo['agent_id'])['name'] === 'Caja viva')
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $vivo['agent_id'])['revoked'] === false)
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $vivo['agent_id'])['is_active'] === true)
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $vivo['agent_id'])['last_heartbeat_at'] !== null)
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $vivo['agent_id'])['printers'][0]['code'] === $codigo)
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $vivo['agent_id'])['capabilities'] === ['escpos', 'qr'])
            // Y el REVOCADO: revocado, y apagado.
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $revocado['agent_id'])['revoked'] === true)
            ->where('agents', fn ($agentes): bool => collect($agentes)->firstWhere('id', $revocado['agent_id'])['is_active'] === false)
        );
});

/**
 * La REVOCACION es definitiva, y la levantada NO resucita el token.
 *
 * REGLA que fija: *la revocacion de un agente es DEFINITIVA, y solo un
 * operador la levanta; al levantarla, el token anterior queda invalidado*.
 *
 * El agente revocado no habla: ni con su token, ni con el token anterior
 * dentro de la ventana de gracia. Y al levantar la revocacion, el token no
 * vuelve a la vida: el agente se registra otra vez y recibe uno nuevo.
 */
test('test_la_revocacion_del_agente_es_definitiva_y_la_levantada_no_devuelve_el_token', function () {
    config(['print.lan_agent_enabled' => true]);

    $escenario = PosFixture::escenario();

    $admin = admin_de_agentes($escenario['store_id']);

    $agente = PrintFixture::agente($escenario['store_id'], 'Caja de la tienda');

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    // 1. El agente HABLA antes de la revocacion.
    $this->withHeaders($cabeceras)
        ->postJson('/api/print-agents/heartbeat')
        ->assertOk();

    // 2. La REVOCACION: responde 200 y el agente deja de hablar.
    $revocacion = $this->actingAs($admin)
        ->postJson('/api/print-agents/'.$agente['agent_id'].'/revoke');

    $revocacion->assertOk()->assertJsonPath('status', 'revoked');

    $this->withHeaders($cabeceras)
        ->postJson('/api/print-agents/heartbeat')
        ->assertUnauthorized();

    // 3. Y la revocacion QUEDA AUDITADA: con quien la hizo y desde donde.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'print_agent.revoked')
        ->orderByDesc('id')
        ->first();

    expect($auditoria)->not->toBeNull('La revocacion del agente no quedo auditada.')
        ->and((int) $auditoria->user_id)->toBe((int) $admin->id)
        ->and((string) $auditoria->entity_type)->toBe('print_agent')
        ->and((int) $auditoria->entity_id)->toBe((int) $agente['agent_id'])
        ->and((string) $auditoria->ip_address)->not->toBe('');

    // 4. La LEVANTADA: solo un operador la levanta, y el token NO vuelve.
    $levantada = $this->actingAs($admin)
        ->postJson('/api/print-agents/'.$agente['agent_id'].'/restore');

    $levantada->assertOk()->assertJsonPath('status', 'restored');

    $fila = DB::table('print_agents')->where('id', $agente['agent_id'])->first();

    expect($fila->revoked_at)->toBeNull()
        ->and((bool) $fila->is_active)->toBeTrue();

    // Y el token ANTERIOR no vuelve a la vida: el agente se registra otra vez.
    $this->withHeaders($cabeceras)
        ->postJson('/api/print-agents/heartbeat')
        ->assertUnauthorized();

    // 5. Y la levantada tambien queda auditada.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'print_agent.restored')
        ->orderByDesc('id')
        ->first();

    expect($auditoria)->not->toBeNull('La levantada de la revocacion no quedo auditada.')
        ->and((int) $auditoria->user_id)->toBe((int) $admin->id)
        ->and((int) $auditoria->entity_id)->toBe((int) $agente['agent_id']);
});

/**
 * La revocacion NO alcanza a los agentes de OTRA tienda.
 *
 * REGLA que fija: *el agente es POR TIENDA: solo se revoca el agente de la
 * tienda activa*. Un agente de otra tienda no existe para quien pregunta.
 */
test('test_la_revocacion_no_alcanza_a_los_agentes_de_otra_tienda', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_de_agentes($escenario['store_id']);

    $otra = PrintFixture::contexto();

    $ajeno = PrintFixture::agente($otra['store_id'], 'Caja de otra tienda');

    $respuesta = $this->actingAs($admin)
        ->postJson('/api/print-agents/'.$ajeno['agent_id'].'/revoke');

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // Y el agente de la otra tienda sigue como estaba.
    $fila = DB::table('print_agents')->where('id', $ajeno['agent_id'])->first();

    expect($fila->revoked_at)->toBeNull()
        ->and((bool) $fila->is_active)->toBeTrue();
});

/**
 * SIN PERMISO no se abre la pantalla de los agentes, y sin sesion tampoco.
 *
 * REGLA que fija: *el permiso es la ley*. Ver la configuracion de las
 * impresoras y revocar un agente no es lo mismo que cobrar: son permisos
 * distintos, y el que revoke un agente es el que puede cambiar la
 * configuracion de la impresion.
 */
test('test_sin_permiso_no_se_abre_la_pantalla_de_agentes', function () {
    $escenario = PosFixture::escenario();

    // 1. Sin sesion: la pantalla redirige al login.
    $this->get('/impresion/agentes')->assertRedirect('/login');

    // 2. Con sesion pero SIN el permiso de la impresion: 403.
    PermissionFixture::catalogo();

    $cajero = PermissionFixture::usuario(
        PermissionFixture::rol('cajero-agentes-'.uniqid(), [Permission::SalesCreate->value]),
        $escenario['store_id'],
    );

    $this->actingAs($cajero)->get('/impresion/agentes')->assertStatus(403);

    // 3. Y sin el permiso de ACTUALIZAR, tampoco se revoca: ver la pantalla
    //    y cambiar la impresion son DOS permisos.
    $soloLectura = PermissionFixture::usuario(
        PermissionFixture::rol('lectura-agentes-'.uniqid(), [Permission::PrintingRead->value]),
        $escenario['store_id'],
    );

    $agente = PrintFixture::agente($escenario['store_id'], 'Caja de la tienda');

    $this->actingAs($soloLectura)
        ->postJson('/api/print-agents/'.$agente['agent_id'].'/revoke')
        ->assertStatus(403);

    expect(DB::table('print_agents')->where('id', $agente['agent_id'])->value('revoked_at'))->toBeNull();
});
