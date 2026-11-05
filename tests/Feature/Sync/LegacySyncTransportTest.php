<?php

use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SyncFixture;
use Tests\TestCase;

/**
 * 0.8.6 — el TRAFICO del sync legacy se filtra ANTES de autenticar.
 *
 * Regla que fija: *el lote viaja firmado, y el transporte se filtra antes*.
 *
 * El legacy (`sync.php`) rechaza tres casos antes de mirar la firma:
 *
 *   1. el trafico desde LOCALHOST sin permiso (`SYNC_ALLOW_LOCALHOST`),
 *   2. la peticion que no llega por HTTPS (`SYNC_REQUIRE_HTTPS`),
 *   3. y la IP que no esta en la lista (`SYNC_ALLOWED_IPS`).
 *
 * Los tres responden 403, y solo el del HTTPS tiene su propio mensaje
 * (`HTTPS is required`): los otros dos responden `Invalid Action`, opaco,
 * como todo lo demas.
 *
 * La comprobacion de HTTPS mira COMO LLEGA la peticion, no lo que dice su
 * cuerpo: `Request::isSecure()` resuelve el transporte y respeta
 * `X-Forwarded-Proto` cuando la app confia en los proxies.
 */
beforeEach(function () {
    config([
        'sync.enabled' => true,
        'sync.clients' => [SyncFixture::CLIENTE => SyncFixture::SECRETO],
        'sync.max_age' => 300,
        'sync.legacy_auth' => false,
    ]);
});

afterEach(function () {
    // La peticion segura se simula forzando el esquema, asi que se
    // deshace: el resto de los tests ven el transporte de verdad.
    app('url')->forceScheme(null);
});

/**
 * Enviar el cuerpo CRUDO al endpoint con un TRANSPORTE concreto.
 *
 * Las variables de servidor son las que decide el transporte: `REMOTE_ADDR`
 * (la IP remota). La peticion SEGURA se simula con el esquema de la URL,
 * que es lo que hace el servidor en produccion: Symfony lo traduce a
 * `HTTPS=on` y `Request::isSecure()` lo ve.
 */
function enviar_lote_con_transporte(
    TestCase $caso,
    string $cuerpo,
    array $servidor = [],
    bool $segura = false,
): TestResponse {
    if ($segura) {
        app('url')->forceScheme('https');
    }

    return $caso->call(
        'POST',
        '/api/sync',
        [],
        [],
        [],
        array_merge(['CONTENT_TYPE' => 'application/json'], $servidor),
        $cuerpo,
    );
}

test('test_una_peticion_sin_https_responde_cuatrocientos_tres_se_exige_https', function () {
    config([
        'sync.require_https' => true,
        // El localhost se admite, para que lo que falle sea el HTTPS y no
        // el filtro de loopback.
        'sync.allow_localhost' => true,
    ]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    // La peticion llega por HTTP plano.
    enviar_lote_con_transporte($this, $lote['body'])
        ->assertStatus(403)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'HTTPS is required',
            'for' => 'invalid',
        ]);
});

test('test_el_trafico_desde_localhost_responde_cuatrocientos_tres_sin_permiso', function () {
    config([
        'sync.require_https' => false,
        'sync.allow_localhost' => false,
    ]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    enviar_lote_con_transporte($this, $lote['body'], ['REMOTE_ADDR' => '127.0.0.1'])
        ->assertStatus(403)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Invalid Action',
            'for' => 'invalid',
        ]);
});

test('test_una_ip_no_admitida_responde_cuatrocientos_tres', function () {
    config([
        'sync.require_https' => false,
        // El localhost se admite: lo que tiene que fallar es la IP.
        'sync.allow_localhost' => true,
        'sync.allowed_ips' => ['10.0.0.1'],
    ]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    enviar_lote_con_transporte($this, $lote['body'], ['REMOTE_ADDR' => '203.0.113.7'])
        ->assertStatus(403)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Invalid Action',
            'for' => 'invalid',
        ]);
});

test('test_una_lista_de_ips_vacia_no_restringe_la_peticion', function () {
    SyncFixture::exigirRedis();

    config([
        'sync.require_https' => false,
        'sync.allow_localhost' => true,
        // Una lista VACIA no restringe: es la lista de IPs admitidas.
        'sync.allowed_ips' => [],
    ]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    enviar_lote_con_transporte($this, $lote['body'], ['REMOTE_ADDR' => '203.0.113.7'])
        ->assertOk()
        ->assertExactJson([
            'status' => 'success',
            'message' => 'sync successfully done',
            'for' => 'sync',
        ]);
});

test('test_un_lote_firmado_por_https_desde_una_ip_admitida_se_acepta', function () {
    SyncFixture::exigirRedis();

    config([
        'sync.require_https' => true,
        'sync.allow_localhost' => true,
        'sync.allowed_ips' => ['203.0.113.7'],
    ]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    enviar_lote_con_transporte($this, $lote['body'], [
        'REMOTE_ADDR' => '203.0.113.7',
    ], true)
        ->assertOk()
        ->assertExactJson([
            'status' => 'success',
            'message' => 'sync successfully done',
            'for' => 'sync',
        ]);
});
