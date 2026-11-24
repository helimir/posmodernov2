<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PrintFixture;

/**
 * F3-02 — el LATIDO HONESTO del agente LAN.
 *
 * REGLA que fija: *el numero que se le dice al agente es el numero de
 * trabajos que ese agente puede tomar*.
 *
 * El latido contaba los trabajos EN COLA de TODA la tienda: tambien los del
 * transporte `browser` (que no se escriben en bytes) y los del transporte
 * `server_socket` (que escribe el servidor, no el agente). El agente recibia
 * un numero que no correspondia a nada que él pudiera tomar: el unico trabajo
 * que el agente toma es el de un destino `lan_agent`.
 *
 * Y EL NUMERO ES EL DEL RECLAMO: el long-poll del agente toma los trabajos
 * encolados de los destinos `lan_agent` de su tienda, asi que el contador del
 * latido y el reclamo tienen que CONTAR LO MISMO. Si divergen, el agente
 * espera trabajo que no va a llegar, o toma trabajo que no le dijeron.
 *
 * Y LA TIENDA ES LA DEL AGENTE: el numero nunca sale del cuerpo de la
 * peticion.
 */

/**
 * El AGENTE de una tienda, con su token en claro.
 *
 * @param  array<string, mixed>  $tienda
 * @return array{agent_id: int, token: string}
 */
function agente_del_latido(array $tienda): array
{
    $agente = PrintFixture::agente($tienda['store_id']);

    return [
        'agent_id' => (int) $agente['agent_id'],
        'token' => (string) $agente['token'],
    ];
}

/**
 * El LATIDO cuenta SOLO los trabajos que el agente puede tomar.
 *
 * REGLA que fija: *el latido no cuenta trabajo de otro transporte*. Un
 * trabajo de `browser` no se escribe en bytes, y uno de `server_socket` los
 * escribe el servidor: ninguno de los dos lo toma el agente.
 */
test('test_el_latido_cuenta_solo_los_trabajos_que_el_agente_puede_tomar', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();

    $agente = agente_del_latido($tienda);

    // El destino del AGENTE (el que trae el contexto): este si lo puede
    // tomar.
    $delAgente = $tienda;

    // Y dos mas que NO: el navegador no escribe bytes, y el socket lo
    // escribe el servidor.
    $delNavegador = PrintFixture::destino($tienda['store_id'], 'browser', [
        'name' => 'Comprobante en el navegador',
    ]);

    $delServidor = PrintFixture::destino($tienda['store_id'], 'server_socket', [
        'name' => 'Impresora del servidor',
    ]);

    $enCola = PrintFixture::trabajo($tienda['store_id'], $delAgente['target_id']);
    PrintFixture::trabajo($tienda['store_id'], $delNavegador['target_id']);
    PrintFixture::trabajo($tienda['store_id'], $delServidor['target_id']);

    // Y un trabajo ya IMPRESO: encolado no es lo mismo que pendiente.
    PrintFixture::trabajo($tienda['store_id'], $delAgente['target_id'], [
        'status' => 'printed',
    ]);

    // Y el trabajo de OTRA tienda, que tampoco cuenta.
    $otra = PrintFixture::contexto();
    PrintFixture::trabajo($otra['store_id'], $otra['target_id']);

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    $latido = $this->withHeaders($cabeceras)->postJson('/api/print-agents/heartbeat');

    $latido->assertOk()->assertJsonPath('status', 'ok');

    // ANTES este numero era 3 (los tres trabajos encolados de la tienda, sin
    // mirar el transporte). Ahora es 1: el unico que el agente puede tomar.
    expect((int) $latido->json('pending_jobs'))->toBe(1);

    // Y el numero coincide con lo que el agente REALMENTE toma: el long-poll
    // le devuelve exactamente ese trabajo, y ninguno mas.
    $trabajos = $this->withHeaders($cabeceras)->getJson('/api/print-agents/jobs?wait=0');

    $trabajos->assertOk()->assertJsonCount(1, 'jobs');

    expect((int) $trabajos->json('jobs.0.job_id'))->toBe($enCola);
});

/**
 * El LATIDO no cuenta el trabajo de otra tienda.
 *
 * REGLA que fija: *la tienda del latido es la del agente*. El numero se
 * cuenta en la tienda del `agent_id` autenticado, nunca en la del cuerpo de
 * la peticion.
 */
test('test_el_latido_no_cuenta_el_trabajo_de_otra_tienda', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();

    $agente = agente_del_latido($tienda);

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    // El trabajo de la OTRA tienda.
    $otra = PrintFixture::contexto();
    PrintFixture::trabajo($otra['store_id'], $otra['target_id']);

    // Y un `store_id` en el cuerpo, que NO cambia la tienda.
    $latido = $this->withHeaders($cabeceras)
        ->postJson('/api/print-agents/heartbeat', ['store_id' => $otra['store_id']]);

    $latido->assertOk();

    expect((int) $latido->json('pending_jobs'))->toBe(0);
});

/**
 * El INVENTARIO que el agente declara no cambia el numero: el desajuste es
 * un AVISO, no un rechazo.
 *
 * REGLA que fija: *una impresora apagada no es un destino descartado*. Un
 * destino que el inventario del agente no declara NO se rechaza: el aviso
 * dice que puede estar apagada, y rechazar el trabajo seria perder un
 * ticket que si se puede imprimir. Asi que el trabajo sigue en la cola y el
 * numero lo cuenta.
 */
test('test_el_latido_cuenta_el_trabajo_de_un_destino_que_el_inventario_no_cubre', function () {
    config(['print.lan_agent_enabled' => true]);

    $tienda = PrintFixture::contexto();

    $agente = agente_del_latido($tienda);

    $destino = $tienda;

    $trabajoId = PrintFixture::trabajo($tienda['store_id'], $destino['target_id']);

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    // El agente declara un inventario que NO cubre ese destino.
    $latido = $this->withHeaders($cabeceras)->postJson('/api/print-agents/heartbeat', [
        'printers' => [['key' => 'IMPRESORA-QUE-NO-EXISTE']],
    ]);

    $latido->assertOk();

    // El AVISO esta: el desajuste se ve.
    expect($latido->json('warnings'))->toHaveCount(1);

    // Y el trabajo SIGUE en la cola y el numero lo cuenta: el aviso no es un
    // rechazo.
    expect((int) $latido->json('pending_jobs'))->toBe(1)
        ->and((string) DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('queued');
});
