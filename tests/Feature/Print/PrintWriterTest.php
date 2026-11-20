<?php

use App\Print\PrintJobStateMachine;
use App\Print\PrintJobWriter;
use App\Print\PrintWriteOutcome;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PrintFixture;
use Tests\Support\DatabaseMigrations;
use Tests\Support\PrintSocketServer;

/**
 * F2-06 — el ESCRITOR directo a la impresora.
 *
 * REGLA que fija: *el servidor escribe los bytes CONGELADOS del trabajo en el
 * destino, y quien puede escribir en un destino lo decide el TRANSPORTE*.
 *
 *  - `server_socket`: los escribe el SERVIDOR, y son los bytes tal cual los
 *    congeló: el hash del trabajo es el hash de lo que sale por el cable;
 *  - `lan_agent`: los escribe el AGENTE, y el servidor sólo deja el trabajo
 *    encolado. Si el servidor los escribiera, el ticket saldría DOS veces;
 *  - `browser`: el navegador es el destino. No hay impresora física, y el
 *    servidor no escribe bytes.
 *
 * Y EL FALLO DE LA IMPRESORA NO PIERDE LA VENTA: la venta ya está
 * confirmada y el ticket ya está encolado. Un trabajo que no se puede
 * imprimir es un trabajo `failed`, CON SU MOTIVO, y reintentable.
 *
 * POR QUE ESTE FICHERO NO VA CON TRANSACCION ENVOLVENTE: uno de sus tests
 * usa DOS SESIONES de PostgreSQL, y con la transacción de `RefreshDatabase` la
 * segunda sesión no ve las filas no commiteadas: el bloqueo de fila dejaría de
 * ser real y la prueba no probaría nada. El aislamiento lo da el `TRUNCATE`
 * del `afterEach`.
 */
uses(WithoutWrappingTransaction::class);

beforeEach(function () {
    DatabaseMigrations::asegurarEsquema();
});

afterEach(function () {
    DB::statement('TRUNCATE print_jobs, print_targets, printers, stores RESTART IDENTITY CASCADE');

    // La segunda sesion no hereda el `lock_timeout` de la prueba de
    // concurrencia: un limite de bloqueo global contaminaria las siguientes.
    DB::connection('pgsql_b')->statement("SET lock_timeout = '0'");
});

/**
 * Un destino del SERVIDOR que apunta al socket de prueba.
 *
 * @return array{store_id: int, printer_id: int, target_id: int}
 */
function destino_del_servidor(PrintSocketServer $socket, string $nombre = 'Caja del servidor'): array
{
    $contexto = PrintFixture::contexto('server_socket');

    DB::table('print_targets')
        ->where('id', $contexto['target_id'])
        ->update(['endpoint' => $socket->endpoint(), 'name' => $nombre, 'updated_at' => now()]);

    return $contexto;
}

test('test_el_escritor_escribe_los_bytes_exactos_en_el_socket_de_la_impresora', function () {
    $socket = PrintSocketServer::start();

    try {
        $contexto = destino_del_servidor($socket);

        // Los BYTES ya congelados por el servidor: el que escribe no
        // interpreta el ticket, lo escribe tal cual (design D11).
        $bytes = "\x1b\x40TICKET DEL SERVIDOR\x1d\x56\x41\x03";

        $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
            'payload_bytes' => $bytes,
        ]);

        $resultado = app(PrintJobWriter::class)->escribir($trabajoId);

        expect($resultado->status)->toBe(PrintWriteOutcome::ESCRITO);

        // 1. Los bytes LLEGARON, y son EXACTAMENTE los del trabajo: byte a
        //    byte, sin que el servidor los recomponga.
        $recibidos = $socket->recibidos();

        expect($recibidos)->toBe($bytes);

        // 2. Y el hash del trabajo es el hash de lo que se escribio: los
        //    bytes viajan congelados, y su hash viaja con ellos.
        $trabajo = DB::table('print_jobs')->where('id', $trabajoId)->first();

        expect((string) $trabajo->payload_sha256)->toBe(hash('sha256', $recibidos))
            ->and((string) $trabajo->status)->toBe('printed')
            ->and($trabajo->last_error)->toBeNull()
            ->and($trabajo->lease_expires_at)->not->toBeNull();
    } finally {
        $socket->stop();
    }
});

