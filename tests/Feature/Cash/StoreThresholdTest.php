<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * Los UMBRALES POR TIENDA: el descuadre de la caja y el documento del
 * consumidor final.
 *
 * Reglas que fija:
 *
 *  - el UMBRAL DEL DESCUADRE de la tienda MANDA sobre el global: una tienda
 *    con dos mil ventas al dia no justifica cada diferencia de centimos, y una
 *    tienda de barrio si. Y si la tienda NO declara ninguno, aplica el
 *    GLOBAL (`config/cash.php`), que es el comportamiento de siempre;
 *  - una venta SIN CLIENTE que supera el UMBRAL DE LA TIENDA no se cobra sin el
 *    documento del consumidor final, y el mensaje dice QUE umbral se ha
 *    superado;
 *  - por DEBAJO del umbral el documento es OPCIONAL, y con CLIENTE no se pide
 *    (el cliente ya es el receptor del comprobante);
 *  - el documento del consumidor final LLEGA al comprobante fiscal como el
 *    identificativo del receptor.
 *
 * Y EL TIPO DEL DOCUMENTO LO DECLARA QUIEN COBRA: el sistema no lo deduce del
 * formato, porque deducirlo seria adivinar.
 */

/**
 * Fija un umbral en la ficha de una tienda.
 *
 * @param  string  $columna  La columna del umbral.
 */
function umbral_de_la_tienda(int $storeId, string $columna, ?string $valor): void
{
    DB::table('store_settings')->updateOrInsert(
        ['store_id' => $storeId],
        [$columna => $valor, 'updated_at' => now()]
    );
}

/**
 * El pedido de una venta de dos unidades a 25, con su pago.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function pedido_con_documento(array $escenario, array $extra = []): array
{
    return array_merge([
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
    ], $extra);
}

/**
 * El UMBRAL DEL DESCUADRE de la tienda MANDA sobre el global.
 *
 * REGLA que fija este test: *el umbral de la tienda manda*. Con el global en
 * 5, un descuadre de 3 no pide motivo; con el de la tienda en 1, el MISMO
 * descuadre lo pide: el que cuenta es el de la tienda.
 */
test('test_el_umbral_de_descuadre_de_la_tienda_manda_sobre_el_global', function () {
    config(['cash.difference_reason_threshold' => '5.0000']);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    // La tienda declara un umbral MAS ESTRECHO que el global.
    umbral_de_la_tienda((int) $escenario['store_id'], 'difference_reason_threshold', '1.0000');

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // El descuadre de 3 SUPERA el umbral de la tienda (1), aunque NO supere el
    // global (5): el motivo es obligatorio.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '97.0000'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    expect($this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '97.0000'])
        ->json('errorMsg'))->toContain('supera el umbral de 1');

    // Y con el motivo, el cierre sale: la caja no se queda abierta.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '97.0000', 'reason' => 'Falta un billete'])
        ->assertCreated()
        ->assertJsonPath('difference', '-3.0000');
});

/**
 * SIN umbral de la tienda, se usa el GLOBAL.
 *
 * REGLA que fija este test: *la precedencia es la tienda y, si no hay, el
 * global*. El global sigue mandando en una tienda que no declara ninguno.
 */
test('test_sin_umbral_de_tienda_se_usa_el_global', function () {
    config(['cash.difference_reason_threshold' => '5.0000']);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    // La tienda NO declara umbral: el de la ficha es `null`.
    umbral_de_la_tienda((int) $escenario['store_id'], 'difference_reason_threshold', null);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // Un descuadre de 3 esta POR DEBAJO del global (5): no hay motivo.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '97.0000'])
        ->assertCreated()
        ->assertJsonPath('difference', '-3.0000');

    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('note'))->toBe('');
});

/**
 * Una venta SIN CLIENTE que supera el umbral NO se cobra sin el documento.
 *
 * REGLA que fija este test: *una venta sin cliente que supera el umbral de la
 * tienda no se cobra sin el documento del consumidor final*, y el mensaje dice
 * QUE umbral se ha superado.
 */
