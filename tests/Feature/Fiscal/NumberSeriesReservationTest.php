<?php

use App\Domain\Fiscal\NumberSeries;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * 0.3.3-T — la reserva es atómica POR CONSTRUCCIÓN.
 *
 * Regla que fija: el número reservado se obtiene en la MISMA sentencia que
 * toma el bloqueo de fila (`UPDATE … RETURNING`), nunca de `rowCount()` ni de
 * la lectura del "último registro" — que es exactamente el defecto del legacy
 * (`_inc/helper/invoice.php:95-109`), donde dos cajas simultáneas recibían
 * el mismo correlativo.
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_la_reserva_devuelve_el_numero_reservado_en_la_misma_sentencia_que_toma_el_bloqueo', function () {
    $context = FiscalSeriesFixture::context();
    $seriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'document',
        'assigned_by' => 'issuer',
        'next_number' => 100,
        'from_number' => 100,
        'to_number' => 200,
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $number = (new NumberSeries(DB::connection()))->reserve($seriesId);

    // El número devuelto es el ANTERIOR al incremento: 100, no 101. Si se
    // derivara de una lectura posterior del contador, o del último registro
    // reservado, devolvería 101.
    expect($number)->toBe(100);

    // Una sola sentencia sobre la serie, y devuelve el número reservado.
    $updates = array_values(array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'update fiscal_series')
    ));

    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toContain('returning next_number - 1')
        ->and($updates[0])->toContain('next_number <= to_number')
        ->and($updates[0])->not->toContain('skip locked');

    // Ninguna lectura del "último registro" ni ningún contador de filas.
    $lastRecordReads = array_values(array_filter($queries, function (string $sql): bool {
        return str_contains($sql, 'fiscal_series')
            && (str_contains($sql, 'order by')
                || str_contains($sql, 'limit 1')
                || str_contains($sql, 'count('));
    }));

    expect($lastRecordReads)->toBe([]);

    // El contador avanzó una unidad y la traza quedó escrita.
    expect((int) DB::table('fiscal_series')->where('id', $seriesId)->value('next_number'))->toBe(101);

    $reservation = DB::table('fiscal_document_reservations')->where('series_id', $seriesId)->first();

    expect($reservation)->not->toBeNull()
        ->and((int) $reservation->number)->toBe(100)
        ->and($reservation->series_role)->toBe('document')
        ->and($reservation->reserved_at)->not->toBeNull()
        ->and($reservation->voided_at)->toBeNull();
});

/**
 * 0.3.3-T (triangulación) — la reserva deja la traza de quién tomó el número
 * y la serie es la que manda el rol; agotado el rango autorizado, la reserva
 * falla en lugar de inventar un número.
 */
test('test_la_reserva_deja_la_traza_del_actor_y_falla_cuando_el_rango_esta_agotado', function () {
    $context = FiscalSeriesFixture::context();
    $seriesId = FiscalSeriesFixture::series($context, [
        'series_role' => 'control',
        'assigned_by' => 'printer',
        'next_number' => 5000,
        'from_number' => 5000,
        'to_number' => 5000,
    ]);

    $user = User::factory()->create();

    $number = (new NumberSeries(DB::connection()))->reserve($seriesId, $user->id);

    $reservation = DB::table('fiscal_document_reservations')->where('series_id', $seriesId)->first();

    expect($number)->toBe(5000)
        ->and((int) $reservation->reserved_by)->toBe($user->id)
        ->and($reservation->series_role)->toBe('control');

    // El rango autorizado está agotado: la siguiente reserva falla y NO
    // inventa un número ni abre un hueco.
    expect(fn () => (new NumberSeries(DB::connection()))->reserve($seriesId))
        ->toThrow(RuntimeException::class);

    expect((int) DB::table('fiscal_series')->where('id', $seriesId)->value('next_number'))->toBe(5001)
        ->and(DB::table('fiscal_document_reservations')->where('series_id', $seriesId)->count())->toBe(1);
});
