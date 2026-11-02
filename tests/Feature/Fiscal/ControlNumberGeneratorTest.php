<?php

use App\Domain\Fiscal\ControlNumberGenerator;
use App\Domain\Fiscal\NumberSeries;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.3.5-T — la primera asignación de control usa el identificador `00` y el
 * secuencial `1`.
 *
 * Regla que fija: *la primera asignación de control es `00` + `1`*. El número
 * de control son dos dígitos de identificador más un secuencial de hasta
 * ocho, y la serie guarda su rango total, que se registra en el documento.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_la_primera_asignacion_de_control_usa_identificador_00_y_secuencial_1', function () {
    $context = FiscalSeriesFixture::context();

    // La serie de CONTROL: la del numero de control, no la del documento.
    $seriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'control',
        'assigned_by' => 'printer',
        'control_identifier' => '00',
        'sequence_length' => 8,
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 9999,
    ]);

    $generator = new ControlNumberGenerator(DB::connection(), new NumberSeries(DB::connection()));

    $assignedAt = new DateTimeImmutable('2026-11-02 11:00:00');

    // 1. La PRIMERA asignacion: identificador `00` y secuencial `1`.
    $first = $generator->assign($seriesId, $assignedAt);

    expect($first->controlNumber)->toBe('00-00000001')
        ->and((int) $first->controlRangeFrom)->toBe(1)
        ->and((int) $first->controlRangeTo)->toBe(9999)
        ->and($first->assignedAt->format('Y-m-d H:i:s'))->toBe('2026-11-02 11:00:00');

    // 2. Y la siguiente sigue el correlativo de la serie, sin huecos.
    $second = $generator->assign($seriesId, $assignedAt);

    expect($second->controlNumber)->toBe('00-00000002');

    // 3. El correlativo se reservo de forma atomica: la traza lo deja escrito.
    $reservations = DB::table('fiscal_document_reservations')
        ->where('series_id', $seriesId)
        ->orderBy('number')
        ->get();

    expect($reservations)->toHaveCount(2)
        ->and($reservations->pluck('number')->map(fn ($n) => (int) $n)->all())->toBe([1, 2])
        ->and($reservations->pluck('series_role')->unique()->all())->toBe(['control']);
});
