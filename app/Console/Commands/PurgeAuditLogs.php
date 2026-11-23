<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * La RETENCION de la auditoria de negocio.
 *
 * REGLA que fija este comando: *la auditoria se guarda, pero no para
 * siempre*. Y tres decisiones que van con ella:
 *
 *  - **NO HAY RETENCION POR DEFECTO**: hay que decir cuantos dias se
 *    guardan (`--dias`). Un comando que borra por su cuenta es un comando
 *    que borra la prueba de alguien sin que nadie lo pidiera;
 *  - **POR DEFECTO SIMULA**: sin `--force` el comando cuenta lo que
 *    borraria y no borra nada. El borrado de verdad se pide de forma
 *    explicita;
 *  - **UN AÑO ES EL SUELO**: por debajo de 365 dias el comando se niega,
 *    con o sin `--force`. Eso no es una confirmacion, es una POLITICA: la
 *    auditoria de un ano tiene que poder revisarse, y un comando que la
 *    borrara por un `--dias=30` equivocado seria un accidente irreparable.
 *
 * Y EL BORRADO NECESITA PERMISO: la tabla es append-only, y el trigger solo
 * deja pasar el borrado si la sesion trae la marca `app.audit_purge`. El
 * comando la pone dentro de la transaccion, y se va sola con ella.
 */
class PurgeAuditLogs extends Command
{
    /**
     * La RETENCION MINIMA, en dias.
     *
     * Por debajo de un ano, la auditoria no se borra.
     */
    public const RETENCION_MINIMA_DIAS = 365;

    /**
     * El nombre y la firma del comando.
     */
    protected $signature = 'ops:audit-purge
                            {--dias= : Cuantos dias se guardan las entradas de la auditoria}
                            {--force : Borrar de verdad; sin esto el comando solo simula}';

    /**
     * La descripcion del comando.
     */
    protected $description = 'Purga las entradas de la auditoria que ya superaron la retencion';

    /**
     * La EJECUCION del comando.
     */
    public function handle(): int
    {
        $dias = $this->option('dias');

        if ($dias === null || $dias === '' || ! is_numeric($dias) || (int) $dias <= 0) {
            $this->error('Hay que decir cuantos dias se guardan: --dias=N.');

            return self::FAILURE;
        }

        $dias = (int) $dias;

        if ($dias < self::RETENCION_MINIMA_DIAS) {
            $this->error(sprintf(
                'La retencion minima es de %d dias (un ano). Con --dias=%d no se borra nada.',
                self::RETENCION_MINIMA_DIAS,
                $dias,
            ));

            return self::FAILURE;
        }

        $corte = now()->subDays($dias);

        $consulta = DB::table('audit_logs')->where('created_at', '<', $corte);

        $total = (clone $consulta)->count();

        if ($total === 0) {
            $this->info(sprintf('No hay entradas anteriores a %s: no hay nada que purgar.', $corte->toDateString()));

            return self::SUCCESS;
        }

        $masAntigua = (clone $consulta)->min('created_at');
        $masReciente = (clone $consulta)->max('created_at');

        if (! $this->option('force')) {
            $this->warn(sprintf(
                'SIMULACION: %d entradas anteriores a %s se borrarian (de %s a %s).',
                $total,
                $corte->toDateString(),
                (string) $masAntigua,
                (string) $masReciente,
            ));
            $this->line('Con --force se borran de verdad.');

            return self::SUCCESS;
        }

        $borradas = DB::transaction(function () use ($consulta): int {
            // La MARCA de la sesion: sin ella, el trigger bloquea el borrado.
            DB::statement("SET LOCAL app.audit_purge = 'on'");

            return $consulta->delete();
        });

        $this->info(sprintf(
            'Se purgaron %d entradas de la auditoria anteriores a %s.',
            $borradas,
            $corte->toDateString(),
        ));

        return self::SUCCESS;
    }
}
