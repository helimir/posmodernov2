<?php

use App\Domain\Fiscal\FiscalIssuanceCoordinator;
use App\Domain\Fiscal\FiscalIssuanceService;
use App\Domain\Sales\SalePaymentStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FiscalProfile;
use App\Models\FiscalProvider;
use App\Models\Product;
use App\Models\Store;
use App\Models\TaxCategory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FakeInvoiceProvider;

/**
 * 0.6.6 / 0.6.6-T — T2 fiscal: la emision y su reintento.
 *
 * Reglas que fija:
 *  - la llamada al actor intermedio ocurre FUERA de la transaccion: el
 *    proveedor es I/O, y un bloqueo de fila durante I/O puede agotar el
 *    `lock_timeout` de la caja (design §B.1, decision C5);
 *  - un fallo de emision deja la venta en `fiscal_failed` y NO libera la
 *    reserva: el numero puede haberse publicado, y un numero publicado no
 *    se devuelve;
 *  - el reintento REUTILIZA la reserva: no quema numeros nuevos, no crea un
 *    segundo documento y no duplica el desglose de impuestos;
 *  - `fiscal_failed` pisa el estado de pago (es un unico enum), asi que un
 *    reintento exitoso RECALCULA el estado de pago desde `sale_payments` en
 *    vez de restaurarlo.
 *
 * El test NO envuelve el caso en la transaccion de `RefreshDatabase`: usa
 * transacciones REALES, porque lo que se afirma es precisamente el nivel de
 * transaccion en el que habla el proveedor.
 */
uses(WithoutWrappingTransaction::class);

beforeEach(function () {
    DB::statement(
        'TRUNCATE users, currencies, countries, stores, products, customers, payment_methods, sales'
        .' RESTART IDENTITY CASCADE'
    );
});

afterEach(function () {
    DB::statement(
        'TRUNCATE users, currencies, countries, stores, products, customers, payment_methods, sales'
        .' RESTART IDENTITY CASCADE'
    );
});

/**
 * El escenario de una venta cobrada con su reserva enlazada.
 *
 * @param  bool  $conBorrador  Si el documento fiscal en `draft` ya existe
 *                             (el caso del reintento) o lo crea T2.
 * @param  bool  $controlRequerido  Si el perfil fiscal exige Numero de Control.
 * @return array<string, int>
 */
