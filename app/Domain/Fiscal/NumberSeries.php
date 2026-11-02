<?php

namespace App\Domain\Fiscal;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reserva atómica de un correlativo fiscal.
 *
 * El número se toma y se entrega en la MISMA sentencia que bloquea la fila
 * (`UPDATE … RETURNING`). Nunca con `SELECT` + `rowCount()` + 1 como hace el
 * legacy en `_inc/helper/invoice.php:95-109`: `rowCount()` de un `SELECT` no
 * es el número de filas afectadas, y separar la lectura de la escritura
 * permite que dos terminales repartan el mismo correlativo (design §D.1, D1).
 *
 * El bloqueo es de fila y ESPERA: la reserva NO usa `SKIP LOCKED` (a
 * diferencia del FIFO de stock, design §B.2). Saltarse la fila convertiría
 * un bloqueo en un correlativo repartido al azar; aquí se espera y se falla
 * con el `lock_timeout` de la conexión.
 *
 * La función NO abre una transacción propia cuando el llamador ya tiene una:
 * la reserva es parte de la unidad atómica del checkout (T1) y de la emisión
 * (T2), y el rollback de esa unidad debe devolver el número (T3).
 */
final class NumberSeries
{
    /**
     * Reserva el siguiente número de la serie y deja la traza.
     *
     * @param  int|null  $reservedBy  Usuario que reserva; la traza lo guarda
     *                                aunque la columna admita null.
     *
     * @throws RuntimeException Si el rango autorizado de la serie está agotado.
     */
    public static function reserve(int $seriesId, ?int $reservedBy = null): int
    {
        // Con una transacción del llamador abierta, la reserva se une a ella:
        // un rollback externo devuelve el número (T3). Sin ella, la reserva
        // es atómica por sí sola para que el UPDATE y su traza no se separen.
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn (): int => self::reserveWithin($seriesId, $reservedBy));
        }

        return self::reserveWithin($seriesId, $reservedBy);
    }

    /**
     * La reserva: un `UPDATE … RETURNING` que toma el bloqueo de fila y
     * devuelve el número, más la traza de la reserva.
     */
    private static function reserveWithin(int $seriesId, ?int $reservedBy): int
    {
        // `next_number <= to_number` acota la serie al rango autorizado. Si la
        // fila no cumple la condición, el UPDATE no la toca: no hay número
        // que repartir y no se quema nada.
        $rows = DB::select(
            'UPDATE fiscal_series
                SET next_number = next_number + 1
              WHERE id = ?
                AND next_number <= to_number
          RETURNING next_number - 1 AS reserved_number, series_role',
            [$seriesId],
            false
        );

        if ($rows === []) {
            throw new RuntimeException(sprintf(
                'La serie fiscal %d no puede repartir mas numeros: el rango autorizado esta agotado.',
                $seriesId
            ));
        }

        $row = $rows[0];
        $number = (int) $row->reserved_number;

        // La traza copia el rol de la serie en el momento de la reserva: no
        // depende de que la serie cambie después.
        DB::table('fiscal_document_reservations')->insert([
            'series_id' => $seriesId,
            'number' => $number,
            'series_role' => $row->series_role,
            'reserved_by' => $reservedBy,
            'reserved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $number;
    }
}
