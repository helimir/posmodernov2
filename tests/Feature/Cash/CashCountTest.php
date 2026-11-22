<?php

use App\Domain\Accounting\CashDenominations;
use App\Support\Pos\PosAmount;
use App\Support\Pos\PosContext;
use Database\Seeders\CashDenominationSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PosFixture;

/**
 * El ARQUEO CON MOTIVO Y POR DENOMINACION.
 *
 * Reglas que fija:
 *
 *  - el DESGLOSE ESPERADO reparte un importe en las piezas de la tienda, de
 *    MAYOR a MENOR (que es como cuenta un cajero), y avisa cuando el importe
 *    no se puede formar con las denominaciones disponibles;
 *  - el CONTEO REAL son las piezas que el cajero cuenta, y su suma es el
 *    saldo contado: el cajero no teclea una cifra, cuenta;
 *  - la DIFERENCIA por denominacion NOMBRA la pieza que sobra o falta;
 *  - un DESCUADRE por encima del umbral NO se cierra sin MOTIVO, y el motivo
 *    queda en la caja y en el Z sellado;
 *  - el cierre se hace POR IMPORTE o POR CONTEO DE PIEZAS, nunca por los dos:
 *    son dos verdades y el cierre solo puede tener una.
 */

/**
 * La MONEDA de la tienda del usuario: la resuelve el CONTEXTO, y no un
 * literal.
 *
 * @return array{id: int, code: string}
 */
function caja_conteo_moneda(int $usuarioId): array
{
    $codigo = (new PosContext(DB::connection(), $usuarioId))->currencyCode();

    return [
        'id' => (int) DB::table('currencies')->where('code', $codigo)->value('id'),
        'code' => $codigo,
    ];
}

/**
 * El CATALOGO de denominaciones de una tienda: es DATO, y lo escribe el
 * propio test.
 *
 * @param  list<array{0: string, 1: string}>  $denominaciones  Valor y tipo.
 * @return array<string, int> El id de cada denominacion, por valor.
 */
function caja_conteo_catalogo(int $storeId, int $currencyId, array $denominaciones): array
{
    $ids = [];

    foreach ($denominaciones as [$valor, $tipo]) {
        $ids[$valor] = (int) DB::table('cash_denominations')->insertGetId([
            'store_id' => $storeId,
            'currency_id' => $currencyId,
            'value' => $valor,
            'kind' => $tipo,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $ids;
}

/**
 * El CATALOGO de la prueba: dos billetes (200 y 50), un billete de 5 y cuatro
 * monedas (1, 0.50, 0.10 y 0.05).
 *
 * @return list<array{0: string, 1: string}>
 */
function caja_conteo_denominaciones(): array
{
    return [
        ['200.0000', 'billete'],
        ['50.0000', 'billete'],
        ['5.0000', 'billete'],
        ['1.0000', 'moneda'],
        ['0.5000', 'moneda'],
        ['0.1000', 'moneda'],
        ['0.0500', 'moneda'],
    ];
}

/**
 * El DESGLOSE ESPERADO reparte el importe en las piezas de la tienda, de
 * mayor a menor: 255 son un billete de 200, uno de 50 y uno de 5.
 */
test('test_el_desglose_esperado_reparte_el_importe_en_piezas_de_mayor_a_menor', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);
    $moneda = caja_conteo_moneda((int) $usuario->id);

    caja_conteo_catalogo($escenario['store_id'], $moneda['id'], caja_conteo_denominaciones());

    $servicio = new CashDenominations(DB::connection());
    $catalogo = $servicio->catalog($escenario['store_id']);

    // El catalogo viene de MAYOR a MENOR: asi se cuenta.
    expect($catalogo)->toHaveCount(7)
        ->and($catalogo[0]['value'])->toBe('200.0000')
        ->and($catalogo[6]['value'])->toBe('0.0500');

    $desglose = $servicio->expected('255.0000', $catalogo);

    // Y el desglose CIERRA: no sobra nada y no falta nada.
    expect($desglose['missing'])->toBeNull()
        ->and($desglose['residual'])->toBeNull()
        ->and($desglose['total'])->toBe('255.0000');

    $piezas = [];

    foreach ($desglose['pieces'] as $pieza) {
        $piezas[$pieza['value']] = $pieza['count'];
    }

    expect($piezas['200.0000'])->toBe(1)
        ->and($piezas['50.0000'])->toBe(1)
        ->and($piezas['5.0000'])->toBe(1)
        ->and($piezas['1.0000'])->toBe(0)
        ->and($piezas['0.0500'])->toBe(0);
});

/**
 * Un importe que NO se puede formar con las denominaciones de la tienda dice
 * QUE FALTA: sin centavos de 0.01 no hay con que formar el ultimo centimo.
 */
test('test_un_importe_que_no_se_puede_formar_con_las_denominaciones_dice_que_falta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);
    $moneda = caja_conteo_moneda((int) $usuario->id);

    caja_conteo_catalogo($escenario['store_id'], $moneda['id'], caja_conteo_denominaciones());

    $servicio = new CashDenominations(DB::connection());
    $catalogo = $servicio->catalog($escenario['store_id']);

    // 255.01 no se puede formar: la moneda mas pequena de la tienda es de
    // 0.05, y no hay con que pagar el centimo que sobra.
    $desglose = $servicio->expected('255.0100', $catalogo);

    expect($desglose['missing'])->not->toBeNull()
        ->and($desglose['missing'])->toContain('0.01')
        ->and($desglose['missing'])->toContain('no se puede formar')
        ->and($desglose['residual'])->toBe('0.0100');
});

