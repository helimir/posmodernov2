<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;

/**
 * F2-09 — la SEGURIDAD del token del agente LAN.
 *
 * REGLA que fija este fichero: *el token del agente se guarda como un hash de
 * contrasena (argon2id) y se verifica con `password_verify`; el token se
 * localiza por su `instance_id`; la revocacion es definitiva; y la rotacion
 * tiene una ventana de gracia*.
 *
 * Antes de esta unidad el token se guardaba con un SHA-256 plano y el
 * middleware lo buscaba POR ESE HASH. Eso no es un hash de contrasena: es un
 * hash rapido, y el token es la credencial de una caja. Con argon2id el hash
 * ya no se puede indexar, asi que el agente se localiza por su identificador
 * y el token se verifica contra SU hash.
 *
 * Y LA ROTACION TIENE VENTANA DE GRACIA: al rotar, el token anterior sigue
 * valiendo un rato, porque el token nuevo tarda en llegarle al agente y sin
 * esa ventana la caja se queda muda.
 */
afterEach(function () {
    // Las pruebas que cambian el ENTORNO lo devuelven: el TLS depende de el.
    app()->detectEnvironment(fn () => 'testing');
});

/**
 * El ADMINISTRADOR de la impresion de esta unidad: una persona con el
 * permiso de cambiar la configuracion de impresion y con su vinculo por la
 * tienda.
 */
function operador_de_impresion(int $storeId): User
{
    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('impresion-agente-'.uniqid(), [
        Permission::PrintingRead->value,
        Permission::PrintingUpdate->value,
    ]);

    return PermissionFixture::usuario($rol, $storeId);
}

/**
 * F2-09 — el token se guarda como un HASH DE CONTRASENA, no como una huella
 * rapida.
 *
 * Y el test lo COMPRUEBA, no se limita a decir "no parece un SHA-256": el
 * hash guardado empieza por `$argon2id$` (con su sal y sus parametros) y
 * verifica el token con `password_verify`.
 */
test('test_el_token_del_agente_se_guarda_com_argon2id_y_no_como_sha256', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();
    $secreto = PrintFixture::secretoDeRegistro($tienda['store_id']);

    $registro = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $tienda['store_id'],
        'instance_id' => 'inst-argon2id',
    ], ['X-Registration-Secret' => $secreto]);

    $registro->assertCreated();

    $token = (string) $registro->json('token');
    $hash = (string) DB::table('print_agents')
        ->where('id', (int) $registro->json('agent_id'))
        ->value('token_hash');

    // 1. Es argon2id: lo dice el prefijo del hash, y un SHA-256 (64
    //    caracteres hexadecimales) no lo tendria.
    expect($hash)->toStartWith('$argon2id$')
        ->and($hash)->not->toBe($token)
        ->and($hash)->not->toBe(hash('sha256', $token))
        ->and(strlen($hash))->not->toBe(64);

    // 2. Y el hash VERIFICA el token entregado, y solo ese.
    expect(password_verify($token, $hash))->toBeTrue()
        ->and(password_verify('otro-token-cualquiera', $hash))->toBeFalse();
});

/**
 * F2-09 — un token CORRECTO autentica y uno incorrecto no.
 */
test('test_un_token_correcto_autentica_y_uno_incorrecto_no', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();
    $agente = PrintFixture::agente($tienda['store_id']);

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    // 1. El token correcto: el agente habla.
    $this->withHeaders($cabeceras)
        ->postJson('/api/print-agents/heartbeat')
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    // 2. El token incorrecto: 401, y no se toca nada.
    $this->withHeaders([
        'Authorization' => 'Bearer '.$agente['token'].'-alterado',
        'X-Agent-Id' => (string) $agente['agent_id'],
    ])->postJson('/api/print-agents/heartbeat')->assertUnauthorized();

    // 3. Y sin token, tampoco.
    $this->withHeaders(['X-Agent-Id' => (string) $agente['agent_id']])
        ->postJson('/api/print-agents/heartbeat')
        ->assertUnauthorized();
});

/**
 * F2-09 — el token EN CLARO no se persiste en ninguna fila.
 */
test('test_el_token_en_claro_no_se_persiste_en_ninguna_fila', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();
    $secreto = PrintFixture::secretoDeRegistro($tienda['store_id']);

    $registro = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $tienda['store_id'],
        'instance_id' => 'inst-en-claro',
    ], ['X-Registration-Secret' => $secreto]);

    $registro->assertCreated();

    $token = (string) $registro->json('token');

    expect($token)->not->toBe('');

    foreach (DB::table('print_agents')->get() as $fila) {
        foreach ((array) $fila as $valor) {
            expect((string) $valor)->not->toContain($token);
        }
    }
});

