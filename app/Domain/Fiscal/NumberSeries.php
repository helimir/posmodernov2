<?php

namespace App\Domain\Fiscal;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Reserva atómica del siguiente número de una serie fiscal.
 *
 * El número se obtiene en la MISMA sentencia que toma el bloqueo de fila
 * (`UPDATE … RETURNING`): nunca se deriva de `rowCount()` ni de la lectura
 * del "último registro", que es el defecto del legacy
 * (`_inc/helper/invoice.php:95-109`) — con dos cajas simultáneas, dos
 * documentos recibían el mismo correlativo.
 *
 * La reserva NO usa `SKIP LOCKED` (a diferencia del FIFO de stock): aquí se
 * ESPERA y se falla con timeout, porque saltarse la fila repartiría el
 * correlativo al azar.
 *
 * La transacción es la del llamante: un rollback devuelve el número y una
 * anulación lo deja consumido (`voided_at`).
 */
final class NumberSeries
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Reserva el siguiente número de la serie y deja la traza de la reserva.
     *
     * @throws RuntimeException cuando el rango autorizado está agotado.
     */
    public function reserve(int $seriesId, ?int $reservedBy = null): int
    {
        $reserved = $this->connection->selectOne(
            'UPDATE fiscal_series
                SET next_number = next_number + 1
              WHERE id = ?
                AND next_number <= to_number
            RETURNING next_number - 1 AS reserved_number',
            [$seriesId],
        );

        if ($reserved === null) {
            throw new RuntimeException(
                "The fiscal series {$seriesId} has no numbers left: the authorized range is exhausted."
            );
        }

        $number = (int) $reserved->reserved_number;

        // La traza: quién tomó el número y cuándo. El rol se copia de la fila
        // que la sentencia anterior bloqueó, no de un literal del código.
        $this->connection->insert(
            'INSERT INTO fiscal_document_reservations
                (series_id, number, series_role, reserved_by, reserved_at)
             SELECT id, ?, series_role, ?, now()
               FROM fiscal_series
              WHERE id = ?',
            [$number, $reservedBy, $seriesId],
        );

        return $number;
    }
}
