<?php

use App\Domain\Accounting\CashRegisterService;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\LedgerWriter;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PosFixture;

/**
 * EL ASIENTO DE APERTURA: el saldo de partida es un HECHO CONTABLE.
 *
 * EL DEFECTO QUE ESTA UNIDAD CIERRA: el saldo de partida vivia SOLO en
 * `cash_registers.opening_balance`, como un dato suelto. El tipo de asiento
 * `opening_balance` existia en el catalogo desde la Fase 0 y NUNCA se
 * escribia. Asi que la cuenta de efectivo del LIBRO no cuadra con la CAJA: un
 * POS cuyo libro no cuadra con su caja no esta cerrado.
 *
 * Reglas que fija:
 *
 *  - al abrir la caja se escribe un asiento de tipo `opening_balance` por el
 *    saldo de partida, con la cuenta de efectivo de la tienda y la de capital
 *    como contrapartida, y en la MISMA transaccion que la apertura: si el
 *    asiento falla, la caja NO queda abierta;
 *  - el DATO `opening_balance` sigue siendo el dato: es lo que el cajero
 *    conto. El asiento es su reflejo en el libro, y el importe del asiento es
 *    el del dato;
 *  - el ESPERADO es el saldo EN CURSO, y solo el saldo en curso: como el
 *    saldo de partida ya esta en el libro, sumarlo otra vez lo contaria dos
 *    veces;
 *  - una tienda SIN plan de cuentas no puede abrir la caja: una caja sin
 *    cuenta donde asentar no es una caja;
 *  - el asiento se escribe SOLO al abrir: una caja cerrada no se vuelve a
 *    asentar.
 */

/**
 * El id del tipo de asiento de apertura en el catalogo.
 */
function apertura_tipo_de_asiento(): int
{
    return (int) DB::table('ledger_entry_types')->where('code', 'opening_balance')->value('id');
}

/**
 * El ASIENTO de apertura de una tienda, o `null` si no hay.
 */
function apertura_asiento_de_la_tienda(int $storeId): ?object
{
    return DB::table('ledger_entries')
        ->where('entry_type_id', apertura_tipo_de_asiento())
        ->where('store_id', $storeId)
        ->first();
}

/**
 * Al ABRIR la caja se escribe el asiento de apertura por la partida.
 */
test('test_al_abrir_la_caja_se_escribe_un_asiento_de_apertura_por_la_partida', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $asiento = apertura_asiento_de_la_tienda((int) $escenario['store_id']);

    expect($asiento)->not->toBeNull('La apertura no dejo asiento de apertura.')
        // EL IMPORTE DEL ASIENTO ES EL DEL DATO: si difieren, es un bug.
        ->and((string) $asiento->amount)->toBe('100.0000')
        // Y apunta a la caja abierta.
        ->and((string) $asiento->reference_type)->toBe('cash_register')
        ->and((int) $asiento->reference_id)->toBe((int) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('id'));
});

/**
 * El asiento tiene DOS LINEAS: el cargo a la cuenta de efectivo de la tienda
 * y el abono a la de capital. Y el cargo es el que hace que la cuenta de
 * efectivo cuadre con la caja.
 */
test('test_el_asiento_de_apertura_carga_la_cuenta_de_efectivo_y_abona_el_capital', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $asiento = apertura_asiento_de_la_tienda((int) $escenario['store_id']);

    $lineas = DB::table('ledger_lines')
        ->where('ledger_entry_id', $asiento->id)
        ->orderBy('id')
        ->get();

    expect($lineas)->toHaveCount(2);

    // Las CUENTAS son las del PLAN de la tienda, no codigos inventados.
    $plan = (new ChartOfAccounts(DB::connection()))->roles((int) $escenario['store_id']);

    $efectivo = $lineas->firstWhere('account_code', $plan['cash']);
    $capital = $lineas->firstWhere('account_code', $plan['equity']);

    expect($efectivo)->not->toBeNull()
        ->and((string) $efectivo->debit)->toBe('100.0000')
        ->and((string) $efectivo->credit)->toBe('0.0000')
        ->and($capital)->not->toBeNull()
        ->and((string) $capital->credit)->toBe('100.0000')
        ->and((string) $capital->debit)->toBe('0.0000');
});

/**
 * El asiento y la caja van en la MISMA TRANSACCION: si el asiento falla, la
 * caja NO queda abierta. No hay una caja abierta sin su reflejo en el libro.
 */
