<?php

use App\Domain\Accounting\CashRegisterService;
use App\Domain\Accounting\LedgerWriter;
use App\Models\CashRegister;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 0.7.5-T / 0.7.5 — la caja por FECHA DE NEGOCIO.
 *
 * Reglas que fija:
 *  - un movimiento cae en el registro de caja de SU fecha de negocio, no en el
 *    de "hoy": el legacy filtraba por `DAY()/MONTH()/YEAR()` calculados
 *    (trampa 62), que parten el dia a la medianoche del servidor y no a la del
 *    negocio;
 *  - una tienda tiene UNA caja por fecha de negocio;
 *  - una caja cerrada no admite mas movimientos.
 *
 * La asociacion del movimiento con la caja es por la CLAVE NATURAL
 * (`store_id`, `business_date`): por eso NO hay una columna `cash_register_id`
 * en `ledger_entries`.
 */
test('test_el_movimiento_cerca_del_cambio_de_dia_queda_en_el_registro_de_caja_de_su_fecha_de_negocio', function () {
    $servicio = new CashRegisterService(DB::connection());
    $escritor = new LedgerWriter(DB::connection());
    $tienda = Store::create(['name' => 'Tienda del cambio de dia']);

    // Las DOS cajas se abren en el MISMO instante: lo que las distingue no es
    // la apertura, es la FECHA DE NEGOCIO.
    Carbon::setTestNow('2026-11-05 01:30:00');

    $cajaDelDia = $servicio->open($tienda->id, new DateTimeImmutable('2026-11-04'), '100.0000');
    $cajaDeLaNoche = $servicio->open($tienda->id, new DateTimeImmutable('2026-11-05'), '150.0000');

    // Y los DOS movimientos ocurren en el MISMO instante, con fechas de
    // negocio DISTINTAS: es la operacion cerca del cambio de dia.
    $instante = new DateTimeImmutable('2026-11-05T01:30:00-04:00');

    $movimientoDelDia = $escritor->write(
        'sale',
        $tienda->id,
        $instante,
        new DateTimeImmutable('2026-11-04'),
        [
            ['account' => '4010000', 'debit' => '10.0000', 'credit' => '0'],
            ['account' => '2110000', 'debit' => '0', 'credit' => '10.0000'],
        ]
    );

    $movimientoDeLaNoche = $escritor->write(
        'sale',
        $tienda->id,
        $instante,
        new DateTimeImmutable('2026-11-05'),
        [
            ['account' => '4010000', 'debit' => '20.0000', 'credit' => '0'],
            ['account' => '2110000', 'debit' => '0', 'credit' => '20.0000'],
        ]
    );

    $asientoDelDia = DB::table('ledger_entries')->where('id', $movimientoDelDia)->first();
    $asientoDeLaNoche = DB::table('ledger_entries')->where('id', $movimientoDeLaNoche)->first();

    // Los dos asientos son del MISMO instante...
    expect((string) $asientoDelDia->occurred_at)->toBe((string) $asientoDeLaNoche->occurred_at)
        ->and((string) $asientoDelDia->business_date)->toBe('2026-11-04')
        ->and((string) $asientoDeLaNoche->business_date)->toBe('2026-11-05');

    // ...pero cada uno cae en el registro de caja de SU fecha de negocio.
    $registroDelDia = $servicio->registerFor(
        $tienda->id,
        new DateTimeImmutable((string) $asientoDelDia->business_date)
    );

    $registroDeLaNoche = $servicio->registerFor(
        $tienda->id,
        new DateTimeImmutable((string) $asientoDeLaNoche->business_date)
    );

    expect((string) $registroDelDia->business_date)->toBe('2026-11-04')
        ->and((string) $registroDeLaNoche->business_date)->toBe('2026-11-05')
        ->and((int) $registroDelDia->id)->toBe($cajaDelDia)
        ->and((int) $registroDeLaNoche->id)->toBe($cajaDeLaNoche)
        ->and((int) $registroDelDia->id)->not->toBe((int) $registroDeLaNoche->id);

    // Y las dos cajas se abrieron en el MISMO instante: la resolucion no puede
    // depender de la apertura, solo de la fecha de negocio.
    expect((string) $registroDelDia->opened_at)->toBe((string) $registroDeLaNoche->opened_at);

    // La fecha de negocio es un DATO del llamante: el servicio no la calcula.
    expect(DB::table('cash_registers')->where('store_id', $tienda->id)->orderBy('business_date')->pluck('business_date')->all())
        ->toBe(['2026-11-04', '2026-11-05']);
});

