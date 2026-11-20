<?php

use App\Models\PrintJob;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;
use Tests\Support\PosHttpServer;

/**
 * F2-05 — el CAMINO POR HTTP del cobro al ticket.
 *
 * REGLA que fija: *cobrar por HTTP de verdad deja el ticket encolado*. El
 * ticket no sale de la pantalla del cajero: sale del cobro, y el trabajo
 * existe con sus bytes congelados.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS, Y POR QUE NO SE PUEDE SIMULAR:
 *
 * Un test con `actingAs()` verifica la PAGINA. Este verifica el CAMINO: el
 * arranque de verdad, el login de verdad, el token CSRF de verdad y el
 * enrutado real. Y el interruptor de emision es una VARIABLE DE ENTORNO del
 * proceso del servidor: por eso el arnes la recibe al arrancar.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_de_la_impresion(string $html): string
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
 * @return array{0: CookieJar, 1: string} La sesion y el token CSRF.
 */
function sesion_de_cobro_con_impresion(PosHttpServer $servidor, array $escenario): array
{
    $cajero = PosFixture::usuario($escenario['store_id']);
    $cajero->email = 'caja@impresion.test';
    $cajero->password = 'clave-del-camino';
    $cajero->save();

    $jar = new CookieJar;

    $login = $servidor->cliente($jar)->get($servidor->url('/login'));
    $token = token_csrf_de_la_impresion($login->body());

    $servidor->cliente($jar, ['allow_redirects' => false])
        ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
        ->post($servidor->url('/login'), [
            'email' => 'caja@impresion.test',
            'password' => 'clave-del-camino',
        ]);

    // El token del login ya no vale: entrar regenera la sesion.
    $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));

    return [$jar, token_csrf_de_la_impresion($caja->body())];
}

/**
 * El COBRO por HTTP de verdad, con la sesion ya abierta.
 *
 * @param  array<string, int>  $escenario
 */
function cobrar_por_http_con_impresion(
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
 * F2-05 — cobrar por HTTP deja el trabajo de impresion encolado.
 */
test('test_el_cobro_por_http_deja_el_ticket_encolado', function () {
    $servidor = PosHttpServer::start(['FISCAL_EMISSION_ENABLED' => 'true']);

    try {
        $escenario = PosFixture::escenario();

        // La tienda IMPRIME AUTOMATICAMENTE y tiene UN destino: sin las dos
        // cosas, el cobro no encola nada y el motivo lo diria.
        DB::table('store_settings')
            ->where('store_id', $escenario['store_id'])
            ->update(['auto_print' => true, 'updated_at' => now()]);

        $destino = PrintFixture::destino($escenario['store_id'], 'lan_agent', [
            'name' => 'Caja del camino',
        ]);

        [$jar, $token] = sesion_de_cobro_con_impresion($servidor, $escenario);

        $cobro = cobrar_por_http_con_impresion($servidor, $jar, $token, $escenario);

        // 1. El cobro responde 201 y dice que el ticket se ENCOLO.
        expect($cobro->status())->toBe(201)
            ->and($cobro->json('print.status'))->toBe('queued')
            ->and($cobro->json('print.reason'))->toBeNull();

        $jobId = (int) $cobro->json('print.job_id');

        expect($jobId)->not->toBe(0);

        // 2. Y el trabajo existe, en la cola, con sus BYTES congelados: lo
        //    que el agente escribe es lo que el servidor genero.
        $job = PrintJob::find($jobId);

        expect($job)->not->toBeNull()
            ->and($job->status)->toBe('queued')
            ->and((int) $job->store_id)->toBe($escenario['store_id'])
            ->and((int) $job->print_target_id)->toBe($destino['target_id'])
            ->and((string) $job->payload_bytes)->not->toBe('')
            ->and((string) $job->payload_sha256)->toBe(hash('sha256', (string) $job->payload_bytes));

        // 3. Y el ticket lleva el control DEL DOCUMENTO: una venta tiene un
        //    numero de control, y el papel no trae otro.
        $control = DB::table('fiscal_document_control_assignments')
            ->where('fiscal_document_id', (int) $cobro->json('fiscal.document_id'))
            ->value('control_number');

        $textos = array_map(static fn (array $linea): string => (string) $linea['text'], $job->payload['lines']);

        expect($textos)->toContain('Nº Control '.(string) $control)
            ->and($cobro->json('control_number'))->toBe((string) $control);

        // 4. Y SIN DESTINO ACTIVO, el cobro por HTTP responde 201 y dice por
        //    que no se encolo: el destino no se adivina, y la venta NO se
        //    pierde por eso.
        DB::table('print_targets')
            ->where('id', $destino['target_id'])
            ->update(['is_active' => false, 'updated_at' => now()]);

        $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));
        $otro = cobrar_por_http_con_impresion(
            $servidor,
            $jar,
            token_csrf_de_la_impresion($caja->body()),
            $escenario
        );

        expect($otro->status())->toBe(201)
            ->and($otro->json('print.status'))->toBe('not_queued')
            ->and($otro->json('print.reason'))->toBe('no_destination')
            ->and($otro->json('print.job_id'))->toBeNull();

        // Y la cola tiene SOLO el primer trabajo: el segundo cobro no
        // encolo nada.
        expect(DB::table('print_jobs')->count())->toBe(1);
    } finally {
        $servidor->stop();
    }
});
