<?php

use App\Domain\Accounting\CashClose;
use App\Domain\Accounting\CashRegisterService;
use App\Domain\Accounting\LedgerWriter;
use App\Support\Pos\PosContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PosFixture;

/**
 * EL Z SELLADO: el cierre de caja como HECHO.
 *
 * EL DEFECTO QUE ESTA UNIDAD CIERRA: el Z no existia. El descuadre se
 * CALCULABA en vivo, asi que si manana se corrigia una venta de ayer, el
 * "cierre" de ayer cambiaba. Un cierre que se puede reescribir desde el pasado
 * no es un cierre: es un calculo.
 *
 * Reglas que fija:
 *
 *  - al cerrar se escribe el Z con su contenido (los totales, el desglose por
 *    medio de pago, el numero de ventas y los correlativos), en la MISMA
 *    transaccion que el cierre: si el snapshot falla, el cierre falla, y no
 *    hay un dia cerrado sin Z;
 *  - el Z lleva la FIRMA de su contenido (`sha256`), y la firma se VERIFICA al
 *    leerlo: un Z alterado se avisa;
 *  - un dia cerrado NO se recalcula: aunque se corrija una venta de ayer, el Z
 *    no cambia;
 *  - REABRIR un cierre exige un MOTIVO, deja el Z anterior marcado en el
 *    historial, y permite volver a cerrar con OTRA fila de Z.
 */

/**
 * Una VENTA de la caja: dos unidades a 25, con un 16 % de impuesto.
 *
 * @param  array<string, mixed>  $escenario
 * @return array<string, mixed>
 */
function z_pedido_de_venta(array $escenario): array
{
    return [
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
}

/**
 * El Z de una tienda, en su ultima fila.
 */
function z_ultimo_de_la_tienda(int $storeId, string $fecha): ?object
{
    return DB::table('cash_closes')
        ->where('store_id', $storeId)
        ->where('business_date', $fecha)
        ->orderBy('id', 'desc')
        ->first();
}

/**
 * Al CERRAR se escribe el Z con su contenido.
 */
test('test_al_cerrar_se_escribe_el_z_con_su_contenido', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', z_pedido_de_venta($escenario))
        ->assertCreated();

    $fecha = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate()->format('Y-m-d');

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', [
            'closing_balance' => '160.0000',
            'reason' => 'Sobran dos unidades en el conteo',
        ])
        ->assertCreated();

    $z = z_ultimo_de_la_tienda((int) $escenario['store_id'], $fecha);

    expect($z)->not->toBeNull('El cierre no sello ningun Z.')
        // LOS TOTALES del dia: 2 x 25 con un 16 % de impuesto son 58.
        ->and((string) $z->subtotal)->toBe('50.0000')
        ->and((string) $z->tax_total)->toBe('8.0000')
        ->and((string) $z->grand_total)->toBe('58.0000')
        // EL NUMERO DE VENTAS.
        ->and((int) $z->sales_count)->toBe(1)
        // LA PARTIDA, el ESPERADO, el CONTADO y el DESCUADRE.
        ->and((string) $z->opening_balance)->toBe('100.0000')
        ->and((string) $z->expected_balance)->toBe('158.0000')
        ->and((string) $z->counted_balance)->toBe('160.0000')
        ->and((string) $z->difference)->toBe('2.0000')
        // QUIEN CERRO y CUANDO.
        ->and((int) $z->closed_by)->toBe((int) $usuario->id)
        ->and((string) $z->closed_at)->not->toBe('')
        // Y LA FIRMA de su contenido.
        ->and((string) $z->content_hash)->toHaveLength(64);
});

/**
 * El Z lleva el DESGLOSE por medio de pago y los CORRELATIVOS del dia.
 */
test('test_el_z_lleva_el_desglose_por_medio_de_pago_y_los_correlativos', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '0.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', z_pedido_de_venta($escenario))
        ->assertCreated();

    $fecha = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate()->format('Y-m-d');

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '58.0000'])
        ->assertCreated();

    $z = z_ultimo_de_la_tienda((int) $escenario['store_id'], $fecha);

    // EL DESGLOSE: lo que entro en la caja y por donde.
    $pagos = json_decode((string) $z->payments, true);

    expect($pagos)->toHaveCount(1)
        ->and((int) $pagos[0]['id'])->toBe((int) $escenario['payment_method_id'])
        ->and((string) $pagos[0]['amount'])->toBe('58.0000')
        ->and((bool) $pagos[0]['is_cash'])->toBeTrue();

    // Y LOS CORRELATIVOS: el documento del dia.
    expect((string) $z->first_document_number)->not->toBe('')
        ->and((string) $z->last_document_number)->toBe((string) $z->first_document_number);
});

