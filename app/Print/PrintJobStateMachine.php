<?php

namespace App\Print;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La MAQUINA DE ESTADOS de los trabajos de impresion.
 *
 * Es la UNICA via por la que un trabajo cambia de estado: ningun
 * controlador escribe el estado a mano. Centralizarlo hace que las reglas
 * valgan para todos los caminos (el long-poll, el resultado del agente, el
 * reintento de la UI y el Barrido de leases caducados) y que no haya dos
 * sitios que puedan dejar el trabajo en un estado imposible.
 *
 * Las transiciones validas son:
 *
 * | Metodo              | Estado de ORIGEN                  | Estado final |
 * |---------------------|-----------------------------------|--------------|
 * | `claim`             | `queued`                          | `claimed`    |
 * | `markPrinting`      | `claimed`                         | `printing`   |
 * | `markPrinted`       | `printing`                        | `printed`    |
 * | `markFailed`        | `claimed`, `printing`             | `failed`     |
 * | `cancel`            | `queued`, `claimed`, `printing`   | `cancelled`  |
 * | `requeue`           | `failed`, o `claimed`/`printing` con el lease CADUCADO | `queued` |
 *
 * `printed` y `cancelled` son TERMINALES: un trabajo impreso no se reimprime
 * ni se reencola, y un trabajo cancelado tampoco. `failed` NO es terminal en
 * sentido de negocio: la UI lo puede reintentar, y el reintento cuenta un
 * intento mas.
 *
 * El estado del trabajo es INDEPENDIENTE del estado del documento fiscal:
 * emitir no depende de imprimir (design D5).
 */
final readonly class PrintJobStateMachine
{
    public function __construct(private Connection $connection) {}

    /**
     * El agente TOMA el trabajo: de `queued` a `claimed`, con su agente y su
     * lease.
     *
     * El lease es el plazo que tiene el agente para imprimir: si el agente
     * muere, el lease caduca y el trabajo vuelve a la cola.
     *
     * @throws RuntimeException Si el trabajo no estaba encolado.
     */
    public function claim(int $jobId, int $agentId, int $leaseSeconds): void
    {
        $this->transicionar($jobId, ['queued'], 'claimed', [
            'agent_id' => $agentId,
            'lease_expires_at' => now()->addSeconds($leaseSeconds),
        ]);
    }

    /**
     * De `claimed` a `printing`: el agente esta imprimiendo.
     *
     * @throws RuntimeException Si el trabajo no estaba reclamado.
     */
    public function markPrinting(int $jobId): void
    {
        $this->transicionar($jobId, ['claimed'], 'printing');
    }

    /**
     * De `printing` a `printed`: el ticket salio de la impresora.
     *
     * @throws RuntimeException Si el trabajo no estaba imprimiendo.
     */
    public function markPrinted(int $jobId): void
    {
        $this->transicionar($jobId, ['printing'], 'printed');
    }

    /**
     * A `failed`, con el error que informa el agente.
     *
     * El fallo puede venir de `claimed` (la impresora estaba apagada antes
     * del primer byte) o de `printing` (el fallo es de media impresion).
     *
     * @throws RuntimeException Si el trabajo no estaba en claimed ni en printing.
     */
    public function markFailed(int $jobId, string $error): void
    {
        $this->transicionar($jobId, ['claimed', 'printing'], 'failed', [
            'last_error' => $error,
        ]);
    }

    /**
     * A `cancelled`: el operador lo descarta.
     *
     * Cancelar es TERMINAL: un trabajo cancelado no se reimprime ni se
     * reencola.
     *
     * @throws RuntimeException Si el trabajo no estaba pendiente ni en curso.
     */
    public function cancel(int $jobId): void
    {
        $this->transicionar($jobId, ['queued', 'claimed', 'printing'], 'cancelled', [
            // El lease se cae: un trabajo cancelado no lo tiene nadie.
            'lease_expires_at' => null,
        ]);
    }

    /**
     * Devuelve el trabajo a la cola y cuenta un intento mas.
     *
     * Es el reintento de la UI: el trabajo estaba `failed` y vuelve a
     * `queued` para que otro agente lo tome. Tambien reencola un trabajo cuyo
     * lease CADUCO (el agente murio con el trabajo en las manos).
     *
     * @throws RuntimeException Si el trabajo no se puede reencolar.
     */
    public function requeue(int $jobId): void
    {
        $trabajo = $this->connection->table('print_jobs')->where('id', $jobId)->first();

        if ($trabajo === null) {
            throw new RuntimeException("El trabajo de impresion {$jobId} no existe.");
        }

        $estado = (string) $trabajo->status;
        $leaseCaducado = in_array($estado, ['claimed', 'printing'], true)
            && $trabajo->lease_expires_at !== null
            && strtotime((string) $trabajo->lease_expires_at) < time();

        if ($estado !== 'failed' && ! $leaseCaducado) {
            throw new RuntimeException(
                "El trabajo de impresion {$jobId} no se puede reencolar desde «{$estado}»: "
                .'solo se reencola un trabajo fallido o con el lease caducado.'
            );
        }

        $this->transicionar($jobId, [$estado], 'queued', [
            'agent_id' => null,
            'lease_expires_at' => null,
            'attempts' => DB::raw('attempts + 1'),
        ]);
    }

    /**
     * Libera los trabajos cuyo lease CADUCO: vuelven a `queued` con un
     * intento mas y sin agente.
     *
     * Es el barrido periodico: un agente que muere con un trabajo en las
     * manos no puede dejar el trabajo bloqueado para siempre. Solo toca los
     * trabajos de la tienda indicada.
     *
     * @return int Cuantos trabajos se liberaron.
     */
    public function releaseExpiredLeases(int $storeId): int
    {
        return $this->connection->table('print_jobs')
            ->where('store_id', $storeId)
            ->whereIn('status', ['claimed', 'printing'])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->update([
                'status' => 'queued',
                'agent_id' => null,
                'lease_expires_at' => null,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * La transicion: el estado de ORIGEN va en el `WHERE`, asi que el cambio
     * es atomico. Dos agentes no toman el mismo trabajo, porque el segundo
     * `UPDATE` no encuentra la fila en `queued`.
     *
     * @param  list<string>  $origenes
     * @param  array<string, mixed>  $cambios
     *
     * @throws RuntimeException Si el estado de origen no encaja.
     */
    private function transicionar(int $jobId, array $origenes, string $destino, array $cambios = []): void
    {
        $actualizadas = $this->connection->table('print_jobs')
            ->where('id', $jobId)
            ->whereIn('status', $origenes)
            ->update(array_merge([
                'status' => $destino,
                'updated_at' => now(),
            ], $cambios));

        if ($actualizadas === 1) {
            return;
        }

        $estado = $this->connection->table('print_jobs')->where('id', $jobId)->value('status');

        if ($estado === null) {
            throw new RuntimeException("El trabajo de impresion {$jobId} no existe.");
        }

        throw new RuntimeException(
            "El trabajo de impresion {$jobId} no puede pasar de «{$estado}» a «{$destino}»: "
            .'el estado de origen debe ser «'.implode('» o «', $origenes).'».'
        );
    }
}
