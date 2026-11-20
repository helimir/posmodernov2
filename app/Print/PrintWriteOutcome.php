<?php

namespace App\Print;

/**
 * El RESULTADO de escribir un trabajo de impresion.
 *
 * REGLA que fija esta clase: *escribir un ticket NUNCA es un fallo del
 * sistema*. El resultado dice SI se escribieron los bytes, o POR QUE no se
 * escribieron. La venta ya está confirmada y el ticket ya está encolado: un
 * ticket que no sale no deshace una venta, y un trabajo que no se puede
 * imprimir es un trabajo `failed`, con su motivo, y reintentable.
 *
 * Y EL MOTIVO ES UN CODIGO CERRADO: quien lee el resultado decide con el
 * CODIGO, y el mensaje es para el operador. Un motivo libre obligaría a
 * adivinar.
 */
final readonly class PrintWriteOutcome
{
    /**
     * Los bytes SALIERON: el ticket está en la impresora.
     */
    public const ESCRITO = 'written';

    /**
     * Los bytes NO salieron, y el motivo dice por qué.
     */
    public const FALLIDO = 'failed';

    /**
     * El servidor NO tenía que escribir este trabajo: su destino lo escribe
     * otro, o el trabajo ya no estaba encolado.
     */
    public const OMITIDO = 'omitted';

    /**
     * El destino lo escribe el AGENTE de la LAN.
     */
    public const MOTIVO_AGENTE = 'lan_agent';

    /**
     * El destino es el NAVEGADOR: no hay destino físico.
     */
    public const MOTIVO_NAVEGADOR = 'browser';

    /**
     * El trabajo no estaba encolado: otro lo tomó, o ya se imprimió.
     */
    public const MOTIVO_NO_ENCOLADO = 'not_queued';

    /**
     * Otro bombeo YA TOMÓ el trabajo: el reclamo es atómico.
     */
    public const MOTIVO_TOMADO = 'claimed_by_other';

    /**
     * No se pudo tomar el trabajo porque la base de datos lo tenía bloqueado.
     */
    public const MOTIVO_BLOQUEADO = 'blocked';

    public function __construct(
        public string $status,
        public int $jobId,
        public ?string $motivo = null,
        public ?string $mensaje = null,
    ) {}

    /**
     * Los bytes salieron.
     */
    public static function escrito(int $jobId): self
    {
        return new self(self::ESCRITO, $jobId);
    }

    /**
     * Los bytes no salieron, y el motivo queda escrito en el trabajo.
     */
    public static function fallido(int $jobId, string $mensaje): self
    {
        return new self(self::FALLIDO, $jobId, null, $mensaje);
    }

    /**
     * El servidor no tenía que escribir este trabajo.
     */
    public static function omitido(int $jobId, string $motivo, string $mensaje): self
    {
        return new self(self::OMITIDO, $jobId, $motivo, $mensaje);
    }

    /**
     * ¿Se escribieron los bytes?
     */
    public function fueEscrito(): bool
    {
        return $this->status === self::ESCRITO;
    }
}
