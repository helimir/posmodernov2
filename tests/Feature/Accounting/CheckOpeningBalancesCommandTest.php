<?php

use App\Models\BankAccount;
use App\Models\BankAccountStore;
use App\Models\Customer;
use App\Models\CustomerStore;
use App\Models\OpeningBalance;
use App\Models\Product;
use App\Models\ProductStore;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierStore;
use App\Models\User;

/**
 * 0.7.6-T / 0.7.6 — el arranque operativo BLOQUEADO sin saldos certificados.
 *
 * Reglas que fija:
 *  - sin saldos de apertura firmados no hay cifras defendibles: el comando
 *    `ops:check-opening-balances` sale con codigo distinto de cero mientras
 *    falte la certificacion, y NOMBRA lo que falta;
 *  - la certificacion es un ACTO HUMANO: `certified_at` (fecha) y
 *    `certified_by` (responsable nominal). El comando COMPRUEBA, no
 *    certifica: es de SOLO LECTURA;
 *  - el stock se comprueba con `product_stores.quantity_in_stock`, el cache
 *    que la caja ve y con el que la direccion financiera reconcilia.
 */
test('test_el_arranque_operativo_queda_bloqueado_hasta_que_exista_un_saldo_certificado', function () {
    $tienda = Store::create(['name' => 'Sucursal Centro']);
    $producto = Product::create(['code' => 'CAFE-001', 'name' => 'Café molido']);

    ProductStore::create([
        'product_id' => $producto->id,
        'store_id' => $tienda->id,
        'quantity_in_stock' => '12.0000',
    ]);

    // SIN certificacion, el arranque operativo queda BLOQUEADO y la salida
    // NOMBRA el saldo que falta.
    $this->artisan('ops:check-opening-balances')
        ->assertExitCode(1)
        ->expectsOutputToContain('[saldo de stock] Tienda: Sucursal Centro | Producto: Café molido');

    // Con el saldo CERTIFICADO (fecha y responsable nominal), el arranque pasa.
    $responsable = User::factory()->create();

    OpeningBalance::create([
        'store_id' => $tienda->id,
        'kind' => 'stock',
        'product_id' => $producto->id,
        'amount' => '12.0000',
        'as_of' => '2026-11-01',
        'certified_at' => now(),
        'certified_by' => $responsable->id,
    ]);

    $this->artisan('ops:check-opening-balances')
        ->assertExitCode(0)
        ->expectsOutputToContain('Saldos de apertura comprobados: 1');
});

test('test_el_comando_sale_con_codigo_de_error_y_mensaje_explicito', function () {
    $tienda = Store::create(['name' => 'Sucursal Norte']);
    $cliente = Customer::create(['name' => 'Ana Pérez']);

    // La cliente debe 150: su saldo exige un saldo de apertura CERTIFICADO.
    CustomerStore::create([
        'customer_id' => $cliente->id,
        'store_id' => $tienda->id,
        'balance' => '150.0000',
    ]);

    $this->artisan('ops:check-opening-balances')
        ->assertExitCode(1)
        ->expectsOutputToContain('[saldo de cliente] Tienda: Sucursal Norte | Cliente: Ana Pérez');
});

test('test_el_comando_pasa_cuando_todos_los_saldos_estan_certificados', function () {
    $responsable = User::factory()->create();
    $tienda = Store::create(['name' => 'Sucursal Sur']);

    $producto = Product::create(['code' => 'AZUCAR-001', 'name' => 'Azúcar refinada']);
    $cliente = Customer::create(['name' => 'Luis Gómez']);
    $proveedor = Supplier::create(['name' => 'Distribuidora del Centro']);
    $cuenta = BankAccount::create(['name' => 'Cuenta corriente principal']);

    // Los CUATRO saldos que exigen certificacion: stock por producto, saldo y
    // deuda de cliente, saldo y deuda de proveedor, y saldo de caja por
    // cuenta y tienda.
    ProductStore::create([
        'product_id' => $producto->id,
        'store_id' => $tienda->id,
        'quantity_in_stock' => '5.0000',
    ]);

    CustomerStore::create([
        'customer_id' => $cliente->id,
        'store_id' => $tienda->id,
        'due' => '25.0000',
    ]);

    SupplierStore::create([
        'supplier_id' => $proveedor->id,
        'store_id' => $tienda->id,
        'balance' => '40.0000',
    ]);

    BankAccountStore::create([
        'bank_account_id' => $cuenta->id,
        'store_id' => $tienda->id,
    ]);

    $certificado = [
        'store_id' => $tienda->id,
        'amount' => '0.0000',
        'as_of' => '2026-11-01',
        'certified_at' => now(),
        'certified_by' => $responsable->id,
    ];

    OpeningBalance::create($certificado + ['kind' => 'stock', 'product_id' => $producto->id]);
    OpeningBalance::create($certificado + ['kind' => 'customer_balance', 'customer_id' => $cliente->id]);
    OpeningBalance::create($certificado + ['kind' => 'supplier_balance', 'supplier_id' => $proveedor->id]);
    OpeningBalance::create($certificado + ['kind' => 'cash', 'bank_account_id' => $cuenta->id]);

    $this->artisan('ops:check-opening-balances')
        ->assertExitCode(0)
        ->expectsOutputToContain('Saldos de apertura comprobados: 4');
});
