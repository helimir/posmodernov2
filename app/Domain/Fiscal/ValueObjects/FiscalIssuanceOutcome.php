<?php

namespace App\Domain\Fiscal\ValueObjects;

/**
 * El RESULTADO de una emision fiscal.
 *
 * `status` es el estado FISCAL de la venta, y su vocabulario es el del
 * dominio: `issued` (emitido), `failed` (fallido) o `not_emitted` (no se
 * emitio). No es el estado de PAGO: ese lo dice `sales.status`.
 *
 * Y el motivo del fallo viaja CON el resultado: un fallo fiscal que no se
 * explica no se puede reintentar a ciegas.
 */
final readonly class FiscalIssuanceOutcome
{
    /**
     * El comprobante se emitio.
     */
    public const EMITIDO = 'issued';

    /**
     * La emision fallo: el comprobante esta pendiente.
     */
    public const FALLIDO = 'failed';

    /**
     * No se emitio: el interruptor de emision esta apagado.
     */
    public const NO_EMITIDO = 'not_emitted';

    public function __construct(
        public string $status,
        public ?int $documentId = null,
        public ?string $controlNumber = null,
        public ?string $message = null,
    ) {}

    /**
     * El comprobante SE EMITIO.
     */
    public static function emitido(?int $documentId, ?string $controlNumber): self
    {
        return new self(self::EMITIDO, $documentId, $controlNumber);
    }

    /**
     * La emision FALLO, con su motivo.
     *
     * El `documentId` es el del borrador que se quedo a medias: el
     * comprobante existe, en `draft`, y el reintento lo encuentra.
     */
    public static function fallido(string $message, ?int $documentId = null): self
    {
        return new self(self::FALLIDO, $documentId, null, $message);
    }

    /**
     * No se emitio: el interruptor esta apagado.
     */
    public static function noEmitido(): self
    {
        return new self(self::NO_EMITIDO);
    }

    /**
     * El resultado, con la forma que viaja en la respuesta.
     *
     * @return array{status: string, document_id: int|null, control_number: string|null, message: string|null}
     */
    public function aArray(): array
    {
        return [
            'status' => $this->status,
            'document_id' => $this->documentId,
            'control_number' => $this->controlNumber,
            'message' => $this->message,
        ];
    }
}
