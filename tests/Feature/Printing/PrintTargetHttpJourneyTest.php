<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\PrintJob;
use App\Models\User;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * F2-07 — el CAMINO POR HTTP: dar de alta un destino y que la venta encole
 * el ticket en ESE destino.
 *
 * REGLA que fija: *lo que la tienda declara es lo que se usa*. El destino se
 * da de alta por la API (con su impresora, su transporte, su endpoint y su
 * ancho) y el cobro encola el ticket en ese destino, y no en otro.
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
function token_csrf_del_camino_del_destino(string $html): string
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
function sesion_del_camino_del_destino(PosHttpServer $servidor, array $escenario): array
{
    $cajero = PosFixture::usuario($escenario['store_id']);
    $cajero->email = 'caja@destino.test';
    $cajero->password = 'clave-del-camino';
    $cajero->save();

    $jar = new CookieJar;

    $login = $servidor->cliente($jar)->get($servidor->url('/login'));
    $token = token_csrf_del_camino_del_destino($login->body());

    $servidor->cliente($jar, ['allow_redirects' => false])
        ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
        ->post($servidor->url('/login'), [
            'email' => 'caja@destino.test',
            'password' => 'clave-del-camino',
        ]);

    // El token del login ya no vale: entrar regenera la sesion.
    $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));

    return [$jar, token_csrf_del_camino_del_destino($caja->body())];
}

/**
 * El COBRO por HTTP de verdad, con la sesion ya abierta.
 *
 * @param  array<string, int>  $escenario
 */
function cobrar_por_http_del_camino_del_destino(PosHttpServer $servidor, CookieJar $jar, string $token, array $escenario): object
{
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
 * F2-07 — el destino dado de alta por la API es el destino del ticket.
 */
test('test_el_cobro_por_http_encola_el_ticket_en_el_destino_dado_de_alta_por_la_api', function () {
    $servidor = PosHttpServer::start(['FISCAL_EMISSION_ENABLED' => 'true']);

    try {
        $escenario = PosFixture::escenario();

        PermissionFixture::catalogo();

        // Quien administra la impresion: un rol con los permisos de esta
        // unidad y con los del cobro, porque el camino es el del cajero.
        $rol = PermissionFixture::rol('impresion-camino-'.uniqid(), [
            Permission::PrintingRead->value,
            Permission::PrintingCreate->value,
            Permission::PrintingUpdate->value,
            Permission::PrintingTest->value,
            Permission::PosAccess->value,
            Permission::SalesCreate->value,
        ]);

        [$jar, $token] = sesion_del_camino_del_destino($servidor, $escenario);

        // La sesion es la del cajero: hay que darle el rol de la impresion.
        $cajero = User::query()->where('email', 'caja@destino.test')->first();
        $cajero->group_id = $rol->id;
        $cajero->save();

        // 1. El ALTA de la impresora del catalogo, por la API.
        $impresora = $servidor->cliente($jar)
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/api/impresion/impresoras'), [
                'code' => '  imp-camino  ',
                'name' => 'Impresora del mostrador',
            ]);

        expect($impresora->status())->toBe(201)
            ->and($impresora->json('code'))->toBe('IMP-CAMINO');

        // 2. Y el ALTA del destino, con su endpoint y su ancho.
        $destino = $servidor->cliente($jar)
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/api/impresion/destinos'), [
                'printer_id' => (int) $impresora->json('id'),
                'name' => 'Caja del mostrador',
                'transport' => 'server_socket',
                'endpoint' => 'tcp://192.168.1.90:9100',
                'printer_profile' => 'default',
                // El ancho que declara la tienda: el del papel de SU
                // impresora.
                'char_per_line' => 120,
                'charset' => 'CP850',
                'max_qr_payload_bytes' => 512,
                'supports_qr' => true,
                'is_active' => true,
            ]);

        expect($destino->status())->toBe(201);

        $destinoId = (int) $destino->json('id');

        expect($destinoId)->not->toBe(0);

        // 3. La tienda IMPRIME AUTOMATICAMENTE: sin el interruptor, el
        //    cobro no encola nada.
        DB::table('store_settings')
            ->where('store_id', $escenario['store_id'])
            ->update(['auto_print' => true, 'updated_at' => now()]);

        // 4. El COBRO por HTTP de verdad.
        $cobro = cobrar_por_http_del_camino_del_destino($servidor, $jar, $token, $escenario);

        expect($cobro->status())->toBe(201)
            ->and($cobro->json('print.status'))->toBe('queued')
            ->and($cobro->json('print.reason'))->toBeNull();

        // 5. Y el trabajo esta encolado CONTRA ESE destino: el que declara
        //    la tienda, y no otro.
        //
        //    El trabajo se lee con el MODELO, no con la consulta cruda: el
        //    `bytea` de PostgreSQL vuelve como un flujo, y sin el cast los
        //    bytes del ticket se perderian en silencio.
        $trabajo = PrintJob::find((int) $cobro->json('print.job_id'));

        expect($trabajo)->not->toBeNull()
            ->and((string) $trabajo->status)->toBe('queued')
            ->and((int) $trabajo->print_target_id)->toBe($destinoId)
            ->and((int) $trabajo->store_id)->toBe((int) $escenario['store_id'])
            ->and((string) $trabajo->payload_bytes)->not->toBe('')
            ->and((string) $trabajo->payload_sha256)->toBe(hash('sha256', (string) $trabajo->payload_bytes));
    } finally {
        $servidor->stop();
    }
});