function escenarioEmision(bool $conBorrador = true, bool $controlRequerido = true): array
{
    $currency = Currency::create([
        'code' => 'ZZA',
        'name' => 'Moneda de prueba',
        'decimals' => 2,
    ]);

    $country = Country::create([
        'iso_code' => 'ZZ',
        'name' => 'Pais de prueba',
        'default_currency_id' => $currency->id,
    ]);

    $profile = FiscalProfile::create([
        'country_id' => $country->id,
        'code' => 'ZZ_MAIN',
        'name' => 'Perfil de prueba',
        'signer_model' => 'printer',
        'rounding_rule' => 'line',
        'tax_inclusive_default' => false,
        'default_currency_id' => $currency->id,
        'requires_control_number' => $controlRequerido,
    ]);

    $provider = FiscalProvider::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'PRINTER',
        'name' => 'Imprenta de prueba',
        'kind' => 'printer',
    ]);

    $store = Store::create(['name' => 'Tienda de la emision']);

    $category = TaxCategory::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'gravable',
        'name' => 'Gravable',
        'evidence_status' => 'verified',
        'source_ref' => 'REF-CATEGORIA',
    ]);

    $taxRateId = DB::table('tax_rates')->insertGetId([
        'tax_category_id' => $category->id,
        'percentage' => 16,
        'rounding_rule' => 'line',
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'priority' => 0,
        'evidence_status' => 'verified',
        'source_ref' => 'REF-TASA',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $product = Product::create([
        'code' => 'ZZP-EMISION-1',
        'name' => 'Producto de la emision',
        'tax_category_id' => $category->id,
        'type' => 'product',
    ]);

    $paymentMethodId = DB::table('payment_methods')->insertGetId([
        'code' => 'ZZPAGO-EMISION',
        'name' => 'Efectivo de prueba',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // La serie del DOCUMENTO. El numero 100 ya esta repartido: la reserva
    // existe y la serie quedo en 101. Un numero que avanza es un numero
    // quemado.
    $seriesId = DB::table('fiscal_series')->insertGetId([
        'fiscal_profile_id' => $profile->id,
        'store_id' => $store->id,
        'provider_id' => $provider->id,
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'prefix' => 'ZZ',
        'next_number' => 101,
        'from_number' => 100,
        'to_number' => 200,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customer = Customer::create(['name' => 'Cliente de la emision']);

    // La venta COBRADA: cabecera, item, totales y pago.
    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $store->id,
        'customer_id' => $customer->id,
        'invoice_no' => 'ZZ100',
        'sale_date' => '2026-11-04',
        'status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $itemId = DB::table('sale_items')->insertGetId([
        'sale_id' => $saleId,
        'product_id' => $product->id,
        'store_id' => $store->id,
        'quantity' => '2.0000',
        'unit_price' => '25.0000',
        'discount' => '0.0000',
        'tax_category_id' => $category->id,
        'tax_rate_id' => $taxRateId,
        'tax_rate_snapshot' => '16.0000',
        'tax_amount' => '8.0000',
        'base_amount' => '50.0000',
        'line_total' => '58.0000',
        'evidence_status' => 'verified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sale_totals')->insert([
        'sale_id' => $saleId,
        'sub_total' => '50.0000',
        'discount' => '0.0000',
        'tax_total' => '8.0000',
        'rounding_adjustment' => '0.0000',
        'grand_total' => '58.0000',
        'evidence_status' => 'verified',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sale_payments')->insert([
        'sale_id' => $saleId,
        'payment_method_id' => $paymentMethodId,
        'amount' => '58.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // La reserva del documento, ENLAZADA con la venta que la origino: es lo
    // que permite al reintento encontrarla sin quemar otro numero.
    $reservationId = DB::table('fiscal_document_reservations')->insertGetId([
        'series_id' => $seriesId,
        'number' => 100,
        'series_role' => 'document',
        'reserved_by' => null,
        'reserved_at' => now(),
        'reference_type' => 'sale',
        'reference_id' => $saleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $documentId = null;

    if ($conBorrador) {
        $documentId = DB::table('fiscal_documents')->insertGetId([
            'fiscal_profile_id' => $profile->id,
            'store_id' => $store->id,
            'series_id' => $seriesId,
            'number' => 100,
            'reservation_id' => $reservationId,
            'document_type' => 'invoice',
            'status' => 'draft',
            'customer_id' => $customer->id,
            'currency_id' => $currency->id,
            'subtotal' => '50.0000',
            'tax_total' => '8.0000',
            'total_amount' => '58.0000',
            'issue_date' => '2026-11-04',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales')->where('id', $saleId)->update(['fiscal_document_id' => $documentId]);
    }

    return [
        'store_id' => (int) $store->id,
        'profile_id' => (int) $profile->id,
        'currency_id' => (int) $currency->id,
        'customer_id' => (int) $customer->id,
        'series_id' => $seriesId,
        'sale_id' => $saleId,
        'item_id' => $itemId,
        'reservation_id' => $reservationId,
        'document_id' => $documentId ?? 0,
    ];
}

/**
 * El coordinador, con sus colaboradores en la MISMA conexion: es lo que
 * hace que la transaccion de T2 sea una sola.
 */
function servicioEmision(FakeInvoiceProvider $provider): FiscalIssuanceCoordinator
{
    $connection = DB::connection();

    return new FiscalIssuanceCoordinator(
        $connection,
        new FiscalIssuanceService($connection, $provider),
        new SalePaymentStatus($connection),
    );
}

/**
 * 0.6.6-T — el reintento reutiliza la reserva y no quema numeros nuevos.
 */
test('test_un_reintento_de_emision_reutiliza_la_reserva_y_no_quema_numeros_nuevos', function () {
    $escenario = escenarioEmision();
    $provider = new FakeInvoiceProvider;
    $coordinator = servicioEmision($provider);

    // 1. El primer intento falla en el proveedor: no hay red, no hay
    //    emision.
    $provider->failOnSubmit = true;

    expect(fn () => $coordinator->issue($escenario['sale_id']))
        ->toThrow(RuntimeException::class, 'El actor intermedio rechazo el envio.');

    // 2. La venta queda en `fiscal_failed`: el estado fiscal MACA el estado
    //    de pago, y el llamante tiene que saber que la emision no salio.
    expect(DB::table('sales')->where('id', $escenario['sale_id'])->value('status'))
        ->toBe('fiscal_failed');

    // 3. La serie NO avanzo: el numero reservado no se devuelve ni se
    //    reparte otro.
    expect((int) DB::table('fiscal_series')->where('id', $escenario['series_id'])->value('next_number'))
        ->toBe(101);

    // 4. La reserva sigue VIVA: un numero puede haberse publicado, y un
    //    numero publicado no se libera.
    $reservas = DB::table('fiscal_document_reservations')
        ->where('series_id', $escenario['series_id'])
        ->get();

    expect($reservas)->toHaveCount(1)
        ->and($reservas->first()->voided_at)->toBeNull()
        ->and((int) $reservas->first()->id)->toBe($escenario['reservation_id']);

    // 5. Y el documento sigue en `draft`: el fallo no dejo el comprobante a
    //    medias.
    expect(DB::table('fiscal_documents')->where('id', $escenario['document_id'])->value('status'))
        ->toBe('draft');

    // 6. El reintento, con el proveedor en pie.
    $provider->failOnSubmit = false;

    $documentId = $coordinator->issue($escenario['sale_id']);

    // 7. La venta vuelve a su estado de pago, RECALCULADO desde los pagos:
    //    `fiscal_failed` habia pisado el estado anterior.
    expect(DB::table('sales')->where('id', $escenario['sale_id'])->value('status'))
        ->toBe('paid');

    // 8. El documento es el MISMO, con la MISMA reserva y `issued`.
    $documento = DB::table('fiscal_documents')->where('id', $documentId)->first();

    expect($documento)->not->toBeNull()
        ->and((int) $documentId)->toBe($escenario['document_id'])
        ->and($documento->status)->toBe('issued')
        ->and((int) $documento->reservation_id)->toBe($escenario['reservation_id']);

    // 9. NO se creo un segundo documento: la serie tiene UN numero y un
    //    documento.
    expect(DB::table('fiscal_documents')->count())->toBe(1);

    // 10. La serie NO avanzo: el reintento no reservo nada nuevo.
    expect((int) DB::table('fiscal_series')->where('id', $escenario['series_id'])->value('next_number'))
        ->toBe(101);

    // 11. Y sigue habiendo UNA sola reserva.
    expect(DB::table('fiscal_document_reservations')->where('series_id', $escenario['series_id'])->count())
        ->toBe(1);

    // 12. El desglose de impuestos NO esta duplicado: una fila por linea y
    //     una de cabecera.
    $impuestos = DB::table('fiscal_document_taxes')->where('fiscal_document_id', $documentId)->get();

    expect($impuestos)->toHaveCount(2)
        ->and($impuestos->whereNull('line_id')->count())->toBe(1)
        ->and($impuestos->whereNotNull('line_id')->count())->toBe(1);
});

/**
 * 0.6.6 — el reintento NO vuelve a llamar al proveedor si el documento ya
 * esta emitido.
 *
 * Regla que fija: *una doble emision ante la autoridad no se puede
 * deshacer*. Si un intento anterior dejo el comprobante `issued` (porque el
 * proveedor emitio y lo que fallo fue una escritura posterior), el reintento
 * NO vuelve a llamar al actor: solo reconcilia el estado de la venta. Un
 * numero devuelto no deshace un comprobante ya publicado.
 */
test('test_un_reintento_no_vuelve_a_llamar_al_proveedor_si_el_documento_ya_esta_emitido', function () {
    $escenario = escenarioEmision();

    // El intento anterior LLEGO a emitir: el documento quedo `issued` y lo
    // que fallo fue una escritura posterior, asi que la venta quedo en
    // `fiscal_failed` con el estado de pago pisado.
    DB::table('fiscal_documents')->where('id', $escenario['document_id'])->update([
        'status' => 'issued',
        'updated_at' => now(),
    ]);

    DB::table('sales')->where('id', $escenario['sale_id'])->update([
        'status' => 'fiscal_failed',
        'updated_at' => now(),
    ]);

    $provider = new FakeInvoiceProvider;
    $coordinator = servicioEmision($provider);

    $documentId = $coordinator->issue($escenario['sale_id']);

    // 1. EL PUNTO CENTRAL: el proveedor NO se volvio a llamar. Ni una sola
    //    llamada: el comprobante ya estaba publicado.
    expect($provider->calls)->toBe([]);

    // 2. El documento es el MISMO, sigue `issued` y con la MISMA reserva: no
    //    se creo otro documento.
    $documento = DB::table('fiscal_documents')->where('id', $documentId)->first();

    expect($documento)->not->toBeNull()
        ->and((int) $documentId)->toBe($escenario['document_id'])
        ->and($documento->status)->toBe('issued')
        ->and((int) $documento->reservation_id)->toBe($escenario['reservation_id'])
        ->and(DB::table('fiscal_documents')->count())->toBe(1);

    // 3. La venta queda RECONCILIADA: el estado de pago se recalcula desde
    //    `sale_payments`, porque `fiscal_failed` lo habia pisado. Los pagos
    //    del escenario cubren el total.
    expect(DB::table('sales')->where('id', $escenario['sale_id'])->value('status'))
        ->toBe('paid');

    // 4. La serie NO avanzo y sigue habiendo UNA sola reserva.
    expect((int) DB::table('fiscal_series')->where('id', $escenario['series_id'])->value('next_number'))
        ->toBe(101)
        ->and(DB::table('fiscal_document_reservations')->where('series_id', $escenario['series_id'])->count())
        ->toBe(1);
});

/**
 * 0.6.6 — la llamada al proveedor ocurre FUERA de la transaccion.
 */
test('test_la_llamada_al_proveedor_ocurre_fuera_de_la_transaccion', function () {
    $escenario = escenarioEmision();
    $provider = new FakeInvoiceProvider;
    $coordinator = servicioEmision($provider);

    $coordinator->issue($escenario['sale_id']);

    // Las TRES llamadas, en el orden del contrato del proveedor.
    expect($provider->calls)->toBe(['submit', 'assignControl', 'issue']);

    // Y las TRES en nivel CERO: ninguna transaccion abierta. Un nivel mayor
    // seria un bloqueo de fila durante I/O (design §B.1, decision C5).
    expect($provider->transactionLevels)->toBe([
        'submit' => 0,
        'assignControl' => 0,
        'issue' => 0,
    ]);
});

/**
 * 0.6.6 — un fallo de emision no libera la reserva ni avanza la serie.
 *
 * Regla que fija: *ningun numero se libera tras un fallo de emision*. El
 * numero puede haberse publicado en la autoridad, y devolverlo al correlativo
 * entregaria DOS veces el mismo numero.
 */
test('test_un_fallo_de_emision_no_libera_la_reserva_ni_avanza_la_serie', function () {
    $escenario = escenarioEmision();
    $provider = new FakeInvoiceProvider;
    $provider->failOnSubmit = true;

    $coordinator = servicioEmision($provider);

    expect(fn () => $coordinator->issue($escenario['sale_id']))
        ->toThrow(RuntimeException::class, 'El actor intermedio rechazo el envio.');

    // La venta quedo en `fiscal_failed`.
    expect(DB::table('sales')->where('id', $escenario['sale_id'])->value('status'))
        ->toBe('fiscal_failed');

    // La reserva sigue viva y la serie no avanzo.
    $reserva = DB::table('fiscal_document_reservations')
        ->where('id', $escenario['reservation_id'])
        ->first();

    expect($reserva)->not->toBeNull()
        ->and($reserva->voided_at)->toBeNull()
        ->and((int) DB::table('fiscal_series')->where('id', $escenario['series_id'])->value('next_number'))
        ->toBe(101);
});

/**
 * 0.6.6 — el reintento no duplica la asignacion de control.
 *
 * `fiscal_document_control_assignments` tiene UNIQUE en
 * `fiscal_document_id`: una reemision sobre el mismo documento no puede
 * insertar una segunda fila. El control YA PUBLICADO se conserva: no se
 * devuelve ni se reasigna.
 */
test('test_el_reintento_no_duplica_la_asignacion_de_control', function () {
    $escenario = escenarioEmision();
    $provider = new FakeInvoiceProvider;
    $coordinator = servicioEmision($provider);

    // 1. El primer intento falla.
    $provider->failOnSubmit = true;

    expect(fn () => $coordinator->issue($escenario['sale_id']))
        ->toThrow(RuntimeException::class, 'El actor intermedio rechazo el envio.');

    expect(DB::table('fiscal_document_control_assignments')->count())->toBe(0);

    // 2. El reintento consolida: el control queda asignado UNA vez.
    $provider->failOnSubmit = false;

    $coordinator->issue($escenario['sale_id']);

    $asignaciones = DB::table('fiscal_document_control_assignments')
        ->where('fiscal_document_id', $escenario['document_id'])
        ->get();

    expect($asignaciones)->toHaveCount(1)
        ->and($asignaciones->first()->control_number)->toBe('00-00000001');

    // 3. Y una REEMISION sobre el mismo documento no inserta una segunda
    //    fila: el control publicado se conserva.
    $coordinator->issue($escenario['sale_id']);

    expect(DB::table('fiscal_document_control_assignments')
        ->where('fiscal_document_id', $escenario['document_id'])
        ->count())->toBe(1);
});

/**
 * 0.6.6 — T2 crea el borrador del documento y lo enlaza a la venta.
 *
 * Este es el camino de PRODUCCION: T1 deja `sales.fiscal_document_id` en
 * `null` y es T2 quien crea el documento, su desglose de impuestos y el
 * enlace con la venta.
 */
test('test_la_emision_crea_el_borrador_del_documento_y_lo_enlaza_a_la_venta', function () {
    $escenario = escenarioEmision(conBorrador: false);
    $provider = new FakeInvoiceProvider;
    $coordinator = servicioEmision($provider);

    $documentId = $coordinator->issue($escenario['sale_id']);

    // 1. El documento existe, con la reserva que ya estaba.
    $documento = DB::table('fiscal_documents')->where('id', $documentId)->first();

    expect($documento)->not->toBeNull()
        ->and($documento->status)->toBe('issued')
        ->and((int) $documento->reservation_id)->toBe($escenario['reservation_id'])
        ->and((int) $documento->series_id)->toBe($escenario['series_id'])
        ->and((int) $documento->number)->toBe(100)
        ->and($documento->document_type)->toBe('invoice')
        ->and((int) $documento->customer_id)->toBe($escenario['customer_id'])
        ->and((int) $documento->currency_id)->toBe($escenario['currency_id'])
        ->and((string) $documento->total_amount)->toBe('58.0000');

    // 2. Y la venta apunta a el.
    expect((int) DB::table('sales')->where('id', $escenario['sale_id'])->value('fiscal_document_id'))
        ->toBe($documentId);

    // 3. El desglose de impuestos: una fila por linea y una de cabecera.
    $impuestos = DB::table('fiscal_document_taxes')->where('fiscal_document_id', $documentId)->get();

    expect($impuestos)->toHaveCount(2);

    $cabecera = $impuestos->whereNull('line_id')->first();
    $deLinea = $impuestos->whereNotNull('line_id')->first();

    expect((int) $deLinea->line_id)->toBe($escenario['item_id'])
        ->and($deLinea->tax_type)->toBe('transfer')
        ->and((string) $deLinea->base_amount)->toBe('50.0000')
        ->and((string) $deLinea->tax_amount)->toBe('8.0000')
        ->and((string) $deLinea->tax_rate_snapshot)->toBe('16.0000')
        ->and($deLinea->evidence_status)->toBe('verified');

    expect($cabecera->line_id)->toBeNull()
        ->and($cabecera->tax_type)->toBe('transfer')
        ->and((string) $cabecera->tax_amount)->toBe('8.0000')
        ->and((string) $cabecera->rounding_adjustment)->toBe('0.0000');
});

/**
 * 0.6.6 — una venta sin reserva NO se puede emitir.
 *
 * Regla que fija: *el reintento no reserva un numero nuevo, jamais*. Si la
 * venta no tiene reserva, no hay numero que emitir: no se inventa uno.
 */
test('test_una_venta_sin_reserva_no_se_puede_emitir', function () {
    $escenario = escenarioEmision();
    $coordinator = servicioEmision(new FakeInvoiceProvider);

    // Se rompe el enlace: la venta se queda sin la reserva que la origino.
    DB::table('fiscal_document_reservations')
        ->where('id', $escenario['reservation_id'])
        ->update(['reference_type' => null, 'reference_id' => null]);

    expect(fn () => $coordinator->issue($escenario['sale_id']))
        ->toThrow(RuntimeException::class, 'no tiene reserva');

    // Y no se reservo nada: la serie sigue donde estaba.
    expect((int) DB::table('fiscal_series')->where('id', $escenario['series_id'])->value('next_number'))
        ->toBe(101);
});
