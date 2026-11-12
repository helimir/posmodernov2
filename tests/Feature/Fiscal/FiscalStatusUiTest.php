<?php

use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\PosFixture;

/**
 * 1.8 — la UI del ESTADO FISCAL.
 *
 * REGLA que fija: *el cajero tiene que saber si el comprobante salio*. La
 * lista de ventas y el detalle enseñan el estado fiscal de la venta, con su
 * numero de control, y el detalle ofrece el REINTENTO y el enlace al
 * comprobante.
 *
 * Y EL REINTENTO NO PIERDE LA VENTA: si el canal vuelve a fallar, la venta
 * sigue existiendo y la pantalla dice POR QUE. Un fallo fiscal que hace
 * perder la venta hace que el cajero cobre otra vez, y eso es PEOR que un
 * comprobante pendiente.
 *
 * El CONTRATO que ve la pantalla es el mismo en las tres pantallas (la
 * lista, el detalle y la caja):
 *
 *  - `status`: `issued` (emitido), `failed` (fallido, con su motivo) o
 *    `not_emitted` (no emitido);
 *  - `document_id`: el comprobante, si lo hay;
 *  - `control_number`: el numero de control, si se emitio;
 *  - `message`: el motivo del fallo, si lo hubo.
 */
uses(WithoutWrappingTransaction::class);

beforeEach(function () {
    $this->escenario = PosFixture::escenario();
    $this->usuario = PosFixture::usuario($this->escenario['store_id']);
});

afterEach(function () {
    DB::statement(
        'TRUNCATE users, currencies, countries, stores, products, customers, payment_methods, sales'
        .' RESTART IDENTITY CASCADE'
    );
});

/**
 * El pedido de una venta de dos unidades a 25, con su pago.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function pedidoConEstadoFiscal(array $escenario): array
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
 * 1.8 — la lista y el detalle ENSEINAN el estado fiscal y su numero de
 * control.
 *
 * REGLA que fija: *el estado fiscal se ve*. Una venta emitida dice que lo
 * esta, y dice su numero de control: sin eso, el comprobante es invisible.
 */
test('test_la_lista_y_el_detalle_de_ventas_ensenan_el_estado_fiscal_y_el_numero_de_control', function () {
    config(['fiscal.emission_enabled' => true]);

    $cobro = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedidoConEstadoFiscal($this->escenario));

    $cobro->assertCreated()->assertJsonPath('fiscal.status', 'issued');

    $saleId = (int) $cobro->json('sale_id');
    $control = (string) $cobro->json('fiscal.control_number');

    // 1. La LISTA.
    $this->actingAs($this->usuario)
        ->get('/ventas')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/index')
            ->where('sales.0.id', $saleId)
            ->where('sales.0.fiscal.status', 'issued')
            ->where('sales.0.fiscal.control_number', $control)
            ->where('sales.0.fiscal.document_id', (int) $cobro->json('fiscal.document_id'))
            ->where('sales.0.fiscal.message', null)
            ->etc());

    // 2. Y el DETALLE.
    $this->actingAs($this->usuario)
        ->get('/ventas/'.$saleId)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/show')
            ->where('sale.id', $saleId)
            ->where('sale.fiscal.status', 'issued')
            ->where('sale.fiscal.control_number', $control)
            ->where('sale.fiscal.message', null)
            ->etc());
});

/**
 * 1.8 — el detalle OFRECE el enlace al comprobante y el reintento cuando
 * corresponde.
 *
 * REGLA que fija: *el comprobante se puede abrir y la emision se puede
 * reintentar*. Una venta emitida ofrece su comprobante; una venta fallida
 * ofrece el reintento.
 */
test('test_el_detalle_ofrece_el_enlace_al_comprobante_y_el_reintento', function () {
    config(['fiscal.emission_enabled' => true]);

    $cobro = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedidoConEstadoFiscal($this->escenario));

    $saleId = (int) $cobro->json('sale_id');
    $documentId = (int) $cobro->json('fiscal.document_id');

    // 1. Una venta EMITIDA: el enlace al comprobante, y no hay reintento
    //    (una venta emitida no se vuelve a emitir).
    $this->actingAs($this->usuario)
        ->get('/ventas/'.$saleId)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sale.fiscal.receipt_url', '/comprobantes/'.$documentId)
            ->where('sale.fiscal.can_retry', false)
            ->etc());
});

/**
 * 1.8 — un fallo fiscal NO pierde la venta, y la pantalla explica por que.
 *
 * REGLA que fija: *un fallo fiscal NO puede hacer perder una venta*. La
 * venta queda en `fiscal_failed`, la pantalla dice el motivo, y el boton de
 * reintentar aparece.
 */
