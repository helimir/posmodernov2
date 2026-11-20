<?php

namespace App\Print;

/**
 * El INFORME de un bombeo: que hizo, y que dejo.
 *
 * REGLA que fija esta clase: *el informe dice las TRES cosas y ni una más*:
 * cuantos trabajos se escribieron, cuantos fallaron, y cuantos quedaron
 * esperando al agente LAN. Un informe que no dice lo que quedó pendiente es
 * un informe que hace creer que ya no queda nada.
 */
final readonly class PrintPumpReport
{
    /**
     * @param  list<PrintWriteOutcome>  $resultados  Lo que pasó con cada
     *                                               trabajo.
     * @param  int  $paraElAgente  Los trabajos que se dejaron para el agente.
     */
    public function __construct(
        public array $resultados,
        public int $paraElAgente,
    ) {}

    /**
     * Los trabajos ESCRITOS: los bytes salieron.
     */
    public function escritos(): int
    {
        return $this->cuantos(PrintWriteOutcome::ESCRITO);
    }

    /**
     * Los trabajos FALLIDOS: la impresora no los quiso, y cada uno tiene su
     * motivo escrito.
     */
    public function fallidos(): int
    {
        return $this->cuantos(PrintWriteOutcome::FALLIDO);
    }

    /**
     * Los trabajos OMITIDOS: otro los tomó, o no eran del servidor.
     */
    public function omitidos(): int
    {
        return $this->cuantos(PrintWriteOutcome::OMITIDO);
    }

    /**
     * Los trabajos que NO se escribieron, con su motivo.
     *
     * @return list<PrintWriteOutcome>
     */
    public function problemas(): array
    {
        return array_values(array_filter(
            $this->resultados,
            static fn (PrintWriteOutcome $resultado): bool => $resultado->status !== PrintWriteOutcome::ESCRITO
        ));
    }

    /**
     * Cuantos resultados hay con un estado dado.
     */
    private function cuantos(string $status): int
    {
        return count(array_filter(
            $this->resultados,
            static fn (PrintWriteOutcome $resultado): bool => $resultado->status === $status
        ));
    }
}
