<?php

use App\Domain\Fiscal\FiscalIssuanceCoordinator;
use App\Domain\Fiscal\FiscalIssuanceService;
use App\Domain\Fiscal\ReceiptRenderer;
use App\Domain\Fiscal\ReceiptTemplate;
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
 * 1.8 — el DOCUMENTO FISCAL COMPLETO.
 *
 * REGLA que fija: *un comprobante fiscal sin emisor, sin receptor y sin
 * lineas NO es un comprobante*.
 *
 * El documento se emitia sin saber quien emitia ni quien recibia: la tabla
 * `fiscal_document_parties` existia y NADIE la escribia, y la plantilla del
 * comprobante llevaba `lines: []`. Estas reglas fijan que:
 *
 *  - el EMISOR es la tienda, con su inscripcion fiscal primaria;
 *  - el RECEPTOR es el cliente de la venta, o el consumidor final que
 *    declara el perfil;
 *  - un reintento NO duplica las partes;
 *  - la plantilla trae las LINEAS de la venta;
 *  - la DENOMINACION es la que declara el perfil, no el tipo crudo;
 *  - la HORA sale del instante de emision, no de la medianoche.
 *
 * El test NO envuelve el caso en la transaccion de `RefreshDatabase`: usa
 * transacciones REALES, porque lo que se afirma es el nivel de transaccion
 * en el que habla el proveedor.
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
 * El escenario de una venta cobrada, con su reserva enlazada.
 *
 * @param  array<string, mixed>  $opciones
 * @return array<string, int>
 */
