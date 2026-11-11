<?php

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * El COBRO SUELTO de un cliente: el movimiento de SU libro y el asiento
 * contable.
 *
 * REGLA que fija: *un cobro a un cliente escribe el movimiento en el libro del
 * cliente Y el asiento contable, en la MISMA transaccion*.
 *
 * EL DEFECTO QUE ESTE TEST CIERRA: el cobro suelto escribia SOLO el libro del
 * cliente. El efectivo del cobro no llegaba a la cuenta de efectivo del libro
 * contable, y el arqueo del dia daba un DESCUADRE FALSO: el sistema decÃ­a que
 * en la caja no habia ese dinero, y el cajero si lo tenia.
 *
 * LAS TRES REGLAS QUE FIJA:
 *
 *  - el cobro escribe el movimiento del libro del cliente (NEGATIVO, porque el
 *    cliente paga) Y el asiento de tipo `sale_payment`: debito de la cuenta de
 *    efectivo por lo cobrado, credito de la cuenta por cobrar por la deuda que
 *    se cancela. Los dos importes en POSITIVO: el signo va en la cuenta, no en
 *    el importe;
 *  - si el ASIENTO falla, el movimiento del libro del cliente TAMBIEN se
 *    revierte: no hay movimiento sin asiento, ni asiento sin movimiento;
 *  - el saldo de la cuenta por cobrar del libro contable sigue siendo IGUAL al
 *    saldo del libro del cliente despues del cobro.
 *
 * El ESCENARIO es una venta a credito parcial: 100,0000 de venta con
 * 50,0000 en efectivo, y un cobro suelto de 20,0000. Asi los dos saldos
 * siguen siendo comparables: un cobro MAYOR que la deuda deja al cliente con
 * saldo a favor, y ese saldo a favor no es una deuda por cobrar.
 */

/**
 * El escenario del cobro suelto: la tienda de demostracion, su cajero, un
 * cliente con ficha y una venta de 100,0000 con 50,0000 en efectivo.
 *
 * @param  mixed  $test  El caso de prueba, para poder hacer la peticion.
 * @return array<string, mixed>
 */
function cobro_escenario(mixed $test): array
{
    $escenario = PosFixture::escenario();

    $servicio = PosFixture::servicioExento(
        $escenario['store_id'],
        $escenario['exempt_tax_category_id']
    );

    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuario($escenario['store_id']);

    $venta = $test->actingAs($usuario)->postJson('/caja/cobrar', [
        'customer_id' => $clienteId,
        'lines' => [
            [
                'product_id' => $servicio,
                'quantity' => '2',
                'unit_price' => '50.0000',
            ],
        ],
        'payments' => [
            ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '50.0000'],
        ],
    ]);

    $venta->assertCreated()->assertJsonPath('grand_total', '100.0000');

    return [
        'escenario' => $escenario,
        'usuario' => $usuario,
        'cliente' => $clienteId,
        'sale_id' => (int) $venta->json('sale_id'),
    ];
}

/**
 * El SALDO de una cuenta contable en la tienda: debito menos credito.
 */
function cobro_saldo_de_cuenta(int $storeId, string $cuenta): string
{
    $movimiento = DB::table('ledger_lines')
        ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
        ->where('ledger_entries.store_id', $storeId)
        ->where('ledger_lines.account_code', $cuenta)
        ->selectRaw(
            'COALESCE(SUM(ledger_lines.debit), 0) AS debe, '
            .'COALESCE(SUM(ledger_lines.credit), 0) AS haber'
        )
        ->first();

    $debe = BigDecimal::of((string) ($movimiento->debe ?? '0'))->toScale(4, RoundingMode::HALF_UP);
    $haber = BigDecimal::of((string) ($movimiento->haber ?? '0'))->toScale(4, RoundingMode::HALF_UP);

    return (string) $debe->minus($haber)->toScale(4, RoundingMode::HALF_UP);
}

