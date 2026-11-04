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

    /**
     * Enlaza la reserva con el hecho que la origino.
     *
     * La reserva sabe a que venta (o a que compra, o a que nota) pertenece:
     * sin ese enlace, el reintento de la emision fiscal no puede ENCONTRAR la
     * reserva que ya tiene, y tendria que reservar un numero NUEVO y
     * quemarlo.
     *
     * La reserva se localiza por (`series_id`, `number`), que es UNIQUE: un
     * numero no se repite dentro de una serie.
     *
     * @param  string  $referenceType  Que hecho origina la reserva (`sale`).
     * @param  int  $referenceId  El id de ese hecho.
     *
     * @throws RuntimeException Cuando la reserva no existe.
     */
    public function link(int $seriesId, int $number, string $referenceType, int $referenceId): void
    {
        $updated = $this->connection->update(
            'UPDATE fiscal_document_reservations
                SET reference_type = ?, reference_id = ?, updated_at = now()
              WHERE series_id = ? AND number = ?',
            [$referenceType, $referenceId, $seriesId, $number]
        );

        if ($updated === 0) {
            throw new RuntimeException(
                "The fiscal series {$seriesId} has no reservation for the number {$number}: "
                .'the reservation cannot be linked.'
            );
        }
    }
}
