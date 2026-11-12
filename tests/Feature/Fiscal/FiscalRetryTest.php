<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * 1.7 — el REINTENTO de la emision fiscal.
 *
 * Reglas que fija:
 *
 *  - el comando `ops:emit-fiscal` reintenta la emision de las ventas en
 *    `fiscal_failed`: una venta concreta o todas las pendientes;
 *  - el reintento REUTILIZA la reserva: no quema numeros nuevos, no crea un
 *    segundo documento y no duplica el desglose de impuestos;
 *  - una venta ya EMITIDA no se reemite: el numero de control PUBLICADO no
 *    se devuelve ni se reasigna, y el proveedor no se vuelve a llamar.
 *
 * El escenario es el de DEMOSTRACION del repo (`PosFixture`), con el
 * interruptor de emision ENCENDIDO.
 */
beforeEach(function () {
    $this->escenario = PosFixture::escenario();
    $this->usuario = PosFixture::usuario($this->escenario['store_id']);

    config(['fiscal.emission_enabled' => true]);
});

/**
 * El pedido de una venta de dos unidades a 25, con su pago.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function pedido_reintento(array $escenario): array
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
 * El perfil PERDIÓ el codigo nativo del estado emitido: la emision falla al
 * consolidar y la venta queda en `fiscal_failed`.
 *
 * El fallo se provoca en el VOCABULARIO del perfil, y no en la serie de
 * control: agotar el rango de control ya no es un fallo de emision (el
 * numero lo toma la venta y el documento lo reutiliza), y un fallo que no
 * existe no prueba nada.
 *
 * @param  array<string, int>  $escenario
 * @return int El identificador de la venta.
 */
function venta_con_emision_fallida(array $escenario, $usuario): int
{
    DB::table('fiscal_status_vocabularies')
        ->where('fiscal_profile_id', $escenario['profile_id'])
        ->where('generic_status', 'issued')
        ->delete();

    $respuesta = test()->actingAs($usuario)->postJson('/caja/cobrar', pedido_reintento($escenario));

    $respuesta->assertCreated();

    return (int) $respuesta->json('sale_id');
}

/**
 * El perfil RECUPERA el codigo nativo del estado emitido: la emision ya
 * puede consolidar.
 *
 * @param  array<string, int>  $escenario
 */
