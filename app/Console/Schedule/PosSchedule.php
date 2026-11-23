<?php

namespace App\Console\Schedule;

use Illuminate\Console\Scheduling\Schedule;

/**
 * El PLAN del sistema: lo que se ejecuta solo, y cada cuanto.
 *
 * REGLA que fija esta clase: *un comando que nadie programa es un comando
 * que no existe*. El barrido de leases estaba escrito desde la F2-08 y, sin
 * planificador, **no se ejecutaba nunca**: un agente que moria con un
 * trabajo en las manos dejaba el comprobante sin imprimir para siempre.
 *
 * Y POR QUE ESTA EN UNA CLASE Y NO EN UN CLOUSE DE `bootstrap/app.php`:
 * porque el plan tiene que poder **comprobarse**. El planificador de
 * Laravel llena los eventos cuando arranca la CONSOLA (`Artisan::starting`),
 * asi que un test que lo mira en caliente depende del orden de los tests y
 * no prueba nada. Con el plan en una clase, el test lo monta **el mismo** y
 * comprueba lo que de verdad se va a ejecutar.
 *
 * Y CADA TAREA TIENE SU POR QUE:
 *
 *  - el BARRIDO DE LEASES, cada minuto: devuelve a la cola un trabajo cuyo
 *    agente murio con el en las manos. Un minuto es el plazo mas corto que
 *    no convierte el barrido en un ruido de motor;
 *  - el BACKUP, cada noche a las 02:00: la base se respalda mientras nadie
 *    la esta usando;
 *  - la PURGA DE LA AUDITORIA, cada noche a las 03:00: la retencion es de
 *    un ano y lo que pasa de ahi se va. Con `--dias=365` y `--force`,
 *    porque el suelo de la retencion es de un ano y el borrado tiene que
 *    ser explicito.
 */
final class PosSchedule
{
    /**
     * Declara el plan sobre un planificador.
     */
    public function define(Schedule $schedule): void
    {
        // El BARRIDO DE LEASES, cada minuto.
        $schedule->command('ops:print-lease-sweep')
            ->everyMinute()
            ->withoutOverlapping();

        // EL BOMPEO de las impresoras que escribe el SERVIDOR, cada minuto.
        //
        // Y ESTE NO ERA OPCIONAL: el comando existia desde la F2-06 (el
        // escritor directo) y su propio docblock decia "el trabajo en
        // bucle es del planificador". Sin esta tarea, los tickets de una
        // impresora de red se quedaban en `queued` PARA SIEMPRE: la venta
        // se cobraba y el papel no salia, y nadie se enteraba.
        //
        // Y CADA MINUTO, no cada diez segundos: el cron de verdad es
        // `schedule:run`, que se despierta una vez por minuto, y una tarea
        // mas rapida que el cron no se ejecuta nunca. El ticket del
        // mostrador sale en el peor caso en un minuto; que salga AL
        // INSTANTE es una decision de la Fase 3.
        $schedule->command('ops:print-pump')
            ->everyMinute()
            ->withoutOverlapping();

        // La REEMISION fiscal de las ventas pendientes, cada cinco minutos.
        //
        // Y ES SEGURA: el reintento reutiliza la reserva de la venta y no
        // quema el correlativo, y solo trabaja con las ventas en
        // `fiscal_failed`. Una venta ya emitida no se reemite.
        $schedule->command('ops:emit-fiscal')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        // El BACKUP, cada noche.
        $schedule->command('ops:backup')
            ->dailyAt('02:00')
            ->withoutOverlapping();

        // La PURGA DE LA AUDITORIA, cada noche.
        $schedule->command('ops:audit-purge', ['--dias' => 365, '--force' => true])
            ->dailyAt('03:00')
            ->withoutOverlapping();
    }
}
