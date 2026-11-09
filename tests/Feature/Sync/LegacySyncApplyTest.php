<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\SyncFixture;
use Tests\TestCase;

/**
 * 0.8.8 — el endpoint del sync legacy APLICA el lote de verdad.
 *
 * REGLA que fija: *el lote se aplica, o el terminal se queda sin sincronizar*.
 *
 * El legacy aplica el lote y, si falla, responde `500 Sync failed`. Un
 * maestro que responde `200` sin tocar la base de datos MIENTE al terminal:
 * el terminal trunca su log con esa respuesta y los datos se pierden sin
 * que nadie lo note.
 *
 * El ADAPTADOR del modulo (`sync.legacy_adapter_enabled`) es el interruptor:
 * apagado, el maestro no traduce ni aplica, porque el mapa de traduccion
 * todavia no esta sembrado ni auditado (0.1.3). Encendido, el lote se
 * aplica en UNA transaccion.
 *
 * El contexto del lote (la TIENDA y la FECHA DE NEGOCIO) sale del cliente
 * AUTENTICADO, nunca del cuerpo del lote: un terminal no escribe en la
 * tienda que no es suya.
 */
beforeEach(function () {
    config([
        'sync.enabled' => true,
        'sync.clients' => [SyncFixture::CLIENTE => SyncFixture::SECRETO],
        'sync.max_age' => 300,
        'sync.legacy_auth' => false,
        'sync.require_https' => false,
        'sync.allow_localhost' => true,
        // El ADAPTADOR: sin el, el maestro no aplica nada.
        'sync.legacy_adapter_enabled' => true,
    ]);

    DB::table('legacy_translation_map')->delete();

    DB::table('legacy_translation_map')->insert([
        ['legacy_table' => 'selling_info', 'legacy_column' => '*', 'new_table' => 'sales', 'new_column' => '*', 'transform' => null],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'invoice_id', 'new_table' => 'sales', 'new_column' => 'reference_no', 'transform' => 'rename'],
        // La TIENDA sale del CONTEXTO (la del cliente autenticado).
        ['legacy_table' => 'selling_info', 'legacy_column' => 'store_id', 'new_table' => 'sales', 'new_column' => 'store_id', 'transform' => 'inject_store'],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'payment_status', 'new_table' => 'sales', 'new_column' => 'status', 'transform' => 'cast_enum:paid:paid:due:confirmed'],
        ['legacy_table' => 'selling_info', 'legacy_column' => 'created_at', 'new_table' => 'sales', 'new_column' => 'sale_date', 'transform' => 'rename'],
    ]);
});

/**
 * Enviar el cuerpo CRUDO al endpoint: el `data` viaja como un string JSON
 * dentro del cuerpo y la firma se calcula sobre sus bytes exactos.
 */
function enviar_lote_aplicado(TestCase $caso, string $cuerpo): TestResponse
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

/**
 * El LOTE del terminal: una venta.
 *
 * @return array<int, array<string, mixed>>
 */
function lote_de_venta(): array
{
    return [
        [
            'sql' => 'INSERT INTO `selling_info` (`invoice_id`, `store_id`, `payment_status`, `created_at`) VALUES (?, ?, ?, ?)',
            // La tienda que envio el legacy es la 999, que NO existe aqui: si
            // el maestro usara la del lote, la venta no tendria donde
            // escribirse.
            'args' => ['12021/00000004', 999, 'paid', '2026-11-05 10:00:00'],
        ],
    ];
}

test('test_el_lote_firmado_se_aplica_de_verdad_y_la_venta_existe', function () {
    SyncFixture::exigirRedis();

    $storeId = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    // La TIENDA del cliente autenticado: es el dato que el lote no puede
    // decidir por si mismo.
    config(['sync.client_stores' => [SyncFixture::CLIENTE => $storeId]]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        lote_de_venta(),
    );

    enviar_lote_aplicado($this, $lote['body'])
        ->assertOk()
        ->assertExactJson([
            'status' => 'success',
            'message' => 'sync successfully done',
            'for' => 'sync',
        ]);

    // Y la VENTA existe: el lote se aplico de verdad.
    $venta = DB::table('sales')->where('reference_no', '12021/00000004')->first();

    expect($venta)->not->toBeNull()
        ->and((int) $venta->store_id)->toBe($storeId)
        ->and((string) $venta->status)->toBe('paid')
        ->and(DB::table('sync_batches')->count())->toBe(1);
});

