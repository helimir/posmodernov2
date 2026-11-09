<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PrintFixture;

/**
 * 0.9.11 — lo unificable es el origen del contenido, no el binario.
 *
 * Regla que fija: *lo unificable es el origen del contenido, no el binario*.
 * El agente LAN NO interpreta el ticket: recibe los BYTES ya generados y sólo
 * los escribe. El corte del papel viaja dentro de los bytes, y el hash viaja
 * con ellos para que el agente pueda verificar lo que recibe.
 *
 * Por eso el servidor es el único que puede decir "este ticket no es
 * representable": si el destino no soporta el QR, el fallo ocurre ANTES de
 * enviar nada (lo fija `test_un_qr_sobre_un_perfil_sin_soporte_falla_en_el_servidor_antes_de_enviarlo`).
 */
test('test_el_agente_recibe_bytes_ya_generados_y_no_interpreta_el_ticket', function () {
    config(['print.lan_agent_enabled' => true]);

    $contexto = PrintFixture::contexto();
    $agente = PrintFixture::agente($contexto['store_id']);

    // Los BYTES ya generados: el servidor los congela en el trabajo. El
    // ultimo es el CORTE del papel (`GS V 65`), que viaja DENTRO.
    $bytes = "\x1b\x40TICKET YA GENERADO\x1d\x56\x41\x03";

    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
        'payload_bytes' => $bytes,
    ]);

    $cabeceras = [
        'Authorization' => 'Bearer '.$agente['token'],
        'X-Agent-Id' => (string) $agente['agent_id'],
    ];

    $respuesta = $this->withHeaders($cabeceras)->getJson('/api/print-agents/jobs?wait=0');

    $respuesta->assertOk();

    $trabajo = collect($respuesta->json('jobs'))->firstWhere('job_id', $trabajoId);

    expect($trabajo)->not->toBeNull();

    // 1. El agente recibe los BYTES, y son EXACTAMENTE los del trabajo: byte a
    //    byte, sin que el servidor los recomponga ni el agente los componga.
    $recibidos = base64_decode((string) $trabajo['payload_b64'], true);

    expect($recibidos)->toBe($bytes)
        ->and(hash('sha256', $recibidos))->toBe((string) $trabajo['payload_sha256'])
        ->and((string) $trabajo['payload_sha256'])->toBe(
            (string) DB::table('print_jobs')->where('id', $trabajoId)->value('payload_sha256')
        );

    // 2. Y NO hay ningun TICKET que interpretar: el trabajo no trae lineas,
    //    ni titulo, ni plantilla. Solo bytes y su hash.
    expect(array_intersect(['lines', 'title', 'payload', 'template'], array_keys($trabajo)))->toBe([]);

    // 3. El CORTE del papel viaja en los bytes: el agente no decide cuando
    //    cortar, solo escribe lo que le dan.
    expect($recibidos)->toContain("\x1d\x56\x41")
        ->and(substr($recibidos, -1))->toBe("\x03");

    // 4. Y el trabajo queda RECLAMADO por el agente: el modelo del ticket
    //    sigue en la base, pero el agente no lo necesita para imprimir.
    expect((string) DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('claimed')
        ->and((int) DB::table('print_jobs')->where('id', $trabajoId)->value('agent_id'))
        ->toBe((int) $agente['agent_id']);
});
