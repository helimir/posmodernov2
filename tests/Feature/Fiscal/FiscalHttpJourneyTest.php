<?php

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;
use Tests\Support\PosHttpServer;

/**
 * 1.7 — el CAMINO POR HTTP de la emision fiscal.
 *
 * Reglas que fija:
 *
 *  - con el interruptor ENCENDIDO, el cobro de verdad deja la venta con su
 *    documento fiscal y su numero de control;
 *  - con el canal FALLANDO, el cobro de verdad responde 201 IGUAL, la venta
 *    se escribe, y el aviso aparece: un fallo fiscal no puede hacer perder
 *    una venta.
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
function token_csrf_de_la_emision(string $html): string
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
function pagina_de_inertia_de_la_emision(string $html): array
{
    if (preg_match('/data-page="([^"]+)"/', $html, $coincidencia) !== 1) {
        return [];
    }

    $json = html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8');

    $pagina = json_decode($json, true);

    return is_array($pagina) ? $pagina : [];
}

/**
 * El CAMINO COMPLETO: login, caja y cobro, por HTTP de verdad.
 *
 * @param  array<string, string>  $entorno  El entorno del servidor.
 */
function cobrar_por_http(PosHttpServer $servidor, array $escenario, array $entorno = []): object
{
    $cajero = PosFixture::usuario($escenario['store_id']);
    $cajero->email = 'caja@emision.test';
    $cajero->password = 'clave-del-camino';
    $cajero->save();

    $jar = new CookieJar;

    $login = $servidor->cliente($jar)->get($servidor->url('/login'));
    $token = token_csrf_de_la_emision($login->body());

    $servidor->cliente($jar, ['allow_redirects' => false])
        ->withHeaders(['X-CSRF-TOKEN' => $token, 'Accept' => 'application/json'])
        ->post($servidor->url('/login'), [
            'email' => 'caja@emision.test',
            'password' => 'clave-del-camino',
        ]);

    // El token del login ya no vale: entrar regenera la sesion.
    $caja = $servidor->cliente($jar)->get($servidor->url('/caja'));
    $token = token_csrf_de_la_emision($caja->body());

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
 * 1.7 — cobrar por HTTP con el interruptor ENCENDIDO deja la venta con su
 * documento fiscal y su numero de control.
 */
test('test_el_cobro_por_http_con_el_interruptor_encendido_deja_la_venta_con_su_documento_fiscal', function () {
    $servidor = PosHttpServer::start(['FISCAL_EMISSION_ENABLED' => 'true']);

    try {
        $escenario = PosFixture::escenario();

        $cobro = cobrar_por_http($servidor, $escenario);

        // 1. El cobro responde 201: la venta esta.
        expect($cobro->status())->toBe(201)
            ->and($cobro->json('fiscal.status'))->toBe('issued')
            ->and($cobro->json('fiscal.control_number'))->not->toBeNull();

        $saleId = (int) $cobro->json('sale_id');
        $documentId = (int) $cobro->json('fiscal.document_id');

        // 2. Y la venta esta ENLAZADA a su documento fiscal, emitido.
        expect($documentId)->not->toBe(0)
            ->and((int) DB::table('sales')->where('id', $saleId)->value('fiscal_document_id'))
            ->toBe($documentId)
            ->and(DB::table('fiscal_documents')->where('id', $documentId)->value('status'))
            ->toBe('issued');

        // 3. Y el documento tiene SU numero de control.
        $control = DB::table('fiscal_document_control_assignments')
            ->where('fiscal_document_id', $documentId)
            ->value('control_number');

        expect($control)->not->toBeNull()
            ->and((string) $control)->toMatch('/^\d{2}-\d{8}$/')
            ->and($cobro->json('fiscal.control_number'))->toBe((string) $control);

        // 4. Y el COMPROBANTE lleva el MISMO numero: una venta tiene UN
        //    numero de control, y la serie avanza UNA vez.
        $serie = DB::table('fiscal_series')
            ->where('id', $escenario['control_series_id'])
            ->first();

        expect($cobro->json('control_number'))->toBe((string) $control)
            ->and((int) $serie->next_number)->toBe(2)
            ->and(DB::table('fiscal_document_reservations')
                ->where('series_id', $escenario['control_series_id'])
                ->count())->toBe(1);
    } finally {
        $servidor->stop();
    }
});

/**
 * 1.7 — cobrar por HTTP con el canal FALLANDO no pierde la venta.
 *
 * REGLA que fija: *un fallo fiscal NO puede hacer perder una venta*. El
 * cobro responde 201, la venta se escribe, y el aviso aparece.
 */
test('test_el_cobro_por_http_con_el_canal_fallando_escribe_la_venta_y_avisa', function () {
    $servidor = PosHttpServer::start(['FISCAL_EMISSION_ENABLED' => 'true']);

    try {
        $escenario = PosFixture::escenario();

        // El perfil pierde el codigo nativo del estado emitido: la emision
        // falla al consolidar. Agotar el rango de control ya no es un fallo
        // de emision (el numero lo toma la venta y el documento lo
        // reutiliza), y un fallo que no existe no prueba nada.
        DB::table('fiscal_status_vocabularies')
            ->where('fiscal_profile_id', $escenario['profile_id'])
            ->where('generic_status', 'issued')
            ->delete();

        $cobro = cobrar_por_http($servidor, $escenario);

        // 1. El cobro responde 201: la venta ESTA.
        expect($cobro->status())->toBe(201);

        $saleId = (int) $cobro->json('sale_id');

        // 2. Y la venta se ESCRIBIO, con su numero y su pago.
        $venta = DB::table('sales')->where('id', $saleId)->first();

        expect($venta)->not->toBeNull()
            ->and($venta->status)->toBe('fiscal_failed')
            ->and(DB::table('sale_payments')->where('sale_id', $saleId)->count())->toBe(1);

        // 3. Y el AVISO aparece: el estado fiscal dice que fallo, con su
        //    motivo.
        expect($cobro->json('fiscal.status'))->toBe('failed')
            ->and($cobro->json('fiscal.message'))->not->toBeNull()
            ->and($cobro->json('fiscal.message'))->not->toBe('');
    } finally {
        $servidor->stop();
    }
});