/**
 * El SALDO del libro del cliente: la suma de sus movimientos.
 */
function cobro_saldo_del_cliente(int $clienteId, int $storeId): string
{
    $saldo = DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->where('store_id', $storeId)
        ->sum('amount');

    return (string) BigDecimal::of((string) ($saldo ?? '0'))->toScale(4, RoundingMode::HALF_UP);
}

/**
 * El ASIENTO de cobro: su tipo del catalogo, su referencia y sus lineas.
 *
 * @return array{type: string, lines: list<array{account: string, debit: string, credit: string}>}|null
 */
function cobro_asiento(int $saleId): ?array
{
    $entry = DB::table('ledger_entries')
        ->where('reference_type', 'customer_payment')
        ->where('reference_id', $saleId)
        ->first();

    if ($entry === null) {
        return null;
    }

    $tipo = DB::table('ledger_entry_types')
        ->where('id', $entry->entry_type_id)
        ->value('code');

    $lineas = [];

    foreach (DB::table('ledger_lines')->where('ledger_entry_id', $entry->id)->orderBy('id')->get() as $linea) {
        $lineas[] = [
            'account' => (string) $linea->account_code,
            'debit' => (string) BigDecimal::of((string) $linea->debit)->toScale(4, RoundingMode::HALF_UP),
            'credit' => (string) BigDecimal::of((string) $linea->credit)->toScale(4, RoundingMode::HALF_UP),
        ];
    }

    return ['type' => (string) $tipo, 'lines' => $lineas];
}

/**
 * El cobro escribe el MOVIMIENTO del libro del cliente Y el ASIENTO de tipo
 * `sale_payment`, y el efectivo llega a la cuenta de caja.
 */
test('test_el_cobro_de_un_cliente_escribe_el_movimiento_de_su_libro_y_el_asiento_de_cobro', function () {
    $datos = cobro_escenario($this);
    $escenario = $datos['escenario'];
    $clienteId = $datos['cliente'];

    // ANTES: el efectivo de la venta esta en la caja (50) y la deuda del
    // cliente son 50.
    expect(cobro_saldo_de_cuenta($escenario['store_id'], '1100000'))->toBe('50.0000')
        ->and(cobro_saldo_de_cuenta($escenario['store_id'], '1200000'))->toBe('50.0000')
        ->and(cobro_saldo_del_cliente($clienteId, $escenario['store_id']))->toBe('50.0000');

    $cobro = $this->actingAs($datos['usuario'])
        ->postJson('/api/clientes/'.$clienteId.'/cobros', [
            'amount' => '20.0000',
            'reference_no' => 'RECIBO-1',
        ]);

    $cobro->assertCreated()
        ->assertJsonPath('customer_id', $clienteId)
        ->assertJsonPath('amount', '20.0000')
        ->assertJsonPath('balance', '30.0000');

    // 1. EL MOVIMIENTO del libro del cliente: NEGATIVO, porque el cliente
    //    paga, y con su saldo derivado.
    $movimiento = DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->where('type', 'payment')
        ->orderByDesc('id')
        ->first();

    expect($movimiento)->not->toBeNull()
        ->and((string) BigDecimal::of((string) $movimiento->amount)->toScale(4, RoundingMode::HALF_UP))
        ->toBe('-20.0000')
        ->and((string) BigDecimal::of((string) $movimiento->balance_after)->toScale(4, RoundingMode::HALF_UP))
        ->toBe('30.0000')
        ->and($movimiento->reference_no)->toBe('RECIBO-1');

    // 2. EL ASIENTO: de tipo `sale_payment`, con el efectivo debitando la
    //    caja y la deuda acreditando la cuenta por cobrar. Los dos importes
    //    en POSITIVO: el signo va en la cuenta, no en el importe.
    $asiento = cobro_asiento((int) $movimiento->id);

    expect($asiento)->not->toBeNull()
        ->and($asiento['type'])->toBe('sale_payment')
        ->and($asiento['lines'])->toBe([
            ['account' => '1100000', 'debit' => '20.0000', 'credit' => '0.0000'],
            ['account' => '1200000', 'debit' => '0.0000', 'credit' => '20.0000'],
        ]);

    // 3. Y el efectivo del cobro LLEGO a la cuenta de caja: 50 de la venta
    //    mas 20 del cobro.
    expect(cobro_saldo_de_cuenta($escenario['store_id'], '1100000'))->toBe('70.0000');
});

