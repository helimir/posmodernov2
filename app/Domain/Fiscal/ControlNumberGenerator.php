<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\ValueObjects\ControlAssignment;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Generador local del Numero de Control — el caso de rol **dual**, en el que
 * el sistema es tambien la imprenta digital.
 *
 * El numero de control son DOS digitos de identificador mas un secuencial de
 * hasta ocho. La **primera** asignacion de una serie usa el identificador
 * `00` y el secuencial `1`; a partir de ahi el secuencial sigue al
 * correlativo de la serie, que se reserva de forma atomica.
 *
 * Cuando hay un actor intermedio (una imprenta externa), el control lo
 * **asigna** la imprenta y llega por `assignControl()`: este generador es el
 * camino local, no una tercera fuente de verdad.
 */
final class ControlNumberGenerator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly NumberSeries $numberSeries,
    ) {}

    /**
     * Reserva el numero de control de la serie y devuelve la asignacion.
     *
     * @param  int  $seriesId  Serie con `series_role='control'`.
     */
    public function assign(int $seriesId, DateTimeImmutable $assignedAt): ControlAssignment
    {
        $series = $this->connection->table('fiscal_series')->where('id', $seriesId)->first();

        if ($series === null) {
            throw new RuntimeException("La serie de control {$seriesId} no existe.");
        }

        // El correlativo se reserva de forma atomica: nunca se deriva de una
        // lectura ni de un `rowCount()`.
        $number = $this->numberSeries->reserve($seriesId);

        // El identificador de dos digitos vive en la serie; la primera
        // asignacion de una serie es `00`.
        $identifier = (string) ($series->control_identifier ?? '00');
        $length = (int) ($series->sequence_length ?? 8);

        if (strlen((string) $number) > $length) {
            throw new RuntimeException(
                "El secuencial {$number} excede la longitud {$length} de la serie de control."
            );
        }

        return new ControlAssignment(
            controlNumber: $identifier.'-'.str_pad((string) $number, $length, '0', STR_PAD_LEFT),
            // El rango total se registra y se imprime: "desde el N° X hasta
            // el N° Y".
            controlRangeFrom: (string) $series->from_number,
            controlRangeTo: (string) $series->to_number,
            assignedAt: $assignedAt,
        );
    }
}