/**
 * EL Z Y EL CIERRE VAN EN LA MISMA TRANSACCION: si el snapshot falla, el
 * cierre falla. No hay un dia cerrado sin Z.
 */
test('test_si_el_snapshot_falla_el_cierre_falla_y_no_hay_un_dia_cerrado_sin_z', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $cajaId = (int) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('id');

    // El Z de ESTE cierre no se puede escribir: la base lo rechaza. Es la
    // forma de que el snapshot falle de verdad.
    DB::statement(
        'ALTER TABLE cash_closes ADD CONSTRAINT z_de_prueba CHECK (counted_balance <> 999.0000)'
    );

    $servicio = new CashRegisterService(DB::connection());

    expect(fn () => $servicio->close($cajaId, '999.0000', (int) $usuario->id, 'Diferencia de conteo justificada'))
        ->toThrow(QueryException::class);

    // Y la caja sigue ABIERTA: no hay un dia cerrado sin Z.
    expect((string) DB::table('cash_registers')->where('id', $cajaId)->value('status'))->toBe('open')
        ->and(DB::table('cash_closes')->count())->toBe(0);
});

/**
 * UN DIA CERRADO NO SE RECALCULA: aunque se corrija una venta de ayer, el Z
 * no cambia.
 */
test('test_un_dia_cerrado_no_se_recalcula_cuando_se_corrige_una_venta_de_ayer', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/cobrar', z_pedido_de_venta($escenario))
        ->assertCreated();

    $contexto = new PosContext(DB::connection(), (int) $usuario->id);
    $fecha = $contexto->businessDate()->format('Y-m-d');

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', [
            'closing_balance' => '160.0000',
            'reason' => 'Sobran dos unidades en el conteo',
        ])
        ->assertCreated();

    $z = z_ultimo_de_la_tienda((int) $escenario['store_id'], $fecha);

    // SE CORRIGE UNA VENTA DE AYER: un movimiento mas en ese dia.
    (new LedgerWriter(DB::connection()))->write(
        entryType: 'adjustment',
        storeId: (int) $escenario['store_id'],
        occurredAt: new DateTimeImmutable('now'),
        businessDate: $contexto->businessDate(),
        lines: [
            ['account' => '1100000', 'debit' => '7.0000', 'credit' => '0'],
            ['account' => '4010000', 'debit' => '0', 'credit' => '7.0000'],
        ],
    );

    // EL Z NO CAMBIA: lo que se cerro ese dia se queda.
    $despues = z_ultimo_de_la_tienda((int) $escenario['store_id'], $fecha);

    expect((string) $despues->expected_balance)->toBe((string) $z->expected_balance)
        ->and((string) $despues->counted_balance)->toBe((string) $z->counted_balance)
        ->and((string) $despues->difference)->toBe((string) $z->difference)
        ->and((string) $despues->content_hash)->toBe((string) $z->content_hash);

    // Y la pantalla enseña las cifras SELLADAS, no un recalculo: el saldo en
    // curso del libro ya no es el de ayer.
    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('state', 'closed')
            ->where('expected_balance', '158.0000')
            ->where('counted_balance', '160.0000')
            ->where('difference', '2.0000')
            // El libro ya dice 165, pero el Z dice 158: el Z manda.
            ->where('running_balance', '165.0000')
            ->etc());
});

/**
 * LA FIRMA SE VERIFICA AL LEER, y un Z ALTERADO se avisa.
 */
test('test_la_firma_del_z_se_verifica_al_leerlo_y_un_z_alterado_se_avisa', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $fecha = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate()->format('Y-m-d');

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '100.0000'])
        ->assertCreated();

    $z = z_ultimo_de_la_tienda((int) $escenario['store_id'], $fecha);

    // El Z recien sellado VERIFICA.
    expect(CashClose::verify($z))->toBeTrue();

    // Y ALGUIEN LO ALTERA: se cambia el saldo contado, sin la firma.
    DB::table('cash_closes')
        ->where('id', $z->id)
        ->update(['counted_balance' => '1.0000']);

    $alterado = z_ultimo_de_la_tienda((int) $escenario['store_id'], $fecha);

    // Y la firma NO cuadra: el Z fue tocado.
    expect(CashClose::verify($alterado))->toBeFalse();

    // Y la pantalla lo dice.
    $this->actingAs($usuario)
        ->get('/caja/dia')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('cash/day')
            ->where('z.verified', false)
            ->etc());
});

