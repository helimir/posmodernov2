<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\PrintJob;
use App\Models\User;
use GuzzleHttp\Cookie\CookieJar;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;
use Tests\Fixtures\PrintFixture;
use Tests\Support\PosHttpServer;

/**
 * F2-08 — el CAMINO POR HTTP: reimprimir un ticket y que salga con los MISMOS
 * bytes.
 *
 * REGLA que fija: *una reimpresion reimprime los MISMOS bytes: no se vuelve a
 * componer el ticket*.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS, Y POR QUE NO SE PUEDE SIMULAR:
 *
 * Un test con `actingAs()` verifica la PAGINA. Este verifica el CAMINO: el
 * arranque de verdad, el login de verdad, el token CSRF de verdad y el
 * enrutado real.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_del_camino_de_la_reimpresion(string $html): string
{
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $coincidencia) !== 1) {
        return '';
    }

    return $coincidencia[1];
}

/**
 * La SESION de quien administra la cola, por HTTP de verdad: login y el token
 * que firma los POST.
 *
 * @param  array<string, int>  $escenario
 * @return array{0: CookieJar, 1: string} La sesion y el token CSRF.
 */
function sesion_del_camino_de_la_reimpresion(PosHttpServer $servidor, array $escenario): array
{
    $persona = PosFixture::usuario($escenario['store_id']);
    $persona->email = 'cola@reimpresion.test';
    $persona->password = 'clave-del-camino';
    $persona->save();

    $jar = new CookieJar;

    $login = $servidor->cliente($jar)->get($servidor->url('/login'));
    $token = token_csrf_del_camino_de_la_reimpresion($login->body());

    $servidor->cliente($jar, ['allow_redirects' => false])
        ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
        ->post($servidor->url('/login'), [
            'email' => 'cola@reimpresion.test',
            'password' => 'clave-del-camino',
        ]);

    // El token del login ya no vale: entrar regenera la sesion.
    $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));

    return [$jar, token_csrf_del_camino_de_la_reimpresion($caja->body())];
}

/**
 * F2-08 — la reimpresion por HTTP crea un trabajo NUEVO con los MISMOS bytes.
 */
test('test_la_reimpresion_por_http_crea_un_trabajo_nuevo_con_los_mismos_bytes', function () {
    $servidor = PosHttpServer::start();

    try {
        $escenario = PosFixture::escenario();

        PermissionFixture::catalogo();

        // Quien administra la cola: un rol con los permisos de esta unidad.
        $rol = PermissionFixture::rol('cola-camino-'.uniqid(), [
            Permission::PrintingRead->value,
            Permission::PrintingReprint->value,
            Permission::PrintingRetry->value,
        ]);

        [$jar, $token] = sesion_del_camino_de_la_reimpresion($servidor, $escenario);

        // La sesion es la de quien administra la cola: hay que darle el rol.
        $persona = User::query()->where('email', 'cola@reimpresion.test')->first();
        $persona->group_id = $rol->id;
        $persona->save();

        // 1. El trabajo ORIGINAL, ya impreso, con sus bytes CONGELADOS.
        $destino = PrintFixture::destino($escenario['store_id']);

        $originalId = PrintFixture::trabajo($escenario['store_id'], $destino['target_id'], [
            'status' => 'printed',
        ]);

        $original = PrintJob::find($originalId);

        $bytesOriginales = (string) $original->payload_bytes;
        $hashOriginal = (string) $original->payload_sha256;

        expect($bytesOriginales)->not->toBe('');

        // 2. La REIMPRESION por HTTP de verdad.
        $respuesta = $servidor->cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url("/api/print-jobs/{$originalId}/reimprimir"));

        expect($respuesta->status())->toBe(201)
            ->and($respuesta->json('reason'))->toBe('reprint')
            ->and((int) $respuesta->json('reprint_of_job_id'))->toBe($originalId);

        // 3. Y HAY DOS TRABAJOS con los MISMOS bytes: el original y el de la
        //    reimpresion. El segundo NO es un ticket recompuesto: es el MISMO
        //    ticket, otra vez.
        $trabajos = PrintJob::query()
            ->where('store_id', $escenario['store_id'])
            ->orderBy('id')
            ->get();

        expect($trabajos)->toHaveCount(2);

        $primero = $trabajos->first();
        $segundo = $trabajos->last();

        expect((int) $primero->id)->toBe($originalId)
            ->and((int) $segundo->id)->not->toBe($originalId)
            ->and((string) $segundo->reason)->toBe('reprint')
            ->and((int) $segundo->reprint_of_job_id)->toBe($originalId)
            ->and((string) $segundo->status)->toBe('queued')
            // LOS MISMOS BYTES y el MISMOS HASH.
            ->and((string) $segundo->payload_bytes)->toBe($bytesOriginales)
            ->and((string) $segundo->payload_sha256)->toBe($hashOriginal)
            ->and(hash('sha256', (string) $segundo->payload_bytes))->toBe($hashOriginal);
    } finally {
        $servidor->stop();
    }
});
