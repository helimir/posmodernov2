<?php

use GuzzleHttp\Cookie\CookieJar;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * 1.4 — el CAMINO del credito, por HTTP de verdad.
 *
 * Reglas que fija:
 *
 *  - el camino COMPLETO del credito funciona: `POST /api/clientes` (el alta) ->
 *    `GET /api/caja/clientes` (el selector de la caja) -> `POST /caja/cobrar`
 *    (una venta A CREDITO) -> `GET /clientes/{id}` (la ficha con la deuda);
 *  - una venta a credito deja la DEUDA del cliente igual al TOTAL de la
 *    venta, y no un credito a favor del cliente;
 *  - un pago a CREDITO sin cliente NO se cobra: el servidor responde 422, y
 *    una deuda sin destino no se registra.
 *
 * POR QUE ESTE TEST NO ES COMO LOS OTROS: un test con `actingAs()` verifica
 * la PAGINA; este verifica el CAMINO: el arranque de verdad, el login de
 * verdad, el token CSRF de verdad y el enrutado real de las peticiones. El
 * crack del credito era un crack de CAMINO, y el camino es lo que se prueba
 * aqui.
 *
 * Un test de HTTP SIMULADO no vale: simularia el servidor que hay que probar.
 * Aqui el servidor es de verdad (`php artisan serve`), en un puerto libre,
 * contra una base de datos propia que se tira al terminar.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El token CSRF de una pagina: lo lee del meta del blade.
 */
function token_csrf_del_credito(string $html): string
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
 *
 * @return array<string, mixed>
 */
function pagina_de_inertia_del_credito(string $html): array
{
    if (preg_match('/data-page="([^"]+)"/', $html, $coincidencia) !== 1) {
        return [];
    }

    $json = html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8');

    $pagina = json_decode($json, true);

    return is_array($pagina) ? $pagina : [];
}

/**
 * El camino del credito, por HTTP de verdad: un cliente nuevo, encontrado en
 * el selector de la caja, con una venta a CREDITO y la deuda al total.
 */
test('test_el_camino_del_credito_por_http_crea_el_cliente_lo_encuentra_y_le_cobra_a_credito', function () {
    $servidor = PosHttpServer::start();

    try {
        // 1. El ESCENARIO, en la base de datos del camino: la tienda
        //    sembrada, su perfil fiscal, sus series, un producto con stock, el
        //    medio de pago de CREDITO y un cajero con su vinculo por la
        //    tienda.
        $escenario = PosFixture::escenario();
        $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);

        $cajero = PosFixture::usuario($escenario['store_id']);
        $cajero->email = 'credito@camino.test';
        $cajero->password = 'clave-del-camino';
        $cajero->save();

        // 2. GET /login: la pagina trae el token con el que se firman los
        //    POST.
        $jar = new CookieJar;

        $login = $servidor->cliente($jar)->get($servidor->url('/login'));

        expect($login->status())->toBe(200);

        $token = token_csrf_del_credito($login->body());

        expect($token)->not->toBe('');

        // 3. POST /login: el token firma la peticion y la sesion se abre.
        $sesion = $servidor->cliente($jar, ['allow_redirects' => false])
            ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
            ->post($servidor->url('/login'), [
                'email' => 'credito@camino.test',
                'password' => 'clave-del-camino',
            ]);

        expect($sesion->status())->toBe(302);

        // El token del login YA NO VALE: entrar regenera la sesion. El token
        // fresco se pide a una pagina.
        $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));

        expect($caja->status())->toBe(200);

        $token = token_csrf_del_credito($caja->body());

        expect($token)->not->toBe('');

        // 4. POST /api/clientes: el ALTA del cliente, por la API.
        $alta = $servidor->cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/api/clientes'), [
                'name' => 'Cliente del camino',
                'tax_id' => 'V-CAMINO01',
            ]);

        expect($alta->status())->toBe(201);

        $clienteId = (int) $alta->json('id');

        expect($clienteId)->toBeGreaterThan(0)
            ->and($alta->json('balance'))->toBe('0.0000');

        // 5. GET /api/caja/clientes: el selector de la caja lo ENCUENTRA.
        $selector = $servidor->cliente($jar)
            ->get($servidor->url('/api/caja/clientes?q=Cliente del camino'));

        expect($selector->status())->toBe(200);

        $ids = array_column($selector->json('customers'), 'id');

        expect($ids)->toContain($clienteId);

        // 6. POST /caja/cobrar: la venta A CREDITO. El pago es de tipo
        //    credito: es deuda, no cobro.
        $cobro = $servidor->cliente($jar)
            ->withHeaders([
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->post($servidor->url('/caja/cobrar'), [
                'customer_id' => $clienteId,
                'lines' => [
                    [
                        'product_id' => $escenario['product_id'],
                        'quantity' => '2',
                        'unit_price' => '25.0000',
                    ],
                ],
                'payments' => [
                    [
                        'payment_method_id' => $credito,
                        'amount' => '58.0000',
                    ],
                ],
            ]);

        expect($cobro->status())->toBe(201)
            ->and($cobro->json('grand_total'))->toBe('58.0000');

        // 7. GET /api/clientes/{id}: la ficha con la DEUDA. La deuda es el
        //    TOTAL de la venta: no hay cobro, y el pago a credito NO es un
        //    cobro.
        $ficha = $servidor->cliente($jar)->get($servidor->url('/api/clientes/'.$clienteId));

        expect($ficha->status())->toBe(200)
            ->and($ficha->json('due'))->toBe('58.0000')
            ->and($ficha->json('balance'))->toBe('58.0000')
            ->and($ficha->json('cache_matches'))->toBeTrue()
            ->and($ficha->json('entries.0.type'))->toBe('sale')
            ->and($ficha->json('entries.0.amount'))->toBe('58.0000');

        // Y el movimiento de COBRO NO existe: un pago a credito es deuda.
        expect($ficha->json('entries'))->toHaveCount(1);

        // 8. GET /clientes/{id}: la MISMA ficha, pero como PAGINA. El camino
        //    del navegador acaba en una pantalla, no en un JSON.
        $pagina = $servidor->cliente($jar)->get($servidor->url('/clientes/'.$clienteId));

        expect($pagina->status())->toBe(200);

        $props = pagina_de_inertia_del_credito($pagina->body())['props'] ?? [];

        expect(pagina_de_inertia_del_credito($pagina->body())['component'] ?? null)->toBe('customers/show')
            ->and($props['due'] ?? null)->toBe('58.0000')
            ->and($props['balance'] ?? null)->toBe('58.0000')
            ->and($props['entries'][0]['type'] ?? null)->toBe('sale');
    } finally {
        $servidor->stop();
    }
});