/**
 * El CONTEO REAL calcula el saldo contado: el cajero cuenta piezas y el
 * sistema las suma.
 */
test('test_el_conteo_real_calcula_el_saldo_contado', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);
    $moneda = caja_conteo_moneda((int) $usuario->id);

    $ids = caja_conteo_catalogo($escenario['store_id'], $moneda['id'], caja_conteo_denominaciones());

    $servicio = new CashDenominations(DB::connection());
    $catalogo = $servicio->catalog($escenario['store_id']);

    // El cajero cuenta un billete de 200, uno de 50 y uno de 5.
    $conteo = [
        $ids['200.0000'] => 1,
        $ids['50.0000'] => 1,
        $ids['5.0000'] => 1,
    ];

    expect($servicio->countedTotal($conteo, $catalogo))->toBe('255.0000');
});

/**
 * Cuando el cajero cuenta BIEN, el conteo real coincide con el esperado: la
 * diferencia por denominacion es cero y el descuadre es cero.
 */
test('test_el_conteo_real_coincide_con_el_esperado_cuando_el_cajero_cuenta_bien', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);
    $moneda = caja_conteo_moneda((int) $usuario->id);

    caja_conteo_catalogo($escenario['store_id'], $moneda['id'], caja_conteo_denominaciones());

    $servicio = new CashDenominations(DB::connection());
    $catalogo = $servicio->catalog($escenario['store_id']);

    $desglose = $servicio->expected('255.0000', $catalogo);

    $conteo = [];

    foreach ($desglose['pieces'] as $pieza) {
        $conteo[$pieza['id']] = $pieza['count'];
    }

    expect($servicio->countedTotal($conteo, $catalogo))->toBe($desglose['total'])
        ->and($servicio->differences($desglose['pieces'], $conteo))->toBe([]);
});

/**
 * La DIFERENCIA por denominacion NOMBRA la pieza que sobra o falta: "sobran 2
 * billetes de 50".
 */
test('test_la_diferencia_por_denominacion_nombra_la_pieza_que_sobra_o_falta', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);
    $moneda = caja_conteo_moneda((int) $usuario->id);

    $ids = caja_conteo_catalogo($escenario['store_id'], $moneda['id'], caja_conteo_denominaciones());

    $servicio = new CashDenominations(DB::connection());
    $catalogo = $servicio->catalog($escenario['store_id']);

    $desglose = $servicio->expected('255.0000', $catalogo);

    // El cajero cuenta TRES billetes de 50 donde deberia haber uno: sobran dos.
    $conteo = [
        $ids['200.0000'] => 1,
        $ids['50.0000'] => 3,
        $ids['5.0000'] => 1,
    ];

    $diferencias = $servicio->differences($desglose['pieces'], $conteo);

    expect($diferencias)->toHaveCount(1)
        ->and($diferencias[0]['value'])->toBe('50.0000')
        ->and($diferencias[0]['kind'])->toBe('billete')
        ->and($diferencias[0]['expected'])->toBe(1)
        ->and($diferencias[0]['counted'])->toBe(3)
        ->and($diferencias[0]['delta'])->toBe(2);

    // Y la pieza se NOMBRA: sobra o falta, y de cuanto.
    expect($servicio->describeDifference($diferencias[0]))->toBe('Sobran 2 billetes de 50.');

    // Y lo que FALTA se nombra tambien.
    $faltan = $servicio->differences($desglose['pieces'], [
        $ids['200.0000'] => 1,
        $ids['50.0000'] => 0,
        $ids['5.0000'] => 0,
    ]);

    expect($servicio->describeDifference($faltan[0]))->toBe('Faltan 1 billete de 50.');
});

