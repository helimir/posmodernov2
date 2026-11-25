<?php

namespace App\Domain\Reports;

use DateTimeImmutable;
use RuntimeException;

/**
 * El PERIODO de un reporte: las dos fechas que acotan lo que se cuenta.
 *
 * REGLA que fija esta clase: *el periodo son dos DATOS, y el reporte no se
 * inventa el dia*. Un reporte que calcula "hoy" con `now()`, o que parte el
 * dia con un `DAY()/MONTH()/YEAR()` calculado, cuenta el dia del SERVIDOR y
 * no el del negocio: la tienda de Venezuela y el servidor de Madrid no
 * comparten medianoche. Asi que las dos fechas entran como DATOS y el
 * periodo solo las ordena.
 *
 * Y EL PERIODO ES INCLUSIVO por los dos extremos: del 1 al 31 cuenta el dia
 * 1 y el dia 31. Un periodo al reves no es un periodo, y aqui se rechaza con
 * un mensaje en vez de devolver una cuenta vacia sin explicar por que.
 */
final readonly class ReportPeriod
{
    public DateTimeImmutable $from;

    public DateTimeImmutable $to;

    public function __construct(DateTimeImmutable $from, DateTimeImmutable $to)
    {
        if ($to < $from) {
            throw new RuntimeException(
                'El periodo del reporte va al reves: empieza el '.$from->format('Y-m-d')
                .' y termina el '.$to->format('Y-m-d').'.'
            );
        }

        $this->from = $from;
        $this->to = $to;
    }

    /**
     * Un PERIODO entre dos fechas en formato `Y-m-d`.
     *
     * REGLA que fija este metodo: *la fecha se VALIDA, no se adivina*. El
     * formato es exacto (`2026-01-31`, no `2026-1-31`) y el dia tiene que
     * existir: un 30 de febrero se rechaza en vez de rodar al 2 de marzo, que
     * seria un periodo que cuenta dias que nadie pidio.
     *
     * @throws RuntimeException Cuando una fecha no es valida.
     */
    public static function between(string $from, string $to): self
    {
        return new self(self::fecha($from, 'desde'), self::fecha($to, 'hasta'));
    }

    /**
     * Los DIAS del periodo, del primero al ultimo, en formato `Y-m-d`.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $dias = [];

        $dia = $this->from;

        while ($dia <= $this->to) {
            $dias[] = $dia->format('Y-m-d');

            $dia = $dia->modify('+1 day');
        }

        return $dias;
    }

    /**
     * Si una fecha CAE en el periodo.
     *
     * @param  string  $date  La fecha, en formato `Y-m-d`.
     */
    public function contains(string $date): bool
    {
        $dia = self::fecha($date, 'consultada');

        return $dia >= $this->from && $dia <= $this->to;
    }

    /**
     * El PERIODO como lo viaja a la pantalla.
     *
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
        ];
    }

    /**
     * Una FECHA de negocio: un dato en formato `Y-m-d`.
     *
     * @throws RuntimeException Cuando la fecha no es valida.
     */
    private static function fecha(string $value, string $papel): DateTimeImmutable
    {
        $texto = trim($value);

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

        // El COMPARISON con la vuelta es lo que detecta el dia que no existe:
        // `createFromFormat` acepta el 30 de febrero y rueda al 2 de marzo.
        if ($fecha === false || $fecha->format('Y-m-d') !== $texto) {
            throw new RuntimeException(
                "La fecha '{$texto}' del reporte ({$papel}) no es una fecha valida en formato Y-m-d."
            );
        }

        return $fecha;
    }
}
