<?php

use App\Domain\Accounting\CashRegisterService;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * La CAJA DEL DIA: la pagina con sus tres estados, la apertura, el cierre y
 * el SALDO EN CURSO derivado.
 *
 * Reglas que fija:
 *
 *  - la pagina tiene TRES estados segun la caja de la fecha de negocio de la
 *    tienda: NO HAY CAJA (el formulario de apertura), esta ABIERTA (el estado
 *    y el formulario de cierre) y esta CERRADA (el resumen del dia). Cada uno
 *    con su contrato de props;
 *  - la APERTURA crea la caja con el SALDO DE PARTIDA (un DATO, contado a
 *    mano) y con QUIEN ABRE, y abrir dos veces la misma fecha de negocio
 *    falla con 422;
 *  - el SALDO EN CURSO se DERIVA de los asientos de ESA fecha de negocio: es
 *    la suma de los debitos menos los creditos de la cuenta de efectivo de
 *    la tienda, no un contador almacenado. Y el ESPERADO es el saldo de
 *    partida mas ese saldo en curso;
 *  - el CIERRE guarda el SALDO CONTADO (otro DATO, el conteo de una caja es un
 *    hecho fisico) y el DESCUADRE es la diferencia entre el contado y el
 *    esperado, CON SU SIGNO;
 *  - una caja CERRADA ya no admite movimientos: una venta posterior al cierre
 *    falla.
 */

/**
 * La FECHA DE NEGOCIO de la tienda: la dice el CONTEXTO, no el reloj del
 * servidor.
 *
 * @param  int  $usuarioId  El usuario que define la tienda activa.
 */
function caja_dia_de_negocio(int $usuarioId): string
{
    return (new PosContext(DB::connection(), $usuarioId))->businessDate()->format('Y-m-d');
}

/**
 * El pedido de una venta de dos unidades a 25, con un 16 % de impuesto.
 *
 * @param  array<string, int>  $escenario
 * @param  int|null  $clienteId  El cliente de la venta; `null` si es de
 *                               mostrador.
 * @return array<string, mixed>
 */
function caja_dia_pedido(array $escenario, ?int $clienteId = null): array
{
    $pedido = [
        'lines' => [
            [
                'product_id' => $escenario['product_id'],
                'quantity' => '2',
                'unit_price' => '25.0000',
            ],
        ],
        'payments' => [
            [
                'payment_method_id' => $escenario['payment_method_id'],
                'amount' => '58.0000',
            ],
        ],
    ];

    if ($clienteId !== null) {
        $pedido['customer_id'] = $clienteId;
    }

    return $pedido;
}

/**
 * La pagina de la caja del dia SIN caja: el formulario de APERTURA, con el
 * saldo de partida en cero y sin ningun saldo en curso.
 */
test('test_la_pagina_de_la_caja_del_dia_sin_caja_pide_el_saldo_de_partida', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            // NO HAY CAJA: el estado lo dice la pagina.
            ->where('state', 'not_opened')
            ->where('register', null)
            ->where('store.id', $escenario['store_id'])
            ->where('store.name', 'Tienda de demostracion')
            ->where('business_date', caja_dia_de_negocio((int) $usuario->id))
            // El saldo de partida, el en curso y el esperado: a cero, porque
            // no hay caja y no hay asientos.
            ->where('opening_balance', '0.0000')
            ->where('running_balance', '0.0000')
            ->where('expected_balance', '0.0000')
            // Y no hay nada que contar todavia.
            ->where('counted_balance', null)
            ->where('difference', null)
            ->has('payments', 0)
            ->where('cash_total', '0.0000')
            ->etc());
});

/**
 * La APERTURA crea la caja con el saldo de partida y con quien abre.
 */