/**
 * Un CIERRE sin MOTIVO y con un descuadre por encima del umbral FALLA: el
 * mensaje dice el descuadre y dice que hace falta el motivo.
 */
test('test_el_cierre_sin_motivo_con_un_descuadre_por_encima_del_umbral_falla', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // El esperado son 100 (la partida, que ya esta en el libro) y se cuentan
    // 97: el descuadre es de 3.
    $respuesta = $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '97.0000'])
        ->assertStatus(422);

    $mensaje = (string) $respuesta->json('errorMsg');

    // El mensaje dice el DESCUADRE y que hace falta el MOTIVO.
    expect($mensaje)->toContain('3')
        ->and($mensaje)->toContain('MOTIVO');

    // Y la caja sigue ABIERTA: un cierre invalido no cierra nada.
    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('status'))->toBe('open')
        ->and(DB::table('cash_closes')->where('store_id', $escenario['store_id'])->count())->toBe(0);
});

/**
 * Un CIERRE con MOTIVO guarda el motivo en la caja (la columna que ya
 * existia) Y en el Z sellado: el Z dice POR QUE se cerro asi.
 */
test('test_el_cierre_con_motivo_guarda_el_motivo_en_la_caja_y_en_el_z', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $motivo = 'Faltaba un billete de 5 en el cajon';

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '97.0000', 'reason' => $motivo])
        ->assertCreated()
        ->assertJsonPath('difference', '-3.0000');

    // El MOTIVO queda en la caja.
    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('note'))->toBe($motivo);

    // Y queda en el Z SELLADO: el cierre dice por que se cerro asi.
    $z = DB::table('cash_closes')
        ->where('store_id', $escenario['store_id'])
        ->orderBy('id', 'desc')
        ->first();

    expect($z)->not->toBeNull()
        ->and((string) $z->close_reason)->toBe($motivo);
});

/**
 * Un DESCUADRE por debajo del umbral NO exige motivo: una diferencia de 3
 * con el umbral en 5 se cierra sin explicacion.
 */
test('test_un_descuadre_por_debajo_del_umbral_no_exige_motivo', function () {
    config(['cash.difference_reason_threshold' => '5.0000']);

    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    // Con el umbral en 5, un descuadre de 3 esta POR DEBAJO: no hay motivo.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '97.0000'])
        ->assertCreated()
        ->assertJsonPath('difference', '-3.0000');

    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('note'))->toBe('');
});

/**
 * Una caja que CUADRA no pide motivo: el descuadre de cero no es un
 * descuadre.
 */
test('test_una_caja_que_cuadra_no_pide_motivo', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '100.0000'])
        ->assertCreated()
        ->assertJsonPath('difference', '0.0000');
});

/**
 * El CIERRE POR CONTEO DE PIEZAS: el cajero cuenta las piezas y el sistema
 * CALCULA el importe. El importe no se acepta por separado.
 */
test('test_el_cierre_por_conteo_de_piezas_calcula_el_importe_contado', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);
    $moneda = caja_conteo_moneda((int) $usuario->id);

    $ids = caja_conteo_catalogo($escenario['store_id'], $moneda['id'], caja_conteo_denominaciones());

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '255.0000'])
        ->assertCreated();

    // El cajero cuenta un billete de 200, uno de 50 y uno de 5: son 255.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', [
            'counted_pieces' => [
                (string) $ids['200.0000'] => 1,
                (string) $ids['50.0000'] => 1,
                (string) $ids['5.0000'] => 1,
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('closing_balance', '255.0000')
        ->assertJsonPath('expected_balance', '255.0000')
        ->assertJsonPath('difference', '0.0000');

    // Y el CONTEO queda en el Z: el cierre guarda las piezas, no solo la cifra.
    $z = DB::table('cash_closes')
        ->where('store_id', $escenario['store_id'])
        ->orderBy('id', 'desc')
        ->first();

    $piezas = json_decode((string) $z->counted_pieces, true);

    expect($piezas)->toBeArray()
        ->and($piezas)->toHaveCount(3)
        ->and(collect($piezas)->pluck('count')->sum())->toBe(3);
});

/**
 * El cierre NO admite el importe Y el conteo de piezas a la vez: son dos
 * verdades y el cierre solo puede tener una.
 */
