<?php

use GuzzleHttp\Cookie\CookieJar;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * 1.12 — el CAMINO de la venta con sobrepago, por HTTP de verdad.
 *
 * REGLA que fija: *la pantalla de la venta dice lo APLICADO, no lo bruto*.
 *
 * POR QUE ESTE TEST ES EL QUE CIERRA LA UNIDAD: con un sobrepago (100
 * entregados por una venta de 58) las dos verdades se juntan y se contradicen
 * si alguna falla:
 *
 *  - la venta tiene que quedar pagada (el cambio no es un pago pendiente);
 *  - el DETALLE tiene que decir 58,0000 aplicado y 100,0000 recibido, y no
 *    al reves: la ficha que dice 100 es la que esconde la verdad;
 *  - la LISTA tiene que decir lo mismo que el detalle: 58,0000 aplicados.
 *
 * Un test con `actingAs()` verifica la PAGINA. Este verifica el CAMINO: el
 * arranque de verdad, el login de verdad, el token CSRF de verdad y el
 * enrutado real de las peticiones.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_de_lo_aplicado(string $html): string
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
function pagina_de_inertia_de_lo_aplicado(string $html): array
{
    if (preg_match('/data-page="([^"]+)"/', $html, $coincidencia) !== 1) {
        return [];
    }

    $json = html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8');

    $pagina = json_decode($json, true);

    return is_array($pagina) ? $pagina : [];
}

/**
 * El camino completo de una venta con sobrepago, por HTTP de verdad: entrar,
 * abrir la caja, cobrar de mas y ver lo que dice la venta.
 */
test('test_el_camino_de_la_venta_con_sobrepago_por_http_dice_el_aplicado_y_no_el_bruto', function () {
    $servidor = PosHttpServer::start();

    try {
        // 1. El ESCENARIO, en la base de datos del camino: la tienda
        //    sembrada, su perfil fiscal, sus series, un producto con stock y
        //    un cajero con su vinculo por la tienda.
        $escenario = PosFixture::escenario();

        $cajero = PosFixture::usuarioSinCaja($escenario['store_id']);
        $cajero->email = 'aplicado-cobro@camino.test';
        $cajero->password = 'clave-del-camino';
        $cajero->save();

        // 2. GET /login: la pagina trae el token con el que se firman los
        //    POST.
        $jar = new CookieJar;

        $login = $servidor->cliente($jar)
            ->get($servidor->url('/login'));

        expect($login->status())->toBe(200);

        $token = token_csrf_de_lo_aplicado($login->body());

        expect($token)->not->toBe('');

        // 3. POST /login: el token firma la peticion y la sesion se abre.
        $sesion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/login'), [
                'email' => 'aplicado-cobro@camino.test',
                'password' => 'clave-del-camino',
            ]);

        expect($sesion->status())->toBe(302);

        // 4. GET /caja/dia: la pagina SIN caja, y con ella un token fresco.
        $pagina = $servidor->cliente($jar)
            ->get($servidor->url('/caja/dia'));

        expect($pagina->status())->toBe(200);

        $token = token_csrf_de_lo_aplicado($pagina->body());

        expect($token)->not->toBe('');

        // 5. POST /caja/dia/abrir: la caja del dia, con su saldo de partida.
        $apertura = $servidor->cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/caja/dia/abrir'), [
                'opening_balance' => '0.0000',
            ]);

        expect($apertura->status())->toBe(201)
            ->and($apertura->json('status'))->toBe('open');

        // 6. POST /caja/cobrar: el cliente entrega 100 por una venta de 58.
        //    La venta esta COBRADA y el cambio es de 42.
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
                        'amount' => '100.0000',
                    ],
                ],
            ]);

        expect($cobro->status())->toBe(201)
            ->and($cobro->json('grand_total'))->toBe('58.0000')
            ->and($cobro->json('status'))->toBe('paid')
            ->and($cobro->json('change'))->toBe('42.0000');

        $saleId = (int) $cobro->json('sale_id');

        // 7. GET /ventas/{id}: el DETALLE dice las TRES cifras. Lo APLICADO
        //    son 58,0000: la verdad de la venta, la misma que dice el
        //    comprobante fiscal.
        $detalle = $servidor->cliente($jar)
            ->get($servidor->url('/ventas/'.$saleId));

        $props = pagina_de_inertia_de_lo_aplicado($detalle->body())['props'] ?? [];
        $pago = $props['sale']['payments'][0] ?? [];

        expect($detalle->status())->toBe(200)
            ->and($pago['received'] ?? null)->toBe('100.0000')
            ->and($pago['applied'] ?? null)->toBe('58.0000')
            ->and($pago['change'] ?? null)->toBe('42.0000')
            ->and($props['sale']['payment_summary']['applied_total'] ?? null)->toBe('58.0000')
            ->and($props['sale']['payment_summary']['matches_total'] ?? null)->toBeTrue();

        // 8. Y GET /ventas: la LISTA dice lo mismo que el detalle. Las dos
        //    pantallas de la misma venta no pueden decir dos verdades.
        $lista = $servidor->cliente($jar)
            ->get($servidor->url('/ventas'));

        $propsLista = pagina_de_inertia_de_lo_aplicado($lista->body())['props'] ?? [];

        expect($lista->status())->toBe(200)
            ->and($propsLista['sales'][0]['applied_total'] ?? null)->toBe('58.0000');
    } finally {
        $servidor->stop();
    }
});