test('test_un_fallo_fiscal_no_pierde_la_venta_y_la_pantalla_explica_por_que', function () {
    config(['fiscal.emission_enabled' => true]);

    // El perfil pierde el codigo nativo del estado emitido: la emision
    // falla al consolidar.
    DB::table('fiscal_status_vocabularies')
        ->where('fiscal_profile_id', $this->escenario['profile_id'])
        ->where('generic_status', 'issued')
        ->delete();

    $cobro = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedidoConEstadoFiscal($this->escenario));

    $cobro->assertCreated()->assertJsonPath('fiscal.status', 'failed');

    $saleId = (int) $cobro->json('sale_id');

    // 1. La venta EXISTE: el cobro no se pierde.
    expect(DB::table('sales')->where('id', $saleId)->exists())->toBeTrue();

    // 2. Y la pantalla dice que fallo, POR QUE, y que se puede reintentar.
    $this->actingAs($this->usuario)
        ->get('/ventas/'.$saleId)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sale.fiscal.status', 'failed')
            ->where('sale.fiscal.can_retry', true)
            ->where('sale.fiscal.message', fn (?string $mensaje): bool => $mensaje !== null && $mensaje !== '')
            ->etc());
});

/**
 * 1.8 — el reintento FUNCIONA: emite el comprobante y devuelve la venta a su
 * estado de pago.
 *
 * REGLA que fija: *el reintento reutiliza la reserva y no quema numeros*. El
 * comprobante sale con el MISMO numero, y la venta vuelve a `paid`.
 */
test('test_el_reintento_emite_el_comprobante_y_devuelve_la_venta_a_su_estado_de_pago', function () {
    config(['fiscal.emission_enabled' => true]);

    DB::table('fiscal_status_vocabularies')
        ->where('fiscal_profile_id', $this->escenario['profile_id'])
        ->where('generic_status', 'issued')
        ->delete();

    $cobro = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedidoConEstadoFiscal($this->escenario));

    $saleId = (int) $cobro->json('sale_id');
    $documentId = (int) $cobro->json('fiscal.document_id');

    // 1. El canal vuelve a estar en pie.
    DB::table('fiscal_status_vocabularies')->insert([
        'fiscal_profile_id' => $this->escenario['profile_id'],
        'native_code' => 'issued',
        'native_label' => 'Emitido (estado generico)',
        'generic_status' => 'issued',
        'is_final' => false,
        'is_cancellation' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. El reintento.
    $reintento = $this->actingAs($this->usuario)
        ->postJson('/ventas/'.$saleId.'/emision');

    $reintento->assertOk()
        ->assertJsonPath('fiscal.status', 'issued')
        ->assertJsonPath('fiscal.document_id', $documentId);

    // 3. Y la venta vuelve a su estado de PAGO, recalculado desde los pagos.
    expect(DB::table('sales')->where('id', $saleId)->value('status'))->toBe('paid');

    // 4. Y NO se quemo un numero nuevo: el documento es el MISMO.
    expect(DB::table('fiscal_documents')->count())->toBe(1)
        ->and((int) DB::table('fiscal_documents')->where('id', $documentId)->value('number'))->toBe(1);
});

/**
 * 1.8 — un reintento que FALLA no pierde la venta.
 *
 * REGLA que fija: *el fallo del reintento no hace perder la venta*. El
 * reintento responde 200 con el estado fiscal, la venta sigue existiendo y
 * el motivo vuelve a estar a la vista.
 */
test('test_un_reintento_que_falla_no_pierde_la_venta', function () {
    config(['fiscal.emission_enabled' => true]);

    DB::table('fiscal_status_vocabularies')
        ->where('fiscal_profile_id', $this->escenario['profile_id'])
        ->where('generic_status', 'issued')
        ->delete();

    $cobro = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedidoConEstadoFiscal($this->escenario));

    $saleId = (int) $cobro->json('sale_id');

    // 1. El reintento falla: el canal sigue caido.
    $reintento = $this->actingAs($this->usuario)
        ->postJson('/ventas/'.$saleId.'/emision');

    $reintento->assertOk()
        ->assertJsonPath('fiscal.status', 'failed')
        ->assertJsonPath('fiscal.message', fn (?string $mensaje): bool => $mensaje !== null && $mensaje !== '');

    // 2. Y la venta SIGUE existiendo, con su cabecera y su pago.
    expect(DB::table('sales')->where('id', $saleId)->exists())->toBeTrue()
        ->and(DB::table('sale_payments')->where('sale_id', $saleId)->count())->toBe(1);
});
