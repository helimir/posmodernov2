<?php

use App\Domain\Credit\CustomerBalanceService;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * Las ESCRITURAS de los clientes: el alta, la edicion y el cobro.
 *
 * Reglas que fija:
 *
 *  - el ALTA crea el cliente y le abre ficha en la TIENDA ACTIVA con el saldo
 *    a CERO, porque lo que no esta movido no se debe; y un nombre vacio no
 *    crea nada (422 con `errorMsg`);
 *  - la EDICION cambia los datos del cliente y NO toca su saldo: el saldo es
 *    DERIVADO de los movimientos, no un campo que se escribe;
 *  - el COBRO reduce la deuda del cliente, y el saldo derivado baja con ella;
 *  - las tres escrituras viajan por la API JSON, en `/api/clientes`, y no en
 *    la ruta de las paginas.
 */

/**
 * El escenario de las escrituras: la tienda de demostracion, su cajero y un
 * cliente con su ficha en la tienda.
 *
 * @return array<string, mixed>
 */
function escenario_de_las_escrituras_de_clientes(): array
{
    $escenario = PosFixture::escenario();

    return [
        'escenario' => $escenario,
        'usuario' => PosFixture::usuario($escenario['store_id']),
        'ana' => CreditFixture::customer($escenario['store_id'], 'Ana Perez', 'V-12345678'),
    ];
}

/**
 * El servicio de credito del cliente en la tienda.
 */
function servicio_de_credito_de_las_escrituras(): CustomerBalanceService
{
    return new CustomerBalanceService(DB::connection());
}

/**
 * El ALTA crea el cliente y le abre ficha en la tienda activa con el saldo a
 * CERO; y un nombre vacio no crea nada.
 */
test('test_el_alta_de_un_cliente_por_la_api_lo_crea_y_un_nombre_vacio_falla_con_422', function () {
    $datos = escenario_de_las_escrituras_de_clientes();
    $escenario = $datos['escenario'];

    $alta = $this->actingAs($datos['usuario'])->postJson('/api/clientes', [
        'name' => 'Cliente Nuevo',
        'tax_id' => 'V-99999999',
        'email' => 'cliente@tienda.test',
        'phone' => '04120000000',
        'credit_limit' => '500.0000',
    ]);

    $alta->assertCreated()
        ->assertJsonPath('name', 'Cliente Nuevo')
        ->assertJsonPath('tax_id', 'V-99999999')
        ->assertJsonPath('credit_limit', '500.0000')
        ->assertJsonPath('balance', '0.0000')
        ->assertJsonPath('due', '0.0000');

    $clienteId = (int) $alta->json('id');

    // Y la ficha existe en la tienda activa, con el cache a cero.
    $ficha = DB::table('customer_stores')
        ->where('customer_id', $clienteId)
        ->where('store_id', $escenario['store_id'])
        ->first();

    expect($ficha)->not->toBeNull()
        ->and((string) $ficha->balance)->toBe('0.0000')
        ->and((string) $ficha->due)->toBe('0.0000')
        ->and($ficha->status)->toBe('active');

    // Un NOMBRE VACIO no crea nada: la ficha tampoco.
    $this->actingAs($datos['usuario'])
        ->postJson('/api/clientes', ['name' => '   '])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    expect(DB::table('customers')->where('name', '   ')->count())->toBe(0);
});

/**
 * La EDICION cambia los datos del cliente y NO toca su saldo: el saldo es
 * DERIVADO de los movimientos, no un campo que se escribe.
 */
test('test_la_edicion_de_un_cliente_cambia_su_nombre_y_no_toca_su_saldo', function () {
    $datos = escenario_de_las_escrituras_de_clientes();
    $escenario = $datos['escenario'];
    $servicio = servicio_de_credito_de_las_escrituras();

    // Ana DEBE 100 antes de la edicion.
    $servicio->registerSale(
        customerId: $datos['ana'],
        storeId: $escenario['store_id'],
        amount: '100.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    $edicion = $this->actingAs($datos['usuario'])->putJson('/api/clientes/'.$datos['ana'], [
        'name' => 'Ana Perez Ruiz',
        'tax_id' => 'V-12345678',
        'credit_limit' => '750.0000',
    ]);

    $edicion->assertOk()
        ->assertJsonPath('id', $datos['ana'])
        ->assertJsonPath('name', 'Ana Perez Ruiz')
        ->assertJsonPath('credit_limit', '750.0000');

    // 1. El nombre cambio en el maestro.
    expect(DB::table('customers')->where('id', $datos['ana'])->value('name'))->toBe('Ana Perez Ruiz');

    // 2. Y el saldo NO se toco: ni el derivado ni el cache.
    expect($servicio->balance($datos['ana'], $escenario['store_id']))->toBe('100.0000')
        ->and($servicio->due($datos['ana'], $escenario['store_id']))->toBe('100.0000')
        ->and((string) DB::table('customer_stores')
            ->where('customer_id', $datos['ana'])
            ->where('store_id', $escenario['store_id'])
            ->value('balance'))->toBe('100.0000');

    // 3. Y los movimientos siguen siendo los mismos: la edicion no inventa
    //    ninguno.
    expect(DB::table('customer_ledger_entries')->where('customer_id', $datos['ana'])->count())->toBe(1);

    // 4. Y un PUT que NO trae el limite lo CONSERVA: el limite no se borra
    //    por omision, solo se quita cuando se manda vacio.
    $this->actingAs($datos['usuario'])
        ->putJson('/api/clientes/'.$datos['ana'], ['name' => 'Ana Perez Ruiz'])
        ->assertOk()
        ->assertJsonPath('credit_limit', '750.0000');
});

/**
 * El COBRO reduce la deuda del cliente, y el saldo DERIVADO baja con ella.
 */
test('test_el_cobro_por_la_api_reduce_la_deuda_y_el_saldo_derivado_baja', function () {
    $datos = escenario_de_las_escrituras_de_clientes();
    $escenario = $datos['escenario'];
    $servicio = servicio_de_credito_de_las_escrituras();

    $servicio->registerSale(
        customerId: $datos['ana'],
        storeId: $escenario['store_id'],
        amount: '100.0000',
        referenceNo: 'VENTA-1',
        entryDate: new DateTimeImmutable('2026-11-04'),
    );

    $cobro = $this->actingAs($datos['usuario'])->postJson('/api/clientes/'.$datos['ana'].'/cobros', [
        'amount' => '30.0000',
        'reference_no' => 'RECIBO-1',
    ]);

    $cobro->assertCreated()
        ->assertJsonPath('customer_id', $datos['ana'])
        ->assertJsonPath('amount', '30.0000')
        ->assertJsonPath('balance', '70.0000')
        ->assertJsonPath('due', '70.0000');

    // El movimiento del cobro es NEGATIVO, y su `balance_after` es el saldo
    // derivado.
    $movimiento = DB::table('customer_ledger_entries')
        ->where('customer_id', $datos['ana'])
        ->where('type', 'payment')
        ->first();

    expect($movimiento)->not->toBeNull()
        ->and((string) $movimiento->amount)->toBe('-30.0000')
        ->and((string) $movimiento->balance_after)->toBe('70.0000')
        ->and($movimiento->reference_no)->toBe('RECIBO-1');

    // Y el saldo derivado baja: 100 de la venta menos 30 del cobro.
    expect($servicio->balance($datos['ana'], $escenario['store_id']))->toBe('70.0000')
        ->and($servicio->due($datos['ana'], $escenario['store_id']))->toBe('70.0000');
});
