<?php

use App\Console\Schedule\PosSchedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * El PLANIFICADOR.
 *
 * REGLA que fija esta unidad: *un comando que nadie programa es un comando
 * que no existe*. El barrido de leases ya estaba escrito desde la F2-08, y
 * sin planificador **no se ejecutaba nunca**: un agente que moria con un
 * trabajo en las manos dejaba el comprobante sin imprimir para siempre.
 *
 * Y EL PLAN SE COMPRUEBA DE DOS FORMAS, porque una sola no basta:
 *
 *  - sobre el plan **montado por la propia clase**, que es lo que se va a
 *    ejecutar, sin depender del orden de los tests;
 *  - sobre el plan **real**, en un proceso aparte, que es lo que hace el
 *    cron. El planificador de Laravel llena los eventos cuando arranca la
 *    CONSOLA, asi que mirar el contenedor en caliente depende de que otro
 *    test haya arrancado la consola antes: eso no es una comprobacion, es
 *    una coincidencia.
 */
describe('el planificador', function () {
    /**
     * El plan montado por la clase, sobre un planificador PROPIO.
     *
     * ⚠️ Uno propio y no el del contenedor: el del contenedor es un
     * singleton que se comparte entre tests, y un test que depende de lo
     * que otro test dejo en el contenedor no es un test.
     */
    function plan(): Schedule
    {
        $schedule = new Schedule('UTC');

        (new PosSchedule)->define($schedule);

        return $schedule;
    }

    /**
     * El EVENTO de un comando.
     */
    function evento(Schedule $schedule, string $comando): ?Event
    {
        foreach ($schedule->events() as $evento) {
            if (str_contains((string) $evento->command, $comando)) {
                return $evento;
            }
        }

        return null;
    }

    it('programa el barrido de leases cada minuto', function () {
        $evento = evento(plan(), 'ops:print-lease-sweep');

        expect($evento)->not->toBeNull()
            ->and($evento->expression)->toBe('* * * * *');
    });

    it('programa el backup cada noche a las 02:00', function () {
        $evento = evento(plan(), 'ops:backup');

        expect($evento)->not->toBeNull()
            ->and($evento->expression)->toBe('0 2 * * *');
    });

    it('programa la purga de la auditoria con la retencion de un ano', function () {
        $evento = evento(plan(), 'ops:audit-purge');

        expect($evento)->not->toBeNull()
            ->and($evento->expression)->toBe('0 3 * * *')
            ->and((string) $evento->command)->toContain('--dias=365')
            ->and((string) $evento->command)->toContain('--force');
    });

    it('ninguna tarea se pisa con si misma', function () {
        $schedule = plan();

        foreach (['ops:print-lease-sweep', 'ops:backup', 'ops:audit-purge'] as $comando) {
            $evento = evento($schedule, $comando);

            expect($evento)->not->toBeNull()
                ->and($evento->withoutOverlapping)->toBeTrue();
        }
    });

    it('el plan REAL de la aplicacion tiene las tres tareas', function () {
        // El PROCESO APARTE: es como lo ve el cron. Aqui la consola
        // arranca de verdad, el planificador se llena, y lo que se comprueba
        // es lo que de verdad se ejecutaria.
        $salida = [];

        exec('php artisan schedule:list 2>&1', $salida);

        $texto = implode("\n", $salida);

        expect($texto)->toContain('ops:print-lease-sweep')
            ->and($texto)->toContain('ops:backup')
            ->and($texto)->toContain('ops:audit-purge')
            ->and($texto)->toContain('--dias=365');
    });
});
