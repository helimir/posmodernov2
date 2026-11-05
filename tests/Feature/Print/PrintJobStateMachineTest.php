<?php

use App\Jobs\DispatchPrintJob;
use App\Models\PrintJob;
use App\Print\PrintJobStateMachine;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PrintFixture;

/**
 * 0.8.5 — la MÁQUINA DE ESTADOS de los trabajos de impresion.
 *
 * Regla que fija: *un trabajo de impresion cambia de estado ONLY por la
 * maquina de estados, y cada transicion valida el estado de ORIGEN*.
 *
 * El estado del trabajo es INDEPENDIENTE del estado del documento fiscal:
 * emitir no depende de imprimir. Un trabajo que se reimprime es un trabajo
 * NUEVO, y un trabajo cancelado es terminal.
 */
test('test_un_trabajo_encolado_se_reclama_con_agente_y_lease', function () {
    $contexto = PrintFixture::contexto();
    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id']);

    $agenteId = DB::table('print_agents')->insertGetId([
        'store_id' => $contexto['store_id'],
        'name' => 'Agente de la prueba',
        'token_hash' => hash('sha256', 'token-de-prueba'),
        'last_heartbeat_at' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(PrintJobStateMachine::class)->claim($trabajoId, (int) $agenteId, 60);

    $trabajo = DB::table('print_jobs')->where('id', $trabajoId)->first();

    expect($trabajo->status)->toBe('claimed')
        ->and((int) $trabajo->agent_id)->toBe((int) $agenteId)
        ->and($trabajo->lease_expires_at)->not->toBeNull()
        ->and(strtotime((string) $trabajo->lease_expires_at))->toBeGreaterThan(time());
});

test('test_un_trabajo_reclamado_pasa_a_imprimiendo', function () {
    $contexto = PrintFixture::contexto();
    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
        'status' => 'claimed',
        'agent_id' => PrintFixture::agente($contexto['store_id'])['agent_id'],
        'lease_expires_at' => now()->addMinute(),
    ]);

    app(PrintJobStateMachine::class)->markPrinting($trabajoId);

    expect(DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('printing');
});

test('test_un_trabajo_imprimiendo_pasa_a_impreso', function () {
    $contexto = PrintFixture::contexto();
    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
        'status' => 'printing',
        'agent_id' => PrintFixture::agente($contexto['store_id'])['agent_id'],
        'lease_expires_at' => now()->addMinute(),
    ]);

    app(PrintJobStateMachine::class)->markPrinted($trabajoId);

    expect(DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('printed');
});

test('test_un_trabajo_fallido_guarda_el_error_del_agente', function () {
    $contexto = PrintFixture::contexto();
    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
        'status' => 'printing',
        'agent_id' => PrintFixture::agente($contexto['store_id'])['agent_id'],
        'lease_expires_at' => now()->addMinute(),
    ]);

    app(PrintJobStateMachine::class)->markFailed($trabajoId, 'La impresora esta apagada');

    $trabajo = DB::table('print_jobs')->where('id', $trabajoId)->first();

    expect($trabajo->status)->toBe('failed')
        ->and($trabajo->last_error)->toBe('La impresora esta apagada');
});

test('test_un_trabajo_cancelado_es_terminal_y_no_se_reencola', function () {
    $contexto = PrintFixture::contexto();
    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id']);

    $maquina = app(PrintJobStateMachine::class);

    $maquina->cancel($trabajoId);

    expect(DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('cancelled');

    // Y NO se reencola: cancelado es terminal.
    expect(fn () => $maquina->requeue($trabajoId))->toThrow(RuntimeException::class);
});

test('test_un_trabajo_fallido_se_reencola_y_cuenta_un_intento_mas', function () {
    $contexto = PrintFixture::contexto();
    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
        'status' => 'failed',
        'attempts' => 2,
        'agent_id' => PrintFixture::agente($contexto['store_id'])['agent_id'],
        'lease_expires_at' => now()->addMinute(),
        'last_error' => 'La impresora esta apagada',
    ]);

    app(PrintJobStateMachine::class)->requeue($trabajoId);

    $trabajo = DB::table('print_jobs')->where('id', $trabajoId)->first();

    expect($trabajo->status)->toBe('queued')
        ->and((int) $trabajo->attempts)->toBe(3)
        ->and($trabajo->agent_id)->toBeNull()
        ->and($trabajo->lease_expires_at)->toBeNull();
});

test('test_un_trabajo_con_el_lease_caducado_vuelve_a_la_cola_con_un_intento_mas', function () {
    $contexto = PrintFixture::contexto();
    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
        'status' => 'claimed',
        'attempts' => 1,
        'agent_id' => PrintFixture::agente($contexto['store_id'])['agent_id'],
        'lease_expires_at' => now()->subMinute(),
    ]);

    $liberados = app(PrintJobStateMachine::class)->releaseExpiredLeases($contexto['store_id']);

    $trabajo = DB::table('print_jobs')->where('id', $trabajoId)->first();

    expect($liberados)->toBe(1)
        ->and($trabajo->status)->toBe('queued')
        ->and((int) $trabajo->attempts)->toBe(2)
        ->and($trabajo->agent_id)->toBeNull()
        ->and($trabajo->lease_expires_at)->toBeNull();
});

test('test_los_trabajos_con_el_lease_caducado_de_otra_tienda_no_se_liberan', function () {
    $contexto = PrintFixture::contexto();
    $otra = PrintFixture::contexto();

    $propio = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
        'status' => 'printing',
        'lease_expires_at' => now()->subMinute(),
    ]);

    $ajeno = PrintFixture::trabajo($otra['store_id'], $otra['target_id'], [
        'status' => 'claimed',
        'lease_expires_at' => now()->subMinute(),
    ]);

    $liberados = app(PrintJobStateMachine::class)->releaseExpiredLeases($contexto['store_id']);

    expect($liberados)->toBe(1)
        ->and(DB::table('print_jobs')->where('id', $propio)->value('status'))->toBe('queued')
        ->and(DB::table('print_jobs')->where('id', $ajeno)->value('status'))->toBe('claimed');
});

test('test_una_transicion_invalida_falla_con_un_error_claro', function () {
    $contexto = PrintFixture::contexto();
    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id']);

    $maquina = app(PrintJobStateMachine::class);

    // Un trabajo ENCOLADO no se puede imprimir: el estado de ORIGEN no encaja.
    expect(fn () => $maquina->markPrinted($trabajoId))->toThrow(RuntimeException::class);

    // Y el trabajo NO cambia de estado.
    expect(DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('queued');
});

test('test_una_transicion_sobre_un_trabajo_inexistente_falla_con_un_error_claro', function () {
    expect(fn () => app(PrintJobStateMachine::class)->claim(999999, 1, 60))
        ->toThrow(RuntimeException::class);
});

test('test_el_job_de_reintento_reencola_el_trabajo_fallido', function () {
    $contexto = PrintFixture::contexto();
    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
        'status' => 'failed',
        'attempts' => 1,
        'last_error' => 'La impresora esta apagada',
    ]);

    (new DispatchPrintJob($trabajoId))->handle(app(PrintJobStateMachine::class));

    $trabajo = PrintJob::find($trabajoId);

    expect($trabajo->status)->toBe('queued')
        ->and($trabajo->attempts)->toBe(2);
});
