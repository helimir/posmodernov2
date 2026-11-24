<?php

use App\Domain\Accounting\CashDay;
use App\Domain\Fiscal\NumberSeries;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * F3-03 — la ANULACION de una venta: la nota de credito que la deja sin
 * efecto.
 *
 * Reglas que fija:
 *
 *  - la anulacion EXIGE un MOTIVO: sin motivo no se anula, porque una venta
 *    anulada sin motivo es una venta que nadie sabe por que se toco;
 *  - una venta con comprobante EMITIDO no se "desemite": ante la autoridad
 *    eso no existe. La via es la NOTA DE CREDITO, un documento DERIVADO con
 *    su propia numeracion en la misma serie, que referencia el original
 *    (numero, fecha, control y monto);
 *  - una venta SIN comprobante emitido se anula SIN nota: no hay nada que
 *    anular ante la autoridad, y no se quema ningun numero;
 *  - la numeracion NO se renumera: la nota consume SU correlativo y el hueco
 *    del original queda consumed para siempre;
 *  - un fallo NO deja la venta anulada a medias: si la nota no sale, la
 *    venta sigue como estaba;
 *  - la anulacion NO repone el stock: la devolucion de la mercaderia es
 *    otra unidad;
 *  - una venta anulada SIGUE SIENDO CAJA: el dinero entro, y el dia no se
 *    toca.
 */

/**
 * El ESCENARIO de la anulacion: la tienda, su usuario con el permiso y una
 * venta EMITIDA (o sin emitir, si se apaga el interruptor).
 *
 * @param  array<string, mixed>  $opciones
 * @return array<string, mixed>
 */
function escenario_de_anulacion(array $opciones = []): array
{
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $permisos = $opciones['permisos'] ?? ['sales.void', 'sales.create', 'sales.read', 'sales.fiscal_retry'];

    $rol = PermissionFixture::rol('anulador-'.uniqid(), $permisos);

    $usuario = PermissionFixture::usuarioConCaja($rol, $escenario['store_id']);

    // El interruptor fiscal decide si la venta sale CON comprobante o sin el.
    config(['fiscal.emission_enabled' => $opciones['emision'] ?? true]);

    $pedido = [
        'lines' => [
            [
                'product_id' => $escenario['product_id'],
                'quantity' => '2',
                'unit_price' => '25.0000',
            ],
        ],
        'payments' => [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '58.0000'],
        ],
    ];

    if (isset($opciones['customer_id'])) {
        $pedido['customer_id'] = $opciones['customer_id'];
    }

    // El CLIENTE de la venta: con ficha en la tienda, porque su credito vive
    // ahi. Lo crea el helper, y no el test: el escenario de la caja se
    // siembra UNA vez (sus productos tienen codigo unico).
    $clienteId = null;

    if ($opciones['con_cliente'] ?? false) {
        $clienteId = CreditFixture::customer($escenario['store_id']);

        $pedido['customer_id'] = $clienteId;
    }

    if (isset($opciones['importe'])) {
        $pedido['payments'][0]['amount'] = $opciones['importe'];
    }

    return [
        'escenario' => $escenario,
        'usuario' => $usuario,
        'pedido' => $pedido,
        'customer_id' => $clienteId,
    ];
}

/**
 * F3-03 — la anulacion EXIGE un MOTIVO.
 *
 * REGLA que fija: *una venta anulada sin motivo es una venta que nadie sabe
 * por que se toco*. Sin motivo no se anula: el 422 lo dice y la venta queda
 * como estaba.
 */
test('test_una_venta_anulada_exige_un_motivo_y_sin_motivo_no_se_anula', function () {
    $contexto = escenario_de_anulacion();

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', $contexto['pedido']);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // 1. SIN MOTIVO: un 422 con el `errorMsg` del contrato.
    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 2. Y CON UN MOTIVO EN BLANCO, tambien: el motivo se exige de verdad.
    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => '   '])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 3. La venta NO se toco: sigue pagada, sin nota y sin evento de
    //    anulacion.
    $venta = DB::table('sales')->where('id', $saleId)->first();

    expect((string) $venta->status)->toBe('paid')
        ->and(DB::table('fiscal_documents')->where('document_type', 'credit_note')->count())->toBe(0)
        ->and(DB::table('fiscal_document_events')->where('action', 'cancel')->count())->toBe(0);
});

