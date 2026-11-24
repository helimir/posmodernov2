<?php

use App\Domain\Credit\CustomerBalanceService;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;

/**
 * F3-03 — el REINTEGRO del cliente: lo que deja una nota de credito.
 *
 * Regla que fija: *un reintegro se registra con SU PROPIO tipo y resta el
 * saldo del cliente*. El tipo `return` dice QUE paso (una devolucion) y el
 * signo NEGATIVO dice en que sentido: lo que el cliente deja de deber, o el
 * credito a favor que le queda.
 *
 * Y NO ES UN COBRO: un cobro es un pago del cliente, y un reintegro es la
 * devolucion de una venta. El libro del cliente distingue los dos por el
 * TIPO, y un reintegro registrado como cobro seria una traza que miente.
 *
 * Y EL SALDO A FAVOR ESTA SOPORTADO: el saldo es la SUMA con signo de los
 * movimientos, y lo que el cliente DEBE es el max(0, saldo). Un saldo
 * NEGATIVO es un credito a favor: no se debe nada y la deuda es cero. Por eso
 * el reintegro puede dejar al cliente en credito, y ese credito se ve.
 */
test('test_un_reintegro_se_registra_con_su_propio_tipo_y_resta_el_saldo_del_cliente', function () {
    $storeId = CreditFixture::store();
    $customerId = CreditFixture::customer($storeId);

    $service = new CustomerBalanceService(DB::connection());

    // 1. La VENTA: el cliente DEBE 58,0000. El movimiento es POSITIVO.
    $service->registerSale(
        customerId: $customerId,
        storeId: $storeId,
        amount: '58.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    // 2. El COBRO: el cliente pago 30,0000, y debe 28,0000. El movimiento es
    //    NEGATIVO y es de tipo `payment`.
    $service->registerPayment(
        customerId: $customerId,
        storeId: $storeId,
        amount: '30.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    expect($service->balance($customerId, $storeId))->toBe('28.0000')
        ->and($service->due($customerId, $storeId))->toBe('28.0000');

    // 3. EL REINTEGRO: la nota de credito de 58,0000. Es de tipo `return` y
    //    es NEGATIVO: el cliente deja de deber y le queda un CREDITO A FAVOR
    //    de 30,0000 (pago 30 por una venta que ya no esta).
    $entryId = $service->registerReturn(
        customerId: $customerId,
        storeId: $storeId,
        amount: '58.0000',
        referenceNo: 'NOTA-1',
        entryDate: new DateTimeImmutable('2026-11-05'),
    );

    $reintegro = DB::table('customer_ledger_entries')->where('id', $entryId)->first();

    expect($reintegro)->not->toBeNull()
        ->and((string) $reintegro->type)->toBe('return')
        ->and((string) $reintegro->amount)->toBe('-58.0000')
        ->and((string) $reintegro->balance_after)->toBe('-30.0000')
        ->and((string) $reintegro->reference_no)->toBe('NOTA-1')
        ->and((string) $reintegro->entry_date)->toBe('2026-11-05');

    // 4. El saldo DERIVADO es el de los movimientos: -30,0000. Y lo que DEBE
    //    es CERO: un saldo negativo es un credito a favor, no una deuda.
    expect($service->balance($customerId, $storeId))->toBe('-30.0000')
        ->and($service->due($customerId, $storeId))->toBe('0.0000');

    // 5. Y el CACHE de la ficha va detras: el saldo a favor tambien es un
    //    saldo, y el cache lo refleja con su signo.
    $cache = DB::table('customer_stores')
        ->where('customer_id', $customerId)
        ->where('store_id', $storeId)
        ->first();

    expect((string) $cache->balance)->toBe('-30.0000')
        ->and((string) $cache->due)->toBe('0.0000')
        ->and($service->holds($customerId, $storeId))->toBeTrue();
});

/**
 * F3-03 — el reintegro NO es un cobro: la traza dice lo que paso.
 *
 * Regla que fija: *el reintegro tiene su propio tipo y no se confunde con el
 * cobro*. Un movimiento de tipo `payment` dice que el cliente PAGO, y una
 * nota de credito no es un pago: es la devolucion de una venta. El extracto
 * del cliente se lee con esa distincion, y por eso el tipo no se improvisa.
 */
test('test_el_reintegro_no_se_registra_como_un_cobro', function () {
    $storeId = CreditFixture::store();
    $customerId = CreditFixture::customer($storeId);

    $service = new CustomerBalanceService(DB::connection());

    $service->registerSale(
        customerId: $customerId,
        storeId: $storeId,
        amount: '58.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    $service->registerReturn(
        customerId: $customerId,
        storeId: $storeId,
        amount: '58.0000',
        referenceNo: 'NOTA-1',
        entryDate: new DateTimeImmutable('2026-11-05'),
    );

    $tipos = DB::table('customer_ledger_entries')
        ->where('customer_id', $customerId)
        ->orderBy('id')
        ->pluck('type')
        ->all();

    // Y el signo del REINTEGRO no lo decide el llamante: es NEGATIVO siempre,
    // porque un reintegro REDUCE lo que el cliente debe.
    $reintegro = DB::table('customer_ledger_entries')
        ->where('customer_id', $customerId)
        ->where('type', 'return')
        ->first();

    expect($tipos)->toBe(['sale', 'return'])
        ->and((string) $reintegro->amount)->toBe('-58.0000');
});
