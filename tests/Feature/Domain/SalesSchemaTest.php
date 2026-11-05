<?php

use App\Domain\Fiscal\Enums\TaxMethod;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\FiscalProfile;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.4.5 — el esquema de ventas, extractos y compras.
 *
 * Reglas que fija:
 * - el porcentaje congelado y el importe del impuesto son columnas
 *   DISTINTAS (el legacy guardaba el % en `tax` y el importe en `item_tax`);
 * - `purchase_items` NO lleva `tax_method`: el método lo decide el perfil
 *   fiscal, no cada línea;
 * - el tipo del asiento del cliente tiene valores CANÓNICOS;
 * - una venta cuyo documento fiscal no salió queda `fiscal_failed`;
 * - todo importe es `numeric(25,4)`, nunca `float`.
 *
 * El nombre de este test es nuevo y queda pendiente de canonizar en §E.5
 * (0.9.6 unifica los nombres divergentes con los canónicos).
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    DB::statement(
        'TRUNCATE customer_ledger_entries, sales, customers, stores, fiscal_profiles,'
        .' countries, currencies RESTART IDENTITY CASCADE'
    );
});

test('test_la_linea_de_venta_separa_el_porcentaje_congelado_del_importe_del_impuesto', function () {
    // 1. El porcentaje congelado y el importe son columnas distintas.
    $saleItem = Schema::getColumnListing('sale_items');
    $purchaseItem = Schema::getColumnListing('purchase_items');

    expect($saleItem)->toContain('tax_rate_snapshot')
        ->and($saleItem)->toContain('tax_amount')
        ->and($saleItem)->not->toContain('tax')
        ->and($saleItem)->not->toContain('item_tax');

    // 2. El metodo del impuesto NO lo decide cada linea.
    expect($purchaseItem)->not->toContain('tax_method')
        ->and($purchaseItem)->toContain('tax_rate_snapshot')
        ->and($purchaseItem)->toContain('tax_amount');

    // 3. Los totales llevan el ajuste de redondeo, y es numerico.
    expect(Schema::getColumnType('sale_totals', 'rounding_adjustment'))->toBe('numeric')
        ->and(Schema::getColumnType('purchase_totals', 'rounding_adjustment'))->toBe('numeric');

    // 4. El tipo del asiento del cliente es canonico: un valor fuera del
    //    conjunto cerrado NO entra.
    $store = Store::create(['name' => 'Tienda de prueba']);
    $customer = Customer::create(['name' => 'Cliente de prueba']);

    $ledger = DB::table('customer_ledger_entries');
    $base = [
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'amount' => 10,
        'entry_date' => '2026-11-03',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    foreach (['sale', 'payment', 'return', 'adjustment'] as $canonico) {
        $ledger->insert($base + ['type' => $canonico]);
    }

    expect($ledger->count())->toBe(4);

    expect(fn () => $ledger->insert($base + ['type' => 'legacy_xyz']))->toThrow(QueryException::class);

    // 5. Una venta sin documento fiscal puede quedar en fiscal_failed.
    $sales = DB::table('sales');
    $saleBase = [
        'store_id' => $store->id,
        'sale_date' => '2026-11-03',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $sales->insert($saleBase + ['status' => 'fiscal_failed']);

    expect(fn () => $sales->insert($saleBase + ['status' => 'no_existe']))->toThrow(QueryException::class);

    // 6. Y ningun importe es float.
    foreach (['sale_items', 'sale_totals', 'sale_payments', 'purchase_items', 'purchase_totals', 'purchase_payments', 'customer_ledger_entries'] as $tabla) {
        foreach (Schema::getColumnListing($tabla) as $columna) {
            $tipo = Schema::getColumnType($tabla, $columna);

            if (in_array($columna, ['id', 'sale_id', 'purchase_id', 'product_id', 'store_id', 'customer_id',
                'supplier_id', 'payment_method_id', 'created_by', 'ref_invoice_id', 'invoice_id',
                'sale_item_id', 'purchase_item_id', 'tax_category_id', 'tax_rate_id',
                'fiscal_document_id', 'sort_order', 'tax_rate_snapshot'], true)) {
                continue;
            }

            expect($tipo)->not->toBe('double precision', "{$tabla}.{$columna} es un float");
        }
    }
});

/**
 * 0.4.5-T — el metodo del impuesto de la COMPRA lo decide el perfil fiscal.
 *
 * Regla que fija: *el motor fiscal es dato*. La linea de compra no dice COMO
 * se calcula su impuesto: el metodo sale del perfil fiscal
 * (`fiscal_profiles.tax_inclusive_default`). Ni la linea ni la tasa lo
 * declaran, porque si lo declararan habria dos fuentes de verdad para el
 * mismo dato.
 */
test('test_el_metodo_de_impuesto_de_la_compra_lo_decide_el_perfil_fiscal_y_no_la_linea', function () {
    // 1. La linea de compra NO declara el metodo del impuesto.
    $purchaseItem = Schema::getColumnListing('purchase_items');

    expect($purchaseItem)->not->toContain('tax_method')
        ->and($purchaseItem)->not->toContain('tax_inclusive')
        ->and($purchaseItem)->toContain('tax_rate_snapshot')
        ->and($purchaseItem)->toContain('tax_amount');

    // 2. Y la tasa vigente tampoco: el metodo no es de la tasa.
    expect(Schema::getColumnListing('tax_rates'))->not->toContain('method');

    // 3. El metodo sale del PERFIL: `tax_inclusive_default` lo decide, y el
    //    motor lo DERIVA de ese dato.
    $currency = Currency::create([
        'code' => 'ZQM',
        'name' => 'Moneda de prueba',
        'decimals' => 2,
    ]);

    $country = Country::create([
        'iso_code' => 'ZQ',
        'name' => 'Pais de prueba',
        'default_currency_id' => $currency->id,
    ]);

    $exclusivo = FiscalProfile::create([
        'country_id' => $country->id,
        'code' => 'ZQ_EXCL',
        'name' => 'Perfil con impuesto añadido',
        'signer_model' => 'printer',
        'rounding_rule' => 'line',
        'tax_inclusive_default' => false,
        'default_currency_id' => $currency->id,
    ]);

    $inclusivo = FiscalProfile::create([
        'country_id' => $country->id,
        'code' => 'ZQ_INCL',
        'name' => 'Perfil con impuesto incluido',
        'signer_model' => 'printer',
        'rounding_rule' => 'line',
        'tax_inclusive_default' => true,
        'default_currency_id' => $currency->id,
    ]);

    expect($exclusivo->taxContext($currency)->taxMethod())->toBe(TaxMethod::Exclusive)
        ->and($inclusivo->taxContext($currency)->taxMethod())->toBe(TaxMethod::Inclusive);
});
