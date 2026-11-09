<?php

use App\Domain\Fiscal\ControlNumberGenerator;
use App\Domain\Fiscal\NumberSeries;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.9.11 — hay dos numeraciones con autoridades distintas.
 *
 * Regla que fija: *hay dos numeraciones con autoridades distintas*. Un
 * documento reserva UN número de documento (del emisor) y OTRO número de
 * control (de la imprenta digital), y son DOS RESERVAS DISTINTAS: cada una
 * en su serie, con su rol y con su autoridad. El correlativo del documento no
 * es el del control, y el uno no avanza al otro.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_un_documento_reserva_un_numero_de_documento_y_otro_de_control_en_reservas_distintas', function () {
    $context = FiscalSeriesFixture::context();

    // 1. Las DOS series: la del documento (del emisor) y la del control (de
    //    la imprenta digital). Son de Series distintas, con autoridades
    //    distintas.
    $serieDelDocumento = FiscalSeriesFixture::series($context, [
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'next_number' => 42,
        'from_number' => 1,
        'to_number' => 9999,
    ]);

    $serieDelControl = FiscalSeriesFixture::series($context, [
        'series_role' => 'control',
        'assigned_by' => 'printer',
        'control_identifier' => '00',
        'sequence_length' => 8,
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 9999,
    ]);

    expect($serieDelDocumento)->not->toBe($serieDelControl);

    // 2. El numero del DOCUMENTO, reservado del emisor.
    $numeroDelDocumento = (new NumberSeries(DB::connection()))->reserve($serieDelDocumento);

    // 3. Y el numero del CONTROL, reservado de la serie de la imprenta: la
    //    asignacion del control RESERVA, no inventa el numero.
    $control = (new ControlNumberGenerator(DB::connection(), new NumberSeries(DB::connection())))
        ->assign($serieDelControl, new DateTimeImmutable('2026-11-02 11:00:00'));

    expect($numeroDelDocumento)->toBe(42)
        ->and($control->controlNumber)->toBe('00-00000001');

    // 4. Son DOS RESERVAS DISTINTAS: cada una en su serie, con su rol y con
    //    su autoridad.
    $reservas = DB::table('fiscal_document_reservations')
        ->orderBy('id')
        ->get();

    expect($reservas)->toHaveCount(2)
        ->and((int) $reservas[0]->series_id)->toBe($serieDelDocumento)
        ->and((string) $reservas[0]->series_role)->toBe('document')
        ->and((int) $reservas[0]->number)->toBe(42)
        ->and((int) $reservas[1]->series_id)->toBe($serieDelControl)
        ->and((string) $reservas[1]->series_role)->toBe('control')
        ->and((int) $reservas[1]->number)->toBe(1);

    // 5. Y el uno NO es el otro: son dos numeraciones, no una con dos roles.
    expect((int) $reservas[0]->number)->not->toBe((int) $reservas[1]->number);

    // 6. Cada serie avanza por su lado: el documento no consume el correlativo
    //    del control, ni al reves.
    expect((int) DB::table('fiscal_series')->where('id', $serieDelDocumento)->value('next_number'))->toBe(43)
        ->and((int) DB::table('fiscal_series')->where('id', $serieDelControl)->value('next_number'))->toBe(2);
});