/**
 * F2-09 — un agente REVOCADO no autentica, aunque su token sea el correcto.
 *
 * Y la revocacion se AUDITA, y solo un operador la levanta: un agente
 * revocado no se puede volver a registrar con su `instance_id` hasta que la
 * revocacion se levante a mano.
 */
test('test_un_agente_revocado_no_autentica_y_solo_un_operador_lo_puede_levantar', function () {
    config(['print.lan_agent_enabled' => true]);

    $escenario = PosFixture::escenario();
    $storeId = (int) $escenario['store_id'];

    $operador = operador_de_impresion($storeId);

    $agente = PrintFixture::agente($storeId, 'Caja de la tienda');
    $secreto = PrintFixture::secretoDeRegistro($storeId);

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    // 1. Antes de revocar, el agente habla.
    $this->withHeaders($cabeceras)->postJson('/api/print-agents/heartbeat')->assertOk();

    // 2. El operador REVOCA el agente.
    $this->actingAs($operador)
        ->postJson('/api/print-agents/'.$agente['agent_id'].'/revoke')
        ->assertOk()
        ->assertJsonPath('status', 'revoked');

    // 3. Y a partir de aqui el agente NO habla, aunque su token sea el
    //    correcto.
    $this->withHeaders($cabeceras)->postJson('/api/print-agents/heartbeat')->assertUnauthorized();

    // 4. La revocacion queda AUDITADA, con quien la hizo.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'print_agent.revoked')
        ->orderByDesc('id')
        ->first();

    expect($auditoria)->not->toBeNull('La revocacion del agente no quedo auditada.')
        ->and((int) $auditoria->user_id)->toBe((int) $operador->id)
        ->and((string) $auditoria->entity_type)->toBe('print_agent')
        ->and((int) $auditoria->entity_id)->toBe((int) $agente['agent_id']);

    // 5. Y no se puede volver a registrar con el mismo `instance_id` sin que
    //    el operador levante la revocacion.
    $reRegistro = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $storeId,
        'instance_id' => $agente['instance_id'],
    ], ['X-Registration-Secret' => $secreto]);

    $reRegistro->assertForbidden();

    expect((string) $reRegistro->json('errorMsg'))->toContain('revocado');

    // 6. El operador LEVANTA la revocacion, y queda auditado tambien.
    $this->actingAs($operador)
        ->postJson('/api/print-agents/'.$agente['agent_id'].'/restore')
        ->assertOk()
        ->assertJsonPath('status', 'restored');

    expect(DB::table('audit_logs')->where('action', 'print_agent.restored')->count())->toBe(1);

    // 7. Y ahora si se puede volver a registrar con el mismo `instance_id`.
    $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $storeId,
        'instance_id' => $agente['instance_id'],
    ], ['X-Registration-Secret' => $secreto])->assertCreated();
});

/**
 * F2-09 — un registro con un `instance_id` que YA EXISTE REEMPLAZA la fila, y
 * el token anterior deja de valer.
 *
 * Es lo que detecta un terminal REINSTALADO: el agente vuelve a registrarse
 * con el mismo `instance_id` (que es su identidad estable), la fila se
 * reemplaza y el token viejo, que es el que puede tener en un disco duro
 * viejo, deja de servir.
 */
test('test_un_registro_con_un_instance_id_existente_reemplaza_la_fila_y_el_token_anterior_deja_de_valer', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();
    $secreto = PrintFixture::secretoDeRegistro($tienda['store_id']);

    $primero = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $tienda['store_id'],
        'instance_id' => 'inst-reinstalado',
    ], ['X-Registration-Secret' => $secreto]);

    $primero->assertCreated();

    $agentId = (int) $primero->json('agent_id');
    $tokenViejo = (string) $primero->json('token');

    // 2. El terminal se reinstala y vuelve a registrarse con SU `instance_id`.
    $segundo = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $tienda['store_id'],
        'instance_id' => 'inst-reinstalado',
    ], ['X-Registration-Secret' => $secreto]);

    $segundo->assertCreated()
        ->assertJsonPath('agent_id', $agentId)
        ->assertJsonPath('replaced', true);

    $tokenNuevo = (string) $segundo->json('token');

    expect($tokenNuevo)->not->toBe($tokenViejo)
        ->and($tokenNuevo)->not->toBe('');

    // 3. UNA sola fila: una instalacion es una fila.
    expect(DB::table('print_agents')->where('store_id', $tienda['store_id'])->count())->toBe(1);

    // 4. Y el token ANTERIOR deja de valer: no hay ventana de gracia para un
    //    reinstalado.
    $this->withHeaders([
        'Authorization' => 'Bearer '.$tokenViejo,
        'X-Agent-Id' => (string) $agentId,
    ])->postJson('/api/print-agents/heartbeat')->assertUnauthorized();

    // 5. El token NUEVO funciona.
    $this->withHeaders([
        'Authorization' => 'Bearer '.$tokenNuevo,
        'X-Agent-Id' => (string) $agentId,
    ])->postJson('/api/print-agents/heartbeat')->assertOk();
});

