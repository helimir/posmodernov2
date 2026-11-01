<?php

use App\Domain\Fiscal\NumberSeries;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * T1 — la secuencia de reservas no deja huecos ni repite número.
 *
 * Regla que fija: el correlativo es consecutivo y único; el número reservado
 * nunca se duplica. Mecanismo del design §E.1: dos sesiones PostgreSQL,
 * `TRUNCATE … RESTART IDENTITY CASCADE` en `afterEach` y **sin**
 * `RefreshDatabase` (aquí no hace falta la segunda sesión).
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_cincuenta_reservas_consecutivas_no_dejan_huecos_ni_repiten_numero', function () {
    $context = FiscalSeriesFixture::context();
    $seriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'next_number' => 100,
        'from_number' => 100,
        'to_number' => 200,
    ]);

    $series = new NumberSeries(DB::connection());

    $numbers = [];
    for ($reservation = 0; $reservation < 50; $reservation++) {
        $numbers[] = $series->reserve($seriesId);
    }

    // 50 correlativos consecutivos, ascendentes: sin huecos y sin repeticiones.
    expect($numbers)->toBe(range(100, 149))
        ->and(array_values(array_unique($numbers)))->toHaveCount(50)
        ->and((int) DB::table('fiscal_series')->where('id', $seriesId)->value('next_number'))->toBe(150);

    // La traza: 50 reservas, con el rol de la serie.
    $reservations = DB::table('fiscal_document_reservations')->where('series_id', $seriesId)->get();

    expect($reservations)->toHaveCount(50)
        ->and($reservations->pluck('number')->map(fn ($number): int => (int) $number)->sort()->values()->all())
        ->toBe(range(100, 149))
        ->and($reservations->pluck('series_role')->unique()->all())->toBe(['document']);
});