test('test_el_terminal_reenvia_tras_perder_la_respuesta_y_el_maestro_lo_acepta_sin_congelarse', function () {
    SyncFixture::exigirRedis();

    $storeId = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    config(['sync.client_stores' => [SyncFixture::CLIENTE => $storeId]]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        lote_de_venta(),
    );

    enviar_lote_aplicado($this, $lote['body'])->assertOk();

    expect(DB::table('sales')->where('reference_no', '12021/00000004')->count())->toBe(1)
        ->and(DB::table('sync_batches')->count())->toBe(1);

    // El REENVIO IDENTICO del mismo lote: el terminal que perdio la
    // respuesta lo reenvia con un `nonce` y un `timestamp` NUEVOS, asi que
    // el cuerpo HTTP es otro y la firma es otra. El lote es el MISMO.
    $reenvio = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        lote_de_venta(),
    );

    enviar_lote_aplicado($this, $reenvio['body'])
        ->assertOk()
        ->assertExactJson([
            'status' => 'success',
            'message' => 'sync successfully done',
            'for' => 'sync',
        ]);

    // Y NO se aplico dos veces.
    expect(DB::table('sales')->where('reference_no', '12021/00000004')->count())->toBe(1)
        ->and(DB::table('sync_batches')->count())->toBe(1);
});

test('test_un_lote_que_no_se_puede_aplicar_responde_sin_cinco_sin_revelar_el_motivo', function () {
    SyncFixture::exigirRedis();

    $storeId = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    config(['sync.client_stores' => [SyncFixture::CLIENTE => $storeId]]);

    // La segunda operacion NO se puede aplicar: `store_id` y `created_at`
    // son NOT NULL en el destino y no viajan.
    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        [
            [
                'sql' => 'INSERT INTO `selling_info` (`invoice_id`, `store_id`, `payment_status`, `created_at`) VALUES (?, ?, ?, ?)',
                'args' => ['12021/00000004', 999, 'paid', '2026-11-05 10:00:00'],
            ],
            [
                'sql' => 'INSERT INTO `selling_info` (`invoice_id`) VALUES (?)',
                'args' => ['12021/00000005'],
            ],
        ],
    );

    $respuesta = enviar_lote_aplicado($this, $lote['body']);

    $respuesta->assertStatus(500)
        ->assertExactJson([
            'status' => 'error',
            'message' => 'Sync failed',
            'for' => 'sync',
        ]);

    // El motivo INTERNO va al log del servidor, nunca al cliente.
    expect((string) $respuesta->getContent())->not->toContain('NOT NULL')
        ->and((string) $respuesta->getContent())->not->toContain('selling_info');

    // Y no queda NADA aplicado: el lote es todo o nada.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sync_batches')->count())->toBe(0);
});

test('test_con_el_adaptador_apagado_el_lote_no_se_aplica_y_el_terminal_no_se_congela', function () {
    SyncFixture::exigirRedis();

    // El interruptor del modulo: apagado, el maestro no traduce ni aplica.
    config(['sync.legacy_adapter_enabled' => false]);

    $storeId = DB::table('stores')->insertGetId(['name' => 'Tienda del maestro']);

    config(['sync.client_stores' => [SyncFixture::CLIENTE => $storeId]]);

    $lote = SyncFixture::loteFirmado(
        SyncFixture::CLIENTE,
        SyncFixture::SECRETO,
        lote_de_venta(),
    );

    // El terminal NO se congela: recibe EXITO y no acumula log.
    enviar_lote_aplicado($this, $lote['body'])
        ->assertOk()
        ->assertExactJson([
            'status' => 'success',
            'message' => 'sync successfully done',
            'for' => 'sync',
        ]);

    // Pero el lote NO se aplico: el maestro todavia no esta listo.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sync_batches')->count())->toBe(0);
});
