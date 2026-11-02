<?php

use App\Domain\Fiscal\NumberSeries;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.3.3-T — la reserva es atómica POR CONSTRUCCIÓN, no por lectura posterior.
 *
 * Regla que fija: el correlativo se toma y se entrega en la MISMA sentencia
 * que bloquea la fila (`UPDATE … RETURNING`). El legacy hacía `SELECT` +
 * `rowCount()` + 1 (`_inc/helper/invoice.php:95-109`): `rowCount()` de un
 * `SELECT` no es el número de filas afectadas, y separar la lectura de la
 * escritura permite que dos terminales repartan el mismo número.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
    DB::disableQueryLog();
});

test('test_la_reserva_devuelve_el_numero_reservado_en_la_misma_sentencia_que_toma_el_bloqueo', function () {
    $context = FiscalSeriesFixture::context();
    $seriesId = FiscalSeriesFixture::series($context, [
        'next_number' => 100,
        'from_number' => 100,
        'to_number' => 200,
    ]);

    // Sólo se registra lo que ocurre DENTRO de la reserva: por eso el log se
    // activa justo antes y se lee justo después.
    DB::enableQueryLog();

    $reserved = NumberSeries::reserve($seriesId);

    $reserveQueries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    // 1. Devuelve el número reservado, no el siguiente ni el último leído.
    expect($reserved)->toBe(100);

    // 2. El contador avanza exactamente uno.
    expect((int) DB::table('fiscal_series')->where('id', $seriesId)->value('next_number'))->toBe(101);

    // 3. La traza queda escrita: quién tomó el número, de qué serie y cuándo.
    $reservation = DB::table('fiscal_document_reservations')->where('series_id', $seriesId)->first();

    expect($reservation)->not->toBeNull()
        ->and((int) $reservation->number)->toBe(100)
        ->and($reservation->series_role)->toBe('document')
        ->and($reservation->reserved_by)->toBeNull()
        ->and($reservation->reserved_at)->not->toBeNull();

    // 4. LA REGLA: el número viene de la sentencia que toma el bloqueo, y no
    //    de ninguna lectura previa. Cero `SELECT` durante la reserva, y
    //    exactamente un `UPDATE` que devuelve el número en la misma sentencia.
    $selects = array_values(array_filter(
        $reserveQueries,
        fn (string $sql): bool => str_starts_with(strtolower(trim($sql)), 'select')
    ));

    expect($selects)->toBe([]);

    $updates = array_values(array_filter(
        $reserveQueries,
        fn (string $sql): bool => str_contains(strtolower($sql), 'update fiscal_series')
    ));

    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toContain('RETURNING');

    // 5. Y cuando el rango autorizado se agota, la reserva NO entrega nada a
    //    medias: falla, el contador no avanza y no queda traza de un número
    //    que no se repartió.
    $exhaustedId = FiscalSeriesFixture::series($context, [
        'next_number' => 201,
        'from_number' => 100,
        'to_number' => 200,
    ]);

    expect(fn () => NumberSeries::reserve($exhaustedId))->toThrow(RuntimeException::class);

    expect((int) DB::table('fiscal_series')->where('id', $exhaustedId)->value('next_number'))->toBe(201)
        ->and(DB::table('fiscal_document_reservations')->where('series_id', $exhaustedId)->count())->toBe(0);
});
