<?php

namespace App\Print;

use Illuminate\Database\Connection;

/**
 * El BOMPEO: el trabajo que escribe los tickets encolados de los destinos
 * que escribe el SERVIDOR.
 *
 * REGLA que fija esta clase: *el servidor bombea SOLO los destinos cuyo
 * transporte es `server_socket`*. Los destinos del agente LAN se DEJAN: el
 * agente los reclama por la cola, y si el servidor los escribiera también, el
 * ticket saldría dos veces.
 *
 * Y EL BOMPEO NO ES UN BUCLE: bombea una vez y sale. El trabajo en bucle es
 * del planificador, no del bombeo (un bombeo en bucle no se puede apagar, y
 * un sistema que no se puede apagar es un sistema que no se puede
 * apagar).
 */
final readonly class PrintPump
{
    public function __construct(
        private Connection $connection,
        private PrintJobWriter $writer,
    ) {}

    /**
     * Bombea los trabajos encolados de los destinos que escribe el servidor.
     *
     * @param  int|null  $destinoId  Un destino concreto; sin el, todos.
     */
    public function bombear(?int $destinoId = null): PrintPumpReport
    {
        $resultados = [];

        foreach ($this->trabajosDelServidor($destinoId) as $id) {
            $resultados[] = $this->writer->escribir((int) $id);
        }

        return new PrintPumpReport($resultados, $this->trabajosDelAgente($destinoId));
    }

    /**
     * Los trabajos ENCOLADOS de los destinos que escribe el servidor.
     *
     * El orden es el de la cola: primero el más antiguo, y dentro del mismo
     * instante el más bajo identificador. Sin orden, dos bombeos podrían
     * sacar los tickets en un orden distinto cada vez.
     *
     * @return list<int>
     */
    private function trabajosDelServidor(?int $destinoId): array
    {
        return $this->connection->table('print_jobs')
            ->join('print_targets', 'print_targets.id', '=', 'print_jobs.print_target_id')
            ->where('print_jobs.status', 'queued')
            ->where('print_targets.transport', PrintTransport::ServerSocket->value)
            ->when(
                $destinoId !== null,
                fn ($consulta) => $consulta->where('print_jobs.print_target_id', $destinoId)
            )
            ->orderBy('print_jobs.created_at')
            ->orderBy('print_jobs.id')
            ->pluck('print_jobs.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Los trabajos ENCOLADOS que se DEJAN para el agente LAN.
     *
     * No se escriben: se cuentan, para que el resumen diga quantos hay
     * esperando al agente.
     */
    private function trabajosDelAgente(?int $destinoId): int
    {
        return $this->connection->table('print_jobs')
            ->join('print_targets', 'print_targets.id', '=', 'print_jobs.print_target_id')
            ->where('print_jobs.status', 'queued')
            ->where('print_targets.transport', PrintTransport::LanAgent->value)
            ->when(
                $destinoId !== null,
                fn ($consulta) => $consulta->where('print_jobs.print_target_id', $destinoId)
            )
            ->count();
    }
}