test('test_una_tienda_no_abre_dos_cajas_para_la_misma_fecha_de_negocio', function () {
    $servicio = new CashRegisterService(DB::connection());
    $tienda = Store::create(['name' => 'Tienda de una caja por dia']);

    $primera = $servicio->open($tienda->id, new DateTimeImmutable('2026-11-04'), '100.0000');

    // La segunda apertura de la MISMA fecha es un error con mensaje util, no
    // un error de PostgreSQL.
    expect(fn () => $servicio->open($tienda->id, new DateTimeImmutable('2026-11-04'), '250.0000'))
        ->toThrow(RuntimeException::class, 'ya tiene una caja abierta');

    // Y no dejo una segunda caja: la tienda tiene UNA caja por fecha.
    expect(DB::table('cash_registers')->where('store_id', $tienda->id)->count())->toBe(1)
        ->and((int) DB::table('cash_registers')->where('id', $primera)->value('opening_balance'))->toBe(100);

    // Otra fecha de negocio, en cambio, es OTRA caja.
    $siguiente = $servicio->open($tienda->id, new DateTimeImmutable('2026-11-05'), '150.0000');

    expect($siguiente)->not->toBe($primera)
        ->and(DB::table('cash_registers')->where('store_id', $tienda->id)->count())->toBe(2);
});

test('test_una_caja_cerrada_no_admite_mas_movimientos', function () {
    $servicio = new CashRegisterService(DB::connection());
    $tienda = Store::create(['name' => 'Tienda que cierra caja']);

    $caja = $servicio->open($tienda->id, new DateTimeImmutable('2026-11-04'), '100.0000');

    // Mientras esta ABIERTA, admite movimientos.
    expect((int) $servicio->registerFor($tienda->id, new DateTimeImmutable('2026-11-04'))->id)->toBe($caja);

    $servicio->close($caja, '175.5000');

    // Cerrada, NO admite mas movimientos.
    expect(fn () => $servicio->registerFor($tienda->id, new DateTimeImmutable('2026-11-04')))
        ->toThrow(RuntimeException::class, 'ya esta cerrada');

    // Y NO se puede cerrar dos veces.
    expect(fn () => $servicio->close($caja, '180.0000'))
        ->toThrow(RuntimeException::class, 'ya esta cerrada');

    // El cierre guardo el saldo OBSERVADO al contar, no uno derivado.
    $registro = DB::table('cash_registers')->where('id', $caja)->first();

    expect((string) $registro->status)->toBe('closed')
        ->and((string) $registro->closing_balance)->toBe('175.5000')
        ->and((string) $registro->closed_at)->not->toBe('');

    // Cerrar una caja que no existe tambien es un error, no un silencio.
    expect(fn () => $servicio->close($caja + 999, '10.0000'))
        ->toThrow(RuntimeException::class, 'no existe');
});

test('test_la_caja_guarda_quien_la_abrio_y_quien_la_cerro', function () {
    $servicio = new CashRegisterService(DB::connection());
    $tienda = Store::create(['name' => 'Tienda con responsables']);

    $quienAbre = User::factory()->create(['name' => 'Cajero que abre']);
    $quienCierra = User::factory()->create(['name' => 'Supervisor que cierra']);

    $caja = $servicio->open(
        $tienda->id,
        new DateTimeImmutable('2026-11-04'),
        '100.0000',
        null,
        $quienAbre->id
    );

    // Quien ABRE queda en SU columna, y todavia no hay quien la haya cerrado.
    $abierta = DB::table('cash_registers')->where('id', $caja)->first();

    expect((int) $abierta->opened_by)->toBe($quienAbre->id)
        ->and($abierta->closed_by)->toBeNull();

    // Quien CIERRA queda en la suya, y NO borra quien abrio.
    $servicio->close($caja, '175.5000', $quienCierra->id);

    $cerrada = DB::table('cash_registers')->where('id', $caja)->first();

    expect((int) $cerrada->opened_by)->toBe($quienAbre->id)
        ->and((int) $cerrada->closed_by)->toBe($quienCierra->id);

    // El modelo EXPONE las dos relaciones.
    $registro = CashRegister::find($caja);

    expect($registro->openedBy->id)->toBe($quienAbre->id)
        ->and($registro->closedBy->id)->toBe($quienCierra->id);

    // Una caja abierta SIN responsable NO inventa uno: la columna queda en
    // null, y el hueco se ve.
    $sinActor = $servicio->open($tienda->id, new DateTimeImmutable('2026-11-05'), '50.0000');

    expect(DB::table('cash_registers')->where('id', $sinActor)->value('opened_by'))->toBeNull()
        ->and(CashRegister::find($sinActor)->opened_by)->toBeNull();
});
