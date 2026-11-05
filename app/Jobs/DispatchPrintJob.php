<?php

namespace App\Jobs;

use App\Print\PrintJobStateMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * El REINTENTO de un trabajo de impresion fallido.
 *
 * Es el job que la UI encola cuando el operador reintenta un trabajo
 * fallido: devuelve el trabajo a la cola y cuenta un intento mas. El cambio
 * de estado lo hace la MAQUINA DE ESTADOS, que es la unica via: este job no
 * escribe el estado a mano.
 *
 * Vive fuera del ciclo de la peticion: la UI no espera a la cola.
 */
final class DispatchPrintJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $printJobId,
    ) {}

    public function handle(PrintJobStateMachine $stateMachine): void
    {
        $stateMachine->requeue($this->printJobId);
    }
}
