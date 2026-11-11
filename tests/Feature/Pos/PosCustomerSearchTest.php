<?php

use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * 1.4 — el SELECTOR DE CLIENTES de la caja.
 *
 * Reglas que fija:
 *
 *  - el selector devuelve SOLO los clientes que tienen ficha en la TIENDA
 *    ACTIVA: el credito de un cliente es POR TIENDA, y un cliente que no esta
 *    en la tienda no se le vende;
 *  - la busqueda es por NOMBRE (como prefijo) o por `tax_id` (exacto);
 *  - un termino de busqueda es DATO, no PATRON: escribir `50%` no devuelve
 *    medio catalogo, porque el comodin va escapado.
 */

/**
 * El escenario del selector: la tienda de demostracion con dos clientes y
 * una segunda tienda con uno propio.
 *
 * @return array<string, mixed>
 */
function escenario_del_selector_de_clientes(): array
{
    $escenario = PosFixture::escenario();

    $otraTienda = CreditFixture::store();

    return [
        'escenario' => $escenario,
        'usuario' => PosFixture::usuario($escenario['store_id']),
        'ana' => CreditFixture::customer($escenario['store_id'], 'Ana Perez', 'V-12345678'),
        'andres' => CreditFixture::customer($escenario['store_id'], 'Andres Ruiz', 'V-87654321'),
        'brenda' => CreditFixture::customer($escenario['store_id'], 'Brenda Diaz', 'J-11111111'),
        'de_otra_tienda' => CreditFixture::customer($otraTienda, 'Carlos Fuera', 'V-00000000'),
    ];
}

/**
 * El selector devuelve los clientes de la TIENDA ACTIVA, y no los de otra.
 */
test('test_el_selector_de_clientes_devuelve_los_de_la_tienda_activa_y_no_los_de_otra_tienda', function () {
    $datos = escenario_del_selector_de_clientes();

    $respuesta = $this->actingAs($datos['usuario'])->getJson('/api/caja/clientes');

    $respuesta->assertOk();

    $ids = array_column($respuesta->json('customers'), 'id');

    expect($ids)->toContain($datos['ana'])
        ->and($ids)->toContain($datos['andres'])
        ->and($ids)->toContain($datos['brenda'])
        ->and($ids)->not->toContain($datos['de_otra_tienda']);

    // Y cada fila trae lo que el selector necesita: el nombre, el documento,
    // el saldo y la deuda, como texto decimal.
    $ana = collect($respuesta->json('customers'))->firstWhere('id', $datos['ana']);

    expect($ana['name'])->toBe('Ana Perez')
        ->and($ana['tax_id'])->toBe('V-12345678')
        ->and($ana['balance'])->toBe('0.0000')
        ->and($ana['due'])->toBe('0.0000');
});

/**
 * La busqueda del selector: por NOMBRE como prefijo y por `tax_id` exacto.
 */
test('test_el_selector_de_clientes_busca_por_nombre_por_documento_y_el_comodin_es_dato', function () {
    $datos = escenario_del_selector_de_clientes();

    // 1. POR NOMBRE, como prefijo.
    $porNombre = $this->actingAs($datos['usuario'])->getJson('/api/caja/clientes?q=An');

    $porNombre->assertOk();

    $ids = array_column($porNombre->json('customers'), 'id');

    expect($ids)->toContain($datos['ana'])
        ->and($ids)->toContain($datos['andres'])
        ->and($ids)->not->toContain($datos['brenda']);

    // 2. POR DOCUMENTO, exacto.
    $porDocumento = $this->actingAs($datos['usuario'])->getJson('/api/caja/clientes?q=V-87654321');

    $porDocumento->assertOk();

    expect(array_column($porDocumento->json('customers'), 'id'))->toBe([$datos['andres']]);

    // 3. Y el COMODIN es DATO, no patron: un `?q=%` NO devuelve todos los
    //    clientes, porque el comodin va escapado en el valor del parametro.
    $comodin = $this->actingAs($datos['usuario'])->getJson('/api/caja/clientes?q=%');

    $comodin->assertOk();

    expect($comodin->json('customers'))->toHaveCount(0);
});