test('test_una_venta_sin_cliente_que_supera_el_umbral_sin_documento_falla_con_422', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // La tienda exige el documento a partir de 50: la venta de 58 lo supera.
    umbral_de_la_tienda((int) $escenario['store_id'], 'final_consumer_id_threshold', '50.0000');

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_con_documento($escenario));

    $respuesta->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // El mensaje dice QUE umbral se ha superado.
    expect($respuesta->json('errorMsg'))->toContain('supera el umbral de 50')
        ->and($respuesta->json('errorMsg'))->toContain('documento del consumidor final');

    // Y NO se cobro nada: la venta no existe.
    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * Una venta que NO supera el umbral NO exige documento.
 *
 * REGLA que fija este test: *por debajo del umbral el documento es
 * opcional*. Y si viene, se escribe igual.
 */
test('test_una_venta_que_no_supera_el_umbral_no_exige_documento', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // La tienda exige el documento a partir de 100: la venta de 58 no llega.
    umbral_de_la_tienda((int) $escenario['store_id'], 'final_consumer_id_threshold', '100.0000');

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_con_documento($escenario))
        ->assertCreated()
        ->assertJsonPath('status', 'paid');
});

/**
 * Una venta CON CLIENTE no exige el documento del consumidor final.
 *
 * REGLA que fija este test: *con cliente, el receptor ya es el cliente*. El
 * documento del consumidor final no se pide, por muy alta que sea la venta.
 */
test('test_una_venta_con_cliente_no_exige_documento', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // La tienda exige el documento a partir de 50: la venta de 58 lo supera.
    umbral_de_la_tienda((int) $escenario['store_id'], 'final_consumer_id_threshold', '50.0000');

    $cliente = PermissionFixture::cliente((int) $escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_con_documento($escenario, ['customer_id' => (int) $cliente->id]))
        ->assertCreated()
        ->assertJsonPath('status', 'paid');
});

/**
 * El documento del consumidor final LLEGA al comprobante fiscal.
 *
 * REGLA que fija este test: *el identificativo del consumidor final es el del
 * receptor del comprobante*, con el tipo que declara quien cobra.
 */
test('test_el_documento_del_consumidor_final_llega_al_comprobante_fiscal', function () {
    config(['fiscal.emission_enabled' => true]);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    // La tienda exige el documento a partir de 50: la venta de 58 lo supera.
    umbral_de_la_tienda((int) $escenario['store_id'], 'final_consumer_id_threshold', '50.0000');

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', pedido_con_documento($escenario, [
            'final_consumer' => [
                'document' => 'V-12345678',
                'kind' => 'cedula',
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('status', 'paid');

    $saleId = (int) DB::table('sales')->orderBy('id', 'desc')->value('id');

    $documentId = (int) DB::table('sales')->where('id', $saleId)->value('fiscal_document_id');

    expect($documentId)->not->toBe(0);

    // El RECEPTOR del comprobante es el consumidor final, con su documento.
    $receptor = DB::table('fiscal_document_parties')
        ->where('fiscal_document_id', $documentId)
        ->where('role', 'receiver')
        ->first();

    expect($receptor)->not->toBeNull()
        ->and((string) $receptor->fiscal_id)->toBe('V-12345678')
        ->and((string) $receptor->fiscal_id_kind)->toBe('cedula');

    // Y la cabecera de la venta lo guarda: el reintento de la emision lo
    // tiene que seguir encontrando.
    expect((string) DB::table('sales')->where('id', $saleId)->value('final_consumer_document'))->toBe('V-12345678')
        ->and((string) DB::table('sales')->where('id', $saleId)->value('final_consumer_document_kind'))->toBe('cedula');
});

/**
 * La PANTALLA de la configuracion de la tienda: los dos umbrales se EDITAN.
 *
 * REGLA que fija este test: *una columna que nadie puede cambiar es un dato
 * que no sirve*. Los umbrales de la tienda se cambian desde la pantalla, y lo
 * que se guarda es lo que la tienda declara.
 */
test('test_la_pantalla_de_la_tienda_guarda_los_dos_umbrales', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuario($escenario['store_id']);

    $this->actingAs($usuario)
        ->putJson('/settings/store', [
            'difference_reason_threshold' => '0.5000',
            'final_consumer_id_threshold' => '75.0000',
        ])
        ->assertRedirect();

    $guardado = DB::table('store_settings')->where('store_id', $escenario['store_id'])->first();

    expect((string) $guardado->difference_reason_threshold)->toBe('0.5000')
        ->and((string) $guardado->final_consumer_id_threshold)->toBe('75.0000');
});
