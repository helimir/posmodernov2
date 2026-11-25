<?php

use App\Domain\Reports\ReportPeriod;

/**
 * El PERIODO de un reporte: las dos fechas que acotan lo que se cuenta.
 *
 * Reglas que fija:
 *
 *  - el periodo son DOS FECHAS CONCRETAS, y la segunda no puede ser anterior
 *    a la primera: un periodo al reves no es un periodo, y un reporte que lo
 *    aceptara ensenaria una cuenta vacia sin decir por que;
 *  - las fechas son DATOS en formato `Y-m-d`: el reporte no se inventa el dia
 *    con `now()` ni con un `DAY()/MONTH()/YEAR()` calculado, porque el dia que
 *    cuenta es el dia de la TIENDA;
 *  - el periodo es INCLUSIVO por los dos extremos: del 1 al 31 cuenta el dia
 *    1 y el dia 31.
 */

/**
 * El periodo del dia 1 al 3 de enero de 2026.
 */
test('test_el_periodo_va_de_la_primera_fecha_a_la_ultima_inclusive', function () {
    $periodo = ReportPeriod::between('2026-01-01', '2026-01-03');

    expect($periodo->from->format('Y-m-d'))->toBe('2026-01-01')
        ->and($periodo->to->format('Y-m-d'))->toBe('2026-01-03')
        ->and($periodo->days())->toBe(['2026-01-01', '2026-01-02', '2026-01-03']);
});

/**
 * Una fecha que no es del formato `Y-m-d` no es una fecha de negocio: el
 * reporte no la adivina.
 */
test('test_el_periodo_rechaza_una_fecha_que_no_es_del_formato_ymd', function () {
    expect(fn () => ReportPeriod::between('01/01/2026', '2026-01-03'))
        ->toThrow(RuntimeException::class);
});

/**
 * Un dia que no existe no es una fecha: el 30 de febrero se rechaza en vez de
 * rodar al 2 de marzo.
 */
test('test_el_periodo_rechaza_un_dia_que_no_existe', function () {
    expect(fn () => ReportPeriod::between('2026-02-30', '2026-03-05'))
        ->toThrow(RuntimeException::class);
});

/**
 * Un periodo al reves no es un periodo: el reporte dice que no, en vez de
 * ensenar una cuenta vacia.
 */
test('test_el_periodo_rechaza_un_periodo_al_reves', function () {
    expect(fn () => ReportPeriod::between('2026-01-10', '2026-01-01'))
        ->toThrow(RuntimeException::class);
});
