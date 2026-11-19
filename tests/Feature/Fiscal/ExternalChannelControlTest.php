<?php

use App\Domain\Fiscal\Enums\ProviderKind;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\PosFixture;

/**
 * F2-04 — el NUMERO DE CONTROL cuando el canal es EXTERNO.
 *
 * REGLA que fija este test: *el numero de control lo reserva el emisor SOLO
 * cuando el emisor es tambien la imprenta; cuando hay una autoridad detras,
 * lo asigna ella*.
 *
 * La pregunta "¿quien asigna el control?" es una propiedad del CANAL (el
 * `ProviderKind`), no de la configuracion:
 *
 *  - `printer`: el ROL DUAL. El sistema es emisor E imprenta, asi que el
 *    emisor reserva el numero de control de su propia serie;
 *  - `pac` y `portal`: hay una AUTORIDAD detras, y el control lo asigna ella:
 *    el emisor NO reserva nada, y el numero llega con el acuse.
 *
 * Y HOY EL SISTEMA ES EL CANAL LOCAL: el comportamiento observable NO cambia
 * (la reserva se sigue haciendo y la serie de control sigue avanzando). La
 * diferencia se ve SOLAMENTE con un canal externo, que todavia no tiene
 * adaptador: por eso este test MONTA ese escenario (un proveedor de tipo
 * `portal`) sin implementar el adaptador.
 *
 * Y EL CONTROL ES UN DATO DEL DOCUMENTO, NO DE LA VENTA: con canal externo la
 * venta, sus lineas, sus totales y su asiento se escriben IGUAL, y lo unico
 * que no ocurre es la reserva del numero de control.
 */
beforeEach(function () {
    config(['fiscal.environment' => 'testing']);

    $this->escenario = PosFixture::escenario();
    $this->usuario = PosFixture::usuario($this->escenario['store_id']);
});

/**
 * El siguiente numero de una serie: el contador que AVANZA cuando se
 * reserva.
 */
function canal_siguiente(int $serieId): int
{
    return (int) DB::table('fiscal_series')->where('id', $serieId)->value('next_number');
}

/**
 * Las reservas de CONTROL de una serie.
 */
function canal_reservas_de_control(int $serieId): int
{
    return DB::table('fiscal_document_reservations')
        ->where('series_id', $serieId)
        ->where('series_role', 'control')
        ->count();
}

/**
 * Las lineas del ASIENTO de una venta: cuenta, debe y haber.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function canal_asiento(int $saleId): array
{
    $entry = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->first();

    if ($entry === null) {
        return [];
    }

    $lineas = [];

    foreach (DB::table('ledger_lines')->where('ledger_entry_id', $entry->id)->orderBy('id')->get() as $linea) {
        $lineas[] = [(string) $linea->account_code, (string) $linea->debit, (string) $linea->credit];
    }

    return $lineas;
}

/**
 * El pedido de una venta de dos unidades a 25, con su pago.
 *
 * @param  array<string, int>  $escenario
 * @return array<string, mixed>
 */
function canal_pedido(array $escenario): array
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
 * MONTA un canal EXTERNO: un proveedor del tipo pedido (`portal` o `pac`) para
 * el perfil de la tienda, y el entorno de emision pasa a apuntar a el.
 *
 * REGLA que fija este helper: *el canal externo se MONTA CON FILAS*. El
 * adaptador no se implementa (no hay a que actor conectarse): lo que se monta
 * es el ESCENARIO, las filas que lo describen.
 *
 * @param  array<string, int>  $escenario
 */