test('test_el_cierre_no_admite_el_importe_y_el_conteo_de_piezas_a_la_vez', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);
    $moneda = caja_conteo_moneda((int) $usuario->id);

    $ids = caja_conteo_catalogo($escenario['store_id'], $moneda['id'], caja_conteo_denominaciones());

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '255.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', [
            'closing_balance' => '255.0000',
            'counted_pieces' => [(string) $ids['200.0000'] => 1],
        ])
        ->assertStatus(422);

    // Y la caja sigue ABIERTA.
    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('status'))->toBe('open');
});

/**
 * La PANTALLA de la caja del dia gana la tabla de denominaciones: el
 * esperado, el contado y la diferencia por pieza.
 */
test('test_la_pagina_de_la_caja_del_dia_muestra_la_tabla_de_denominaciones', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);
    $moneda = caja_conteo_moneda((int) $usuario->id);

    $ids = caja_conteo_catalogo($escenario['store_id'], $moneda['id'], caja_conteo_denominaciones());

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '255.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            // El CATALOGO de la tienda.
            ->has('count.denominations', 7)
            ->where('count.denominations.0.value', '200.0000')
            ->where('count.denominations.0.kind', 'billete')
            // El ESPERADO: 255 son un billete de 200, uno de 50 y uno de 5.
            ->has('count.expected', 7)
            ->where('count.expected_error', null)
            // Y el UMBRAL: el importe a partir del cual hace falta el motivo.
            ->where('count.threshold', '0.0100')
            ->etc());

    $esperado = collect(DB::table('cash_denominations')
        ->where('store_id', $escenario['store_id'])
        ->orderByDesc('value')
        ->pluck('value', 'id')
        ->all());

    expect($esperado->get($ids['200.0000']))->toBe('200.0000');
});

/**
 * El SEMBRADOR siembra el catalogo de la tienda de demostracion: las
 * denominaciones son DATO del negocio, no codigo.
 */
test('test_el_sembrador_siembra_el_catalogo_de_denominaciones_de_la_tienda_de_demostracion', function () {
    PosFixture::escenario();

    (new CashDenominationSeeder)->run();

    $filas = DB::table('cash_denominations')
        ->where('store_id', DB::table('stores')->where('code_name', 'DEMO')->value('id'))
        ->orderByDesc('value')
        ->get();

    expect($filas)->not->toBeEmpty()
        // Y hay billetes y monedas: el cajero cuenta las dos cosas.
        ->and($filas->pluck('kind')->unique()->sort()->values()->all())->toBe(['billete', 'moneda'])
        // Y todas las piezas tienen un valor positivo.
        ->and(collect($filas)->every(fn ($fila): bool => (float) $fila->value > 0))->toBeTrue();
});

/**
 * El cierre por conteo RECHAZA una pieza que no es del catalogo de la tienda:
 * el conteo de una caja no puede traer piezas que la tienda no tiene.
 */
test('test_el_cierre_por_conteo_rechaza_una_pieza_que_no_es_del_catalogo_de_la_tienda', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);
    $moneda = caja_conteo_moneda((int) $usuario->id);

    $ids = caja_conteo_catalogo($escenario['store_id'], $moneda['id'], caja_conteo_denominaciones());

    // Una pieza de OTRA tienda: la misma moneda, pero de otra tienda.
    $otra = DB::table('stores')->insertGetId([
        'name' => 'Tienda de otra caja',
        'code_name' => 'CAJA-COUNT-OTRA',
        'sort_order' => 99,
        'status' => true,
        'feedback_status' => 'ready',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $piezaAjena = (int) DB::table('cash_denominations')->insertGetId([
        'store_id' => $otra,
        'currency_id' => $moneda['id'],
        'value' => '500.0000',
        'kind' => 'billete',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '255.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', [
            'counted_pieces' => [
                (string) $ids['200.0000'] => 1,
                (string) $piezaAjena => 1,
            ],
        ])
        ->assertStatus(422);

    // Y la caja sigue ABIERTA: un conteo invalido no cierra nada.
    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('status'))->toBe('open');
});

/**
 * El importe legible: un descuadre de 3.0000 se dice "de 3", y uno de 0.0100
 * se dice "de 0.01".
 */
test('test_el_importe_legible_no_trae_ceros_de_mas', function () {
    expect(PosAmount::legible('3.0000'))->toBe('3')
        ->and(PosAmount::legible('0.0100'))->toBe('0.01')
        ->and(PosAmount::legible('0.0000'))->toBe('0')
        ->and(PosAmount::legible('12.5000'))->toBe('12.5');
});