/**
 * Un FALLO del asiento revierte el movimiento del libro del cliente: no hay
 * movimiento sin asiento, ni asiento sin movimiento.
 */
test('test_un_fallo_del_asiento_revierte_el_movimiento_del_libro_del_cliente', function () {
    $datos = cobro_escenario($this);
    $escenario = $datos['escenario'];
    $clienteId = $datos['cliente'];

    // El tipo de asiento `sale_payment` se APAGA del catalogo: es un tipo
    // que no admite asientos nuevos, y el cobro falla.
    DB::table('ledger_entry_types')->where('code', 'sale_payment')->update(['is_active' => false]);

    $cobro = $this->actingAs($datos['usuario'])
        ->postJson('/api/clientes/'.$clienteId.'/cobros', [
            'amount' => '20.0000',
            'reference_no' => 'RECIBO-1',
        ]);

    $cobro->assertStatus(422)->assertJsonStructure(['errorMsg']);

    // 1. NO hay movimiento del libro del cliente por este cobro: el cobro
    //    se revirtio entero. (El movimiento de la VENTA sigue ahi: es otro
    //    hecho, con su propio comprobante.)
    expect(DB::table('customer_ledger_entries')
        ->where('customer_id', $clienteId)
        ->where('type', 'payment')
        ->where('reference_no', 'RECIBO-1')
        ->count())->toBe(0);

    // 2. Y NO hay asiento de cobro.
    expect(DB::table('ledger_entries')->where('reference_type', 'customer_payment')->count())->toBe(0);

    // 3. Y los saldos NO se movieron: ni la caja, ni la deuda, ni el libro
    //    del cliente.
    expect(cobro_saldo_de_cuenta($escenario['store_id'], '1100000'))->toBe('50.0000')
        ->and(cobro_saldo_de_cuenta($escenario['store_id'], '1200000'))->toBe('50.0000')
        ->and(cobro_saldo_del_cliente($clienteId, $escenario['store_id']))->toBe('50.0000');
});

/**
 * El saldo de la cuenta por cobrar CONTABLE y el del LIBRO DEL CLIENTE siguen
 * coincidiendo despues del cobro.
 */
test('test_el_saldo_de_cuentas_por_cobrar_y_el_del_libro_del_cliente_coinciden_despues_del_cobro', function () {
    $datos = cobro_escenario($this);
    $escenario = $datos['escenario'];
    $clienteId = $datos['cliente'];

    $this->actingAs($datos['usuario'])
        ->postJson('/api/clientes/'.$clienteId.'/cobros', [
            'amount' => '20.0000',
            'reference_no' => 'RECIBO-1',
        ])
        ->assertCreated();

    // La cuenta por cobrar del LIBRO CONTABLE: 50 de deuda menos 20 de cobro.
    expect(cobro_saldo_de_cuenta($escenario['store_id'], '1200000'))->toBe('30.0000');

    // Y el LIBRO DEL CLIENTE: 100 de venta menos 50 cobrados en la venta
    // menos 20 de este cobro.
    expect(cobro_saldo_del_cliente($clienteId, $escenario['store_id']))->toBe('30.0000');

    // Los dos son IGUALES.
    expect(cobro_saldo_de_cuenta($escenario['store_id'], '1200000'))
        ->toBe(cobro_saldo_del_cliente($clienteId, $escenario['store_id']));
});
