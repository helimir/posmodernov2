<?php

use GuzzleHttp\Cookie\CookieJar;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * 1.5 — el CAMINO de la caja del dia, por HTTP de verdad.
 *
 * Reglas que fija:
 *
 *  - el camino COMPLETO de la unidad funciona: `GET /caja/dia` ->
 *    `POST /caja/dia/abrir` -> `POST /caja/cobrar` -> `GET /caja/dia` ->
 *    `POST /caja/dia/cerrar` -> `GET /caja/dia`;
 *  - el SALDO EN CURSO se ve ACTUALIZADO en la pagina despues de cobrar: es
 *    un derivado de los asientos, no un numero escrito a mano;
 *  - el CIERRE se lleva el DESCUADRE: el contado menos el esperado, con su
 *    signo;
 *  - el camino no depende de Redis ni de un puerto fijo.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS: un test con `actingAs()` verifica
 * la PAGINA; este verifica el CAMINO: el arranque de verdad, el login de
 * verdad, el token CSRF de verdad y el enrutado real de las peticiones. Un
 * test de HTTP SIMULADO no vale: simularia el servidor que hay que probar.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_de_la_caja(string $html): string
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
function pagina_de_inertia_de_la_caja(string $html): array
{
    if (preg_match('/data-page="([^"]+)"/', $html, $coincidencia) !== 1) {
        return [];
    }

    $json = html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8');

    $pagina = json_decode($json, true);

    return is_array($pagina) ? $pagina : [];
}

/**
 * El camino completo de la caja del dia, por HTTP de verdad: abrir la caja,
 * cobrar, ver el saldo en curso y cerrar con el descuadre.
 */
test('test_el_camino_de_la_caja_del_dia_por_http_abre_cobra_ve_el_saldo_y_cierra_con_el_descuadre', function () {
    $servidor = PosHttpServer::start();

    try {
        // 1. El ESCENARIO, en la base de datos del camino: la tienda
        //    sembrada, su perfil fiscal, sus series, un producto con stock y
        //    un cajero con su vinculo por la tienda. El cajero viene SIN caja
        //    del dia: abrirla es parte del camino.
        $escenario = PosFixture::escenario();

        $cajero = PosFixture::usuarioSinCaja($escenario['store_id']);
        $cajero->email = 'caja-dia@camino.test';
        $cajero->password = 'clave-del-camino';
        $cajero->save();

        // 2. GET /login: la pagina trae el token con el que se firman los
        //    POST.
        $jar = new CookieJar;

        $login = $servidor->cliente($jar)
            ->get($servidor->url('/login'));

        expect($login->status())->toBe(200);

        $token = token_csrf_de_la_caja($login->body());

        expect($token)->not->toBe('');

        // 3. POST /login: el token firma la peticion y la sesion se abre.
        $sesion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/login'), [
                'email' => 'caja-dia@camino.test',
                'password' => 'clave-del-camino',
            ]);

        expect($sesion->status())->toBe(302);

        // El token del login YA NO VALE: entrar regenera la sesion. El token
        // fresco se pide a una pagina.
        $pagina = $servidor->cliente($jar)
            ->get($servidor->url('/caja/dia'));

        expect($pagina->status())->toBe(200);

        $props = pagina_de_inertia_de_la_caja($pagina->body())['props'] ?? [];

        // 4. La pagina SIN caja: el estado lo dice la pagina.
        expect(pagina_de_inertia_de_la_caja($pagina->body())['component'] ?? null)->toBe('cash/day')
            ->and($props['state'] ?? null)->toBe('not_opened')
            ->and($props['register'] ?? null)->toBeNull()
            ->and($props['opening_balance'] ?? null)->toBe('0.0000')
            ->and($props['expected_balance'] ?? null)->toBe('0.0000');

        $token = token_csrf_de_la_caja($pagina->body());

        expect($token)->not->toBe('');

        // 5. POST /caja/dia/abrir: la caja del dia, con su saldo de partida.
        $apertura = $servidor->cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/caja/dia/abrir'), [
                'opening_balance' => '100.0000',
            ]);

        expect($apertura->status())->toBe(201)
            ->and($apertura->json('status'))->toBe('open')
            ->and($apertura->json('opening_balance'))->toBe('100.0000');

        // 6. GET /caja/dia: la caja esta ABIERTA y el esperado es la partida.
        $abierta = $servidor->cliente($jar)
            ->get($servidor->url('/caja/dia'));

        $props = pagina_de_inertia_de_la_caja($abierta->body())['props'] ?? [];

        expect($abierta->status())->toBe(200)
            ->and($props['state'] ?? null)->toBe('open')
            ->and($props['opening_balance'] ?? null)->toBe('100.0000')
            ->and($props['running_balance'] ?? null)->toBe('0.0000')
            ->and($props['expected_balance'] ?? null)->toBe('100.0000');

        // 7. POST /caja/cobrar: la venta se escribe de verdad, y la caja del
        //    dia esta ABIERTA, asi que el cobro pasa.
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

        expect($cobro->status())->toBe(201)
            ->and($cobro->json('grand_total'))->toBe('58.0000')
            ->and($cobro->json('status'))->toBe('paid');

        // 8. GET /caja/dia: el SALDO EN CURSO se ve ACTUALIZADO. Es un
        //    derivado de los asientos: la venta debita la cuenta de efectivo
        //    de la tienda.
        $trasLaVenta = $servidor->cliente($jar)
            ->get($servidor->url('/caja/dia'));

        $props = pagina_de_inertia_de_la_caja($trasLaVenta->body())['props'] ?? [];

        expect($trasLaVenta->status())->toBe(200)
            ->and($props['state'] ?? null)->toBe('open')
            ->and($props['running_balance'] ?? null)->toBe('58.0000')
            ->and($props['expected_balance'] ?? null)->toBe('158.0000')
            ->and($props['payments'][0]['amount'] ?? null)->toBe('58.0000')
            ->and($props['cash_total'] ?? null)->toBe('58.0000');

        // 9. POST /caja/dia/cerrar: se cuentan 160 y el esperado son 158. El
        //    DESCUADRE son dos, con su signo.
        $cierre = $servidor->cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/caja/dia/cerrar'), [
                'closing_balance' => '160.0000',
            ]);

        expect($cierre->status())->toBe(201)
            ->and($cierre->json('status'))->toBe('closed')
            ->and($cierre->json('closing_balance'))->toBe('160.0000')
            ->and($cierre->json('expected_balance'))->toBe('158.0000')
            ->and($cierre->json('difference'))->toBe('2.0000');

        // 10. GET /caja/dia: el RESUMEN DEL DIA, con el descuadre y con quien
        //     abrio y quien cerro.
        $cerrada = $servidor->cliente($jar)
            ->get($servidor->url('/caja/dia'));

        $props = pagina_de_inertia_de_la_caja($cerrada->body())['props'] ?? [];

        expect($cerrada->status())->toBe(200)
            ->and($props['state'] ?? null)->toBe('closed')
            ->and($props['opening_balance'] ?? null)->toBe('100.0000')
            ->and($props['running_balance'] ?? null)->toBe('58.0000')
            ->and($props['expected_balance'] ?? null)->toBe('158.0000')
            ->and($props['counted_balance'] ?? null)->toBe('160.0000')
            ->and($props['difference'] ?? null)->toBe('2.0000')
            ->and($props['register']['opened_by']['name'] ?? null)->not->toBeNull()
            ->and($props['register']['closed_by']['name'] ?? null)->not->toBeNull();
    } finally {
        $servidor->stop();
    }
});
