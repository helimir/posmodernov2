<?php

use GuzzleHttp\Cookie\CookieJar;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * 1.6 — el CAMINO de las reglas del cobro, por HTTP de verdad.
 *
 * REGLA que fija: *las cuatro reglas del cobro cierran el ARQUEO*: la venta
 * queda pagada, el desglose de la caja dice lo que APLICO y no lo que se
 * recibio, y el esperado y el desglose dicen LO MISMO.
 *
 * POR QUE ESTE TEST ES EL QUE CIERRA LA UNIDAD: con un sobrepago (100
 * entregados por una venta de 58), las tres reglas se juntan y se contradicen
 * si alguna falla:
 *
 *  - la venta debe quedar `paid` (el cambio no es un pago pendiente);
 *  - el desglose por medio de pago debe decir 58,0000 y no 100,0000 (lo que
 *    entro al cajon es lo aplicado);
 *  - el esperado de la caja (derivado del asiento) y el desglose deben
 *    decir lo mismo, y el ARQUEO debe cerrar en cero.
 *
 * Un test con `actingAs()` verifica la PAGINA. Este verifica el CAMINO: el
 * arranque de verdad, el login de verdad, el token CSRF de verdad y el
 * enrutado real de las peticiones.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_de_las_reglas(string $html): string
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
function pagina_de_inertia_de_las_reglas(string $html): array
{
    if (preg_match('/data-page="([^"]+)"/', $html, $coincidencia) !== 1) {
        return [];
    }

    $json = html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8');

    $pagina = json_decode($json, true);

    return is_array($pagina) ? $pagina : [];
}

/**
 * El camino completo del cobro con sobrepago, por HTTP de verdad: abrir la
 * caja, cobrar de mas, ver el desglose y cerrar el arqueo.
 */
test('test_el_camino_del_cobro_con_sobrepago_por_http_deja_la_venta_pagada_y_el_arqueo_cuadra', function () {
    $servidor = PosHttpServer::start();

    try {
        // 1. El ESCENARIO, en la base de datos del camino: la tienda
        //    sembrada, su perfil fiscal, sus series, un producto con stock y
        //    un cajero con su vinculo por la tienda.
        $escenario = PosFixture::escenario();

        $cajero = PosFixture::usuarioSinCaja($escenario['store_id']);
        $cajero->email = 'reglas-cobro@camino.test';
        $cajero->password = 'clave-del-camino';
        $cajero->save();

        // 2. GET /login: la pagina trae el token con el que se firman los
        //    POST.
        $jar = new CookieJar;

        $login = $servidor->cliente($jar)
            ->get($servidor->url('/login'));

        expect($login->status())->toBe(200);

        $token = token_csrf_de_las_reglas($login->body());

        expect($token)->not->toBe('');

        // 3. POST /login: el token firma la peticion y la sesion se abre.
        $sesion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/login'), [
                'email' => 'reglas-cobro@camino.test',
                'password' => 'clave-del-camino',
            ]);

        expect($sesion->status())->toBe(302);

        // 4. GET /caja/dia: la pagina SIN caja, y con ella un token fresco.
        $pagina = $servidor->cliente($jar)
            ->get($servidor->url('/caja/dia'));

        expect($pagina->status())->toBe(200);

        $token = token_csrf_de_las_reglas($pagina->body());

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

        // 7. GET /caja/dia: el SALDO EN CURSO es el del asiento (58) y el
        //    DESGLOSE dice lo APLICO (58), no lo recibido (100). Los dos
        //    dicen lo mismo: el arqueo no se contradice en la misma pantalla.
        $trasLaVenta = $servidor->cliente($jar)
            ->get($servidor->url('/caja/dia'));

        $props = pagina_de_inertia_de_las_reglas($trasLaVenta->body())['props'] ?? [];

        expect($trasLaVenta->status())->toBe(200)
            ->and($props['state'] ?? null)->toBe('open')
            ->and($props['running_balance'] ?? null)->toBe('58.0000')
            ->and($props['expected_balance'] ?? null)->toBe('58.0000')
            ->and($props['payments'][0]['amount'] ?? null)->toBe('58.0000')
            ->and($props['cash_total'] ?? null)->toBe('58.0000');

        // 8. POST /caja/dia/cerrar: se cuentan 58 y el esperado son 58. El
        //    ARQUEO CUADRA: la diferencia es cero.
        $cierre = $servidor->cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/caja/dia/cerrar'), [
                'closing_balance' => '58.0000',
            ]);

        expect($cierre->status())->toBe(201)
            ->and($cierre->json('status'))->toBe('closed')
            ->and($cierre->json('expected_balance'))->toBe('58.0000')
            ->and($cierre->json('difference'))->toBe('0.0000');
    } finally {
        $servidor->stop();
    }
});
