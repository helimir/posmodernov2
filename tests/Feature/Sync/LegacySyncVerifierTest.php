<?php

use App\Sync\Legacy\LegacySyncVerifier;
use Tests\Fixtures\SyncFixture;

/**
 * 0.8.6-T — el CONTRATO DE WIRE v2 del sync legacy, INTACTO.
 *
 * Regla que fija: *el contrato de wire no cambia*.
 *
 * El terminal legacy empuja su log de SQL con un protocolo firmado (v2) y
 * el maestro tiene que aceptarlo EXACTAMENTE como lo hacia el legacy:
 * mismas claves, mismo canonizado, misma firma, mismo limite de antiguedad,
 * mismo orden de verificacion y misma forma de respuesta.
 *
 * El orden de verificacion es el del legacy (`_inc/helper/sync.php`):
 *
 *   1. el cliente es conocido,
 *   2. el `timestamp` tiene la forma `^\d{10}$`,
 *   3. la antiguedad no supera `SYNC_MAX_AGE`,
 *   4. la firma coincide (`hash_equals`),
 *   5. y SOLO ENTONCES se consume el nonce.
 *
 * El paso 5 va al final a proposito: una peticion con la firma rota NO
 * quema el nonce, para que un terminal no autenticado no pueda agotar los
 * nonces de los demas.
 */
test('test_un_lote_firmado_con_los_vectores_dorados_se_acepta_con_la_misma_respuesta', function () {
    SyncFixture::exigirRedis();

    config([
        'sync.enabled' => true,
        'sync.clients' => [SyncFixture::CLIENTE => SyncFixture::SECRETO],
        'sync.max_age' => 300,
        'sync.legacy_auth' => false,
        // El TRAFICO se filtra antes de autenticar (como en el legacy). El
        // endpoint de este test se llama desde loopback y sin HTTPS, asi que
        // se declara EXPLICITAMENTE: el default de produccion (exigir
        // HTTPS, no admitir localhost) no se relaja.
        'sync.require_https' => false,
        'sync.allow_localhost' => true,
    ]);

    $verificador = app(LegacySyncVerifier::class);

    // 1. El lote firmado con el CANONIZADO DEL LEGACY: el `data` es un
    //    string JSON y la firma es el HMAC del canonizado.
    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    $verificacion = $verificador->verify($lote['payload'], $lote['body']);

    expect($verificacion->ok)->toBeTrue()
        ->and($verificacion->mode)->toBe('signed')
        ->and($verificacion->client)->toBe(SyncFixture::CLIENTE)
        ->and($verificacion->reason)->toBeNull()
        ->and($verificacion->httpStatus)->toBe(200);

    // 2. La RESPUESTA del endpoint es la del legacy: TRES claves y nada
    //    mas (`status`, `message`, `for`). Es otro lote, con otro nonce:
    //    el de arriba ya se consumio al verificarlo aqui.
    $loteDelEndpoint = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    $respuesta = $this->call(
        'POST',
        '/api/sync',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $loteDelEndpoint['body'],
    );

    $respuesta->assertOk();

    expect(array_keys((array) $respuesta->json()))->toBe(['status', 'message', 'for'])
        ->and($respuesta->json('status'))->toBe('success')
        ->and($respuesta->json('message'))->toBe('sync successfully done')
        ->and($respuesta->json('for'))->toBe('sync');

    // 3. Una FIRMA INVALIDA con un nonce desconocido: se rechaza y el
    //    NONCE QUEDA DISPONIBLE. Si el nonce se consumiera antes de
    //    validar la firma, la peticion siguiente fallaria con
    //    `replayed_request` y el terminal legitimo se quedaria fuera.
    $nonce = bin2hex(random_bytes(16));

    $conFirmaRota = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
        ['nonce' => $nonce, 'signature' => str_repeat('0', 64)],
    );

    $rechazado = $verificador->verify($conFirmaRota['payload'], $conFirmaRota['body']);

    expect($rechazado->ok)->toBeFalse()
        ->and($rechazado->reason)->toBe('bad_signature')
        ->and($rechazado->httpStatus)->toBe(401);

    // Y el endpoint responde 401, opaco: el motivo interno NO sale.
    $this->call(
        'POST',
        '/api/sync',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $conFirmaRota['body'],
    )
        ->assertStatus(401)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Invalid Action',
            'for' => 'invalid',
        ]);

    // 4. El MISMO nonce, ahora con la firma buena: entra. El nonce NO se
    //    habia quemado.
    $conFirmaBuena = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
        ['nonce' => $nonce],
    );

    $aceptado = $verificador->verify($conFirmaBuena['payload'], $conFirmaBuena['body']);

    expect($aceptado->ok)->toBeTrue()
        ->and($aceptado->mode)->toBe('signed')
        ->and($aceptado->client)->toBe(SyncFixture::CLIENTE);

    // 5. Y el MISMO nonce otra vez: ahora SI es un reenvio.
    $reenvio = $verificador->verify($conFirmaBuena['payload'], $conFirmaBuena['body']);

    expect($reenvio->ok)->toBeFalse()
        ->and($reenvio->reason)->toBe('replayed_request')
        ->and($reenvio->httpStatus)->toBe(401);
});

/**
 * 0.8.6 — el almacen de NONCES es el anti-replay, y sin el NO SE ACEPTA
 * NADA.
 *
 * Regla que fija: *el verificador falla cerrado*.
 *
 * Los nonces viven en Redis con `SET NX EX` (mismo contrato que el
 * `fopen(..., 'x')` del legacy). Si Redis no esta, el verificador NO puede
 * garantizar que una peticion no se reenvie, asi que RECHAZA: aceptar sin
 * anti-replay es dejar la puerta abierta a que una peticion capturada se
 * repita para siempre.
 *
 * El motivo es interno (`nonce_store_unavailable`): el terminal solo ve el
 * 401 opaco del legacy.
 */
test('test_sin_el_almacen_de_nonces_el_verificador_falla_cerrado', function () {
    config([
        'sync.enabled' => true,
        'sync.clients' => [SyncFixture::CLIENTE => SyncFixture::SECRETO],
        'sync.max_age' => 300,
        'sync.legacy_auth' => false,
        // Una conexion que no escucha en ningun sitio.
        'database.redis.roto' => [
            'host' => '127.0.0.1',
            'port' => 6399,
            'database' => 0,
            'prefix' => 'sync_test_roto_',
        ],
        'sync.redis_connection' => 'roto',
    ]);

    $verificador = app(LegacySyncVerifier::class);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        SyncFixture::sentencias(),
    );

    $verificacion = $verificador->verify($lote['payload'], $lote['body']);

    expect($verificacion->ok)->toBeFalse()
        ->and($verificacion->reason)->toBe('nonce_store_unavailable')
        ->and($verificacion->httpStatus)->toBe(401)
        ->and($verificacion->message)->toBe('Invalid Action')
        ->and($verificacion->for)->toBe('invalid');
});