/**
 * F3-03 — una venta EMITIDA se anula con una NOTA DE CREDITO.
 *
 * REGLA que fija: *una venta con comprobante emitido no se desemite: la via
 * es la nota de credito*. La nota es un documento DERIVADO, con su propia
 * numeracion en la MISMA serie, que referencia el original (numero, fecha,
 * control y monto). Y el original queda ANULADO, con su numero consumido
 * para siempre.
 */
test('test_una_venta_con_comprobante_emitido_se_anula_con_una_nota_de_credito', function () {
    $contexto = escenario_de_anulacion();

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', $contexto['pedido']);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // El comprobante EMITIDO de la venta.
    $original = DB::table('fiscal_documents')
        ->where('id', DB::table('sales')->where('id', $saleId)->value('fiscal_document_id'))
        ->first();

    expect($original)->not->toBeNull()
        ->and((string) $original->status)->toBe('issued')
        ->and((string) $original->document_type)->toBe('invoice');

    $numeroDelOriginal = (int) $original->number;

    // 1. La ANULACION responde 200 con el estado de la venta y su nota.
    $respuesta = $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado']);

    $respuesta->assertOk()
        ->assertJsonPath('sale_id', $saleId)
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('reason', 'Error en el monto facturado')
        ->assertJsonStructure(['note' => ['id', 'number', 'issue_date']]);

    // 2. La VENTA queda anulada.
    expect((string) DB::table('sales')->where('id', $saleId)->value('status'))->toBe('cancelled');

    // 3. El ORIGINAL queda anulado: su numero se consume y no se renumera.
    $anulado = DB::table('fiscal_documents')->where('id', (int) $original->id)->first();

    expect((string) $anulado->status)->toBe('void')
        ->and($anulado->voided_at)->not->toBeNull();

    $evento = DB::table('fiscal_document_events')
        ->where('fiscal_document_id', (int) $original->id)
        ->where('action', 'cancel')
        ->first();

    expect($evento)->not->toBeNull()
        ->and(json_decode((string) $evento->payload, true)['reason'])->toBe('Error en el monto facturado');

    // 4. LA NOTA DE CREDITO: un documento DERIVADO, con el correlativo
    //    SIGUIENTE de la MISMA serie, que referencia al original.
    $nota = DB::table('fiscal_documents')->where('document_type', 'credit_note')->first();

    expect($nota)->not->toBeNull()
        ->and((string) $nota->status)->toBe('issued')
        ->and((int) $nota->series_id)->toBe((int) $original->series_id)
        ->and((int) $nota->number)->toBe($numeroDelOriginal + 1)
        ->and((string) $nota->total_amount)->toBe((string) $original->total_amount);

    $enlace = DB::table('fiscal_document_links')->where('fiscal_document_id', (int) $nota->id)->first();

    expect((string) $enlace->link_type)->toBe('corrects')
        ->and((int) $enlace->parent_document_id)->toBe((int) $original->id)
        ->and((string) $enlace->parent_reference)->toBe((string) $original->number);

    // Y la referencia COMPLETA del original: numero, fecha, control y monto.
    $carga = json_decode(
        (string) DB::table('fiscal_document_events')
            ->where('fiscal_document_id', (int) $nota->id)
            ->where('action', 'issue')
            ->value('payload'),
        true
    );

    expect($carga['source_document_id'])->toBe((int) $original->id)
        ->and($carga['source_number'])->toBe((string) $original->number)
        ->and($carga['source_date'])->toBe((string) $original->issue_date)
        ->and($carga['source_total'])->toBe((string) $original->total_amount);

    // 5. Y LA NUMERACION NO SE RENUMERA: el original consume su numero y la
    //    nota el suyo. La siguiente reserva devuelve el SIGUIENTE.
    $serieId = (int) $original->series_id;

    expect((int) DB::table('fiscal_series')->where('id', $serieId)->value('next_number'))
        ->toBe($numeroDelOriginal + 2)
        ->and((new NumberSeries(DB::connection()))->reserve($serieId))->toBe($numeroDelOriginal + 2);
});

/**
 * F3-03 — una venta SIN comprobante emitido se anula SIN nota.
 *
 * REGLA que fija: *sin comprobante emitido no hay nada que anular ante la
 * autoridad*. La venta se anula, pero no se emite ninguna nota y no se quema
 * ningun numero de la serie.
 */
