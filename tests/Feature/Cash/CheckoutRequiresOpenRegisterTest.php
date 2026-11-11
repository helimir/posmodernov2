<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El COBRO exige la caja del dia ABIERTA.
 *
 * Esta es la deuda que la unidad cierra: se podia vender sin caja abierta, y
 * eso es un agujero de negocio (una venta sin caja es una venta sin contra
 * que contrastar).
 *
 * Reglas que fija:
 *
 *  - el cobro SIN caja abierta falla con 422 y con un mensaje que lo dice, y
 *    NO queda ninguna venta: la transaccion revierte entera;
 *  - el cobro con la caja CERRADA falla igual: una caja cerrada no admite
 *    movimientos;
 *  - el cobro con la caja ABIERTA funciona, y la venta es una venta entera.
 */

/**
 * El pedido de una venta de dos unidades a 25, con un 16 % de impuesto.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function caja_cobro_pedido(array $escenario): array
{
    return [
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
    ];
}

/**
 * El cobro SIN caja abierta falla con 422 y el mensaje lo dice. Y no queda
 * ninguna venta: la transaccion revierte entera.
 */
test('test_el_cobro_sin_caja_abierta_falla_con_422_y_no_deja_venta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', caja_cobro_pedido($escenario));

    $respuesta->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // El mensaje DICE que la caja del dia no esta abierta.
    expect($respuesta->json('errorMsg'))->toContain('caja del dia no esta abierta');

    // Y no queda NADA de la venta: ni cabecera, ni lineas, ni totales, ni
    // pagos, ni asiento, ni cambio de stock, ni numero quemado.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('sale_totals')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0)
        ->and(DB::table('fiscal_document_reservations')->count())->toBe(0)
        ->and((string) DB::table('inventory_lots')->value('available'))->toBe('10.0000');
});

/**
 * El cobro con la caja CERRADA falla igual: una caja cerrada no admite
 * movimientos.
 */
test('test_el_cobro_con_la_caja_cerrada_falla_igual', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '100.0000'])
        ->assertCreated();

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', caja_cobro_pedido($escenario));

    $respuesta->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    expect($respuesta->json('errorMsg'))->toContain('caja del dia no esta abierta');

    // Y tampoco queda venta.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * El cobro con la caja ABIERTA funciona: la venta es una venta entera.
 */
test('test_el_cobro_con_la_caja_abierta_funciona', function () {
    $escenario = PosFixture::escenario();

    // El escenario de la caja trae la caja del dia ABIERTA.
    $usuario = PosFixture::usuario($escenario['store_id']);

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', caja_cobro_pedido($escenario));

    $respuesta->assertCreated()
        ->assertJsonPath('status', 'paid')
        ->assertJsonPath('grand_total', '58.0000');

    expect(DB::table('sales')->count())->toBe(1)
        ->and(DB::table('sale_payments')->count())->toBe(1)
        ->and(DB::table('ledger_entries')->count())->toBe(1);
});
