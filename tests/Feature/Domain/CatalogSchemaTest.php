<?php

use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 0.4.1-T — el producto apunta a una categoría fiscal, no a un porcentaje.
 *
 * Regla que fija: *el tratamiento fiscal del producto es una categoría*. El
 * legacy guardaba el PORCENTAJE en el producto, que duplicaba el dato y
 * quedaba viejo cuando la tasa cambiaba.
 */
uses(WithoutWrappingTransaction::class);

test('test_el_producto_apunta_a_una_categoria_fiscal_y_no_a_un_porcentaje', function () {
    $columns = Schema::getColumnListing('products');

    expect($columns)->toContain('tax_category_id')
        ->and($columns)->not->toContain('tax_rate')
        ->and($columns)->not->toContain('taxrate_id')
        ->and($columns)->not->toContain('tax_percentage')
        ->and($columns)->not->toContain('tax_rate_id');

    // El maestro del catálogo no guarda el estado por tienda.
    expect($columns)->not->toContain('purchase_price')
        ->and($columns)->not->toContain('sell_price')
        ->and($columns)->not->toContain('quantity_in_stock');

    // Y el precio, en el bridge, es numérico: un float no es un dinero.
    $bridge = Schema::getColumnListing('product_stores');

    expect($bridge)->toContain('purchase_price')
        ->and($bridge)->toContain('sell_price')
        ->and($bridge)->toContain('quantity_in_stock')
        ->and($bridge)->toContain('store_id')
        ->and($bridge)->toContain('status')
        ->and($bridge)->toContain('sort_order');

    // El JSON, no el blob.
    expect(Schema::getColumnType('pos_templates', 'template_content'))->toBe('jsonb')
        ->and(Schema::getColumnType('payment_methods', 'country_rules'))->toBe('jsonb');
});