test('test_una_venta_sin_comprobante_emitido_se_anula_sin_nota_y_sin_quemar_numeros', function () {
    $contexto = escenario_de_anulacion(['emision' => false, 'con_cliente' => true]);

    $clienteId = (int) $contexto['customer_id'];

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', $contexto['pedido']);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    expect(DB::table('sales')->where('id', $saleId)->value('fiscal_document_id'))->toBeNull();

    $serieId = (int) $contexto['escenario']['document_series_id'];

    $numerosAntes = (int) DB::table('fiscal_series')->where('id', $serieId)->value('next_number');

    $respuesta = $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Se cobro de mas']);

    $respuesta->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('note', null);

    // 1. La venta queda anulada.
    expect((string) DB::table('sales')->where('id', $saleId)->value('status'))->toBe('cancelled');

    // 2. Y NO hay nota: no habia nada emitido que anular ante la autoridad.
    expect(DB::table('fiscal_documents')->where('document_type', 'credit_note')->count())->toBe(0);

    // 3. Y la serie NO avanza: una anulacion sin nota no quema numeros.
    expect((int) DB::table('fiscal_series')->where('id', $serieId)->value('next_number'))->toBe($numerosAntes);

    // 4. Y EL LIBRO DEL CLIENTE se compensa IGUAL: la deuda de una venta
    //    anulada desaparece con nota o sin ella, porque el cliente no debe
    //    una venta que ya no esta. El cliente pago 58 por una venta que no
    //    esta: le quedan 58 a favor.
    $movimientos = DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->orderBy('id')
        ->get();

    expect($movimientos)->toHaveCount(3)
        ->and((string) $movimientos[0]->type)->toBe('sale')
        ->and((string) $movimientos[1]->type)->toBe('payment')
        ->and((string) $movimientos[2]->type)->toBe('return')
        ->and((string) $movimientos[2]->amount)->toBe('-58.0000');

    $saldo = DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $contexto['escenario']['store_id'])
        ->first();

    expect((string) $saldo->balance)->toBe('-58.0000')
        ->and((string) $saldo->due)->toBe('0.0000');
});

/**
 * F3-03 — la NOTA DE CREDITO resta el total del saldo del cliente.
 *
 * REGLA que fija: *el libro del cliente es un mini-libro, y la nota de
 * credito RESTA*. La venta sumo el total y el cobro resto lo aplicado; la
 * nota de credito resta el TOTAL de la venta, y el cliente que habia
 * pagado queda con un CREDITO A FAVOR (lo que se le devuelve).
 */
test('test_la_nota_de_credito_resta_el_total_del_saldo_del_cliente', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('anulador-'.uniqid(), ['sales.void', 'sales.create', 'sales.read']);

    $usuario = PermissionFixture::usuarioConCaja($rol, $escenario['store_id']);

    $clienteId = CreditFixture::customer($escenario['store_id']);

    config(['fiscal.emission_enabled' => true]);

    // Una venta de 58 con un cliente que solo paga 30: debe 28.
    $cobro = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'customer_id' => $clienteId,
            'lines' => [
                ['product_id' => $escenario['product_id'], 'quantity' => '2', 'unit_price' => '25.0000'],
            ],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '30.0000'],
            ],
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // 1. ANTES: la venta sumo 58 y el cobro resto 30: el cliente debe 28.
    $movimientos = DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->orderBy('id')
        ->get();

    expect($movimientos)->toHaveCount(2)
        ->and((string) $movimientos[0]->type)->toBe('sale')
        ->and((string) $movimientos[0]->amount)->toBe('58.0000')
        ->and((string) $movimientos[1]->type)->toBe('payment')
        ->and((string) $movimientos[1]->amount)->toBe('-30.0000');

    $saldoAntes = DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $escenario['store_id'])
        ->value('balance');

    expect((string) $saldoAntes)->toBe('28.0000');

    // 2. La ANULACION.
    $this->actingAs($usuario)
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'La nota de credito del cliente'])
        ->assertOk();

    // 3. DESPUES: un movimiento de tipo `return` con el NEGATIVO del total.
    $reintegro = DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->where('type', 'return')
        ->first();

    expect($reintegro)->not->toBeNull()
        ->and((string) $reintegro->amount)->toBe('-58.0000')
        ->and((int) $reintegro->ref_invoice_id)->toBe($saleId);

    // 4. Y el saldo derivado: 58 - 30 - 58 = -30. Un CREDITO A FAVOR de 30
    //    (el cliente pago 30 por una venta que ya no esta).
    $saldoDespues = DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $escenario['store_id'])
        ->first();

    expect((string) $saldoDespues->balance)->toBe('-30.0000')
        ->and((string) $saldoDespues->due)->toBe('0.0000');
});

