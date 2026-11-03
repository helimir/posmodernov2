<?php

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerStore;
use App\Models\PaymentMethod;
use App\Models\PosTemplate;
use App\Models\Product;
use App\Models\ProductStore;
use App\Models\PurchaseItem;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\PurchaseTotal;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SaleTotal;
use App\Models\SupplierStore;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.4.6-T — los modelos no usan float para importes.
 *
 * Regla que fija: *todo importe es `numeric(25,4)`*. Un `float` no es un
 * dinero: 0.1 + 0.2 no es 0.3 y el redondeo se lleva por delante de la
 * factura.
 *
 * Este slice cubre los modelos del catálogo, los bridges y los clientes; los
 * de venta y compra llegan con M11–M13 y este mismo test los cubrira.
 */
uses(WithoutWrappingTransaction::class);

test('test_los_modelos_de_venta_y_compra_no_usan_float_para_importes', function () {
    /** @var array<class-string, list<string>> $modelos */
    $modelos = [
        ProductStore::class => ['purchase_price', 'sell_price', 'quantity_in_stock'],
        CustomerStore::class => ['balance', 'due'],
        SupplierStore::class => ['balance', 'due'],
        Customer::class => ['credit_limit'],
        SaleItem::class => ['quantity', 'unit_price', 'discount', 'tax_rate_snapshot', 'tax_amount'],
        SaleTotal::class => ['sub_total', 'discount', 'tax_total', 'rounding_adjustment', 'grand_total'],
        SalePayment::class => ['amount'],
        SaleReturn::class => ['total'],
        SaleReturnItem::class => ['quantity', 'amount'],
        PurchaseItem::class => ['quantity', 'unit_price', 'discount', 'tax_rate_snapshot', 'tax_amount'],
        PurchaseTotal::class => ['sub_total', 'discount', 'tax_total', 'rounding_adjustment', 'grand_total'],
        PurchasePayment::class => ['amount'],
        PurchaseReturn::class => ['total'],
        PurchaseReturnItem::class => ['quantity', 'amount'],
        CustomerLedgerEntry::class => ['amount', 'balance_after'],
    ];

    foreach ($modelos as $modelo => $columnas) {
        $casts = (new $modelo)->getCasts();

        foreach ($columnas as $columna) {
            expect($casts[$columna] ?? null)
                ->toBe('decimal:4', "{$modelo}.{$columna} no se castea a decimal:4")
                ->and($casts[$columna] ?? null)
                ->not->toBe('float', "{$modelo}.{$columna} usa float para un importe");
        }
    }

    // El JSON es `array`, no texto: las reglas por pais son datos.
    expect((new PosTemplate)->getCasts()['template_content'])->toBe('array')
        ->and((new PaymentMethod)->getCasts()['country_rules'])->toBe('array');

    // Y las relaciones FK estan declaradas.
    expect(method_exists(Product::class, 'taxCategory'))->toBeTrue()
        ->and(method_exists(ProductStore::class, 'store'))->toBeTrue()
        ->and(method_exists(CustomerStore::class, 'customer'))->toBeTrue()
        ->and(method_exists(SupplierStore::class, 'supplier'))->toBeTrue();
});
