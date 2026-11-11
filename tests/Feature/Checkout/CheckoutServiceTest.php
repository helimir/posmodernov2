<?php

use App\Domain\Accounting\LedgerWriter;
use App\Domain\Checkout\CheckoutInput;
use App\Domain\Checkout\CheckoutLineInput;
use App\Domain\Checkout\CheckoutPaymentInput;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Fiscal\ControlNumberGenerator;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Fiscal\TaxRateResolver;
use App\Domain\Inventory\LotConsumer;
use App\Domain\Inventory\StockInvariant;
use App\Domain\Sales\SalePaymentStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FiscalProfile;
use App\Models\FiscalProvider;
use App\Models\Product;
use App\Models\ProductStore;
use App\Models\Store;
use App\Models\TaxCategory;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.6.5 / 0.6.5-T — el checkout es UNA unidad atomica.
 *
 * Reglas que fija:
 *  - toda escritura del checkout es una unidad atomica: si algo falla a
 *    mitad, NO queda cabecera, ni linea, ni total, ni pago, ni cambio de
 *    stock, NI un numero quemado (design §B.1, `esquema-dominio` §"Atomicidad
 *    de toda escritura");
 *  - la reserva del numero de CONTROL va DENTRO de la misma transaccion que
 *    la del numero de documento: si el rango de control esta agotado, la
 *    excepcion revierte T1 entera y el numero del documento TAMBIEN vuelve;
 *  - una venta completa deja cabecera, items con la tasa CONGELADA, totales
 *    que cuadran, el pago y el asiento contable, y el numero reservado con
 *    su rol;
 *  - el redondeo que no cuadra va al ingreso: es parte de lo cobrado, y el
 *    asiento sigue cuadrando.
 *
 * El test NO envuelve el caso en la transaccion de `RefreshDatabase`: usa
 * transacciones REALES y verifica el resultado desde la SEGUNDA sesion
 * (`pgsql_b`), que no ve lo no confirmado. Asi se prueba que el numero
 * VUELVE de verdad y no solo en la vista de la sesion que reservo.
 */
uses(WithoutWrappingTransaction::class);

/**
 * El escenario de una venta: tienda, perfil fiscal, serie de documento,
 * serie de control, un producto con stock y una tasa vigente.
 *
 * @param  bool  $controlAgotado  La serie de control sin cupo.
 * @return array<string, int>
 */
function escenarioCheckout(bool $controlAgotado = false): array
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
    ]);

    $provider = FiscalProvider::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'PRINTER',
        'name' => 'Imprenta de prueba',
        'kind' => 'printer',
    ]);

    $store = Store::create(['name' => 'Tienda del checkout']);

    // La serie del DOCUMENTO: el correlativo del emisor.
    $documentSeriesId = DB::table('fiscal_series')->insertGetId([
        'fiscal_profile_id' => $profile->id,
        'store_id' => $store->id,
        'provider_id' => $provider->id,
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'prefix' => 'ZZ',
        'next_number' => 100,
        'from_number' => 100,
        'to_number' => 200,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // La serie de CONTROL: la de la imprenta. Sin cupo si esta agotada:
    // `next_number` por encima de `to_number` no puede repartir numero.
    $controlSeriesId = DB::table('fiscal_series')->insertGetId([
        'fiscal_profile_id' => $profile->id,
        'store_id' => $store->id,
        'provider_id' => $provider->id,
        'series_role' => 'control',
        'assigned_by' => 'printer',
        'prefix' => null,
        'next_number' => $controlAgotado ? 11 : 1,
        'from_number' => 1,
        'to_number' => 10,
        'control_identifier' => '00',
        'sequence_length' => 8,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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
        'code' => 'ZZP-CHECKOUT-1',
        'name' => 'Producto del checkout',
        'tax_category_id' => $category->id,
        'type' => 'product',
    ]);

    // El puente NO tiene override de categoria: la del producto.
    ProductStore::create([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'tax_category_id' => null,
        'purchase_price' => 10,
        'sell_price' => 20,
        'quantity_in_stock' => 10,
    ]);

    // Un lote de 10 unidades: el FIFO tiene de donde tomar.
    $purchaseId = DB::table('purchases')->insertGetId([
        'store_id' => $store->id,
        'purchase_date' => '2026-11-03',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $purchaseItemId = DB::table('purchase_items')->insertGetId([
        'purchase_id' => $purchaseId,
        'product_id' => $product->id,
        'store_id' => $store->id,
        'quantity' => 10,
        'unit_price' => 10,
        'discount' => 0,
        'tax_rate_snapshot' => 16,
        'tax_amount' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('inventory_lots')->insert([
        'product_id' => $product->id,
        'store_id' => $store->id,
        'purchase_item_id' => $purchaseItemId,
        'quantity_received' => 10,
        'quantity_consumed' => 0,
        'quantity_returned' => 0,
        'unit_cost' => 10,
        'received_at' => '2026-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $paymentMethodId = DB::table('payment_methods')->insertGetId([
        'code' => 'ZZPAGO-1',
        'name' => 'Efectivo de prueba',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'store_id' => (int) $store->id,
        'profile_id' => (int) $profile->id,
        'document_series_id' => $documentSeriesId,
        'control_series_id' => $controlSeriesId,
        'tax_category_id' => (int) $category->id,
        'tax_rate_id' => $taxRateId,
        'product_id' => (int) $product->id,
        'payment_method_id' => $paymentMethodId,
    ];
}

/**
 * El servicio del checkout, con TODOS sus colaboradores en la misma
 * conexion: es lo que hace que la transaccion de T1 sea una sola.
 */
function servicioCheckout(): CheckoutService
{
    $connection = DB::connection();

    return new CheckoutService(
        $connection,
        new NumberSeries($connection),
        new ControlNumberGenerator($connection, new NumberSeries($connection)),
        new TaxRateResolver($connection),
        new LotConsumer($connection, new StockInvariant($connection)),
        new LedgerWriter($connection),
        new SalePaymentStatus($connection),
        new CustomerBalanceService($connection),
    );
}

/**
 * El mapa de roles a cuentas contables: es DATO de la entrada, no un
 * literal del dominio.
 *
 * @return array<string, string>
 */
function cuentasCheckout(): array
{
    return [
        'revenue' => '4010000',
        'tax_payable' => '2110000',
        'cash' => '1100000',
        'receivable' => '1200000',
    ];
}

/**
 * Una venta de mostrador: 2 unidades a 25, con un 16% de impuesto.
 *
 * @param  array<string, int>  $escenario
 */
function ventaCheckout(array $escenario, ?int $customerId = null): CheckoutInput
{
    return new CheckoutInput(
        storeId: $escenario['store_id'],
        customerId: $customerId,
        documentSeriesId: $escenario['document_series_id'],
        controlSeriesId: $escenario['control_series_id'],
        lines: [
            new CheckoutLineInput(
                productId: $escenario['product_id'],
                quantity: '2',
                unitPrice: '25.0000',
            ),
        ],
        payments: [
            new CheckoutPaymentInput(
                paymentMethodId: $escenario['payment_method_id'],
                amount: '58.0000',
            ),
        ],
        accounts: cuentasCheckout(),
        issuedAt: new DateTimeImmutable('2026-11-04T10:15:00-04:00'),
        businessDate: new DateTimeImmutable('2026-11-04T00:00:00-04:00'),
    );
}

beforeEach(function () {
    DB::statement(
        'TRUNCATE users, currencies, countries, stores, products, customers, payment_methods'
        .' RESTART IDENTITY CASCADE'
    );
});

afterEach(function () {
    DB::statement(
        'TRUNCATE users, currencies, countries, stores, products, customers, payment_methods'
        .' RESTART IDENTITY CASCADE'
    );
});

/**
 * 0.6.5-T — un fallo intermedio no deja cabecera, ni cambio de stock, ni
 * numeros quemados.
 */
test('test_un_fallo_intermedio_no_deja_cabecera_ni_cambio_de_stock_persistidos', function () {
    // La serie de CONTROL esta agotada: su reserva va a fallar.
    $escenario = escenarioCheckout(controlAgotado: true);
    $service = servicioCheckout();

    expect(fn () => $service->checkout(ventaCheckout($escenario)))
        ->toThrow(RuntimeException::class);

    // La SEGUNDA sesion no ve lo no confirmado: si algo se hubiera escrito,
    // lo veria aqui. Y no ve nada.
    $otra = DB::connection('pgsql_b');

    expect($otra->table('sales')->count())->toBe(0)
        ->and($otra->table('sale_items')->count())->toBe(0)
        ->and($otra->table('sale_totals')->count())->toBe(0)
        ->and($otra->table('sale_payments')->count())->toBe(0)
        ->and($otra->table('sale_item_lot_consumptions')->count())->toBe(0)
        ->and($otra->table('ledger_entries')->count())->toBe(0)
        ->and($otra->table('ledger_lines')->count())->toBe(0);

    // El stock NO cambio: ni el lote, ni su cache.
    expect((float) $otra->table('inventory_lots')
        ->where('product_id', $escenario['product_id'])->value('available'))->toBe(10.0)
        ->and((float) $otra->table('product_stores')
            ->where('product_id', $escenario['product_id'])->value('quantity_in_stock'))->toBe(10.0);

    // Y el numero del documento VOLVIO: la serie sigue en su `next_number` y
    // no hay ninguna reserva. Ningun numero se quema.
    expect((int) $otra->table('fiscal_series')
        ->where('id', $escenario['document_series_id'])->value('next_number'))->toBe(100)
        ->and($otra->table('fiscal_document_reservations')
            ->where('series_id', $escenario['document_series_id'])->count())->toBe(0)
        ->and($otra->table('fiscal_document_reservations')->count())->toBe(0);

    // Y la siguiente reserva de la serie da el MISMO numero: no hay hueco.
    expect((new NumberSeries(DB::connection()))->reserve($escenario['document_series_id']))->toBe(100);
});

/**
 * 0.6.5 — el numero de control se reserva DENTRO de T1.
 *
 * Regla que fija: *el documento y su numero de control son un solo hecho*.
 * Cuando la venta falla MAS ADELANTE (no hay stock), el numero de control
 * vuelve con el del documento: si el control se reservara fuera de la
 * transaccion, su numero se quemaria aunque la venta no existiera.
 */
test('test_sin_stock_suficiente_la_venta_falla_y_tambien_vuelve_el_numero_de_control', function () {
    $escenario = escenarioCheckout();
    $service = servicioCheckout();

    // Se piden 50 unidades y solo hay 10 en el lote: el FIFO no puede.
    $input = new CheckoutInput(
        storeId: $escenario['store_id'],
        customerId: null,
        documentSeriesId: $escenario['document_series_id'],
        controlSeriesId: $escenario['control_series_id'],
        lines: [
            new CheckoutLineInput(
                productId: $escenario['product_id'],
                quantity: '50',
                unitPrice: '25.0000',
            ),
        ],
        payments: [
            new CheckoutPaymentInput(
                paymentMethodId: $escenario['payment_method_id'],
                amount: '1450.0000',
            ),
        ],
        accounts: cuentasCheckout(),
        issuedAt: new DateTimeImmutable('2026-11-04T10:15:00-04:00'),
        businessDate: new DateTimeImmutable('2026-11-04T00:00:00-04:00'),
    );

    expect(fn () => $service->checkout($input))
        ->toThrow(RuntimeException::class);

    $otra = DB::connection('pgsql_b');

    // Ni venta, ni linea, ni consumo de lote, ni cambio de stock.
    expect($otra->table('sales')->count())->toBe(0)
        ->and($otra->table('sale_items')->count())->toBe(0)
        ->and($otra->table('sale_totals')->count())->toBe(0)
        ->and($otra->table('sale_payments')->count())->toBe(0)
        ->and($otra->table('sale_item_lot_consumptions')->count())->toBe(0)
        ->and((float) $otra->table('inventory_lots')->value('available'))->toBe(10.0);

    // Y NINGUN numero se consumio: ni el del documento, ni el de control.
    expect((int) $otra->table('fiscal_series')
        ->where('id', $escenario['document_series_id'])->value('next_number'))->toBe(100)
        ->and((int) $otra->table('fiscal_series')
            ->where('id', $escenario['control_series_id'])->value('next_number'))->toBe(1)
        ->and($otra->table('fiscal_document_reservations')->count())->toBe(0);
});

/**
 * 0.6.6 — la venta ENLAZA su reserva con el numero de documento.
 *
 * Regla que fija: *la reserva del numero sabe a que venta pertenece*. Sin
 * ese enlace, el reintento de la emision fiscal (T2) no puede encontrar la
 * reserva que ya tiene y tendria que reservar un numero NUEVO: quemando un
 * correlativo por un fallo de la red.
 *
 * El enlace se ve desde la SEGUNDA sesion (`pgsql_b`), que no ve lo no
 * confirmado: asi se prueba que es real y no solo visible en la sesion que
 * reservo.
 */
test('test_la_venta_enlaza_su_reserva_de_documento_con_su_numero_de_venta', function () {
    $escenario = escenarioCheckout();
    $service = servicioCheckout();

    $result = $service->checkout(ventaCheckout($escenario));

    $otra = DB::connection('pgsql_b');

    // 1. La reserva del DOCUMENTO apunta a la venta.
    $reserva = $otra->table('fiscal_document_reservations')
        ->where('series_id', $escenario['document_series_id'])
        ->first();

    expect($reserva)->not->toBeNull()
        ->and($reserva->reference_type)->toBe('sale')
        ->and((int) $reserva->reference_id)->toBe($result->saleId);

    // 2. Y la del CONTROL NO se enlaza: T2 no la necesita, y el control
    //    compuesto no se puede deducir del texto.
    $control = $otra->table('fiscal_document_reservations')
        ->where('series_id', $escenario['control_series_id'])
        ->first();

    expect($control)->not->toBeNull()
        ->and($control->reference_type)->toBeNull()
        ->and($control->reference_id)->toBeNull();
});

/**
 * 0.6.5 — la venta completa deja cabecera, items, totales, pago y asiento.
 */
test('test_una_venta_completa_registra_cabecera_items_totales_pago_y_asiento', function () {
    $escenario = escenarioCheckout();
    $service = servicioCheckout();

    $result = $service->checkout(ventaCheckout($escenario));

    // 1. La cabecera: con su numero, su estado y su fecha de negocio.
    $sale = DB::table('sales')->where('id', $result->saleId)->first();

    expect($sale)->not->toBeNull()
        ->and((int) $sale->store_id)->toBe($escenario['store_id'])
        // El prefijo lo declara la serie, no el codigo.
        ->and($sale->invoice_no)->toBe('ZZ100')
        // El pago cubre el total: la venta esta pagada.
        ->and($sale->status)->toBe('paid')
        ->and((string) $sale->sale_date)->toBe('2026-11-04')
        // El documento fiscal lo cierra T2.
        ->and($sale->fiscal_document_id)->toBeNull()
        ->and($result->documentNumber)->toBe('ZZ100')
        ->and($result->status)->toBe('paid')
        ->and($result->grandTotal)->toBe('58.0000');

    // 2. El numero de control tambien se reservo, con su formato.
    expect($result->controlNumber)->toBe('00-00000001');

    // 3. El item lleva la tasa CONGELADA y la evidencia de esa tasa.
    $items = DB::table('sale_items')->where('sale_id', $result->saleId)->get();

    expect($items)->toHaveCount(1);

    $item = $items->first();

    expect((int) $item->product_id)->toBe($escenario['product_id'])
        ->and((int) $item->store_id)->toBe($escenario['store_id'])
        ->and((string) $item->quantity)->toBe('2.0000')
        ->and((string) $item->unit_price)->toBe('25.0000')
        ->and((int) $item->tax_category_id)->toBe($escenario['tax_category_id'])
        ->and((int) $item->tax_rate_id)->toBe($escenario['tax_rate_id'])
        ->and((string) $item->tax_rate_snapshot)->toBe('16.0000')
        ->and($item->evidence_status)->toBe('verified')
        ->and((string) $item->base_amount)->toBe('50.0000')
        ->and((string) $item->tax_amount)->toBe('8.0000')
        ->and((string) $item->line_total)->toBe('58.0000');

    // 4. Los totales CUADRAN: la suma de las lineas mas el ajuste es el total
    //    redondeado, y el total es el subtotal menos el descuento mas el
    //    impuesto mas el ajuste.
    $totals = DB::table('sale_totals')->where('sale_id', $result->saleId)->first();

    expect((string) $totals->sub_total)->toBe('50.0000')
        ->and((string) $totals->discount)->toBe('0.0000')
        ->and((string) $totals->tax_total)->toBe('8.0000')
        ->and((string) $totals->rounding_adjustment)->toBe('0.0000')
        ->and((string) $totals->grand_total)->toBe('58.0000')
        ->and($totals->evidence_status)->toBe('verified');

    $sumaLineas = '0';

    foreach ($items as $linea) {
        $sumaLineas = bcadd($sumaLineas, (string) $linea->line_total, 4);
    }

    expect(bccomp(bcadd($sumaLineas, (string) $totals->rounding_adjustment, 4), (string) $totals->grand_total, 4))->toBe(0)
        // Y el total es el subtotal menos el descuento mas el impuesto mas
        // el ajuste. Aqui el descuento de documento es cero: con descuento,
        // el `sub_total` del motor ya viene NETO de el, y la relacion es
        // `sub_total + tax_total + rounding_adjustment`.
        ->and(bccomp(
            bcadd(
                bcadd(
                    bcsub((string) $totals->sub_total, (string) $totals->discount),
                    (string) $totals->tax_total
                ),
                (string) $totals->rounding_adjustment
            ),
            (string) $totals->grand_total,
            4
        ))->toBe(0);

    // 5. El pago quedo con su importe y con lo APLICADO: lo recibido y lo
    //    que cubre de la venta son dos datos.
    $payments = DB::table('sale_payments')->where('sale_id', $result->saleId)->get();

    expect($payments)->toHaveCount(1)
        ->and((int) $payments->first()->payment_method_id)->toBe($escenario['payment_method_id'])
        ->and((string) $payments->first()->amount)->toBe('58.0000')
        ->and((string) $payments->first()->applied)->toBe('58.0000');

    // 6. El asiento contable: el tipo del catalogo, la referencia a la venta
    //    y las lineas CUADRAN con las cuentas del mapa de la entrada.
    $entry = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $result->saleId)
        ->first();

    expect($entry)->not->toBeNull();

    $entryTypeId = DB::table('ledger_entry_types')->where('code', 'sale')->value('id');

    expect((int) $entry->entry_type_id)->toBe((int) $entryTypeId)
        ->and($entry->reference_type)->toBe('sale')
        ->and((int) $entry->reference_id)->toBe($result->saleId)
        ->and((string) $entry->business_date)->toBe('2026-11-04');

    $lines = DB::table('ledger_lines')->where('ledger_entry_id', $entry->id)->orderBy('id')->get();

    // Sin cliente, el debito es de la caja; el ingreso y el impuesto se
    // acreditan.
    expect($lines->pluck('account_code')->all())->toBe(['1100000', '4010000', '2110000']);

    $debe = '0';
    $haber = '0';

    foreach ($lines as $linea) {
        $debe = bcadd($debe, (string) $linea->debit, 4);
        $haber = bcadd($haber, (string) $linea->credit, 4);
    }

    expect(bccomp($debe, $haber, 4))->toBe(0)
        ->and(bccomp($debe, '58.0000', 4))->toBe(0)
        ->and(bccomp((string) $lines[0]->debit, '58.0000', 4))->toBe(0)
        ->and(bccomp((string) $lines[1]->credit, '50.0000', 4))->toBe(0)
        ->and(bccomp((string) $lines[2]->credit, '8.0000', 4))->toBe(0);

    // 7. Y el numero reservado quedo con su rol.
    $reservation = DB::table('fiscal_document_reservations')
        ->where('series_id', $escenario['document_series_id'])
        ->first();

    expect($reservation)->not->toBeNull()
        ->and((int) $reservation->number)->toBe(100)
        ->and($reservation->series_role)->toBe('document');

    // 8. Y el stock bajo: el FIFO consumio del lote y actualizo su cache.
    expect((float) DB::table('inventory_lots')->value('available'))->toBe(8.0)
        ->and((float) DB::table('product_stores')->value('quantity_in_stock'))->toBe(8.0)
        ->and(DB::table('sale_item_lot_consumptions')->count())->toBe(1);
});

/**
 * 0.6.5 — el redondeo que no cuadra va al ingreso, y el asiento sigue
 * cuadrando.
 *
 * Regla que fija: *lo cobrado es lo que se registra*. Con un precio que no
 * cae entero, el total redondeado no es exactamente la base mas el
 * impuesto: esa diferencia es del ingreso, no un descuadre.
 */
test('test_el_redondeo_que_no_cuadra_va_al_ingreso_y_el_asiento_sigue_cuadrando', function () {
    $escenario = escenarioCheckout();
    $service = servicioCheckout();

    $customer = Customer::create(['name' => 'Cliente del checkout']);

    // 10.005 a un 16%: el total redondeado (11.61) no es exactamente la base
    // (10.0050) mas el impuesto (1.6008).
    $input = new CheckoutInput(
        storeId: $escenario['store_id'],
        customerId: (int) $customer->id,
        documentSeriesId: $escenario['document_series_id'],
        controlSeriesId: $escenario['control_series_id'],
        lines: [
            new CheckoutLineInput(
                productId: $escenario['product_id'],
                quantity: '1',
                unitPrice: '10.005',
            ),
        ],
        payments: [
            new CheckoutPaymentInput(
                paymentMethodId: $escenario['payment_method_id'],
                amount: '11.6100',
                // El TIPO del medio de pago es DATO de la entrada: el dominio
                // no consulta la base de datos para saberlo.
                paymentMethodType: 'cash',
            ),
        ],
        accounts: cuentasCheckout(),
        issuedAt: new DateTimeImmutable('2026-11-04T10:15:00-04:00'),
        businessDate: new DateTimeImmutable('2026-11-04T00:00:00-04:00'),
    );

    $result = $service->checkout($input);

    $totals = DB::table('sale_totals')->where('sale_id', $result->saleId)->first();

    expect((string) $totals->sub_total)->toBe('10.0050')
        ->and((string) $totals->tax_total)->toBe('1.6008')
        ->and((string) $totals->grand_total)->toBe('11.6100')
        ->and((string) $totals->rounding_adjustment)->toBe('0.0000');

    // El asiento tiene CUATRO lineas: con cliente pagada en efectivo, el
    // debito es de la CAJA (lo cobrado), NO hay linea de cuenta por cobrar
    // (lo que quedo a credito es cero), y el redondeo va al ingreso.
    $entry = DB::table('ledger_entries')->where('reference_id', $result->saleId)->first();
    $lines = DB::table('ledger_lines')->where('ledger_entry_id', $entry->id)->orderBy('id')->get();

    expect($lines->pluck('account_code')->all())->toBe(['1100000', '4010000', '2110000', '4010000'])
        ->and(bccomp((string) $lines[0]->debit, '11.6100', 4))->toBe(0)
        ->and(bccomp((string) $lines[3]->credit, '0.0042', 4))->toBe(0);

    $debe = '0';
    $haber = '0';

    foreach ($lines as $linea) {
        $debe = bcadd($debe, (string) $linea->debit, 4);
        $haber = bcadd($haber, (string) $linea->credit, 4);
    }

    expect(bccomp($debe, $haber, 4))->toBe(0)
        ->and(bccomp($debe, '11.6100', 4))->toBe(0)
        ->and(DB::table('sales')->where('id', $result->saleId)->value('status'))->toBe('paid');
});

/**
 * 0.6.2-T — la suma de las lineas mas el ajuste de redondeo es el total
 * redondeado.
 *
 * Regla que fija: *el ajuste de redondeo es visible en el total*. Con dos
 * lineas cuyo redondeo no cuadra, la suma de las lineas NO es el total: la
 * diferencia es el AJUSTE, y se PERSISTE en `sale_totals.rounding_adjustment`
 * en vez de esconderse.
 */
test('test_la_suma_de_lineas_mas_el_ajuste_de_redondeo_iguala_el_total_redondeado', function () {
    $escenario = escenarioCheckout();
    $service = servicioCheckout();

    // Dos lineas de 10.005: cada una redondea a 11.61, y el total redondeado
    // del documento es 23.21. La suma de las lineas da 23.22: no cuadra, y la
    // diferencia es el ajuste.
    $input = new CheckoutInput(
        storeId: $escenario['store_id'],
        customerId: null,
        documentSeriesId: $escenario['document_series_id'],
        controlSeriesId: $escenario['control_series_id'],
        lines: [
            new CheckoutLineInput(
                productId: $escenario['product_id'],
                quantity: '1',
                unitPrice: '10.005',
            ),
            new CheckoutLineInput(
                productId: $escenario['product_id'],
                quantity: '1',
                unitPrice: '10.005',
            ),
        ],
        payments: [
            new CheckoutPaymentInput(
                paymentMethodId: $escenario['payment_method_id'],
                amount: '23.2100',
            ),
        ],
        accounts: cuentasCheckout(),
        issuedAt: new DateTimeImmutable('2026-11-04T10:15:00-04:00'),
        businessDate: new DateTimeImmutable('2026-11-04T00:00:00-04:00'),
    );

    $result = $service->checkout($input);

    $items = DB::table('sale_items')->where('sale_id', $result->saleId)->orderBy('id')->get();
    $totals = DB::table('sale_totals')->where('sale_id', $result->saleId)->first();

    // 1. Las dos lineas, con su redondeo.
    expect($items)->toHaveCount(2)
        ->and((string) $items[0]->line_total)->toBe('11.6100')
        ->and((string) $items[1]->line_total)->toBe('11.6100');

    $sumaLineas = '0';

    foreach ($items as $linea) {
        $sumaLineas = bcadd($sumaLineas, (string) $linea->line_total, 4);
    }

    // 2. La suma NO es el total: la diferencia es el ajuste, y el ajuste es
    //    VISIBLE (no vale cero y no se esconde).
    expect($sumaLineas)->toBe('23.2200')
        ->and((string) $totals->grand_total)->toBe('23.2100')
        ->and((string) $totals->rounding_adjustment)->toBe('-0.0100');

    // 3. Y la identidad se cumple EXACTA: las lineas mas el ajuste dan el
    //    total redondeado.
    expect(bcadd($sumaLineas, (string) $totals->rounding_adjustment, 4))
        ->toBe((string) $totals->grand_total);
});

/**
 * 0.6.3-T — un documento emitido conserva la tasa, la categoria y el estado
 * de evidencia CONGELADOS.
 *
 * Regla que fija: *el impuesto se persiste, no se recalcula*. El documento
 * emitido guarda el porcentaje, la categoria y la evidencia que se le
 * aplicaron; un cambio posterior de la tasa vigente NO lo altera.
 */
test('test_un_documento_emitido_conserva_la_tasa_la_categoria_y_el_estado_de_evidencia_congelados', function () {
    $escenario = escenarioCheckout();
    $service = servicioCheckout();

    $result = $service->checkout(ventaCheckout($escenario));

    $item = DB::table('sale_items')->where('sale_id', $result->saleId)->first();
    $totals = DB::table('sale_totals')->where('sale_id', $result->saleId)->first();

    // 1. El documento emitido lleva CONGELADOS la tasa, la categoria y la
    //    evidencia.
    expect((string) $item->tax_rate_snapshot)->toBe('16.0000')
        ->and((int) $item->tax_rate_id)->toBe($escenario['tax_rate_id'])
        ->and((int) $item->tax_category_id)->toBe($escenario['tax_category_id'])
        ->and((string) $item->evidence_status)->toBe('verified')
        ->and((string) $item->tax_amount)->toBe('8.0000')
        ->and((string) $totals->evidence_status)->toBe('verified')
        ->and((string) $totals->tax_total)->toBe('8.0000');

    // 2. Ahora cambia el CATALOGO: la tasa vigente cambia de porcentaje y de
    //    estado de evidencia, y la categoria tambien.
    DB::table('tax_rates')->where('id', $escenario['tax_rate_id'])->update([
        'percentage' => 20,
        'evidence_status' => 'pending_confirmation',
        'updated_at' => now(),
    ]);

    DB::table('tax_categories')->where('id', $escenario['tax_category_id'])->update([
        'evidence_status' => 'pending_confirmation',
        'updated_at' => now(),
    ]);

    // 3. El cambio es REAL: el catalogo ya no dice lo mismo.
    $tasa = DB::table('tax_rates')->where('id', $escenario['tax_rate_id'])->first();
    $categoria = DB::table('tax_categories')->where('id', $escenario['tax_category_id'])->first();

    expect((string) $tasa->percentage)->toBe('20.000000')
        ->and((string) $tasa->evidence_status)->toBe('pending_confirmation')
        ->and((string) $categoria->evidence_status)->toBe('pending_confirmation');

    // 4. Y el documento emitido NO se movio: el impuesto se persiste, no se
    //    recalcula.
    $item = DB::table('sale_items')->where('sale_id', $result->saleId)->first();
    $totals = DB::table('sale_totals')->where('sale_id', $result->saleId)->first();

    expect((string) $item->tax_rate_snapshot)->toBe('16.0000')
        ->and((int) $item->tax_rate_id)->toBe($escenario['tax_rate_id'])
        ->and((int) $item->tax_category_id)->toBe($escenario['tax_category_id'])
        ->and((string) $item->evidence_status)->toBe('verified')
        ->and((string) $item->tax_amount)->toBe('8.0000')
        ->and((string) $totals->evidence_status)->toBe('verified')
        ->and((string) $totals->tax_total)->toBe('8.0000');
});