test('test_el_servidor_no_escribe_en_un_destino_que_escribe_el_agente_lan', function () {
    $socket = PrintSocketServer::start();

    try {
        $contexto = destino_del_servidor($socket);

        // El mismo destino, pero lo escribe el AGENTE: el servidor no lo
        // toca, o el ticket saldría dos veces.
        DB::table('print_targets')
            ->where('id', $contexto['target_id'])
            ->update(['transport' => 'lan_agent', 'updated_at' => now()]);

        $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id']);

        $resultado = app(PrintJobWriter::class)->escribir($trabajoId);

        expect($resultado->status)->toBe(PrintWriteOutcome::OMITIDO)
            ->and($resultado->motivo)->toBe(PrintWriteOutcome::MOTIVO_AGENTE);

        // 1. El trabajo QUEDA ENCOLADO: lo reclama el agente.
        expect(DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('queued');

        // 2. Y no salió NI UN byte por el socket.
        expect($socket->recibidos())->toBe('');
    } finally {
        $socket->stop();
    }
});

test('test_el_navegador_no_es_un_destino_fisico_y_el_servidor_no_escribe_en_el', function () {
    $contexto = PrintFixture::contexto('browser');

    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id']);

    $resultado = app(PrintJobWriter::class)->escribir($trabajoId);

    expect($resultado->status)->toBe(PrintWriteOutcome::OMITIDO)
        ->and($resultado->motivo)->toBe(PrintWriteOutcome::MOTIVO_NAVEGADOR)
        ->and(DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('queued');
});

test('test_un_endpoint_no_admitido_falla_con_un_mensaje_util', function () {
    $contexto = PrintFixture::contexto('server_socket');

    // El CHECK del esquema admite `smb://` con cualquier sufijo: esta cola no
    // nombra ninguna impresora, y el escritor NO escribe a ciegas.
    DB::table('print_targets')
        ->where('id', $contexto['target_id'])
        ->update(['endpoint' => 'smb://SERVIDOR', 'updated_at' => now()]);

    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id']);

    $resultado = app(PrintJobWriter::class)->escribir($trabajoId);

    expect($resultado->status)->toBe(PrintWriteOutcome::FALLIDO)
        ->and($resultado->mensaje)->toContain('no nombra una impresora compartida');

    // Y el trabajo queda FALLIDO, con el motivo a la vista.
    $trabajo = DB::table('print_jobs')->where('id', $trabajoId)->first();

    expect((string) $trabajo->status)->toBe('failed')
        ->and((string) $trabajo->last_error)->toContain('no nombra una impresora compartida');
});

test('test_un_trabajo_fallido_guarda_el_motivo_y_se_puede_reintentar', function () {
    $contexto = PrintFixture::contexto('server_socket');

    DB::table('print_targets')
        ->where('id', $contexto['target_id'])
        ->update(['endpoint' => 'smb://SERVIDOR', 'updated_at' => now()]);

    $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id'], [
        'attempts' => 1,
    ]);

    $maquina = app(PrintJobStateMachine::class);

    // 1. El trabajo falla: la impresora no esta, y el motivo queda escrito.
    app(PrintJobWriter::class)->escribir($trabajoId);

    $fallido = DB::table('print_jobs')->where('id', $trabajoId)->first();

    expect((string) $fallido->status)->toBe('failed')
        ->and((string) $fallido->last_error)->not->toBe('');

    // 2. Y se puede REINTENTAR: vuelve a la cola y cuenta un intento mas.
    $maquina->requeue($trabajoId);

    $reintentado = DB::table('print_jobs')->where('id', $trabajoId)->first();

    expect((string) $reintentado->status)->toBe('queued')
        ->and((int) $reintentado->attempts)->toBe(2)
        ->and($reintentado->agent_id)->toBeNull()
        ->and($reintentado->lease_expires_at)->toBeNull();
});

