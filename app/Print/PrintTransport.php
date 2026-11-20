<?php

namespace App\Print;

use RuntimeException;

/**
 * El TRANSPORTE de un destino de impresion: QUIEN escribe los bytes.
 *
 * REGLA que fija este enum: *quien puede escribir en un destino lo decide el
 * TRANSPORTE, y el TRANSPORTE es DATO*.
 *
 * | Transporte      | Quien escribe los bytes                          |
 * |-----------------|--------------------------------------------------|
 * | `server_socket` | el SERVIDOR: los escribe en el socket o en la cola |
 * | `lan_agent`     | el AGENTE de la LAN: el servidor sólo encola      |
 * | `browser`       | el NAVEGADOR: no hay destino físico               |
 *
 * Y EL SERVIDOR NO ESCRIBE EN UN DESTINO DEL AGENTE: si lo hiciera, el
 * ticket saldría DOS veces, una por el servidor y otra por el agente. Por eso
 * la pregunta "¿este destino lo escribe el servidor?" tiene UNA respuesta, y
 * la respuesta la da la fila del destino, no el código que la usa.
 */
enum PrintTransport: string
{
    /**
     * El navegador ES el destino: se sirve el comprobante en HTML/PDF desde
     * el mismo modelo de plantilla. No hay bytes ESC/POS ni endpoint.
     */
    case Browser = 'browser';

    /**
     * El AGENTE de la LAN escribe los bytes: el servidor sólo encola el
     * trabajo, y el agente lo reclama por la cola.
     */
    case LanAgent = 'lan_agent';

    /**
     * El SERVIDOR escribe los bytes: en el socket de la impresora de red o en
     * la cola de la impresora compartida de Windows.
     */
    case ServerSocket = 'server_socket';

    /**
     * ¿Los escribe el SERVIDOR?
     *
     * REGLA que fija este método: *solo el transporte `server_socket` lo
     * escribe el servidor*. Los otros dos tienen su dueño, y el servidor
     * respeta ese dueño: un trabajo de un destino del agente se queda
     * encolado.
     */
    public function loEscribeElServidor(): bool
    {
        return $this === self::ServerSocket;
    }

    /**
     * ¿El destino es FISICO?
     *
     * El navegador no lo es: no hay impresora detrás, y el comprobante se ve
     * en la pantalla.
     */
    public function esDestinoFisico(): bool
    {
        return $this !== self::Browser;
    }

    /**
     * El TRANSPORTE declarado, o un error con mensaje útil.
     *
     * @throws RuntimeException Si el transporte no existe.
     */
    public static function desde(?string $valor): self
    {
        $transporte = self::tryFrom((string) $valor);

        if ($transporte === null) {
            throw new RuntimeException(
                "El transporte de impresion «{$valor}» no existe: solo se admiten "
                .implode(', ', array_column(self::cases(), 'value')).'.'
            );
        }

        return $transporte;
    }
}