/**
 * F3-03 — un FALLO no deja la venta anulada a medias.
 *
 * REGLA que fija: *un fallo fiscal NO puede dejar una venta anulada a
 * medias*. Si la nota de credito no se puede numerar, la venta sigue como
 * estaba: ni anulada, ni sin comprobante, y el numero no quemado.
 */
test('test_un_fallo_en_la_anulacion_no_deja_la_venta_anulada_a_medias', function () {
    $contexto = escenario_de_anulacion();

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', $contexto['pedido']);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $original = DB::table('fiscal_documents')
        ->where('id', DB::table('sales')->where('id', $saleId)->value('fiscal_document_id'))
        ->first();

    $serieId = (int) $original->series_id;

    // El RANGO se agota: la siguiente reserva no tiene numero que dar.
    DB::table('fiscal_series')->where('id', $serieId)->update([
        'to_number' => (int) DB::table('fiscal_series')->where('id', $serieId)->value('next_number') - 1,
    ]);

    $respuesta = $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado']);

    $respuesta->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 1. La venta SIGUE COMO ESTABA: no esta anulada a medias.
    expect((string) DB::table('sales')->where('id', $saleId)->value('status'))->toBe('paid');

    // 2. El comprobante NO se anulo: la anulacion es una unidad atomica.
    expect((string) DB::table('fiscal_documents')->where('id', (int) $original->id)->value('status'))
        ->toBe('issued');

    // 3. Y no hay nota a medias, ni evento de anulacion.
    expect(DB::table('fiscal_documents')->where('document_type', 'credit_note')->count())->toBe(0)
        ->and(DB::table('fiscal_document_events')->where('action', 'cancel')->count())->toBe(0);

    // 4. Y el numero NO se quemo: la reserva de la nota esta dentro de la
    //    misma unidad atomica, y la unidad se revirtio entera.
    expect((int) DB::table('fiscal_series')->where('id', $serieId)->value('next_number'))
        ->toBe((int) $original->number + 1);
});

/**
 * F3-03 — la anulacion NO repone el stock.
 *
 * REGLA que fija: *la anulacion es FISCAL y CONTABLE, no fisica*. La
 * mercaderia no vuelve al almacen: la devolucion con la reposicion del
 * lote EXACTO es otra unidad. Un stock repuesto aqui seria mercaderia que
 * nadie ha devuelto.
 */
test('test_la_anulacion_no_repone_el_stock_de_la_venta', function () {
    $contexto = escenario_de_anulacion();

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', $contexto['pedido']);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // El lote, DESPUES de la venta: de diez unidades quedan ocho.
    $lote = DB::table('inventory_lots')
        ->where('product_id', $contexto['escenario']['product_id'])
        ->first();

    expect((string) $lote->available)->toBe('8.0000');

    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk();

    // 1. El lote SIGUE igual: la mercaderia no ha vuelto.
    $despues = DB::table('inventory_lots')
        ->where('product_id', $contexto['escenario']['product_id'])
        ->first();

    expect((string) $despues->available)->toBe('8.0000')
        ->and((string) $despues->quantity_returned)->toBe('0.0000');

    // 2. Y el cache del stock no se toca.
    expect((string) DB::table('product_stores')
        ->where('product_id', $contexto['escenario']['product_id'])
        ->where('store_id', $contexto['escenario']['store_id'])
        ->value('quantity_in_stock'))->toBe('8.0000');
});

/**
 * F3-03 — una venta anulada SIGUE SIENDO CAJA.
 *
 * REGLA que fija: *la anulacion no toca la caja del dia*. El dinero entro
 * en el cajon y sigue ahi: la venta anulada es caja, y el Z del dia no
 * cambia. Devolver el dinero es otra operacion.
 */
