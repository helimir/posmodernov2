<?php

use App\Domain\Fiscal\NumberSeries;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * T3 — un rollback devuelve el número; una anulación NO.
 *
 * Regla que fija: una transacción fallida devuelve el número, pero una
 * anulación no lo hace. La devolución se verifica desde la SEGUNDA conexión
 * (`pgsql_b`), que no ve la transacción no commiteada: así se prueba que el
 * número volvió de verdad y no sólo en la vista de la sesión que reservó.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_el_rollback_de_la_reserva_devuelve_el_numero_y_se_verifica_desde_la_segunda_conexion', function () {
    $context = FiscalSeriesFixture::context();
    $seriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'next_number' => 100,
        'from_number' => 100,
        'to_number' => 200,
    ]);

    $a = DB::connection('pgsql');
    $b = DB::connection('pgsql_b');

    // La reserva ocurre DENTRO de una transacción que luego se revierte: el
    // fallo de la factura devuelve el número.
    $a->beginTransaction();

    try {
        expect((new NumberSeries($a))->reserve($seriesId))->toBe(100);

        throw new RuntimeException('fallo forzado despues de reservar');
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->toBe('fallo forzado despues de reservar');
    } finally {
        $a->rollBack();
    }

    // La conexión B nunca vio la transacción no commiteada: el número volvió
    // DE VERDAD, no sólo en la vista de la sesión A.
    expect((int) $b->table('fiscal_series')->where('id', $seriesId)->value('next_number'))->toBe(100)
        ->and((int) $b->table('fiscal_document_reservations')->where('series_id', $seriesId)->where('number', 100)->count())
        ->toBe(0);

    // Y la siguiente reserva devuelve el MISMO número: la serie no tiene hueco.
    expect((new NumberSeries($b))->reserve($seriesId))->toBe(100);

    expect((int) $b->table('fiscal_series')->where('id', $seriesId)->value('next_number'))->toBe(101)
        ->and((int) $b->table('fiscal_document_reservations')->where('series_id', $seriesId)->where('number', 100)->count())
        ->toBe(1);
});
