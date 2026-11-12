<?php

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * 1.8 — el CAMINO POR HTTP del comprobante fiscal.
 *
 * REGLA que fija: *el cajero ve el estado fiscal y abre el comprobante*.
 *
 * Con el interruptor ENCENDIDO, el cobro de verdad deja la venta con su
 * documento fiscal y su numero de control; la pagina de la venta enseña ese
 * estado; y el comprobante se abre y se imprime.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS, Y POR QUE NO SE PUEDE SIMULAR:
 *
 * Un test con `actingAs()` verifica la PAGINA. Este verifica el CAMINO: el
 * arranque de verdad, el login de verdad, el token CSRF de verdad y el
 * enrutado real de las peticiones. Y el interruptor de emision es una
 * VARIABLE DE ENTORNO del proceso del servidor: por eso el arnes la recibe al
 * arrancar.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_del_comprobante(string $html): string
{
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $coincidencia) !== 1) {
        return '';
    }

    return $coincidencia[1];
}

/**
 * La PAGINA de Inertia que devuelve el servidor: el componente y sus props.
 *
 * @return array<string, mixed>
 */
function pagina_de_inertia_del_comprobante(string $html): array
{
    if (preg_match('/data-page="([^"]+)"/', $html, $coincidencia) !== 1) {
        return [];
    }

    $json = html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8');

    $pagina = json_decode($json, true);

    return is_array($pagina) ? $pagina : [];
}

/**
 * El CAMINO COMPLETO: login y cobro, por HTTP de verdad.
 *
 * @param  array<string, mixed>  $escenario
 * @return array{0: object, 1: CookieJar} La respuesta del cobro y la sesion.
 */
function cobrar_por_http_del_comprobante(PosHttpServer $servidor, array $escenario): array
{
    $cajero = PosFixture::usuario($escenario['store_id']);
    $cajero->email = 'caja@comprobante.test';
    $cajero->password = 'clave-del-camino';
    $cajero->save();

    $jar = new CookieJar;

    $login = $servidor->cliente($jar)->get($servidor->url('/login'));
    $token = token_csrf_del_comprobante($login->body());

    $servidor->cliente($jar, ['allow_redirects' => false])
        ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
        ->post($servidor->url('/login'), [
            'email' => 'caja@comprobante.test',
            'password' => 'clave-del-camino',
        ]);

    // El token del login ya no vale: entrar regenera la sesion.
    $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));
    $token = token_csrf_del_comprobante($caja->body());

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
 * 1.8 — cobrar por HTTP con el interruptor ENCENDIDO deja la venta con su
 * estado fiscal, y el comprobante se abre.
 *
 * REGLA que fija: *el estado fiscal se ve y el comprobante se imprime*. El
 * cobro dice el estado, la pagina de la venta lo enseña con su numero de
 * control, y el comprobante se sirve con ese MISMO numero.
 */
test('test_el_cobro_por_http_muestra_el_estado_fiscal_y_el_comprobante_se_abre', function () {
    $servidor = PosHttpServer::start(['FISCAL_EMISSION_ENABLED' => 'true']);

    try {
        $escenario = PosFixture::escenario();

        [$cobro, $jar] = cobrar_por_http_del_comprobante($servidor, $escenario);

        // 1. El cobro responde 201 y dice el ESTADO FISCAL.
        expect($cobro->status())->toBe(201)
            ->and($cobro->json('fiscal.status'))->toBe('issued')
            ->and($cobro->json('fiscal.control_number'))->not->toBeNull();

        $saleId = (int) $cobro->json('sale_id');
        $documentId = (int) $cobro->json('fiscal.document_id');
        $control = (string) $cobro->json('fiscal.control_number');

        // 2. La pagina de la venta ENSEÑA ese estado, con su numero de
        //    control.
        $venta = $servidor->cliente($jar)->get($servidor->url('/ventas/'.$saleId));

        expect($venta->status())->toBe(200);

        $pagina = pagina_de_inertia_del_comprobante($venta->body());

        expect($pagina['component'] ?? null)->toBe('sales/show')
            ->and($pagina['props']['sale']['fiscal']['status'] ?? null)->toBe('issued')
            ->and($pagina['props']['sale']['fiscal']['control_number'] ?? null)->toBe($control);

        // 3. Y el COMPROBANTE se abre, con el MISMO numero de control y el
        //    total de la venta.
        $comprobante = $servidor->cliente($jar)->get($servidor->url('/comprobantes/'.$documentId));

        expect($comprobante->status())->toBe(200)
            ->and($comprobante->body())->toContain('N&#186; Control '.$control)
            ->and($comprobante->body())->toContain('Total: 58.0000');

        // 4. Y las PARTES del comprobante son las de la venta: el emisor es
        //    la tienda y el receptor es el consumidor final.
        $parties = DB::table('fiscal_document_parties')
            ->where('fiscal_document_id', $documentId)
            ->get();

        expect($parties)->toHaveCount(1)
            ->and($parties->first()->role)->toBe('issuer')
            ->and((string) $parties->first()->name)->toBe('Tienda de demostracion');
    } finally {
        $servidor->stop();
    }
});