test('test_si_el_asiento_de_apertura_falla_la_caja_no_queda_abierta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    // El tipo de asiento se APAGA: el libro ya no admite aperturas.
    DB::table('ledger_entry_types')
        ->where('code', 'opening_balance')
        ->update(['is_active' => false]);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y no hay un hecho a medias: ni caja abierta ni asiento a medias.
    expect(DB::table('cash_registers')->where('store_id', $escenario['store_id'])->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * EL ESPERADO ES SOLO EL SALDO EN CURSO.
 *
 * Con una partida de 100 y UN SOLO movimiento de 10, el esperado es 110, y NO
 * 210: la partida ya esta en el libro (con su asiento de apertura), y
 * sumarla otra vez contaria ese dinero dos veces.
 */
test('test_el_esperado_es_solo_el_saldo_en_curso_y_no_la_partida_mas_el_saldo_en_curso', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // UN SOLO movimiento de 10 en la cuenta de efectivo de la tienda.
    $contexto = new PosContext(DB::connection(), (int) $usuario->id);

    (new LedgerWriter(DB::connection()))->write(
        entryType: 'sale',
        storeId: (int) $escenario['store_id'],
        occurredAt: new DateTimeImmutable('now'),
        businessDate: $contexto->businessDate(),
        lines: [
            ['account' => '1100000', 'debit' => '10.0000', 'credit' => '0'],
            ['account' => '4010000', 'debit' => '0', 'credit' => '10.0000'],
        ],
    );

    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('state', 'open')
            // La partida: el dato que se conto.
            ->where('opening_balance', '100.0000')
            // El saldo en curso: la partida (100) MAS el movimiento (10).
            ->where('running_balance', '110.0000')
            // EL ESPERADO: 110, NO 210.
            ->where('expected_balance', '110.0000')
            ->etc());
});

/**
 * La PARTIDA SIGUE SIENDO EL DATO: lo que el cajero conto a mano. El
 * asiento es su reflejo en el libro, no su sustituto.
 */
test('test_la_partida_sigue_siendo_el_dato_que_se_conto', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // El DATO, en su fila.
    $caja = DB::table('cash_registers')->where('store_id', $escenario['store_id'])->first();

    expect((string) $caja->opening_balance)->toBe('100.0000');

    // Y el asiento tiene ESE importe, no otro.
    $asiento = apertura_asiento_de_la_tienda((int) $escenario['store_id']);

    expect((string) $asiento->amount)->toBe((string) $caja->opening_balance);

    // Y la pantalla lo enseña como el dato, al lado del saldo en curso.
    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('opening_balance', '100.0000')
            ->where('running_balance', '100.0000')
            ->where('expected_balance', '100.0000')
            ->etc());
});

/**
 * SIN PLAN DE CUENTAS la apertura falla con un mensaje util: una caja sin
 * cuenta donde asentar no es una caja.
 */
test('test_sin_plan_de_cuentas_la_apertura_falla_con_un_mensaje_util', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    // La tienda se queda SIN plan.
    DB::table('store_accounts')->where('store_id', $escenario['store_id'])->delete();

    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000']);

    $respuesta->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // El mensaje dice QUE falta y PARA QUE.
    expect((string) $respuesta->json('errorMsg'))->toContain('cuenta de efectivo');

    // Y no abrio nada.
    expect(DB::table('cash_registers')->where('store_id', $escenario['store_id'])->count())->toBe(0)
        ->and(DB::table('ledger_entries')->count())->toBe(0);
});

/**
 * UNA CAJA CERRADA NO SE VUELVE A ASENTAR: el asiento de apertura se escribe
 * SOLO al abrir. Ni al cerrar, ni al reabrir, ni al volver a cerrar.
 */
test('test_una_caja_cerrada_no_se_vuelve_a_asentar', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $servicio = new CashRegisterService(DB::connection());

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $cajaId = (int) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('id');

    expect(apertura_asiento_de_la_tienda((int) $escenario['store_id']))->not->toBeNull();

    // Cerrar.
    $servicio->close($cajaId, '100.0000', (int) $usuario->id);

    expect(DB::table('ledger_entries')->where('entry_type_id', apertura_tipo_de_asiento())->count())->toBe(1);

    // Reabrir y volver a cerrar.
    $servicio->reopen($cajaId, 'Se corrigio una venta del dia', (int) $usuario->id);
    $servicio->close($cajaId, '100.0000', (int) $usuario->id);

    // Y el asiento de apertura sigue siendo UNO: la partida no se asienta dos
    // veces, porque la caja se abre una vez.
    expect(DB::table('ledger_entries')->where('entry_type_id', apertura_tipo_de_asiento())->count())->toBe(1);
});
