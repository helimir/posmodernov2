<?php

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;
use Tests\Support\PosHttpServer;
use Tests\Support\PrintSocketServer;

/**
 * F2-06 — el CAMINO POR HTTP del cobro al papel.
 *
 * REGLA que fija: *cobrar por HTTP de verdad y bombear el ticket lo saca por
 * la impresora*. El cobro encola el trabajo con sus bytes congelados (F2-05) y
 * el bombeo los escribe en el destino (F2-06). Entre los dos, el POS imprime
 * sin agente.
 *
 * Y NO SE SIMULA NADA: el cobro es por HTTP de verdad, contra el arnes del
 * repo (`PosHttpServer`), y los bytes se comprueban en el socket que escucha
 * de verdad. Un comprobante que "sale" porque el test lo afirma no es un
 * comprobante que salga.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_del_camino_de_impresion(string $html): string
{
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $coincidencia) !== 1) {
        return '';
    }

    return $coincidencia[1];
}

/**
 * La SESION del cajero, por HTTP de verdad: login y el token que firma los
 * POST.
 *
 * @param  array<string, int>  $escenario
 * @return array{0: CookieJar, 1: string}
 */
function sesion_del_cobro_del_escritor(PosHttpServer $servidor, array $escenario): array
{
    $cajero = PosFixture::usuario($escenario['store_id']);
    $cajero->email = 'caja@escritor.test';
    $cajero->password = 'clave-del-escritor';
    $cajero->save();

    $jar = new CookieJar;

    $login = $servidor->cliente($jar)->get($servidor->url('/login'));
    $token = token_csrf_del_camino_de_impresion($login->body());

    $servidor->cliente($jar, ['allow_redirects' => false])
        ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
        ->post($servidor->url('/login'), [
            'email' => 'caja@escritor.test',
            'password' => 'clave-del-escritor',
        ]);

    // El token del login ya no vale: entrar regenera la sesion.
    $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));

    return [$jar, token_csrf_del_camino_de_impresion($caja->body())];
}

/**
 * El COBRO por HTTP de verdad, con la sesion ya abierta.
 *
 * @param  array<string, int>  $escenario
 */
function cobro_por_http_del_escritor(
    PosHttpServer $servidor,
    CookieJar $jar,
    string $token,
    array $escenario
): object {
    return $servidor->cliente($jar)
        ->withHeaders([
            'X-CSRF-TOKEN' => $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])
        ->post($servidor->url('/caja/cobrar'), [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $escenario['payment_method_id'],
                    'amount' => '58.0000',
                ],
            ],
        ]);
}

/**
 * F2-06 — el cobro por HTTP deja el ticket encolado y el bombeo lo saca por
 * la impresora.
 */
test('test_el_cobro_por_http_y_el_bombeo_sacan_el_ticket_por_la_impresora', function () {
    $socket = PrintSocketServer::start();
    $servidor = PosHttpServer::start(['FISCAL_EMISSION_ENABLED' => 'true']);

    try {
        $escenario = PosFixture::escenario();

        // La tienda IMPRIME AUTOMATICAMENTE y tiene UN destino: sin las dos
        // cosas, el cobro no encola nada y el motivo lo diria.
        DB::table('store_settings')
            ->where('store_id', $escenario['store_id'])
            ->update(['auto_print' => true, 'updated_at' => now()]);

        // El destino que escribe el SERVIDOR: la impresora de red de prueba.
        PrintFixture::destino($escenario['store_id'], 'server_socket', [
            'name' => 'Caja del servidor',
            'endpoint' => $socket->endpoint(),
        ]);

        [$jar, $token] = sesion_del_cobro_del_escritor($servidor, $escenario);

        $cobro = cobro_por_http_del_escritor($servidor, $jar, $token, $escenario);

        // 1. El cobro responde 201 y dice que el ticket se ENCOLO.
        expect($cobro->status())->toBe(201)
            ->and($cobro->json('print.status'))->toBe('queued');

        $jobId = (int) $cobro->json('print.job_id');

        expect($jobId)->not->toBe(0)
            ->and(DB::table('print_jobs')->where('id', $jobId)->value('status'))->toBe('queued');

        // 2. Y el BOMBEO lo escribe: el comando trabaja sobre la misma base
        //    de datos del camino, y el trabajo sale por el socket.
        expect(Artisan::call('ops:print-pump'))->toBe(0);

        expect(DB::table('print_jobs')->where('id', $jobId)->value('status'))->toBe('printed');

        // 3. Y los bytes del TICKET llegaron a la impresora: son los del
        //    trabajo, y su hash es el del trabajo.
        $recibidos = $socket->recibidos();

        expect($recibidos)->not->toBe('')
            ->and(hash('sha256', $recibidos))->toBe((string) DB::table('print_jobs')
            ->where('id', $jobId)
            ->value('payload_sha256'))
            ->and($recibidos)->toContain("\x1d\x56\x41");
    } finally {
        $servidor->stop();
        $socket->stop();
    }
});
