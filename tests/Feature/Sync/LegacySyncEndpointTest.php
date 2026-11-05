<?php

use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SyncFixture;
use Tests\TestCase;

/**
 * 0.8.6 — el endpoint del sync legacy responde como el legacy.
 *
 * Regla que fija: *la respuesta del sync es la del legacy*.
 *
 * El sync tiene SU PROPIO contrato de respuesta, con tres claves
 * (`status`, `message`, `for`) y su codigo HTTP. No es el `{errorMsg}` del
 * resto de la API: el terminal legacy lee estas tres claves, y si el
 * maestro cambiara la forma el terminal se quedaria sin sincronizar.
 *
 * Los codigos son los del legacy (`sync.php`):
 *
 * | Situacion                              | Codigo | Mensaje                                  |
 * |----------------------------------------|--------|------------------------------------------|
 * | lote autenticado                       | 200    | `sync successfully done`                 |
 * | la sincronizacion esta apagada         | 403    | `Synchronization is disabled on this server` |
 * | cuerpo demasiado grande                | 413    | `Payload too large`                      |
 * | demasiadas sentencias                  | 413    | `Too many statements`                    |
 * | accion que no es `sync`                | 400    | `Invalid Action`                         |
 * | lote vacio                             | 400    | `Nothing to sync`                        |
 * | autenticacion fallida                  | 401    | `Invalid Action` (opaco)                 |
 */
beforeEach(function () {
    config([
        'sync.enabled' => true,
        'sync.clients' => [SyncFixture::CLIENTE => SyncFixture::SECRETO],
        'sync.max_age' => 300,
        'sync.legacy_auth' => false,
        // El TRAFICO se filtra antes de autenticar (como en el legacy). En
        // los tests la peticion sale de loopback y sin HTTPS, asi que cada
        // caso declara EXPLICITAMENTE el transporte que necesita. El
        // default de produccion sigue siendo exigir HTTPS y no admitir
        // localhost: no se relaja aqui, se declara.
        'sync.require_https' => false,
        'sync.allow_localhost' => true,
    ]);
});

/**
 * Enviar el cuerpo CRUDO al endpoint: el `data` viaja como un string JSON
 * dentro del cuerpo y la firma se calcula sobre sus bytes exactos, asi que
 * el cuerpo tiene que llegar tal cual se firmo.
 */
function enviar_lote(TestCase $caso, string $cuerpo): TestResponse
{
    return $caso->call(
        'POST',
        '/api/sync',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $cuerpo,
    );
}

test('test_el_lote_autenticado_responde_exito_con_las_tres_claves_del_legacy', function () {
    SyncFixture::exigirRedis();

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    enviar_lote($this, $lote['body'])
        ->assertOk()
        ->assertExactJson([
            'status' => 'success',
            'message' => 'sync successfully done',
            'for' => 'sync',
        ]);
});

test('test_una_accion_desconocida_responde_cuatrocientos_accion_invalida', function () {
    SyncFixture::exigirRedis();

    // La accion va DENTRO de la firma: el terminal firma lo que envia.
    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
        ['action' => 'inventario'],
    );

    enviar_lote($this, $lote['body'])
        ->assertStatus(400)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Invalid Action',
            'for' => 'invalid',
        ]);
});

test('test_un_lote_vacio_responde_cuatrocientos_nada_que_sincronizar', function () {
    SyncFixture::exigirRedis();

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        [],
    );

    enviar_lote($this, $lote['body'])
        ->assertStatus(400)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Nothing to sync',
            'for' => 'invalid',
        ]);
});

test('test_una_autenticacion_fallida_responde_cuatrocientos_uno_sin_revelar_el_motivo', function () {
    SyncFixture::exigirRedis();

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
        ['signature' => str_repeat('0', 64)],
    );

    $respuesta = enviar_lote($this, $lote['body']);

    $respuesta->assertStatus(401)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Invalid Action',
            'for' => 'invalid',
        ]);

    // El motivo INTERNO (firma rota, cliente desconocido, nonce repetido...)
    // va al log del servidor, nunca al cliente.
    expect((string) $respuesta->getContent())->not->toContain('bad_signature')
        ->and((string) $respuesta->getContent())->not->toContain('unknown_client')
        ->and((string) $respuesta->getContent())->not->toContain('replayed_request');
});

test('test_la_sincronizacion_apagada_responde_cuatrocientos_tres_sincronizacion_deshabilitada', function () {
    config(['sync.enabled' => false]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    enviar_lote($this, $lote['body'])
        ->assertStatus(403)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Synchronization is disabled on this server',
            'for' => 'invalid',
        ]);
});

test('test_un_cuerpo_demasiado_grande_responde_cuatrocientos_trece_payload_demasiado_grande', function () {
    config(['sync.max_payload' => 64]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    enviar_lote($this, $lote['body'])
        ->assertStatus(413)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Payload too large',
            'for' => 'invalid',
        ]);
});

test('test_un_lote_con_demasiadas_sentencias_responde_cuatrocientos_trece', function () {
    SyncFixture::exigirRedis();

    config(['sync.max_statements' => 1]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        [
            ['sql' => 'INSERT INTO selling_info (invoice_number) VALUES (?)', 'args' => ['V-0001']],
            ['sql' => 'INSERT INTO selling_info (invoice_number) VALUES (?)', 'args' => ['V-0002']],
        ],
    );

    enviar_lote($this, $lote['body'])
        ->assertStatus(413)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Too many statements',
            'for' => 'invalid',
        ]);
});