function escenarioComprobante(array $opciones = []): array
{
    $conCliente = $opciones['con_cliente'] ?? true;
    $conInscripcion = $opciones['con_inscripcion'] ?? true;

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
        'requires_control_number' => true,
        // El ESQUEMA del identificativo del pais: es lo que declara el tipo
        // del receptor, porque el cliente tiene un unico campo de documento.
        'id_scheme_code' => 'ZZ.RIF',
    ]);

    $provider = FiscalProvider::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'PRINTER',
        'name' => 'Imprenta de prueba',
        'kind' => 'printer',
    ]);

    $store = Store::create([
        'name' => 'Tienda de la emision',
        'address' => 'Calle 1, Caracas',
    ]);

    // La ZONA HORARIA de la tienda: el reloj del comprobante es el del
    // negocio, no el del servidor.
    DB::table('store_settings')->insert([
        'store_id' => $store->id,
        'timezone' => 'America/Caracas',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($conInscripcion) {
        DB::table('store_fiscal_registrations')->insert([
            'store_id' => $store->id,
            'tax_id' => 'J-12345678-9',
            'tax_id_scheme' => 'rif',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

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
        'code' => 'ZZP-COMPROBANTE-1',
        'name' => 'Producto del comprobante',
        'tax_category_id' => $category->id,
        'type' => 'product',
    ]);

    $paymentMethodId = DB::table('payment_methods')->insertGetId([
        'code' => 'ZZPAGO-COMPROBANTE',
        'name' => 'Efectivo de prueba',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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

    $customer = null;

    if ($conCliente) {
        $customer = Customer::create([
            'name' => 'Cliente de la emision',
            'tax_id' => 'V-12345678',
            'address' => 'Urb. Los Pinos, Caracas',
        ]);
    }

    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $store->id,
        'customer_id' => $customer?->id,
        'invoice_no' => 'ZZ100',
        'sale_date' => '2026-11-04',
        'status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sale_items')->insert([
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

    return [
        'store_id' => (int) $store->id,
        'profile_id' => (int) $profile->id,
        'currency_id' => (int) $currency->id,
        'customer_id' => $customer === null ? 0 : (int) $customer->id,
        'series_id' => $seriesId,
        'sale_id' => $saleId,
        'reservation_id' => $reservationId,
    ];
}

/**
 * El coordinador, con sus colaboradores en la MISMA conexion.
 */
function servicioComprobante(FakeInvoiceProvider $provider): FiscalIssuanceCoordinator
{
    $connection = DB::connection();

    return new FiscalIssuanceCoordinator(
        $connection,
        new FiscalIssuanceService($connection, $provider),
        new SalePaymentStatus($connection),
    );
}

/**
 * Un TOKEN del perfil fiscal.
 */
function tokenDelPerfil(int $profileId, string $clave, string $valor): void
{
    DB::table('fiscal_profile_fields')->insert([
        'fiscal_profile_id' => $profileId,
        'key' => $clave,
        'data_type' => 'string',
        'label' => $clave,
        'value' => $valor,
        'required' => false,
        'applies_to' => 'document',
        'evidence_status' => 'verified',
        'source_ref' => 'REF-PRUEBA',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * 1.8 — el EMISOR es la tienda, con su inscripcion fiscal primaria.
 *
 * REGLA que fija: *el comprobante declara quien emite*. El emisor es la
 * tienda: su nombre, su domicilio y el identificativo fiscal de su
 * inscripcion primaria.
 */
test('test_el_comprobante_declara_el_emisor_con_la_inscripcion_fiscal_de_la_tienda', function () {
    $escenario = escenarioComprobante();

    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    $parties = DB::table('fiscal_document_parties')
        ->where('fiscal_document_id', $documentId)
        ->orderBy('role')
        ->get();

    expect($parties)->toHaveCount(2);

    $emisor = $parties->firstWhere('role', 'issuer');

    expect($emisor)->not->toBeNull()
        ->and((string) $emisor->name)->toBe('Tienda de la emision')
        ->and((string) $emisor->address)->toBe('Calle 1, Caracas')
        ->and((string) $emisor->fiscal_id)->toBe('J-12345678-9')
        ->and((string) $emisor->fiscal_id_kind)->toBe('rif');
});

/**
 * 1.8 — una tienda SIN inscripcion emite sin identificativo, y el hueco se ve.
 *
 * REGLA que fija: *el hueco se ve, no se inventa*. Una tienda sin inscripcion
 * fiscal no tiene identificativo que declarar, y el comprobante sale sin
 * el: inventar uno seria afirmar un dato que nadie ha verificado.
 */
test('test_una_tienda_sin_inscripcion_fiscal_emite_sin_identificador', function () {
    $escenario = escenarioComprobante(['con_inscripcion' => false]);

    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    $emisor = DB::table('fiscal_document_parties')
        ->where('fiscal_document_id', $documentId)
        ->where('role', 'issuer')
        ->first();

    expect($emisor)->not->toBeNull()
        ->and((string) $emisor->name)->toBe('Tienda de la emision')
        ->and($emisor->fiscal_id)->toBeNull()
        ->and($emisor->fiscal_id_kind)->toBeNull();
});

/**
 * 1.8 — el RECEPTOR es el cliente de la venta.
 *
 * REGLA que fija: *el comprobante declara quien recibe*. El receptor es el
 * cliente: su nombre, su documento y su domicilio.
 *
 * Y SU TIPO DE IDENTIFICATIVO es `rif` POR DEFECTO: el POS no distingue un
 * RIF de una cedula, porque el cliente tiene un unico campo de documento. Es
 * una LIMITACION del modelo de datos, no una regla del pais.
 */
test('test_el_comprobante_declara_el_receptor_con_los_datos_del_cliente', function () {
    $escenario = escenarioComprobante();

    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    $receptor = DB::table('fiscal_document_parties')
        ->where('fiscal_document_id', $documentId)
        ->where('role', 'receiver')
        ->first();

    expect($receptor)->not->toBeNull()
        ->and((string) $receptor->name)->toBe('Cliente de la emision')
        ->and((string) $receptor->fiscal_id)->toBe('V-12345678')
        ->and((string) $receptor->fiscal_id_kind)->toBe('rif')
        ->and((string) $receptor->address)->toBe('Urb. Los Pinos, Caracas');
});

/**
 * 1.8 — sin cliente, el receptor es el CONSUMIDOR FINAL que declara el perfil.
 *
 * REGLA que fija: *el consumidor final es un token del perfil*. Su nombre no
 * es un literal del codigo, y si el perfil no lo declara NO hay receptor: un
 * nombre inventado es peor que un hueco.
 */
test('test_sin_cliente_el_receptor_es_el_consumidor_final_que_declara_el_perfil', function () {
    $escenario = escenarioComprobante(['con_cliente' => false]);

    // El perfil NO declara el consumidor final: no hay receptor.
    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    expect(DB::table('fiscal_document_parties')
        ->where('fiscal_document_id', $documentId)
        ->where('role', 'receiver')
        ->count())->toBe(0);

    // Y con el token declarado, el receptor es el consumidor final.
    tokenDelPerfil($escenario['profile_id'], 'final_consumer_name', 'Consumidor Final');

    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    $receptor = DB::table('fiscal_document_parties')
        ->where('fiscal_document_id', $documentId)
        ->where('role', 'receiver')
        ->first();

    expect($receptor)->not->toBeNull()
        ->and((string) $receptor->name)->toBe('Consumidor Final')
        ->and($receptor->fiscal_id)->toBeNull();
});

/**
 * 1.8 — un reintento NO duplica las partes.
 *
 * REGLA que fija: *las partes se escriben UNA vez por documento*. La tabla
 * tiene UNIQUE en `(fiscal_document_id, role)`, asi que un reintento no
 * puede dejar dos emisores: actualiza el que hay.
 */
test('test_un_reintento_de_emision_no_duplica_las_partes_del_comprobante', function () {
    $escenario = escenarioComprobante();

    $coordinator = servicioComprobante(new FakeInvoiceProvider);

    $documentId = $coordinator->issue($escenario['sale_id']);

    expect(DB::table('fiscal_document_parties')->where('fiscal_document_id', $documentId)->count())
        ->toBe(2);

    // El reintento: el mismo documento, con el mismo numero.
    $otroDocumentId = $coordinator->issue($escenario['sale_id']);

    expect($otroDocumentId)->toBe($documentId)
        ->and(DB::table('fiscal_document_parties')->where('fiscal_document_id', $documentId)->count())
        ->toBe(2)
        ->and(DB::table('fiscal_document_parties')->where('role', 'issuer')->count())->toBe(1)
        ->and(DB::table('fiscal_document_parties')->where('role', 'receiver')->count())->toBe(1);
});

/**
 * 1.8 — la plantilla trae las LINEAS de la venta.
 *
 * REGLA que fija: *un comprobante sin lineas no es un comprobante*. Las
 * lineas son las de la venta, con su descripcion, su cantidad, su precio, su
 * descuento, su impuesto y su total.
 */
test('test_la_plantilla_del_comprobante_trae_las_lineas_de_la_venta', function () {
    $escenario = escenarioComprobante();

    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    $template = ReceiptTemplate::fromDocument(DB::connection(), $documentId);

    expect($template->lines)->toHaveCount(1);

    $linea = $template->lines[0];

    expect($linea['description'])->toBe('Producto del comprobante')
        ->and($linea['quantity'])->toBe('2.0000')
        ->and($linea['unit_price'])->toBe('25.0000')
        ->and($linea['discount'])->toBe('0.0000')
        ->and($linea['tax_amount'])->toBe('8.0000')
        ->and($linea['amount'])->toBe('58.0000')
        ->and($linea['exempt'])->toBeFalse();
});

/**
 * 1.8 — la DENOMINACION es la del perfil, no el tipo crudo.
 *
 * REGLA que fija: *la denominacion del documento la declara el perfil*. El
 * `document_type` es el codigo interno del tipo de documento, no el nombre
 * que lleva el comprobante.
 */
test('test_la_denominacion_del_comprobante_es_la_del_perfil_y_no_el_tipo_crudo', function () {
    $escenario = escenarioComprobante();

    // Sin token, se cae al tipo del documento: es lo que se hacia antes.
    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    expect(ReceiptTemplate::fromDocument(DB::connection(), $documentId)->denomination)->toBe('invoice');

    // Con el token, la denominacion es la que declara el perfil.
    tokenDelPerfil($escenario['profile_id'], 'document_denomination', 'Factura');

    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    expect(ReceiptTemplate::fromDocument(DB::connection(), $documentId)->denomination)->toBe('Factura');
});

/**
 * 1.8 — la HORA sale del instante de emision, no de la medianoche.
 *
 * REGLA que fija: *el comprobante lleva la hora a la que salio*. La fecha es
 * la del documento (la de negocio de la venta) y la hora es la del instante
 * de la emision, en la zona de la tienda.
 */
test('test_la_hora_del_comprobante_sale_del_instante_de_emision_y_no_de_la_medianoche', function () {
    $escenario = escenarioComprobante();

    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    // El instante de la emision, en la zona de la tienda (America/Caracas):
    // las 15:30 UTC son las 11:30 en Caracas.
    DB::table('fiscal_documents')->where('id', $documentId)->update([
        'issued_at' => '2026-11-04 15:30:45+00',
    ]);

    $template = ReceiptTemplate::fromDocument(DB::connection(), $documentId);

    expect($template->issuedAt->format('d/m/Y'))->toBe('04/11/2026')
        ->and($template->issuedAt->format('H:i:s'))->toBe('11:30:45')
        ->and($template->issuedAt->getTimezone()->getName())->toBe('America/Caracas');

    // Y el comprobante RENDERIZADO lo dice, con la hora y su a.m/p.m.
    $html = (new ReceiptRenderer)->render($template);

    expect($html)->toContain('04/11/2026')
        ->and($html)->toContain('11.30.45 a.m');
});

/**
 * 1.8 — el comprobante renderizado lleva el numero, el control, el total y la
 * base imponible.
 *
 * REGLA que fija: *el comprobante cuenta la venta*. El numero de documento,
 * el numero de control, la base imponible y el valor total son los del
 * documento emitido.
 */
test('test_el_comprobante_renderizado_lleva_el_numero_el_control_el_total_y_la_base_imponible', function () {
    $escenario = escenarioComprobante();

    $documentId = servicioComprobante(new FakeInvoiceProvider)->issue($escenario['sale_id']);

    $html = (new ReceiptRenderer)->render(ReceiptTemplate::fromDocument(DB::connection(), $documentId));

    // 1. El numero de documento y el de control.
    expect($html)->toContain('N&#186; 100')
        ->and($html)->toContain('N&#186; Control 00-00000001');

    // 2. La base imponible y el impuesto, discriminados por alicuota.
    expect($html)->toContain('Base: 50.0000')
        ->and($html)->toContain('16%')
        ->and($html)->toContain('8.0000');

    // 3. Y el valor total.
    expect($html)->toContain('Total: 58.0000');

    // 4. Y las PARTES, con sus identificativos.
    expect($html)->toContain('Tienda de la emision')
        ->and($html)->toContain('J-12345678-9')
        ->and($html)->toContain('Cliente de la emision')
        ->and($html)->toContain('V-12345678');

    // 5. Y las LINEAS de la venta.
    expect($html)->toContain('Producto del comprobante')
        ->and($html)->toContain('2.0000')
        ->and($html)->toContain('58.0000');
});
