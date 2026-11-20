<?php

namespace App\Print;

/**
 * El RESULTADO de encolar un ticket.
 *
 * REGLA que fija esta clase: *encolar un ticket NUNCA es un fallo*. El
 * resultado dice SI se encolo (con el identificador del trabajo) o POR QUE
 * no se encolo (con su motivo). Encolar es un paso MAS del cobro: la venta
 * ya esta confirmada, y un ticket que no sale no deshace una venta.
 *
 * EL MOTIVO ES UN CODIGO CERRADO: el cajero lee el TEXTO y la pantalla decide
 * con el CODIGO. Un motivo libre obligaria a la pantalla a adivinar.
 */
final readonly class PrintQueueOutcome
{
    /**
     * El ticket se ENCOLO: hay un trabajo con sus bytes congelados.
     */
    public const ENCOLADO = 'queued';

    /**
     * El ticket NO se encolo, y el motivo dice por que.
     */
    public const NO_ENCOLADO = 'not_queued';

    /**
     * La tienda no imprime automaticamente: el interruptor esta apagado.
     */
    public const AUTO_PRINT_APAGADO = 'auto_print_disabled';

    /**
     * La tienda no tiene ningun destino de impresion activo.
     */
    public const SIN_DESTINO = 'no_destination';

    /**
     * La tienda tiene mas de un destino activo: el sistema no elige.
     */
    public const DESTINO_AMBIGUO = 'ambiguous_destination';

    /**
     * La venta no tiene comprobante fiscal: no hay ticket que imprimir.
     */
    public const SIN_COMPROBANTE = 'no_document';

    /**
     * El comprobante existe pero no esta emitido.
     */
    public const COMPROBANTE_NO_EMITIDO = 'document_not_issued';

    /**
     * El destino no puede representar el ticket.
     */
    public const FALLO_DEL_DESTINO = 'dispatch_failed';

    public function __construct(
        public string $status,
        public ?int $jobId = null,
        public ?string $reason = null,
        public ?string $message = null,
    ) {}

    /**
     * El ticket se encolo: el trabajo existe, con sus bytes congelados.
     */
    public static function encolado(int $jobId): self
    {
        return new self(self::ENCOLADO, $jobId);
    }

    /**
     * El ticket NO se encolo, y el motivo lo dice.
     */
    public static function noEncolado(string $reason, string $message): self
    {
        return new self(self::NO_ENCOLADO, null, $reason, $message);
    }

    /**
     * El resultado, con la forma que viaja en la respuesta del cobro.
     *
     * @return array{status: string, job_id: int|null, reason: string|null, message: string|null}
     */
    public function aArray(): array
    {
        return [
            'status' => $this->status,
            'job_id' => $this->jobId,
            'reason' => $this->reason,
            'message' => $this->message,
        ];
    }
}