test('test_la_apertura_crea_la_caja_con_el_saldo_de_partida_y_quien_la_abre', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('opening_balance', '100.0000');

    $caja = DB::table('cash_registers')->where('store_id', $escenario['store_id'])->first();

    expect($caja)->not->toBeNull()
        ->and((string) $caja->business_date)->toBe(caja_dia_de_negocio((int) $usuario->id))
        ->and((string) $caja->opening_balance)->toBe('100.0000')
        ->and((string) $caja->status)->toBe('open')
        // Quien ABRE queda en SU columna: es el responsable del extremo.
        ->and((int) $caja->opened_by)->toBe((int) $usuario->id)
        ->and($caja->closed_by)->toBeNull()
        ->and($caja->closed_at)->toBeNull();
});

/**
 * Abrir dos veces la MISMA fecha de negocio falla con 422: una tienda tiene
 * UNA caja por fecha de negocio.
 */
test('test_abrir_dos_veces_la_caja_del_dia_falla_con_422', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '250.0000'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y no se abrio una segunda caja: el saldo de partida sigue siendo el
    // primero.
    expect(DB::table('cash_registers')->where('store_id', $escenario['store_id'])->count())->toBe(1)
        ->and((string) DB::table('cash_registers')
            ->where('store_id', $escenario['store_id'])
            ->value('opening_balance'))->toBe('100.0000');
});

/**
 * La caja ABIERTA: la pagina enseña el estado y el formulario de cierre.
 */
test('test_la_pagina_de_la_caja_del_dia_muestra_el_estado_de_la_caja_abierta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('state', 'open')
            ->where('register.id', (int) DB::table('cash_registers')
                ->where('store_id', $escenario['store_id'])
                ->value('id'))
            ->where('register.status', 'open')
            ->where('register.opening_balance', '100.0000')
            ->where('register.opened_by.id', (int) $usuario->id)
            ->where('register.closed_by', null)
            // El saldo de partida, el en curso y el esperado.
            ->where('opening_balance', '100.0000')
            ->where('running_balance', '0.0000')
            ->where('expected_balance', '100.0000')
            // Todavia no hay nada contado: la caja esta abierta.
            ->where('counted_balance', null)
            ->where('difference', null)
            ->etc());
});

/**
 * El SALDO EN CURSO se DERIVA de los asientos de la fecha de negocio: una
 * venta de la caja lo sube, y el esperado es el saldo de partida mas ese
 * saldo en curso.
 */
test('test_el_saldo_en_curso_se_deriva_de_los_asientos_de_la_fecha_de_negocio', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // Una VENTA de la caja: 2 x 25 con un 16 % de impuesto son 58.
    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', caja_dia_pedido($escenario))
        ->assertCreated()
        ->assertJsonPath('grand_total', '58.0000');

    // El SALDO EN CURSO es el DERIVADO del asiento: el efectivo de la tienda
    // debitado por el total de la venta. Y el esperado es la partida mas el.
    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('state', 'open')
            ->where('opening_balance', '100.0000')
            ->where('running_balance', '58.0000')
            ->where('expected_balance', '158.0000')
            // Y el desglose por medio de pago: lo que entro en la caja.
            ->has('payments', 1)
            ->where('payments.0.id', $escenario['payment_method_id'])
            ->where('payments.0.name', 'Efectivo')
            ->where('payments.0.type', 'cash')
            ->where('payments.0.amount', '58.0000')
            ->where('payments.0.is_cash', true)
            ->where('cash_total', '58.0000')
            ->etc());

    // Y el DERIVADO es el de los asientos, no el de un contador: el cambio de
    // la cuenta de efectivo tambien cuenta.
    $movimiento = DB::table('ledger_lines')
        ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
        ->where('ledger_entries.store_id', $escenario['store_id'])
        ->where('ledger_entries.business_date', caja_dia_de_negocio((int) $usuario->id))
        ->where('ledger_lines.account_code', '1100000')
        ->first();

    expect($movimiento)->not->toBeNull()
        ->and((string) $movimiento->debit)->toBe('58.0000');
});

