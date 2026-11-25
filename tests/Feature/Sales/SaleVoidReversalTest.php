<?php

use App\Domain\Accounting\CashDay;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * F3-07 — la REVERSA de una venta anulada: la deuda con nombre, CERRADA.
 *
 * Reglas que fija:
 *
 *  - la REVERSA es el ESPEJO del asiento de la venta, y el EFECTIVO NO se
 *    revierte: el dinero entro en el cajon y sigue ahi, asi que la caja del dia
 *    NO cambia;
 *  - en el lugar del efectivo va la cuenta de PASIVO: el CREDITO A FAVOR del
 *    cliente, que es una deuda de la tienda;
 *  - con eso el LIBRO DEL CLIENTE y el LIBRO CONTABLE dicen lo MISMO: el
 *    reintegro de la nota de credito deja al cliente con un credito a favor y
 *    el pasivo de la tienda tiene exactamente ese importe;
 *  - una tienda SIN cuenta de pasivo NO escribe la reversa, pero la anulacion
 *    sigue siendo valida: es un hecho fiscal, no una cuenta.
 */

/**
 * El ESCENARIO de la anulacion: la tienda, un usuario con el permiso y una
 * VENTA A CREDITO (la parte que no se cobra es la deuda).
 *
 * @return array<string, mixed>
 */
function escenario_de_la_reversa(): array
{
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('anulador-'.uniqid(), ['sales.void', 'sales.create', 'sales.read']);

    $usuario = PermissionFixture::usuarioConCaja($rol, $escenario['store_id']);

    $clienteId = CreditFixture::customer((int) $escenario['store_id']);

    // La venta se cobra a MEDIAS: 30 de efectivo y 28 a credito. El efectivo
    // no se revierte, y el pasivo queda con los 30 que el cliente entrego.
    $cobro = null;

    return [
        'escenario' => $escenario,
        'usuario' => $usuario,
        'cliente_id' => $clienteId,
        'cobro' => $cobro,
    ];
}

/**
 * LA REVERSA: el espejo del asiento, con el efectivo hacia el pasivo.
 */
test('test_la_reversa_de_la_venta_es_el_espejo_del_asiento_con_el_efectivo_hacia_el_pasivo', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('anulador-'.uniqid(), ['sales.void', 'sales.create', 'sales.read']);

    $usuario = PermissionFixture::usuarioConCaja($rol, $escenario['store_id']);

    $clienteId = CreditFixture::customer((int) $escenario['store_id']);

    // La VENTA: 58 de total, 30 cobrados en efectivo y 28 a credito.
    $cobro = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '30.0000'],
            ],
            'customer_id' => $clienteId,
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');
    $storeId = (int) $escenario['store_id'];

    // El ASIENTO de la venta: el efectivo, la cartera, los ingresos y el
    // impuesto.
    $original = DB::table('ledger_entries')
        ->where('reference_type', 'sale')
        ->where('reference_id', $saleId)
        ->first(['id']);

    expect($original)->not->toBeNull();

    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $original->id)->get();

    expect($lineas->pluck('account_code')->all())->toContain('1100000')
        ->and($lineas->pluck('account_code')->all())->toContain('1200000');

    // La ANULACION.
    $this->actingAs($usuario)
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk()
        // Y la respuesta dice que la REVERSA se escribio.
        ->assertJsonPath('reversal_written', true);

    // 1. LA REVERSA existe, es del tipo `refund` y CUADRA.
    $reversa = DB::table('ledger_entries')
        ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
        ->where('ledger_entries.reference_type', 'sale_void')
        ->where('ledger_entries.reference_id', $saleId)
        ->select('ledger_entries.*', 'ledger_entry_types.code as type_code')
        ->first();

    expect($reversa)->not->toBeNull()
        ->and((string) $reversa->type_code)->toBe('refund');

    $debe = '0';
    $haber = '0';

    foreach (DB::table('ledger_lines')->where('ledger_entry_id', $reversa->id)->get() as $linea) {
        $debe = bcadd($debe, (string) $linea->debit, 4);
        $haber = bcadd($haber, (string) $linea->credit, 4);
    }

    expect(bccomp($debe, $haber, 4))->toBe(0)
        ->and(bccomp($debe, '58.0000', 4))->toBe(0);

    // 2. EL EFECTIVO NO ESTA en la reversa: el dinero sigue en el cajon.
    $lineas = DB::table('ledger_lines')->where('ledger_entry_id', $reversa->id)->get();

    expect($lineas->pluck('account_code')->all())->not->toContain('1100000')
        ->and($lineas->pluck('account_code')->all())->toContain('2100000')
        ->and($lineas->pluck('account_code')->all())->toContain('1200000');

    // 3. Y LA CAJA DEL DIA NO CAMBIA: la venta anulada sigue siendo caja.
    $fecha = (string) DB::table('sales')->where('id', $saleId)->value('sale_date');

    $dia = new CashDay(DB::connection());

    expect($dia->runningBalance($storeId, new DateTimeImmutable($fecha), '1100000'))->toBe('30.0000');
});

