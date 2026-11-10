<?php

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PosFixture;

/**
 * La PANTALLA de la caja: el contrato que la interfaz consume.
 *
 * Reglas que fija:
 *
 *  - la pantalla abre con 200 y con el componente `pos/index`, y entrega el
 *    escenario completo: la tienda, el catalogo, los medios de pago, las dos
 *    series y la fecha de negocio;
 *  - la MONEDA viaja con su simbolo y sus separadores: el codigo solo no
 *    alcanza, porque una pantalla sin el simbolo no se lee. Los datos salen de
 *    la tabla `currencies`, no de un literal de la interfaz;
 *  - el PRESUPUESTO devuelve el total calculado por el MOTOR y NO cobra
 *    nada: no hay venta, ni lineas, ni cambio de stock, ni asiento contable,
 *    ni numero de documento quemado;
 *  - un presupuesto con lineas invalidas responde 422 y no calcula nada: un
 *    cobro invalido no llega al motor.
 */

/**
 * El pedido de un presupuesto: las mismas lineas que se cobran.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function presupuesto_de_caja(array $escenario, string $descuento = '0.0000'): array
{
    return [
        'lines' => [
            [
                'product_id' => $escenario['product_id'],
                'quantity' => '2',
                'unit_price' => '25.0000',
                'discount' => $descuento,
            ],
        ],
        'payments' => [
            [
                'payment_method_id' => $escenario['payment_method_id'],
                'amount' => '58.0000',
            ],
        ],
    ];
}

/**
 * La pantalla de la caja entrega el CONTRATO de sus props, con la moneda.
 */
test('test_la_pantalla_de_la_caja_entrega_el_contrato_de_sus_props_con_la_moneda', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)->get('/caja');

    $respuesta->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('pos/index')
            ->where('store.id', $escenario['store_id'])
            ->where('store.name', 'Tienda de demostracion')
            ->where('store.code_name', 'DEMO')
            ->where('store.timezone', 'America/Caracas')
            ->where('document_series_id', $escenario['document_series_id'])
            ->where('control_series_id', $escenario['control_series_id'])
            ->where('currency_code', 'VES')
            ->where('catalog_page_size', 24)
            ->has('catalog', 1)
            ->where('catalog.0.id', $escenario['product_id'])
            ->where('catalog.0.code', 'HUMO-CAJA-001')
            ->where('catalog.0.name', 'Producto de la caja')
            ->where('catalog.0.sell_price', '25.0000')
            ->where('catalog.0.quantity_in_stock', '10.0000')
            ->has('payment_methods', 1)
            ->where('payment_methods.0.id', $escenario['payment_method_id'])
            ->where('payment_methods.0.code', 'HUMO-EFECTIVO')
            // La MONEDA: el codigo, el simbolo, donde va el simbolo, los
            // decimales y los dos separadores.
            ->where('currency.code', 'VES')
            ->where('currency.symbol', 'Bs.')
            ->where('currency.symbol_position', 'before')
            ->where('currency.decimals', 2)
            ->where('currency.thousand_separator', ',')
            ->where('currency.decimal_separator', '.')
            ->etc());

    // Y la fecha de negocio es la de la TIENDA, no la del servidor.
    $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Caracas')))->format('Y-m-d');

    $respuesta->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('business_date', $fecha)
        ->etc());
});

/**
 * El presupuesto devuelve el total del MOTOR y no cobra nada.
 */
test('test_el_presupuesto_devuelve_el_total_del_motor_y_no_cobra_nada', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/presupuesto', presupuesto_de_caja($escenario));

    // El desglose: la base imponible, el impuesto, el descuento, el ajuste de
    // redondeo y el total. Los importes son TEXTO decimal.
    $respuesta->assertOk()
        ->assertJsonPath('subtotal', '50.0000')
        ->assertJsonPath('tax_total', '8.0000')
        ->assertJsonPath('discount', '0.0000')
        ->assertJsonPath('rounding_adjustment', '0.0000')
        ->assertJsonPath('grand_total', '58.0000');

    // Y NO cobra: no hay venta, ni linea, ni pago, ni asiento, ni cambio de
    // stock, ni numero reservado.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('sale_totals')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0)
        ->and(DB::table('fiscal_document_reservations')->count())->toBe(0)
        ->and((string) DB::table('inventory_lots')->value('available'))->toBe('10.0000');
});

/**
 * El presupuesto NO necesita pagos: el total no depende de como se paga.
 *
 * La pantalla pregunta el total cuando el cliente aun no ha dado nada, y en
 * ese momento no hay ningun pago que enviar. Exigirlo haria fallar la
 * pantalla justo cuando mas se necesita.
 */
test('test_el_presupuesto_no_necesita_pagos_para_calcular_el_total', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/presupuesto', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
        ]);

    $respuesta->assertOk()
        ->assertJsonPath('grand_total', '58.0000')
        ->assertJsonPath('tax_total', '8.0000');

    // Y sigue sin cobrar nada.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_totals')->count())->toBe(0);
});

/**
 * Un presupuesto con lineas INVALIDAS responde 422 y no calcula nada.
 */
test('test_el_presupuesto_con_lineas_invalidas_responde_422_y_no_calcula', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // 1. Una linea con cantidad cero.
    $this->actingAs($usuario)
        ->postJson('/caja/presupuesto', [
            'lines' => [
                ['product_id' => $escenario['product_id'], 'quantity' => '0', 'unit_price' => '25.0000'],
            ],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 2. Un presupuesto sin lineas.
    $this->actingAs($usuario)
        ->postJson('/caja/presupuesto', [
            'lines' => [],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 3. Una cantidad que no es un numero decimal.
    $this->actingAs($usuario)
        ->postJson('/caja/presupuesto', [
            'lines' => [
                ['product_id' => $escenario['product_id'], 'quantity' => 'no-es-un-numero', 'unit_price' => '25.0000'],
            ],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 4. Y nada de eso se calculo ni se cobro.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_totals')->count())->toBe(0)
        ->and(DB::table('fiscal_document_reservations')->count())->toBe(0)
        ->and((string) DB::table('inventory_lots')->value('available'))->toBe('10.0000');
});