function canal_externo(array $escenario, string $kind = 'portal'): void
{
    $proveedorId = DB::table('fiscal_providers')->insertGetId([
        'fiscal_profile_id' => $escenario['profile_id'],
        'code' => 'EXTERNO_'.strtoupper($kind),
        'name' => 'Canal externo de prueba',
        'kind' => $kind,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('fiscal_profile_environments')
        ->where('fiscal_profile_id', $escenario['profile_id'])
        ->where('role', 'issuer')
        ->where('is_testing', true)
        ->update([
            'provider_id' => $proveedorId,
            'updated_at' => now(),
        ]);
}

/**
 * F2-04 — el TIPO DE CANAL declara quien asigna el numero de control.
 *
 * REGLA que fija este test: *la regla vive en el `ProviderKind`*, y es la
 * tabla del diseno: el rol dual reserva el control, y los canales con
 * autoridad detras no.
 */
test('test_el_tipo_de_canal_declara_quien_asigna_el_numero_de_control', function () {
    // 1. LA TABLA DE LA REGLA: tres canales, tres respuestas.
    expect(ProviderKind::Printer->issuerReservesControl())->toBeTrue()
        ->and(ProviderKind::Pac->issuerReservesControl())->toBeFalse()
        ->and(ProviderKind::Portal->issuerReservesControl())->toBeFalse();

    // 2. Y el CONJUNTO CERRADO es el de la regla: solo el rol dual reserva.
    $reservan = [];

    foreach (ProviderKind::cases() as $tipo) {
        if ($tipo->issuerReservesControl()) {
            $reservan[] = $tipo;
        }
    }

    expect(ProviderKind::cases())->toHaveCount(3)
        ->and($reservan)->toBe([ProviderKind::Printer]);
});

/**
 * F2-04 — el CONTEXTO de la caja expone la pregunta.
 *
 * REGLA que fija este test: *la pregunta se hace en un solo sitio*. El
 * contexto resuelve el entorno y el proveedor de la tienda (el camino es el
 * del `FiscalProviderResolver`, no uno nuevo), y expone el canal y la
 * respuesta. El checkout no resuelve el proveedor por su cuenta.
 */
test('test_el_contexto_de_la_caja_expone_el_canal_y_quien_asigna_el_control', function () {
    // 1. El canal LOCAL: el sistema es emisor E imprenta.
    $contexto = new PosContext(DB::connection());

    expect($contexto->providerKind())->toBe(ProviderKind::Printer)
        ->and($contexto->issuerReservesControl())->toBeTrue();

    // 2. Y el canal EXTERNO: hay una autoridad detras, y no reserva.
    canal_externo($this->escenario, 'portal');

    $contexto = new PosContext(DB::connection());

    expect($contexto->providerKind())->toBe(ProviderKind::Portal)
        ->and($contexto->issuerReservesControl())->toBeFalse();
});

/**
 * F2-04 — con un canal EXTERNO el checkout NO reserva la serie de control.
 *
 * REGLA que fija este test: *cuando hay una autoridad detras, el control lo
 * asigna ella*. El emisor no quema ningun numero del rango autorizado.
 */
test('test_con_un_canal_externo_el_checkout_no_reserva_la_serie_de_control', function () {
    canal_externo($this->escenario, 'portal');

    $antes = canal_siguiente($this->escenario['control_series_id']);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', canal_pedido($this->escenario));

    $respuesta->assertCreated();

    // 1. La serie de control NO avanza: el rango autorizado no se gasta.
    expect(canal_siguiente($this->escenario['control_series_id']))->toBe($antes);

    // 2. Y no hay NINGUNA reserva de control.
    expect(canal_reservas_de_control($this->escenario['control_series_id']))->toBe(0);

    // 3. Y el comprobante NO lleva numero de control: lo asigna la autoridad.
    expect($respuesta->json('control_number'))->toBeNull();
});

/**
 * F2-04 — con el canal LOCAL el checkout SI reserva la serie de control.
 *
 * REGLA que fija este test: *el comportamiento de hoy no cambia*. El rol dual
 * es el sistema: el emisor reserva su control y la serie avanza UNA vez.
 */
test('test_con_el_canal_local_el_checkout_reserva_la_serie_de_control', function () {
    $antes = canal_siguiente($this->escenario['control_series_id']);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', canal_pedido($this->escenario));

    $respuesta->assertCreated();

    // 1. La serie avanza UNA vez.
    expect(canal_siguiente($this->escenario['control_series_id']))->toBe($antes + 1);

    // 2. Y hay UNA sola reserva de control: la de la venta.
    expect(canal_reservas_de_control($this->escenario['control_series_id']))->toBe(1);

    // 3. Y el comprobante lleva su numero de control.
    expect($respuesta->json('control_number'))->not->toBeNull();
});

/**
 * F2-04 — el numero de DOCUMENTO se reserva igual en los dos canales.
 *
 * REGLA que fija este test: *el emisor numera SIEMPRE*. El numero de control
 * es lo unico que depende del canal; el del documento no (art. 8.2).
 */
test('test_el_numero_de_documento_se_reserva_igual_en_los_dos_canales', function () {
    // 1. El canal LOCAL.
    $antes = canal_siguiente($this->escenario['document_series_id']);

    $local = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', canal_pedido($this->escenario));

    $local->assertCreated();

    expect(canal_siguiente($this->escenario['document_series_id']))->toBe($antes + 1);

    // 2. Y el canal EXTERNO: el emisor numeraria igual.
    canal_externo($this->escenario, 'portal');

    $antes = canal_siguiente($this->escenario['document_series_id']);

    $externo = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', canal_pedido($this->escenario));

    $externo->assertCreated();

    expect(canal_siguiente($this->escenario['document_series_id']))->toBe($antes + 1)
        ->and($externo->json('invoice_no'))->not->toBeNull()
        ->and($externo->json('invoice_no'))->not->toBe($local->json('invoice_no'));
});

/**
 * F2-04 — la VENTA y el ASIENTO se escriben igual en los dos canales.
 *
 * REGLA que fija este test: *el control es un dato del DOCUMENTO, no de la
 * VENTA*. Lo que no cambia con el canal es la venta entera: sus lineas, sus
 * totales, su pago y su asiento.
 */
test('test_el_asiento_y_la_venta_se_escriben_igual_en_los_dos_canales', function () {
    // 1. El canal LOCAL.
    $local = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', canal_pedido($this->escenario));

    $local->assertCreated();

    // 2. El canal EXTERNO.
    canal_externo($this->escenario, 'portal');

    $externo = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', canal_pedido($this->escenario));

    $externo->assertCreated();

    $ventaLocal = DB::table('sales')->where('id', $local->json('sale_id'))->first();
    $ventaExterna = DB::table('sales')->where('id', $externo->json('sale_id'))->first();

    // 3. La VENTA: los mismos totales y el mismo estado de pago.
    expect($externo->json('grand_total'))->toBe($local->json('grand_total'))
        ->and($externo->json('status'))->toBe($local->json('status'))
        ->and((string) $ventaExterna->sale_date)->toBe((string) $ventaLocal->sale_date)
        ->and((int) $ventaExterna->store_id)->toBe((int) $ventaLocal->store_id);

    // 4. Los TOTALES: los mismos.
    $totalesLocal = DB::table('sale_totals')->where('sale_id', $local->json('sale_id'))->first();
    $totalesExterno = DB::table('sale_totals')->where('sale_id', $externo->json('sale_id'))->first();

    expect((string) $totalesExterno->grand_total)->toBe((string) $totalesLocal->grand_total)
        ->and((string) $totalesExterno->sub_total)->toBe((string) $totalesLocal->sub_total)
        ->and((string) $totalesExterno->tax_total)->toBe((string) $totalesLocal->tax_total);

    // 5. Y el ASIENTO: las mismas lineas, con las mismas cuentas.
    expect(canal_asiento((int) $externo->json('sale_id')))
        ->toBe(canal_asiento((int) $local->json('sale_id')));

    // 6. Y el STOCK baja igual: la venta es la misma.
    expect((float) DB::table('product_stores')
        ->where('product_id', $this->escenario['product_id'])
        ->value('quantity_in_stock'))->toBe(6.0);
});

/**
 * F2-04 — el proveedor LOCAL sigue reutilizando la reserva de la venta.
 *
 * REGLA que fija este test: *el canal local es el de rol dual, y su
 * proveedor reutiliza el control que la venta reservo*. El comprobante y el
 * documento fiscal llevan el MISMO numero, y la serie avanza UNA vez.
 */
test('test_el_proveedor_local_sigue_reutilizando_la_reserva_de_control_de_la_venta', function () {
    config(['fiscal.emission_enabled' => true]);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', canal_pedido($this->escenario));

    $respuesta->assertCreated()->assertJsonPath('fiscal.status', 'issued');

    // 1. UNA sola reserva de control: la de la venta.
    expect(canal_reservas_de_control($this->escenario['control_series_id']))->toBe(1);

    // 2. Y el COMPROBANTE lleva el MISMO control que el DOCUMENTO fiscal.
    expect($respuesta->json('control_number'))->toBe($respuesta->json('fiscal.control_number'))
        ->and($respuesta->json('control_number'))->not->toBeNull();
});

/**
 * F2-04 — la PREMISA: el adaptador del canal externo NO esta implementado.
 *
 * REGLA que fija este test: *un canal que no esta conectado NO se emula*.
 * El error dice QUE canal es y POR QUE no se puede usar: no un fallo raro.
 *
 * Y es el CONTRATO que cumplira el adaptador futuro: cuando exista, el
 * `assignControl()` recibira el control que devuelva la autoridad.
 */
test('test_un_canal_externo_sin_implementacion_da_un_error_util', function () {
    canal_externo($this->escenario, 'portal');

    config(['fiscal.emission_enabled' => true]);

    $respuesta = $this->actingAs($this->usuario)
        ->postJson('/caja/cobrar', canal_pedido($this->escenario));

    // 1. La VENTA se escribe: un fallo fiscal NO puede hacer perder una venta.
    $respuesta->assertCreated()->assertJsonPath('fiscal.status', 'failed');

    // 2. Y el motivo dice QUE canal es y POR QUE falla.
    expect($respuesta->json('fiscal.message'))->toContain('portal')
        ->and($respuesta->json('fiscal.message'))->toContain('implementacion');

    // 3. Y el numero de control sigue SIN reservarse: la autoridad no ha
    //    asignado ninguno.
    expect(canal_reservas_de_control($this->escenario['control_series_id']))->toBe(0);
});
