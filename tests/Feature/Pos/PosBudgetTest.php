<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * El PRESUPUESTO de la caja: lo que se va a cobrar, calculado por el motor.
 *
 * Reglas que fija:
 *
 *  - el motor APLICA el descuento: un presupuesto con descuento no coincide
 *    con el mismo presupuesto sin descuento;
 *  - el total del presupuesto COINCIDE EXACTAMENTE con el `grand_total` de la
 *    venta que cobra el mismo carrito: el presupuesto y el cobro son la misma
 *    aritmetica, la del motor de impuestos;
 *  - el presupuesto NO escribe nada: ni cabecera, ni linea, ni pago, ni
 *    asiento, ni cambio de stock.
 */

/**
 * El presupuesto de un carrito, con el descuento que se le pase.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function presupuesto_con_descuento(array $escenario, string $descuento, string $pagado = '58.0000'): array
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
                'amount' => $pagado,
            ],
        ],
    ];
}

/**
 * El motor APLICA el descuento: los dos presupuestos no coinciden.
 */
test('test_el_presupuesto_con_descuento_no_coincide_con_el_total_sin_descuento', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // 1. Sin descuento: dos unidades a 25 con un 16 % son 58.
    $sinDescuento = $this->actingAs($usuario)
        ->postJson('/caja/presupuesto', presupuesto_con_descuento($escenario, '0.0000'));

    $sinDescuento->assertOk()
        ->assertJsonPath('subtotal', '50.0000')
        ->assertJsonPath('tax_total', '8.0000')
        ->assertJsonPath('discount', '0.0000')
        ->assertJsonPath('grand_total', '58.0000');

    // 2. Con diez de descuento: la base es 40, el impuesto 6.40 y el total
    //    46.40. El motor descuenta, y el total BAJA.
    $conDescuento = $this->actingAs($usuario)
        ->postJson('/caja/presupuesto', presupuesto_con_descuento($escenario, '10.0000'));

    $conDescuento->assertOk()
        ->assertJsonPath('subtotal', '40.0000')
        ->assertJsonPath('tax_total', '6.4000')
        ->assertJsonPath('discount', '10.0000')
        ->assertJsonPath('grand_total', '46.4000');

    // 3. Y los dos totales NO son lo mismo: el descuento se aplico.
    expect($conDescuento->json('grand_total'))
        ->not->toBe($sinDescuento->json('grand_total'));
});

/**
 * El total del presupuesto COINCIDE con el `grand_total` de la venta.
 *
 * Esta es la prueba que ata las dos mitades: el presupuesto y el cobro son
 * la MISMA aritmetica. Si el presupuesto se separara del motor, el cajero
 * veria un total y cobraria otro.
 */
test('test_el_total_del_presupuesto_coincide_con_el_grand_total_de_la_venta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // 1. El presupuesto del carrito.
    $presupuesto = $this->actingAs($usuario)
        ->postJson('/caja/presupuesto', presupuesto_con_descuento($escenario, '10.0000'));

    $presupuesto->assertOk();

    // 2. El COBRO del mismo carrito, pagando el total exacto.
    $cobro = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', presupuesto_con_descuento($escenario, '10.0000', '46.4000'));

    $cobro->assertCreated()->assertJsonPath('status', 'paid');

    // 3. Los dos totales son el MISMO numero.
    expect($presupuesto->json('grand_total'))->toBe($cobro->json('grand_total'))
        ->and((string) DB::table('sale_totals')
            ->where('sale_id', (int) $cobro->json('sale_id'))
            ->value('grand_total'))->toBe($presupuesto->json('grand_total'))
        // Y el desglose tambien: la base, el impuesto y el descuento.
        ->and((string) DB::table('sale_totals')
            ->where('sale_id', (int) $cobro->json('sale_id'))
            ->value('sub_total'))->toBe($presupuesto->json('subtotal'))
        ->and((string) DB::table('sale_totals')
            ->where('sale_id', (int) $cobro->json('sale_id'))
            ->value('tax_total'))->toBe($presupuesto->json('tax_total'));
});