/**
 * Una venta CON CLIENTE pagada en efectivo SUBE el esperado de la caja, y el
 * arqueo CUADRA.
 *
 * EL DEFECTO QUE ESTE TEST CIERRA: el asiento de la venta con cliente
 * debita la cuenta por cobrar y NUNCA la de efectivo, asi que el efectivo que
 * entra al cajon no aparece en el libro contable. El esperado de la caja se
 * queda corto y el arqueo da un DESCUADRE FALSO en cada venta con cliente.
 *
 * REGLA que fija: *el efectivo que entra al cajon llega a la cuenta de
 * efectivo de la tienda*, y el arqueo compara el conteo con lo que el libro
 * dice: si el libro esta bien, el arqueo cuadra.
 */
test('test_una_venta_con_cliente_pagada_en_efectivo_sube_el_esperado_de_la_caja_y_el_arqueo_cuadra', function () {
    $escenario = PosFixture::escenario();
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '0.0000'])
        ->assertCreated();

    // Una VENTA CON CLIENTE pagada en efectivo: 58,0000 entran al cajon.
    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', caja_dia_pedido($escenario, $clienteId))
        ->assertCreated()
        ->assertJsonPath('grand_total', '58.0000');

    // El SALDO EN CURSO sube: el efectivo de la venta esta en la cuenta de
    // efectivo de la tienda, y el esperado es la partida mas ese saldo.
    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('state', 'open')
            ->where('opening_balance', '0.0000')
            ->where('running_balance', '58.0000')
            ->where('expected_balance', '58.0000')
            // Y el desglose: lo que entro en la caja es dinero de la caja.
            ->where('cash_total', '58.0000')
            ->etc());

    // Y el ARQUEO CUADRA: se cuenta lo que dice el libro, y la diferencia es
    // CERO.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '58.0000'])
        ->assertCreated()
        ->assertJsonPath('expected_balance', '58.0000')
        ->assertJsonPath('difference', '0.0000');
});

/**
 * El CIERRE guarda el saldo contado y el DESCUADRE es la diferencia entre el
 * contado y el esperado, con su signo.
 */
test('test_el_cierre_guarda_el_saldo_contado_y_calcula_el_descuadre', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', caja_dia_pedido($escenario))
        ->assertCreated();

    // El esperado son 158: se cuentan 160 y sobran dos.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '160.0000'])
        ->assertCreated()
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('expected_balance', '158.0000')
        ->assertJsonPath('difference', '2.0000');

    $caja = DB::table('cash_registers')->where('store_id', $escenario['store_id'])->first();

    expect((string) $caja->status)->toBe('closed')
        ->and((string) $caja->closing_balance)->toBe('160.0000')
        // Quien CIERRA queda en SU columna, y no borra quien abrio.
        ->and((int) $caja->closed_by)->toBe((int) $usuario->id)
        ->and((int) $caja->opened_by)->toBe((int) $usuario->id)
        ->and((string) $caja->closed_at)->not->toBe('');
});

/**
 * La caja CERRADA: la pagina enseña el resumen del dia, con el descuadre y
 * con quien abrio y quien cerro.
 */
test('test_la_pagina_de_la_caja_del_dia_muestra_el_resumen_del_dia_cuando_esta_cerrada', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', caja_dia_pedido($escenario))
        ->assertCreated();

    // Se cuenta de mas: el descuadre lo dice, con su signo.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '155.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('state', 'closed')
            ->where('register.status', 'closed')
            ->where('register.closing_balance', '155.0000')
            ->where('register.opened_by.id', (int) $usuario->id)
            ->where('register.closed_by.id', (int) $usuario->id)
            ->where('opening_balance', '100.0000')
            ->where('running_balance', '58.0000')
            ->where('expected_balance', '158.0000')
            ->where('counted_balance', '155.0000')
            // El DESCUADRE: el contado menos el esperado, con su signo.
            ->where('difference', '-3.0000')
            ->etc());
});

/**
 * Cerrar dos veces la misma caja falla con 422: el cierre es de UNA unidad.
 */
test('test_cerrar_dos_veces_la_caja_del_dia_falla_con_422', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '158.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '160.0000'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y el saldo contado sigue siendo el PRIMERO: el segundo cierre no
    // escribio nada.
    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('closing_balance'))->toBe('158.0000');
});

/**
 * Una caja CERRADA ya no admite movimientos: una venta posterior al cierre
 * falla y no deja venta.
 */
