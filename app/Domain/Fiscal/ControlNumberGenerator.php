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
 *
 * Y HAY UN SOLO NUMERO DE CONTROL POR VENTA: la reserva que hace el checkout
 * (T1) para el COMPROBANTE es la misma que reutiliza el documento fiscal
 * (T2). Dos reservas serian dos numeros para una venta, y el comprobante
 * impreso llevaria un control que el documento fiscal no lleva.
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
     * Es el camino de los usos que NO son de una venta (un comprobante que
     * no pertenece a ninguna venta, por ejemplo).
     *
     * @param  int  $seriesId  Serie con `series_role='control'`.
     */
    public function assign(int $seriesId, DateTimeImmutable $assignedAt): ControlAssignment
    {
        $serie = $this->serie($seriesId);

        // El correlativo se reserva de forma atomica: nunca se deriva de una
        // lectura ni de un `rowCount()`.
        $number = $this->numberSeries->reserve($seriesId);

        return $this->asignacion($serie, $number, $assignedAt);
    }

    /**
     * El numero de control de una VENTA: lo reserva UNA vez y lo REUTILIZA.
     *
     * REGLA que fija este metodo: *una venta tiene UN numero de control*.
     *
     *  - Si la venta YA tiene su reserva de control, se REUTILIZA: la serie
     *    no avanza, y el numero es el MISMO que lleva el comprobante;
     *  - si no la tiene, se reserva una y se ENLAZA a la venta: el enlace es
     *    lo que permite que el documento fiscal (y el reintento) la
     *    encuentren.
     *
     * @param  int  $seriesId  Serie con `series_role='control'`.
     * @param  int  $saleId  La venta a la que pertenece el control.
     */
    public function assignForSale(int $seriesId, int $saleId, DateTimeImmutable $assignedAt): ControlAssignment
    {
        $reserva = $this->reservaDeLaVenta($saleId);

        if ($reserva !== null) {
            // La reserva YA esta: se REUTILIZA y la serie NO avanza.
            return $this->asignacion(
                $this->serie((int) $reserva->series_id),
                (int) $reserva->number,
                $assignedAt
            );
        }

        $serie = $this->serie($seriesId);

        $number = $this->numberSeries->reserve($seriesId);

        // Y la reserva se ENLAZA a la venta: sin ese enlace, el documento
        // fiscal no encontraria el control del comprobante.
        $this->numberSeries->link($seriesId, $number, 'sale', $saleId);

        return $this->asignacion($serie, $number, $assignedAt);
    }

    /**
     * La reserva de control de la VENTA, si la tiene.
     *
     * Se busca por el ENLACE (`reference_type` + `reference_id`), como la
     * reserva del documento: el control que importa es el de ESTA venta, no
     * el ultimo de la serie.
     */
    private function reservaDeLaVenta(int $saleId): ?object
    {
        return $this->connection->table('fiscal_document_reservations')
            ->where('series_role', 'control')
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->orderBy('id')
            ->first();
    }

    /**
     * La SERIE de control.
     *
     * @throws RuntimeException Cuando la serie no existe.
     */
    private function serie(int $seriesId): object
    {
        $serie = $this->connection->table('fiscal_series')->where('id', $seriesId)->first();

        if ($serie === null) {
            throw new RuntimeException("La serie de control {$seriesId} no existe.");
        }

        return $serie;
    }

    /**
     * La ASIGNACION de control: el numero COMPUESTO y el rango total.
     *
     * El control compuesto son dos digitos de identificador mas el
     * secuencial de la serie, y el rango total se registra y se imprime
     * ("desde el N° X hasta el N° Y"). Los tres salen de la SERIE: son
     * DATOS, no literales del codigo.
     */
    private function asignacion(object $serie, int $number, DateTimeImmutable $assignedAt): ControlAssignment
    {
        // El identificador de dos digitos vive en la serie; la primera
        // asignacion de una serie es `00`.
        $identifier = (string) ($serie->control_identifier ?? '00');
        $length = (int) ($serie->sequence_length ?? 8);

        if (strlen((string) $number) > $length) {
            throw new RuntimeException(
                "El secuencial {$number} excede la longitud {$length} de la serie de control."
            );
        }

        return new ControlAssignment(
            controlNumber: $identifier.'-'.str_pad((string) $number, $length, '0', STR_PAD_LEFT),
            // El rango total se registra y se imprime: "desde el N° X hasta
            // el N° Y".
            controlRangeFrom: (string) $serie->from_number,
            controlRangeTo: (string) $serie->to_number,
            assignedAt: $assignedAt,
        );
    }
}