/**
 * F2-09 — un registro SIN `instance_id` se rechaza, con un mensaje util.
 */
test('test_un_registro_sin_instance_id_se_rechaza_con_un_mensaje_util', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();
    $secreto = PrintFixture::secretoDeRegistro($tienda['store_id']);

    $respuesta = $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => $tienda['store_id'],
    ], ['X-Registration-Secret' => $secreto]);

    $respuesta->assertStatus(422);

    expect((string) $respuesta->json('errorMsg'))->toContain('instance_id');

    // Y no se registra NINGUN agente.
    expect(DB::table('print_agents')->where('store_id', $tienda['store_id'])->count())->toBe(0);
});

/**
 * F2-09 — la VENTANA DE GRACIA respeta la fecha.
 *
 * Antes de la fecha, el token ANTERIOR sigue valiendo. Pasada la fecha, ya no
 * valida. Y el token NUEVO val siempre.
 */
test('test_la_ventana_de_gracia_respeta_la_fecha', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();
    $agente = PrintFixture::agente($tienda['store_id']);

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    // 1. La ROTACION: el token vigente pasa a "previo" con su fecha, y el
    //    nuevo entra como vigente.
    $rotacion = $this->withHeaders($cabeceras)->postJson('/api/print-agents/rotate');

    $rotacion->assertOk()->assertJsonPath('status', 'ok');

    $tokenNuevo = (string) $rotacion->json('token');

    expect($tokenNuevo)->not->toBe($agente['token'])
        ->and((string) $rotacion->json('token_valid_until'))->not->toBe('');

    // 2. ANTES de la fecha, el token anterior SIGUE valiendo: el token nuevo
    //    puede estar de camino.
    $this->withHeaders($cabeceras)->postJson('/api/print-agents/heartbeat')->assertOk();

    // 3. PASADA la fecha, el token anterior ya no vale.
    DB::table('print_agents')
        ->where('id', $agente['agent_id'])
        ->update(['token_previo_valido_hasta' => now()->subSecond()]);

    $this->withHeaders($cabeceras)->postJson('/api/print-agents/heartbeat')->assertUnauthorized();

    // 4. Y el token NUEVO sigue valiendo.
    $this->withHeaders([
        'Authorization' => 'Bearer '.$tokenNuevo,
        'X-Agent-Id' => (string) $agente['agent_id'],
    ])->postJson('/api/print-agents/heartbeat')->assertOk();
});

/**
 * F2-09 — con el TLS exigido, una peticion SIN TLS se rechaza ANTES de mirar
 * el token.
 *
 * Y el rechazo lo dice: el mensaje dice COMO resolverlo.
 */
test('test_con_tls_exigido_una_peticion_sin_tls_se_rechaza_antes_de_mirar_el_token', function () {
    config(['print.lan_agent_enabled' => true, 'print.require_tls' => true]);

    // El ENTORNO decide: fuera de `local` y de `testing`, el TLS se exige.
    app()->detectEnvironment(fn () => 'production');

    // NO hay token, y el `X-Agent-Id` tampoco: si el rechazo fuera el del
    // token, el mensaje seria el del token.
    $respuesta = $this->postJson('/api/print-agents/heartbeat', []);

    $respuesta->assertForbidden();

    $mensaje = (string) $respuesta->json('errorMsg');

    expect($mensaje)->toContain('TLS')
        ->and($mensaje)->toContain('PRINT_REQUIRE_TLS')
        ->and($mensaje)->not->toContain('token del agente no es valido');

    // Y el REGISTRO tambien: ahi es donde el token se ENTREGA en claro.
    $this->postJson('/api/print-agents/register', [
        'name' => 'Caja de la tienda',
        'store_id' => 1,
        'instance_id' => 'inst-sin-tls',
    ])->assertForbidden();
});

/**
 * F2-09 — en LOCAL el TLS no se exige: el desarrollo y las pruebas no usan
 * TLS.
 */
test('test_en_local_el_tls_no_se_exige', function () {
    config(['print.lan_agent_enabled' => true, 'print.require_tls' => true]);

    // El ENTORNO dice `local`: el TLS no se exige.
    app()->detectEnvironment(fn () => 'local');

    $tienda = PrintFixture::contexto();
    $agente = PrintFixture::agente($tienda['store_id']);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ])->postJson('/api/print-agents/heartbeat')->assertOk();
});