test('test_dos_bombeos_a_la_vez_no_escriben_el_mismo_trabajo', function () {
    $socket = PrintSocketServer::start();

    try {
        $contexto = destino_del_servidor($socket);

        $trabajoId = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id']);

        // DOS SESIONES: el bloqueo de fila solo es real entre sesiones
        // distintas, y con una sola sesion el reclamo no se probaria.
        $a = DB::connection('pgsql');
        $b = DB::connection('pgsql_b');

        // Si la segunda sesion ESPERARA al bloqueo, esta sentencia la cortaria
        // al segundo.
        $b->statement("SET lock_timeout = '2s'");

        // 1. La sesion A TOMA el trabajo y lo tiene en las manos, sin confirmar.
        $a->beginTransaction();

        (new PrintJobStateMachine($a))->claimByServer($trabajoId, 60);

        // 2. La sesion B intenta escribirlo y NO PUEDE: el reclamo es atomico
        //    (el estado de origen va en el `WHERE`) y la fila esta bloqueada.
        $escritor = new PrintJobWriter($b, new PrintJobStateMachine($b));

        $resultado = $escritor->escribir($trabajoId);

        expect($resultado->status)->not->toBe(PrintWriteOutcome::ESCRITO)
            ->and($resultado->status)->toBe(PrintWriteOutcome::OMITIDO);

        // 3. A suelta el bloqueo.
        $a->rollBack();

        // 4. Ahora si: la sesion B toma el trabajo y lo escribe.
        $resultado = $escritor->escribir($trabajoId);

        expect($resultado->status)->toBe(PrintWriteOutcome::ESCRITO)
            ->and(DB::table('print_jobs')->where('id', $trabajoId)->value('status'))->toBe('printed');

        // 5. Y el ticket salio UNA SOLA VEZ: si el segundo bombeo hubiera
        //    escrito tambien, los bytes habrian llegado dos veces.
        $recibidos = $socket->recibidos();

        expect($recibidos)->not->toBe('')
            ->and(substr_count($recibidos, "\x1d\x56\x41"))->toBe(1);
    } finally {
        $socket->stop();
    }
});

test('test_el_comando_bombea_lo_que_puede_deja_lo_del_agente_y_no_hace_nada_en_bucle', function () {
    $socket = PrintSocketServer::start();

    try {
        $contexto = destino_del_servidor($socket);

        // El destino del AGENTE, en la MISMA tienda: el comando lo deja
        // encolado, porque lo reclama el agente.
        $delAgente = PrintFixture::destino($contexto['store_id'], 'lan_agent', [
            'name' => 'Caja del agente',
        ]);

        $delServidor = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id']);
        $paraElAgente = PrintFixture::trabajo($contexto['store_id'], $delAgente['target_id']);

        // 1. El comando BOMBEA lo que el servidor puede escribir.
        $codigo = Artisan::call('ops:print-pump');
        $salida = Artisan::output();

        expect($codigo)->toBe(0)
            ->and($salida)->toMatch('/Trabajos escritos\s+1/')
            ->and($salida)->toMatch('/Trabajos fallidos\s+0/')
            ->and($salida)->toMatch('/Trabajos dejados para el agente LAN\s+1/');

        expect(DB::table('print_jobs')->where('id', $delServidor)->value('status'))->toBe('printed');

        // 2. Y deja lo del agente ENCOLADO: el agente lo reclama.
        expect(DB::table('print_jobs')->where('id', $paraElAgente)->value('status'))->toBe('queued');

        // 3. Y los bytes del ticket salieron por el socket.
        $recibidos = $socket->recibidos();

        expect($recibidos)->not->toBe('')
            ->and(hash('sha256', $recibidos))->toBe((string) DB::table('print_jobs')
            ->where('id', $delServidor)
            ->value('payload_sha256'));

        // 4. Y NO HACE NADA EN BUCLE: el comando bombea una vez y sale. Un
        //    segundo bombeo no reimprime el ticket.
        expect(Artisan::call('ops:print-pump'))->toBe(0);

        expect($socket->recibidos())->toBe('')
            ->and(DB::table('print_jobs')->where('id', $delServidor)->value('status'))->toBe('printed');
    } finally {
        $socket->stop();
    }
});

test('test_el_comando_solo_bombea_el_destino_que_se_le_pide', function () {
    $socket = PrintSocketServer::start();

    try {
        $contexto = destino_del_servidor($socket);

        $otro = PrintFixture::destino($contexto['store_id'], 'server_socket', [
            'name' => 'Caja de la otra',
            'endpoint' => 'tcp://127.0.0.1:9100',
        ]);

        $pedido = PrintFixture::trabajo($contexto['store_id'], $contexto['target_id']);
        $otroTrabajo = PrintFixture::trabajo($contexto['store_id'], $otro['target_id']);

        expect(Artisan::call('ops:print-pump', ['--destino' => (string) $otro['target_id']]))->toBe(0);

        // El destino pedido se escribio; el otro NO: el comando no bombea
        // destinos que no se le han pedido.
        expect(DB::table('print_jobs')->where('id', $otroTrabajo)->value('status'))->toBe('printed')
            ->and(DB::table('print_jobs')->where('id', $pedido)->value('status'))->toBe('queued');
    } finally {
        $socket->stop();
    }
});
