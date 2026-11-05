<?php

namespace App\Print;

use App\Models\PrintTarget;
use Illuminate\Database\Connection;
use JsonException;
use RuntimeException;

/**
 * El CONGELAMIENTO de un trabajo de impresión.
 *
 * Los bytes se generan ANTES de escribir el trabajo: si la generación falla,
 * no queda NINGÚN trabajo a medias. Un trabajo sin bytes es un ticket que el
 * agente no puede imprimir; un trabajo con bytes y sin hash es un ticket que
 * nadie puede verificar.
 *
 * El trabajo nace `queued`: lo toma el agente, no el servidor (design D5).
 */
final readonly class PrintJobDispatcher
{
    public function __construct(
        private EscposRenderer $renderer,
        private Connection $connection,
    ) {}

    /**
     * Genera los bytes y encola el trabajo.
     *
     * @throws RuntimeException Si el ticket no es representable en el destino.
     */
    public function dispatch(int $storeId, int $printTargetId, PrintPayload $payload, ?int $actorId = null): int
    {
        $target = PrintTarget::find($printTargetId);

        if ($target === null) {
            throw new RuntimeException("El destino de impresion {$printTargetId} no existe.");
        }

        if ((int) $target->store_id !== $storeId) {
            throw new RuntimeException(
                "El destino de impresion {$printTargetId} no pertenece a la tienda {$storeId}."
            );
        }

        // Los bytes PRIMERO. Si la generacion falla, no se crea el trabajo.
        $renderizado = $this->renderer->render($target, $payload);

        // El `payload_bytes` es un `bytea`: se escribe en hexadecimal con
        // `decode(..., 'hex')` porque el constructor de consultas convierte
        // cualquier cadena con un byte nulo en un flujo, y el flujo se
        // inserta como su descripcion textual: los bytes se truncan.
        $trabajo = $this->connection->selectOne(
            <<<'SQL'
                INSERT INTO print_jobs
                    (store_id, print_target_id, agent_id, status, payload, payload_bytes, payload_sha256, attempts, created_by, created_at, updated_at)
                VALUES (?, ?, NULL, ?, CAST(? AS jsonb), decode(?, 'hex'), ?, 0, ?, ?, ?)
                RETURNING id
            SQL,
            [
                $storeId,
                $printTargetId,
                'queued',
                $this->modelo($payload),
                bin2hex($renderizado->bytes),
                $renderizado->sha256,
                $actorId,
                now(),
                now(),
            ]
        );

        return (int) $trabajo->id;
    }

    /**
     * El modelo del ticket, serializado para el `jsonb`.
     *
     * @throws JsonException Si el modelo no se puede serializar.
     */
    private function modelo(PrintPayload $payload): string
    {
        return json_encode($payload->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
