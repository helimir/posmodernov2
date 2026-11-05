<?php

namespace App\Print;

/**
 * Lo que se imprime.
 *
 * Es el MODELO del ticket, no los bytes: el dominio decide QUÉ se imprime y
 * cada renderer decide CÓMO. El mismo modelo alimenta al renderer ESC/POS y
 * al render HTML/PDF del transporte `browser` (design D11).
 *
 * Las líneas son texto con un estilo opcional; el título es la cabecera en
 * negrita; el QR es el contenido del código, si el destino lo admite.
 */
final readonly class PrintPayload
{
    public function __construct(
        /** @var list<array{text: string, style?: string}> */
        public array $lines = [],
        public ?string $qrContent = null,
        public string $title = '',
    ) {}

    /**
     * El modelo serializado: es lo que se congela en `print_jobs.payload`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'lines' => $this->lines,
            'qr_content' => $this->qrContent,
        ];
    }
}
