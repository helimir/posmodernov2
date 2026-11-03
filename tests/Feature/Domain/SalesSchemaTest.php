<?php

use App\Models\Customer;
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

test('test_las_ventas_y_las_compras_separan_el_porcentaje_del_importe_y_no_guardan_el_metodo_del_impuesto', function () {
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
