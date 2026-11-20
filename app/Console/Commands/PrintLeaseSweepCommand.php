<?php

namespace App\Console\Commands;

use App\Print\PrintJobStateMachine;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;

/**
 * El comando que LIBERA los trabajos de impresion cuyo lease CADUCO.
 *
 * REGLA que fija este comando: *un trabajo con el lease caducado no queda
 * bloqueado para siempre*.
 *
 * El lease es el plazo que tiene el agente para imprimir. Si el agente muere
 * con el trabajo en las manos, el lease caduca y el trabajo se queda en
 * `claimed` para siempre: nadie lo vuelve a tomar y el comprobante no sale.
 * Este comando barre los trabajos caducados y los devuelve a la cola, con un
 * intento mas y sin agente.
 *
 * Y NO HACE NADA EN BUCLE: barre una vez y sale, con un resumen de lo que
 * hizo. El trabajo en bucle es del planificador (una unidad posterior), y un
 * barrido en bucle no se puede apagar.
 */
class PrintLeaseSweepCommand extends Command
{
    /**
     * La firma del comando.
     */
    protected $signature = 'ops:print-lease-sweep';

    /**
     * La descripcion que aparece en `php artisan list`.
     */
    protected $description = 'Devuelve a la cola los trabajos de impresion cuyo lease caduco.';

    /**
     * La ayuda de `--help`.
     */
    protected $help = <<<'HELP'
        Devuelve a la COLA los trabajos de impresion cuyo ALQUILER caduco.

        Un agente que muere con un trabajo en las manos no puede avisar: el trabajo se queda
        reclamado para siempre, y el comprobante no sale nunca. Este comando lo deshace: los
        trabajos caducados vuelven a la cola, con un intento mas y sin agente.

        El barrido es de TODO el sistema, no de una tienda: si solo barriera las tiendas que
        alguien visita, los trabajos de las demas quedarian bloqueados para siempre.

        El comando barre UNA VEZ y sale, con un resumen de lo que hizo. El trabajo en bucle es del
        planificador, no del barrido.

        Los trabajos TERMINADOS no se tocan: un ticket impreso y un ticket cancelado estan donde
        tienen que estar.
        HELP;

    /**
     * Barre una vez y dice que hizo.
     */
    public function handle(PrintJobStateMachine $machine, Connection $connection): int
    {
        $liberados = $this->barrer($machine, $connection);

        $this->line('');
        $this->line('EL BARRIDO DE LEASES');
        $this->line('=======================');
        $this->line('');
        $this->line(sprintf('  %-34s %s', 'Trabajos liberados', (string) $liberados));

        if ($liberados === 0) {
            $this->line('');
            $this->line('No hay trabajos con el lease caducado: la cola esta al dia.');
        }

        return self::SUCCESS;
    }

    /**
     * El BARRIDO: por cada tienda con trabajos caducados, se los devuelve a
     * la cola.
     *
     * La regla la pone la MAQUINA DE ESTADOS (`releaseExpiredLeases()`): el
     * comando no reimplementa el criterio de "caducado", solo dice DONDE mirar.
     */
    private function barrer(PrintJobStateMachine $machine, Connection $connection): int
    {
        $tiendas = $connection->table('print_jobs')
            ->whereIn('status', ['claimed', 'printing'])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->distinct()
            ->pluck('store_id');

        $liberados = 0;

        foreach ($tiendas as $tienda) {
            $liberados += $machine->releaseExpiredLeases((int) $tienda);
        }

        return $liberados;
    }
}