/**
 * REABRIR pide un MOTIVO, deja el Z anterior marcado en el historial y
 * permite VOLVER A CERRAR (con otra fila de Z, y la anterior sigue).
 */
test('test_reabrir_deja_el_z_anterior_marcado_y_permite_volver_a_cerrar', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '100.0000'])
        ->assertCreated();

    $fecha = (new PosContext(DB::connection(), (int) $usuario->id))->businessDate()->format('Y-m-d');

    $primero = z_ultimo_de_la_tienda((int) $escenario['store_id'], $fecha);

    // REABRIR, con su motivo.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/reabrir', ['reason' => 'Se corrigio una venta del dia'])
        ->assertOk()
        ->assertJsonPath('status', 'open');

    // La caja vuelve a estar ABIERTA.
    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('status'))->toBe('open');

    // EL Z ANTERIOR NO SE BORRA: se queda, marcado, con su motivo y con quien.
    $marcado = DB::table('cash_closes')->where('id', $primero->id)->first();

    expect($marcado)->not->toBeNull()
        ->and((string) $marcado->reopen_reason)->toBe('Se corrigio una venta del dia')
        ->and((int) $marcado->reopened_by)->toBe((int) $usuario->id)
        ->and((string) $marcado->reopened_at)->not->toBe('');

    // Y la REAPERTURA ESTA AUDITADA: quien deshizo el cierre y por que.
    $auditoria = DB::table('audit_logs')
        ->where('action', 'cash_register.reopened')
        ->where('entity_id', (int) $marcado->cash_register_id)
        ->first();

    expect($auditoria)->not->toBeNull()
        ->and((int) $auditoria->user_id)->toBe((int) $usuario->id)
        ->and((string) $auditoria->context)->toContain('Se corrigio una venta del dia');

    // VOLVER A CERRAR: se escribe OTRA fila de Z, y la anterior sigue. Y el
    // descuadre vuelve a exigir su MOTIVO.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', [
            'closing_balance' => '110.0000',
            'reason' => 'Diferencia de conteo justificada',
        ])
        ->assertCreated();

    $filas = DB::table('cash_closes')
        ->where('store_id', $escenario['store_id'])
        ->where('business_date', $fecha)
        ->orderBy('id')
        ->get();

    expect($filas)->toHaveCount(2);

    $vigente = $filas->last();

    // La VIGENTE es la ultima fila NO reabierta.
    expect((int) $vigente->id)->not->toBe((int) $primero->id)
        ->and($vigente->reopened_at)->toBeNull()
        ->and((string) $vigente->counted_balance)->toBe('110.0000')
        // Y la anterior sigue en el historial.
        ->and((string) $filas->first()->reopen_reason)->toBe('Se corrigio una venta del dia');
});

/**
 * REABRIR SIN MOTIVO falla: un dia reabierto sin motivo es un dia que nadie
 * sabe por que se toco.
 */
test('test_reabrir_sin_motivo_falla', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/cerrar', ['closing_balance' => '100.0000'])
        ->assertCreated();

    // SIN MOTIVO.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/reabrir', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // Y con un motivo en blanco.
    $this->actingAs($usuario)
        ->postJson('/caja/dia/reabrir', ['reason' => '   '])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);

    // La caja sigue CERRADA y el Z sigue sellado.
    expect((string) DB::table('cash_registers')
        ->where('store_id', $escenario['store_id'])
        ->value('status'))->toBe('closed');

    expect(DB::table('cash_closes')->where('reopened_at', null)->count())->toBe(1);
});

/**
 * REABRIR una caja que NO esta cerrada falla: no hay un cierre que deshacer.
 */
test('test_reabrir_una_caja_abierta_falla', function () {
    $escenario = PosFixture::escenario();
    $usuario = PosFixture::usuarioSinCaja($escenario['store_id']);

    $this->actingAs($usuario)
        ->postJson('/caja/dia/abrir', ['opening_balance' => '100.0000'])
        ->assertCreated();

    $this->actingAs($usuario)
        ->postJson('/caja/dia/reabrir', ['reason' => 'No hacia falta'])
        ->assertStatus(422)
        ->assertJsonStructure(['errorMsg']);
});
