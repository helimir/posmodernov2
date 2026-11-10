<?php

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * El CAMINO de la caja, por HTTP de verdad.
 *
 * Reglas que fija:
 *
 *  - el camino COMPLETO funciona: `GET /login` -> `POST /login` ->
 *    `GET /caja` -> `POST /caja/presupuesto` -> `POST /caja/cobrar` ->
 *    `GET /caja` de nuevo;
 *  - sin sesion, la caja NO se sirve: redirige al login;
 *  - el camino no depende de Redis ni de un puerto fijo.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS, Y POR QUE NO SE PUEDE SIMULAR:
 *
 * Un test con `actingAs()` verifica la PAGINA. Este verifica el CAMINO: el
 * arranque de verdad, el login de verdad, el token CSRF de verdad y el
 * enrutado real de las peticiones. Y el camino ya fallo dos veces en
 * produccion de un modo que ni el CI veia: el 419 del CSRF (el login no
 * funcionaba en el navegador) y las mayusculas de las rutas de Inertia (en
 * Linux la aplicacion no renderizaba nada). Los dos fallos se minearon al
 * probar el arranque POR HTTP de verdad.
 *
 * Un test de HTTP SIMULADO no vale: simularia el servidor que hay que
 * probar. Aqui el servidor es de verdad (`php artisan serve`), en un puerto
 * libre, contra una base de datos propia que se tira al terminar.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_de(string $html): string
{
    if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $coincidencia) !== 1) {
        return '';
    }

    return $coincidencia[1];
}

/**
 * La PAGINA de Inertia que devuelve el servidor: el componente y sus props.
 *
 * El HTML lleva la pagina en el atributo `data-page`, con el JSON escapado.
 * Se lee y se desescapa, para poder afirmar sobre el componente y sobre las
 * props y no sobre el HTML.
 *
 * @return array<string, mixed>
 */
function pagina_de_inertia(string $html): array
{
    if (preg_match('/data-page="([^"]+)"/', $html, $coincidencia) !== 1) {
        return [];
    }

    $json = html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8');

    $pagina = json_decode($json, true);

    return is_array($pagina) ? $pagina : [];
}

/**
 * El camino completo de la caja, por HTTP de verdad.
 */
test('test_el_camino_de_la_caja_por_http_loguea_presupuesta_y_cobra', function () {
    $servidor = PosHttpServer::start();

    try {
        // 1. El ESCENARIO, en la base de datos del camino: la tienda
        //    sembrada, su perfil fiscal, sus series, un producto con stock y
        //    un cajero con su vinculo por la tienda.
        $escenario = PosFixture::escenario();

        $cajero = PosFixture::usuario($escenario['store_id']);
        $cajero->email = 'caja@camino.test';
        $cajero->password = 'clave-del-camino';
        $cajero->save();

        // 2. SIN SESION, la caja NO se sirve: redirige al login. Se comprueba
        //    ANTES de entrar, con un cliente sin cookies.
        $sinSesion = Http::withOptions(['allow_redirects' => false])
            ->get($servidor->url('/caja'));

        expect($sinSesion->status())->toBe(302)
            ->and((string) $sinSesion->header('Location'))->toContain('/login');

        // 3. GET /login: la pagina trae el token con el que se firman los
        //    POST. Sin el, TODO POST responde 419.
        //
        //    El cliente guarda las cookies DESDE AQUI: el token vive en la
        //    sesion, y el POST tiene que enviar la MISMA sesion que lo creo.
        $jar = new CookieJar;

        $login = Http::withOptions(['cookies' => $jar])
            ->get($servidor->url('/login'));

        expect($login->status())->toBe(200);

        $token = token_csrf_de($login->body());

        expect($token)->not->toBe('');

        // 4. POST /login: el token firma la peticion y la sesion se abre.
        $sesion = Http::withOptions(['cookies' => $jar, 'allow_redirects' => false])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/login'), [
                'email' => 'caja@camino.test',
                'password' => 'clave-del-camino',
            ]);

        expect($sesion->status())->toBe(302);

        // 5. GET /caja: la pantalla del cajero, con la sesion de verdad.
        $caja = Http::withOptions(['cookies' => $jar])
            ->get($servidor->url('/caja'));

        expect($caja->status())->toBe(200);

        // La pagina de Inertia renderiza el componente de la caja, con el
        // escenario que la pantalla necesita.
        $pagina = pagina_de_inertia($caja->body());

        expect($pagina['component'] ?? null)->toBe('pos/index')
            ->and($pagina['props']['store']['name'] ?? null)->toBe('Tienda de demostracion')
            ->and($pagina['props']['currency']['symbol'] ?? null)->toBe('Bs.')
            ->and($pagina['props']['catalog'][0]['sell_price'] ?? null)->toBe('25.0000');

        // El token del login YA NO VALE: entrar regenera la sesion y con ella
        // el token CSRF. El navegador no se entera porque cada pagina trae el
        // suyo; un cliente HTTP tiene que hacer lo mismo. Con el token viejo
        // el servidor responde 419.
        $token = token_csrf_de($caja->body());

        expect($token)->not->toBe('');

        // 6. POST /caja/presupuesto: el total que calcula el motor.
        $presupuesto = Http::withOptions(['cookies' => $jar])
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/caja/presupuesto'), [
                'lines' => [
                    [
                        'product_id' => $escenario['product_id'],
                        'quantity' => '2',
                        'unit_price' => '25.0000',
                    ],
                ],
            ]);

        expect($presupuesto->status())->toBe(200)
            ->and($presupuesto->json('grand_total'))->toBe('58.0000')
            ->and($presupuesto->json('tax_total'))->toBe('8.0000');

        // 7. POST /caja/cobrar: la venta se escribe de verdad.
        $cobro = Http::withOptions(['cookies' => $jar])
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
            ->and($cobro->json('status'))->toBe('paid')
            ->and($cobro->json('change'))->toBe('0.0000')
            ->and($cobro->json('invoice_no'))->not->toBeNull();

        // 8. GET /caja de nuevo: la caja sigue abierta para el siguiente
        //    cliente.
        $otraVez = Http::withOptions(['cookies' => $jar])
            ->get($servidor->url('/caja'));

        expect($otraVez->status())->toBe(200)
            ->and(pagina_de_inertia($otraVez->body())['component'] ?? null)->toBe('pos/index');
    } finally {
        $servidor->stop();
    }
});
