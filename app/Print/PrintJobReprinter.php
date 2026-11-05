<?php

namespace App\Print;

use App\Models\PrintJob;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * La REIMPRESION de un ticket.
 *
 * Regla que fija: *reimprimir no es reemitir*.
 *
 * Una reimpresion es un trabajo de impresion NUEVO, con los MISMOS bytes
 * congelados y el mismo hash, contra el MISMO documento fiscal. NO consume
 * correlativo: no reserva numeros ni toca `fiscal_documents` ni
 * `fiscal_document_reservations`. El estado `issued` del documento es
 * INDEPENDIENTE del estado del trabajo de impresion: la impresora puede
 * estar caida todo el dia y el comprobante sigue emitido.
 *
 * Los bytes se COPIAN tal cual, no se vuelven a generar: el ticket que sale
 * en la reimpresion es byte a byte el ticket que salio la primera vez.
 */
final readonly class PrintJobReprinter
{
    public function __construct(private Connection $connection) {}

    /**
     * Crea el trabajo de reimpresion y devuelve su identificador.
     *
     * @param  int|null  $actorId  Quien pidio la reimpresion (auditoria).
     *
     * @throws RuntimeException Si el trabajo original no existe o no tiene
     *                          bytes congelados.
     */
    public function reprint(int $originalJobId, ?int $actorId = null): int
    {
        $original = PrintJob::find($originalJobId);

        if ($original === null) {
            throw new RuntimeException("El trabajo de impresion {$originalJobId} no existe.");
        }

        $bytes = $original->payload_bytes;

        if ($bytes === null || $bytes === '') {
            throw new RuntimeException(
                "El trabajo de impresion {$originalJobId} no tiene bytes congelados: no se puede reimprimir."
            );
        }

        // El trabajo NUEVO: mismos bytes, mismo hash, `reason='reprint'` y el
        // puntero al original. CERO reservas y CERO cambios en el documento
        // fiscal: aqui no se toca `fiscal_documents` ni
        // `fiscal_document_reservations`.
        $nuevo = $this->connection->selectOne(
            <<<'SQL'
                INSERT INTO print_jobs
                    (store_id, print_target_id, agent_id, status, payload, payload_bytes, payload_sha256,
                     reason, reprint_of_job_id, lease_expires_at, attempts, last_error, created_by, created_at, updated_at)
                VALUES (?, ?, NULL, 'queued', CAST(? AS jsonb), decode(?, 'hex'), ?,
                        'reprint', ?, NULL, 0, NULL, ?, ?, ?)
                RETURNING id
            SQL,
            [
                (int) $original->store_id,
                (int) $original->print_target_id,
                $this->modelo($original),
                // El `bytea` se escribe en hexadecimal: el constructor de
                // consultas trunca cualquier cadena con un byte nulo.
                bin2hex($bytes),
                $original->payload_sha256,
                (int) $originalJobId,
                $actorId,
                now(),
                now(),
            ]
        );

        return (int) $nuevo->id;
    }

    /**
     * El MODELO del ticket, copiado del original para el `jsonb`.
     */
    private function modelo(PrintJob $original): ?string
    {
        if ($original->payload === null) {
            return null;
        }

        return json_encode($original->payload, JSON_UNESCAPED_UNICODE);
    }
}