test('test_una_venta_anulada_sigue_siendo_caja_del_dia', function () {
    $contexto = escenario_de_anulacion();

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', $contexto['pedido']);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $storeId = (int) $contexto['escenario']['store_id'];

    // La FECHA DE NEGOCIO de la venta es la del dia que la caja registra.
    $fecha = (string) DB::table('sales')->where('id', $saleId)->value('sale_date');

    $dia = new CashDay(DB::connection());

    $antes = $dia->runningBalance(
        $storeId,
        new DateTimeImmutable($fecha),
        '1100000'
    );

    // La venta debita la caja: 58,0000.
    expect($antes)->toBe('58.0000');

    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk();

    // Y DESPUES de anular, la caja sigue con los mismos 58,0000: la venta
    // anulada sigue siendo caja.
    expect($dia->runningBalance(
        $storeId,
        new DateTimeImmutable($fecha),
        '1100000'
    ))->toBe('58.0000');
});

/**
 * F3-03 — la anulacion QUEDA AUDITADA.
 *
 * REGLA que fija: *toda accion queda auditada*. La anulacion deja quien la
 * hizo, sobre que venta, en que tienda y POR QUE: una venta anulada sin
 * rastro es una venta que nadie puede defender despues.
 */
test('test_la_anulacion_queda_auditada_con_quien_y_por_que', function () {
    $contexto = escenario_de_anulacion();

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', $contexto['pedido']);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk();

    $auditoria = DB::table('audit_logs')
        ->where('action', 'sale.voided')
        ->where('entity_type', 'sale')
        ->where('entity_id', $saleId)
        ->first();

    expect($auditoria)->not->toBeNull()
        ->and((int) $auditoria->user_id)->toBe((int) $contexto['usuario']->id)
        ->and((int) $auditoria->store_id)->toBe((int) $contexto['escenario']['store_id']);

    $contextoAuditado = json_decode((string) $auditoria->context, true);

    expect($contextoAuditado['reason'])->toBe('Error en el monto facturado');
});

/**
 * F3-03 — una venta anulada no se anula DOS VECES.
 *
 * REGLA que fija: *la anulacion es un HECHO, no un estado que se repite*.
 * Una segunda anulacion no emite una segunda nota de credito ni un segundo
 * reintegro en el libro del cliente: se rechaza.
 */
test('test_una_venta_ya_anulada_no_se_anula_dos_veces', function () {
    $contexto = escenario_de_anulacion();

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', $contexto['pedido']);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk();

    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'otra vez'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y solo hay UNA nota de credito.
    expect(DB::table('fiscal_documents')->where('document_type', 'credit_note')->count())->toBe(1);
});

/**
 * F3-03 — una venta anulada NO se reintenta.
 *
 * REGLA que fija: *la anulacion es un HECHO, y el comprobante de una venta
 * anulada esta anulado*. Reintentar su emision emitiria ante la autoridad un
 * comprobante que ya no esta en pie.
 */
test('test_una_venta_anulada_no_se_reintenta_la_emision_fiscal', function () {
    $contexto = escenario_de_anulacion();

    $cobro = $this->actingAs($contexto['usuario'])
        ->postJson('/caja/cobrar', $contexto['pedido']);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk();

    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$saleId.'/emision')
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y el comprobante sigue anulado: el reintento no lo resucito.
    $documentoId = DB::table('sales')->where('id', $saleId)->value('fiscal_document_id');

    expect((string) DB::table('fiscal_documents')->where('id', (int) $documentoId)->value('status'))
        ->toBe('void');
});

/**
 * F3-03 — una venta de OTRA TIENDA es un 404.
 *
 * REGLA que fija: *una venta de otra tienda no existe para quien pregunta*.
 * Un 403 le diria al supervisor que la venta EXISTE, y eso ya es
 * informacion de otra tienda.
 */
test('test_la_anulacion_de_una_venta_de_otra_tienda_es_un_404', function () {
    $contexto = escenario_de_anulacion();

    $otraTienda = (int) CreditFixture::store();

    $ventaAjena = PermissionFixture::venta($otraTienda);

    $this->actingAs($contexto['usuario'])
        ->postJson('/ventas/'.$ventaAjena->id.'/anulacion', ['reason' => 'Error en el monto'])
        ->assertNotFound();

    // Y la venta ajena no se toco.
    expect((string) DB::table('sales')->where('id', $ventaAjena->id)->value('status'))->toBe('paid');
});
