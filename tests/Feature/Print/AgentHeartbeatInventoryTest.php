<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PrintFixture;

/**
 * F2-09 — el LATIDO QUE ACEPTA EL CUERPO.
 *
 * REGLA que fija este fichero: *el latido declara el inventario de impresoras
 * del agente, el servidor lo guarda y lo enseña, y AVISA de los destinos que
 * ese inventario no cubre, pero NO rechaza el trabajo*.
 *
 * El heartbeat antes ignoraba el cuerpo: el agente no podia decir que
 * impresoras tiene, y el servidor no podia avisarle de que un destino no
 * estaba cubierto. El desajuste entre la impresora declarada y la impresora
 * real era, por tanto, SILENCIOSO.
 *
 * Y EL AVISO NO ES UN RECHAZO: una impresora puede estar apagada en ese
 * momento, y rechazar el trabajo seria perder un ticket que si se puede
 * imprimir. El servidor AVISA y deja seguir.
 */
test('test_el_latido_acepta_el_cuerpo_con_el_inventario_y_el_servidor_lo_guarda', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();
    $agente = PrintFixture::agente($tienda['store_id']);

    $codigo = (string) DB::table('printers')
        ->where('id', $tienda['printer_id'])
        ->value('code');

    // 1. El latido DECLARA el inventario y las capacidades.
    $respuesta = $this->withHeaders([
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ])->postJson('/api/print-agents/heartbeat', [
        'printers' => [
            [
                'key' => $codigo,
                'name' => 'Impresora de prueba',
                'profile' => 'default',
                'char_per_line' => 42,
                'status' => 'ready',
            ],
        ],
        'capabilities' => ['escpos', 'qr', 'cut'],
    ]);

    $respuesta->assertOk()->assertJsonPath('status', 'ok');

    // 2. El servidor lo GUARDA.
    $fila = DB::table('print_agents')->where('id', $agente['agent_id'])->first();

    $inventario = json_decode((string) $fila->printers, true);
    $capacidades = json_decode((string) $fila->capabilities, true);

    expect($inventario)->toBeArray()
        ->and((string) ($inventario[0]['code'] ?? ''))->toBe($codigo)
        ->and((string) ($inventario[0]['name'] ?? ''))->toBe('Impresora de prueba')
        ->and((int) ($inventario[0]['char_per_line'] ?? 0))->toBe(42)
        ->and($capacidades)->toBe(['escpos', 'qr', 'cut']);

    // 3. Y lo ENSEÑA: el agente ve lo que el servidor tiene de el.
    expect($respuesta->json('printers.0.code'))->toBe($codigo)
        ->and($respuesta->json('printers.0.name'))->toBe('Impresora de prueba')
        ->and($respuesta->json('capabilities'))->toBe(['escpos', 'qr', 'cut']);
});

/**
 * F2-09 — el servidor AVISA de un destino que el inventario del agente no
 * cubre.
 */
test('test_el_servidor_avisa_de_un_destino_no_cubierto_por_el_inventario', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();

    // Un SEGUNDO destino de la misma tienda, con OTRA impresora: es el que
    // el inventario del agente no va a declarar.
    $segundo = PrintFixture::destino($tienda['store_id'], 'lan_agent', [
        'name' => 'Segundo destino',
    ]);

    $agente = PrintFixture::agente($tienda['store_id']);

    $codigo = (string) DB::table('printers')
        ->where('id', $tienda['printer_id'])
        ->value('code');

    $respuesta = $this->withHeaders([
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ])->postJson('/api/print-agents/heartbeat', [
        'printers' => [['key' => $codigo, 'name' => 'Impresora de prueba']],
    ]);

    $respuesta->assertOk();

    // El aviso es del destino NO cubierto, y solo de ese.
    $avisos = $respuesta->json('warnings');

    expect($avisos)->toBeArray()->toHaveCount(1)
        ->and((int) $avisos[0]['target_id'])->toBe((int) $segundo['target_id'])
        ->and((string) $avisos[0]['reason'])->not->toBe('');

    // Y si el inventario declara las dos impresoras, no hay aviso.
    $codigoDelSegundo = (string) DB::table('printers')
        ->where('id', $segundo['printer_id'])
        ->value('code');

    $this->withHeaders([
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ])->postJson('/api/print-agents/heartbeat', [
        'printers' => [
            ['key' => $codigo],
            ['key' => $codigoDelSegundo],
        ],
    ])->assertOk()->assertJsonPath('warnings', []);
});

/**
 * F2-09 — el desajuste del inventario NO rechaza el trabajo.
 *
 * Una impresora puede estar apagada en ese momento, y rechazar el trabajo
 * seria perder un ticket que SI se puede imprimir. El servidor avisa y deja
 * entregar el trabajo.
 */
test('test_el_desajuste_del_inventario_no_rechaza_el_trabajo', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();
    $agente = PrintFixture::agente($tienda['store_id']);

    $trabajoId = PrintFixture::trabajo($tienda['store_id'], $tienda['target_id']);

    $codigo = (string) DB::table('printers')
        ->where('id', $tienda['printer_id'])
        ->value('code');

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    // 1. El latido AVISA: el inventario no declara la impresora del destino.
    $latido = $this->withHeaders($cabeceras)->postJson('/api/print-agents/heartbeat', [
        'printers' => [['key' => 'IMPRESORA-QUE-NO-EXISTE']],
    ]);

    $latido->assertOk();

    expect($latido->json('warnings'))->toHaveCount(1);

    // 2. Y el trabajo SE ENTREGA igual: el aviso no es un rechazo.
    $trabajos = $this->withHeaders($cabeceras)->getJson('/api/print-agents/jobs?wait=0');

    $trabajos->assertOk();

    $ids = array_map(fn (array $trabajo) => (int) $trabajo['job_id'], $trabajos->json('jobs'));

    expect($ids)->toContain($trabajoId)
        ->and(DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('claimed');
});
