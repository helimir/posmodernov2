<?php

use App\Domain\Fiscal\NumberSeries;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;
use Tests\Fixtures\FiscalSeriesFixture;

/**
 * T2 — el bloqueo de fila es REAL, no simulado.
 *
 * Regla que fija: la reserva fiscal NO usa `SKIP LOCKED` (a diferencia del
 * FIFO de stock): espera y falla con timeout. La conexión A bloquea la serie
 * con `SELECT … FOR UPDATE`; la conexión B intenta reservar con
 * `SET LOCAL lock_timeout = '250ms'` DENTRO de su transacción y falla con
 * `SQLSTATE 55P03` (`lock_not_available`).
 */
uses(WithoutWrappingTransaction::class);

afterEach(function () {
    FiscalSeriesFixture::truncate();
});

test('test_la_reserva_bloqueada_falla_con_sqlstate_55p03_y_el_numero_no_avanza', function () {
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

    // Conexión A: bloquea la serie y MANTIENE el bloqueo (su transacción sigue
    // abierta). La serie se commiteó antes, así que B la ve.
    $a->beginTransaction();
    $a->select('SELECT id FROM fiscal_series WHERE id = ? FOR UPDATE', [$seriesId]);

    // Conexión B: su propia transacción, con el timeout DENTRO de ella
    // (`SET LOCAL` se descarta al hacer COMMIT/ROLLBACK).
    $b->beginTransaction();
    $b->statement("SET LOCAL lock_timeout = '250ms'");

    $failure = null;
    $startedAt = hrtime(true);

    try {
        (new NumberSeries($b))->reserve($seriesId);
    } catch (QueryException $exception) {
        $failure = $exception;
    } finally {
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        $b->rollBack();
        $a->rollBack();
    }

    expect($failure)->toBeInstanceOf(QueryException::class)
        ->and($failure->getCode())->toBe('55P03')
        // `55P03` es el SQLSTATE de la condición `lock_not_available`; el
        // mensaje de PostgreSQL la nombra en lenguaje natural.
        ->and(strtolower($failure->getMessage()))->toContain('lock not available');

    // Esperó al bloqueo (no falló de inmediato) y el timeout está acotado.
    expect($elapsedMs)->toBeGreaterThanOrEqual(250)
        ->and($elapsedMs)->toBeLessThanOrEqual(3000);

    // El número NO avanza: la sentencia bloqueada no llegó a actualizar la
    // fila, y no quedó ninguna reserva.
    expect((int) DB::table('fiscal_series')->where('id', $seriesId)->value('next_number'))->toBe(100)
        ->and(DB::table('fiscal_document_reservations')->where('series_id', $seriesId)->count())->toBe(0);
});
