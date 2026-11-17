<?php

namespace App\Domain\Fiscal\Enums;

/**
 * Actor intermedio de la emisión. Dato genérico: no lleva ningún código de
 * país (design §C.1, §D.3).
 */
enum ProviderKind: string
{
    case Printer = 'printer';
    case Pac = 'pac';
    case Portal = 'portal';

    /**
     * ¿El EMISOR reserva el numero de control en este canal?
     *
     * REGLA que fija este método: *el numero de control lo reserva el emisor
     * SOLO cuando el emisor es también la imprenta; cuando hay una autoridad
     * detrás, lo asigna ella*.
     *
     *  - `printer`: el ROL DUAL. El sistema es emisor E imprenta, así que el
     *    control lo saca el propio emisor, de su serie de control;
     *  - `pac` y `portal`: hay una AUTORIDAD detrás (el proveedor
     *    tecnológico o la autoridad), y el control lo asigna ella: el emisor
     *    no reserva nada y el número llega con el acuse.
     *
     * Y LA REGLA ES DEL DOMINIO, no de la configuración: el tipo de canal es
     * un dato genérico (design §C.1, ADR-0003), y la ÚNICA implementación de
     * esta regla está aquí. Un literal en el checkout sería una segunda
     * verdad, y las dos divergirían.
     */
    public function issuerReservesControl(): bool
    {
        return match ($this) {
            self::Printer => true,
            self::Pac, self::Portal => false,
        };
    }
}