/**
 * El LIBRO DEL CLIENTE y el LIBRO CONTABLE dicen lo mismo.
 */
test('test_el_libro_del_cliente_y_el_pasivo_de_la_tienda_dicen_el_mismo_credito_a_favor', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('anulador-'.uniqid(), ['sales.void', 'sales.create', 'sales.read']);

    $usuario = PermissionFixture::usuarioConCaja($rol, $escenario['store_id']);

    $clienteId = CreditFixture::customer((int) $escenario['store_id']);
    $storeId = (int) $escenario['store_id'];

    $cobro = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '30.0000'],
            ],
            'customer_id' => $clienteId,
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    $this->actingAs($usuario)
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado'])
        ->assertOk();

    // 1. El LIBRO DEL CLIENTE: el cliente queda con un credito a favor de 30
    //    (pago 30 de 58).
    $saldo = DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $storeId)
        ->value('balance');

    expect((string) $saldo)->toBe('-30.0000');

    // 2. Y el PASIVO de la tienda tiene EXACTAMENTE ese importe: los dos
    //    libros dicen lo mismo.
    $pasivo = DB::table('ledger_lines')
        ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
        ->where('ledger_entries.reference_type', 'sale_void')
        ->where('ledger_entries.reference_id', $saleId)
        ->where('ledger_lines.account_code', '2100000')
        ->value('credit');

    expect((string) $pasivo)->toBe('30.0000');
});

/**
 * Una tienda SIN cuenta de pasivo no escribe la reversa, pero la anulacion
 * sigue siendo valida.
 */
test('test_una_tienda_sin_cuenta_de_pasivo_no_escribe_la_reversa_y_la_anulacion_sigue_valida', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('anulador-'.uniqid(), ['sales.void', 'sales.create', 'sales.read']);

    $usuario = PermissionFixture::usuarioConCaja($rol, $escenario['store_id']);

    $clienteId = CreditFixture::customer((int) $escenario['store_id']);

    // La tienda se queda SIN cuenta de pasivo: el rol es OPCIONAL.
    DB::table('store_accounts')
        ->where('store_id', $escenario['store_id'])
        ->where('role', 'liability')
        ->delete();

    $cobro = $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                ['payment_method_id' => $escenario['payment_method_id'], 'amount' => '30.0000'],
            ],
            'customer_id' => $clienteId,
        ]);

    $cobro->assertCreated();

    $saleId = (int) $cobro->json('sale_id');

    // La ANULACION sigue adelante: es un hecho fiscal, no una cuenta.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/ventas/'.$saleId.'/anulacion', ['reason' => 'Error en el monto facturado']);

    $respuesta->assertOk()
        ->assertJsonPath('reversal_written', false)
        ->assertJsonPath('status', 'cancelled');

    // Y el AVISO dice que los dos libros siguen diciendo cosas distintas.
    expect($respuesta->json('warning'))->toContain('no tiene cuenta de pasivo');

    // Y NO se escribio ninguna reversa.
    expect(DB::table('ledger_entries')
        ->where('reference_type', 'sale_void')
        ->where('reference_id', $saleId)
        ->count())->toBe(0);
});
