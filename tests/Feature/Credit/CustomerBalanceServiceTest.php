<?php

use App\Domain\Credit\CustomerBalanceService;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;

/**
 * 0.6.7 / 0.6.7-T — el saldo del cliente se deriva de sus movimientos.
 *
 * Regla que fija: *el saldo del cliente se deriva de sus movimientos*. El
 * saldo NO es un contador: es la SUMA de los movimientos del cliente en esa
 * tienda. El signo lo lleva el importe, nunca el tipo: en
 * `customer_ledger_entries.amount` POSITIVO es lo que el cliente DEBE y
 * NEGATIVO es lo que ha pagado o devuelto.
 *
 * `customer_stores.balance` y `customer_stores.due` son un CACHE con
 * invariante, como `product_stores.quantity_in_stock` con los lotes: se
 * actualizan DESPUES de cada movimiento, a partir del saldo derivado.
 */

/**
 * 0.6.7-T — un cobro reduce la deuda y el saldo se deriva de los movimientos.
 */
test('test_un_cobro_reduce_la_deuda_del_cliente_y_el_saldo_se_deriva_de_sus_movimientos', function () {
    $storeId = CreditFixture::store();
    $customerId = CreditFixture::customer($storeId);

    $service = new CustomerBalanceService(DB::connection());

    // 1. Una VENTA genera deuda: el movimiento es POSITIVO, porque lo que el
    //    cliente DEBE. Es el movimiento del hecho, y lo escribe el checkout:
    //    el servicio de credito no lo inventa.
    DB::table('customer_ledger_entries')->insert([
        'customer_id' => $customerId,
        'store_id' => $storeId,
        'type' => 'sale',
        'reference_no' => 'VENTA-1',
        'amount' => '100.0000',
        'balance_after' => '100.0000',
        'entry_date' => '2026-11-04',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Un COBRO reduce la deuda: el movimiento es NEGATIVO, y su `type` es
    //    `payment`: el tipo NO lleva el signo.
    $entryId = $service->registerPayment(
        customerId: $customerId,
        storeId: $storeId,
        amount: '50.0000',
        referenceNo: 'RECIBO-1',
        entryDate: new DateTimeImmutable('2026-11-05'),
    );

    $cobro = DB::table('customer_ledger_entries')->where('id', $entryId)->first();

    expect((int) $entryId)->toBeGreaterThan(0)
        ->and($cobro->type)->toBe('payment')
        ->and((string) $cobro->amount)->toBe('-50.0000')
        ->and((string) $cobro->balance_after)->toBe('50.0000')
        ->and((string) $cobro->reference_no)->toBe('RECIBO-1')
        ->and((string) $cobro->entry_date)->toBe('2026-11-05');

    // 3. El saldo DERIVADO es la SUMA de los movimientos: 100,00 de la venta
    //    menos 50,00 del cobro.
    $suma = bcadd('100.0000', '-50.0000', 4);

    expect($service->balance($customerId, $storeId))->toBe('50.0000')
        ->and($service->balance($customerId, $storeId))->toBe($suma);

    // 4. Lo que DEBE es el max(0, saldo).
    expect($service->due($customerId, $storeId))->toBe('50.0000');

    // 5. Y el CACHE coincide con el saldo derivado: la invariante se sostiene.
    expect($service->holds($customerId, $storeId))->toBeTrue();

    $cache = DB::table('customer_stores')
        ->where('customer_id', $customerId)
        ->where('store_id', $storeId)
        ->first();

    expect((string) $cache->balance)->toBe('50.0000')
        ->and((string) $cache->due)->toBe('50.0000');
});

/**
 * 0.6.7 — el saldo se DERIVA: un movimiento que no pasa por el servicio
 * tambien lo mueve.
 *
 * Regla que fija: *el saldo es la verdad de los movimientos, no un contador*.
 * El legacy guardaba el saldo en el cliente Y en su ficha de tienda: dos
 * copias que se desincronizaban. Aqui hay UNA sola verdad (los movimientos) y
 * el bridge es su cache.
 */
test('test_el_saldo_se_deriva_de_los_movimientos_y_no_de_un_contador', function () {
    $storeId = CreditFixture::store();
    $customerId = CreditFixture::customer($storeId);

    $service = new CustomerBalanceService(DB::connection());

    // Una DEVOLUCION del cliente: el importe es NEGATIVO, porque lo que ha
    // devuelto.
    DB::table('customer_ledger_entries')->insert([
        'customer_id' => $customerId,
        'store_id' => $storeId,
        'type' => 'return',
        'reference_no' => 'DEVOLUCION-1',
        'amount' => '-30.0000',
        'entry_date' => '2026-11-04',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // El saldo derivado lo VE: el servicio no lleva una cuenta propia.
    expect($service->balance($customerId, $storeId))->toBe('-30.0000');

    // Y lo que DEBE es el max(0, saldo): con saldo negativo, el cliente no
    // debe nada.
    expect($service->due($customerId, $storeId))->toBe('0.0000');
});
