<?php

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * F2-02 — el CAMINO POR HTTP del comprobante con los tokens del perfil.
 *
 * REGLA que fija: *el cajero ve el comprobante completo*. Una venta SIN
 * cliente, abierta por HTTP de verdad, lleva la mencion de conformidad, el
 * identificativo fiscal del emisor y el consumidor final.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS, Y POR QUE NO SE PUEDE SIMULAR:
 *
 * Un test con `actingAs()` verifica la PAGINA. Este verifica el CAMINO: el
 * arranque de verdad, el login de verdad, el token CSRF de verdad y el
 * enrutado real de la peticion. Y el interruptor de emision es una VARIABLE
 * DE ENTORNO del proceso del servidor: por eso el arnes la recibe al
 * arrancar.
 *
 * Y REUSA EL ARNES EXISTENTE (`PosHttpServer`): el servidor de verdad, en un
 * puerto libre, contra una base de datos propia que se tira al terminar.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_del_perfil(string $html): string
{
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $coincidencia) !== 1) {
        return '';
    }

    return $coincidencia[1];
}

/**
 * El CAMINO COMPLETO: login y cobro SIN cliente, por HTTP de verdad.
 *
 * @param  array<string, mixed>  $escenario
 * @return array{0: object, 1: CookieJar} La respuesta del cobro y la sesion.
 */
function cobrar_por_http_del_perfil(PosHttpServer $servidor, array $escenario): array
{
    $cajero = PosFixture::usuario($escenario['store_id']);
    $cajero->email = 'caja@perfil.test';
    $cajero->password = 'clave-del-camino';
    $cajero->save();

    $jar = new CookieJar;

    $login = $servidor->cliente($jar)->get($servidor->url('/login'));
    $token = token_csrf_del_perfil($login->body());

    $servidor->cliente($jar, ['allow_redirects' => false])
        ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
        ->post($servidor->url('/login'), [
            'email' => 'caja@perfil.test',
            'password' => 'clave-del-camino',
        ]);

    // El token del login ya no vale: entrar regenera la sesion.
    $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));
    $token = token_csrf_del_perfil($caja->body());

    // El cobro NO lleva cliente: es una venta de mostrador.
    $cobro = $servidor->cliente($jar)
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

    return [$cobro, $jar];
}

/**
 * F2-02 — el comprobante de una venta SIN cliente, abierto por HTTP, lleva la
 * mencion de conformidad, el RIF del emisor y el consumidor final.
 *
 * REGLA que fija: *el comprobante que ve el cajero esta COMPLETO*. Los tres
 * tokens del perfil y la inscripcion fiscal del emisor llegan al papel: sin
 * ellos, el comprobante no cumple la providencia.
 */
test('test_el_comprobante_por_http_de_una_venta_sin_cliente_lleva_la_conformidad_el_rif_y_el_consumidor_final', function () {
    $servidor = PosHttpServer::start(['FISCAL_EMISSION_ENABLED' => 'true']);

    try {
        $escenario = PosFixture::escenario();

        [$cobro, $jar] = cobrar_por_http_del_perfil($servidor, $escenario);

        // 1. El cobro responde 201 y el documento esta EMITIDO.
        expect($cobro->status())->toBe(201)
            ->and($cobro->json('fiscal.status'))->toBe('issued');

        $documentId = (int) $cobro->json('fiscal.document_id');

        // 2. Y el COMPROBANTE se abre.
        $comprobante = $servidor->cliente($jar)->get($servidor->url('/comprobantes/'.$documentId));

        expect($comprobante->status())->toBe(200);

        $html = $comprobante->body();

        // 3. La MENCION DE CONFORMIDAD (art. 19).
        expect($html)->toContain('Emitido conforme a')
            ->and($html)->toContain('SNAT/2026/00093')
            ->and($html)->toContain('43.484');

        // 4. El RIF DEL EMISOR (art. 8.4).
        expect($html)->toContain('DEMO-J-000000000-0');

        // 5. Y el CONSUMIDOR FINAL como receptor (art. 8.6).
        expect($html)->toContain('Consumidor Final');

        // 6. Y las PARTES del documento lo confirman: emisor y receptor.
        $parties = DB::table('fiscal_document_parties')
            ->where('fiscal_document_id', $documentId)
            ->get();

        expect($parties)->toHaveCount(2);

        $emisor = $parties->firstWhere('role', 'issuer');
        $receptor = $parties->firstWhere('role', 'receiver');

        expect((string) $emisor->fiscal_id)->toBe('DEMO-J-000000000-0')
            ->and((string) $emisor->fiscal_id_kind)->toBe('rif')
            ->and((string) $receptor->name)->toBe('Consumidor Final');
    } finally {
        $servidor->stop();
    }
});
