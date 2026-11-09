<?php

use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleTotal;
use App\Models\Store;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * La costura en el NAVEGADOR: las ventas se ven.
 *
 * Reglas que fija:
 *
 *  - la pagina de ventas es de SOLO LECTURA: lista las ventas mas recientes
 *    (la primera es la ultima) y su detalle, con las lineas, los totales, los
 *    pagos y el asiento contable;
 *  - la pagina exige sesion: sin autenticar, REDIRIGE (no responde 200 con
 *    una pantalla vacia);
 *  - los importes viajan como TEXTO decimal: la columna es `numeric(25,4)` y
 *    un `float` no es un dinero.
 */

/**
 * Una venta de prueba, con su linea, sus totales y su pago.
 */
function venta_de_pagina(User $usuario): Sale
{
    $tienda = Store::create(['name' => 'Sucursal Centro']);

    $cliente = Customer::create(['name' => 'Cliente de prueba']);

    $producto = Product::create([
        'code' => 'HUMO-PAGINA-1',
        'name' => 'Producto de prueba',
    ]);

    $medio = PaymentMethod::create([
        'code' => 'HUMO-PAGO-1',
        'name' => 'Efectivo de prueba',
    ]);

    $venta = Sale::create([
        'store_id' => $tienda->id,
        'customer_id' => $cliente->id,
        'invoice_no' => 'HUMO-0001',
        'sale_date' => '2026-11-09',
        'status' => 'paid',
        'created_by' => $usuario->id,
    ]);

    SaleItem::create([
        'sale_id' => $venta->id,
        'product_id' => $producto->id,
        'store_id' => $tienda->id,
        'quantity' => '1.0000',
        'unit_price' => '20.0000',
        'discount' => '0.0000',
        'base_amount' => '20.0000',
        'tax_rate_snapshot' => '20.0000',
        'tax_amount' => '4.0000',
        'line_total' => '24.0000',
        'evidence_status' => 'verified',
    ]);

    SaleTotal::create([
        'sale_id' => $venta->id,
        'sub_total' => '20.0000',
        'discount' => '0.0000',
        'tax_total' => '4.0000',
        'rounding_adjustment' => '0.0000',
        'grand_total' => '24.0000',
        'evidence_status' => 'verified',
    ]);

    SalePayment::create([
        'sale_id' => $venta->id,
        'payment_method_id' => $medio->id,
        'amount' => '24.0000',
    ]);

    return $venta;
}

/**
 * 0.4.3-T — la lista de ventas responde 200 y DEVUELVE las ventas.
 */
test('test_la_pagina_de_ventas_responde_200_y_devuelve_las_ventas', function () {
    $usuario = User::factory()->create();
    $venta = venta_de_pagina($usuario);

    $this->actingAs($usuario)
        ->get('/ventas')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/index')
            ->has('sales', 1)
            ->where('sales.0.id', $venta->id)
            ->where('sales.0.invoice_no', 'HUMO-0001')
            ->where('sales.0.sale_date', '2026-11-09')
            ->where('sales.0.customer', 'Cliente de prueba')
            ->where('sales.0.status', 'paid')
            ->where('sales.0.grand_total', '24.0000')
            ->where('sales.0.items_count', 1)
            ->etc());
});

/**
 * 0.4.3-T — el detalle devuelve la venta con sus lineas y sus totales.
 */
test('test_la_pagina_de_detalle_devuelve_la_venta_con_sus_lineas_y_sus_totales', function () {
    $usuario = User::factory()->create();
    $venta = venta_de_pagina($usuario);

    $this->actingAs($usuario)
        ->get('/ventas/'.$venta->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('sales/show')
            ->where('sale.id', $venta->id)
            ->where('sale.invoice_no', 'HUMO-0001')
            ->where('sale.sale_date', '2026-11-09')
            ->where('sale.customer', 'Cliente de prueba')
            ->where('sale.status', 'paid')
            ->has('sale.lines', 1)
            ->where('sale.lines.0.product_name', 'Producto de prueba')
            ->where('sale.lines.0.quantity', '1.0000')
            ->where('sale.lines.0.unit_price', '20.0000')
            ->where('sale.lines.0.base_amount', '20.0000')
            ->where('sale.lines.0.tax_amount', '4.0000')
            ->where('sale.lines.0.line_total', '24.0000')
            ->where('sale.totals.sub_total', '20.0000')
            ->where('sale.totals.tax_total', '4.0000')
            ->where('sale.totals.rounding_adjustment', '0.0000')
            ->where('sale.totals.grand_total', '24.0000')
            ->has('sale.payments', 1)
            ->where('sale.payments.0.amount', '24.0000')
            ->etc());
});

/**
 * 0.4.3-T — sin sesion, la pagina REDIRIGE: no se sirve una pantalla de
 * ventas a quien no ha entrado.
 */
test('test_sin_autenticar_la_pagina_de_ventas_redirige', function () {
    $this->get('/ventas')->assertRedirect('/login');
    $this->get('/ventas/1')->assertRedirect('/login');
});