function reintento_perfil_con_estado_emitido(array $escenario): void
{
    DB::table('fiscal_status_vocabularies')->insert([
        'fiscal_profile_id' => $escenario['profile_id'],
        'native_code' => 'issued',
        'native_label' => 'Emitido (estado generico de la prueba)',
        'generic_status' => 'issued',
        'is_final' => false,
        'is_cancellation' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * 1.7 — el comando reintenta una venta en `fiscal_failed` y la emite.
 *
 * REGLA que fija: *una venta con el comprobante pendiente se puede
 * reintentar*. El comando la emite y dice que la emitio.
 */
test('test_el_comando_reintenta_una_venta_en_fiscal_failed_y_la_emite', function () {
    $saleId = venta_con_emision_fallida($this->escenario, $this->usuario);

    // 1. La venta esta pendiente: el comprobante no salio.
    expect(DB::table('sales')->where('id', $saleId)->value('status'))->toBe('fiscal_failed')
        ->and(DB::table('fiscal_documents')->where('id', (int) DB::table('sales')
            ->where('id', $saleId)->value('fiscal_document_id'))->value('status'))->toBe('draft');

    // 2. El perfil recupera su vocabulario: el canal vuelve a poder
    //    consolidar.
    reintento_perfil_con_estado_emitido($this->escenario);

    // 3. El comando reintenta ESA venta.
    $codigo = Artisan::call('ops:emit-fiscal', ['--sale' => $saleId]);

    $salida = Artisan::output();

    expect($codigo)->toBe(0)
        ->and($salida)->toContain((string) $saleId);

    // 4. La venta queda EMITIDA, con su documento y su control.
    $documentId = (int) DB::table('sales')->where('id', $saleId)->value('fiscal_document_id');

    expect(DB::table('fiscal_documents')->where('id', $documentId)->value('status'))->toBe('issued')
        ->and(DB::table('fiscal_document_control_assignments')->where('fiscal_document_id', $documentId)->count())
        ->toBe(1);

    // 5. Y la venta vuelve a su estado de PAGO, RECALCULADO desde los pagos:
    //    `fiscal_failed` habia pisado el estado anterior.
    expect(DB::table('sales')->where('id', $saleId)->value('status'))->toBe('paid');
});

/**
 * 1.7 — el reintento NO quema numeros.
 *
 * REGLA que fija: *el reintento reutiliza la reserva*. El numero del
 * documento ya estaba reservado, y un numero reservado no se vuelve a
 * repartir: el correlativo de la serie NO avanza. Y el CONTROL tampoco: la
 * venta ya tiene el suyo, y el documento lo reutiliza en vez de reservar
 * otro.
 */
test('test_el_reintento_no_quema_numeros', function () {
    $saleId = venta_con_emision_fallida($this->escenario, $this->usuario);

    // 1. La serie del documento queda en 2: el numero 1 esta reservado.
    expect((int) DB::table('fiscal_series')
        ->where('id', $this->escenario['document_series_id'])->value('next_number'))->toBe(2);

    // Y la del CONTROL tambien: la venta ya tiene el suyo reservado.
    $controlAntes = (int) DB::table('fiscal_series')
        ->where('id', $this->escenario['control_series_id'])
        ->value('next_number');

    expect($controlAntes)->toBe(2);

    reintento_perfil_con_estado_emitido($this->escenario);

    Artisan::call('ops:emit-fiscal', ['--sale' => $saleId]);

    // 2. La serie del DOCUMENTO NO avanzo: el reintento no reservo nada nuevo.
    expect((int) DB::table('fiscal_series')
        ->where('id', $this->escenario['document_series_id'])->value('next_number'))->toBe(2);

    // 3. Y sigue habiendo UNA sola reserva del documento, la MISMA.
    expect(DB::table('fiscal_document_reservations')
        ->where('series_id', $this->escenario['document_series_id'])
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->count())->toBe(1);

    // 4. Y la serie del CONTROL tampoco avanzo: el reintento REUTILIZO la
    //    reserva de la venta, en vez de asignar un control nuevo.
    expect((int) DB::table('fiscal_series')
        ->where('id', $this->escenario['control_series_id'])->value('next_number'))
        ->toBe($controlAntes)
        ->and(DB::table('fiscal_document_reservations')
            ->where('series_id', $this->escenario['control_series_id'])
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->count())->toBe(1);
});

/**
 * 1.7 — una venta ya emitida NO se reemite.
 *
 * REGLA que fija: *una doble emision no se puede deshacer*. Si el documento
 * ya esta `issued`, el reintento NO vuelve a llamar al proveedor: el control
 * PUBLICADO se conserva, no se devuelve ni se reasigna, y no se escribe un
 * segundo evento de emision.
 */
test('test_una_venta_ya_emitida_no_se_reemite_y_conserva_su_control_publicado', function () {
    // 1. Una venta emitida de verdad.
    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', pedido_reintento($this->escenario));

    $respuesta->assertCreated()->assertJsonPath('fiscal.status', 'issued');

    $saleId = (int) $respuesta->json('sale_id');
    $documentId = (int) DB::table('sales')->where('id', $saleId)->value('fiscal_document_id');

    $controlPublicado = DB::table('fiscal_document_control_assignments')
        ->where('fiscal_document_id', $documentId)
        ->value('control_number');

    $eventos = DB::table('fiscal_document_events')->where('fiscal_document_id', $documentId)->count();
    $controlAntes = (int) DB::table('fiscal_series')
        ->where('id', $this->escenario['control_series_id'])
        ->value('next_number');

    // 2. La venta queda en `fiscal_failed`: el que fallo fue una escritura
    //    POSTERIOR a la emision, no la emision.
    DB::table('sales')->where('id', $saleId)->update([
        'status' => 'fiscal_failed',
        'updated_at' => now(),
    ]);

    // 3. El reintento NO la reemite.
    Artisan::call('ops:emit-fiscal', ['--sale' => $saleId]);

    // 4. El control PUBLICADO se conserva: ni se devuelve ni se reasigna, y
    //    sigue habiendo UNA sola asignacion.
    expect(DB::table('fiscal_document_control_assignments')->where('fiscal_document_id', $documentId)->count())
        ->toBe(1)
        ->and(DB::table('fiscal_document_control_assignments')
            ->where('fiscal_document_id', $documentId)->value('control_number'))
        ->toBe($controlPublicado);

    // 5. Y el proveedor NO se volvio a llamar: no se escribio un SEGUNDO
    //    evento de emision, y la serie del control no avanzo.
    expect(DB::table('fiscal_document_events')->where('fiscal_document_id', $documentId)->count())
        ->toBe($eventos)
        ->and((int) DB::table('fiscal_series')
            ->where('id', $this->escenario['control_series_id'])->value('next_number'))
        ->toBe($controlAntes);

    // 6. Y la venta queda RECONCILIADA: el estado de pago se recalcula.
    expect(DB::table('sales')->where('id', $saleId)->value('status'))->toBe('paid');
});

/**
 * 1.7 — el comando reintenta TODAS las ventas pendientes.
 *
 * REGLA que fija: *las ventas pendientes se reintentan en lote*. El operador
 * no tiene que saber cuales son: el comando las busca.
 */
test('test_el_comando_reintenta_todas_las_ventas_pendientes', function () {
    $saleId = venta_con_emision_fallida($this->escenario, $this->usuario);

    reintento_perfil_con_estado_emitido($this->escenario);

    // 1. Sin `--sale`, el comando reintenta las pendientes.
    $codigo = Artisan::call('ops:emit-fiscal');

    expect($codigo)->toBe(0)
        ->and(Artisan::output())->toContain((string) $saleId);

    // 2. Y la venta queda emitida.
    $documentId = (int) DB::table('sales')->where('id', $saleId)->value('fiscal_document_id');

    expect(DB::table('fiscal_documents')->where('id', $documentId)->value('status'))->toBe('issued');
});