test('test_una_caja_cerrada_ya_no_admite_movimientos', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '100.0000'])
        ->assertCreated();

    // La venta posterior al cierre NO se cobra.
    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', caja_dia_pedido($escenario))
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    expect(DB::table('sales')->count())->toBe(0)
        ->and(DB::table('sale_items')->count())->toBe(0)
        ->and(DB::table('sale_payments')->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0)
        ->and((string) DB::table('inventory_lots')->value('available'))->toBe('10.0000');
});

/**
 * Un pago de tipo CREDITO no es dinero de la caja: es DEUDA. El desglose lo
 * MARCA ("not cash") y el total de efectivo no lo cuenta.
 */
test('test_el_desglose_marca_el_pago_a_credito_como_que_no_es_dinero_de_la_caja', function () {
    $escenario = PosFixture::escenario();
    $credito = PosFixture::medioDePagoDeCredito($escenario['store_id']);
    $clienteId = CreditFixture::customer($escenario['store_id']);
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // Una venta pagada CON CREDITO: es deuda, no dinero de la caja.
    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', [
            'customer_id' => $clienteId,
            'lines' => [
                [
                    'product_id' => $escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $credito,
                    'amount' => '58.0000',
                ],
            ],
        ])
        ->assertCreated();

    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('payments', function ($pagos) use ($credito): bool {
                $pago = collect($pagos)->firstWhere('id', $credito);

                return $pago !== null
                    && $pago['type'] === 'credit'
                    && $pago['amount'] === '58.0000'
                    // El que NO es efectivo: se marca, pero no suma.
                    && $pago['is_cash'] === false;
            })
            // Y el total de efectivo NO lo cuenta: el cajero no lo cuenta.
            ->where('cash_total', '0.0000')
            ->etc());
});

/**
 * El saldo de partida es un DATO: tiene que ser un numero decimal y no puede
 * ser NEGATIVO. Una caja no arranca con saldo negativo.
 */
test('test_la_apertura_rechaza_un_saldo_de_partida_que_no_es_un_numero_decimal_o_es_negativo', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    // 1. Un saldo NEGATIVO.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '-50.0000'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 2. Un saldo que no es un numero.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => 'no-es-un-numero'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // 3. Y un saldo que no viene.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y nada de eso abrio una caja.
    expect(DB::table('cash_registers')->where('store_id', $escenario['store_id'])->count())->toBe(0);
});

/**
 * El saldo contado es un DATO: tiene que ser un numero decimal y no puede ser
 * NEGATIVO. Una caja no se cierra con saldo negativo.
 */
test('test_el_cierre_rechaza_un_saldo_contado_que_no_es_un_numero_decimal_o_es_negativo', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '-1.0000'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => 'mucho'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y la caja sigue ABIERTA: un cierre invalido no cierra nada.
    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('status'))->toBe('open');
});

/**
 * Sin sesion, la caja del dia REDIRIGE: no se sirve una pantalla de caja a
 * quien no ha entrado.
 */
test('test_sin_autenticar_la_caja_del_dia_redirige_al_login', function () {
    $this->get('/caja/dia')->assertRedirect('/login');
    $this->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])->assertStatus(401);
    $this->postJson('/caja/dia/cerrar', ['closing_balance' => '100.0000'])->assertStatus(401);
    $this->get('/reportes/stock')->assertRedirect('/login');
});

/**
 * La caja del dia se resuelve por la CLAVE NATURAL `(store_id,
 * business_date)`: el registro de la fecha de negocio del usuario es el
 * suyo, no el de "hoy" del servidor.
 */
test('test_la_caja_del_dia_se_resuelve_por_la_fecha_de_negocio_de_la_tienda', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $servicio = new CashRegisterService(DB::connection());

    $registro = $servicio->registerFor(
        $escenario['store_id'],
        new DateTimeImmutable(caja_dia_de_negocio((int) $usuario->id))
    );

    expect((int) $registro->id)->toBe((int) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('id'))
        ->and((string) $registro->business_date)->toBe(caja_dia_de_negocio((int) $usuario->id));
});
