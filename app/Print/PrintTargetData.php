<?php

namespace App\Print;

/**
 * Los DATOS de un destino de impresion: lo que la tienda DECLARA.
 *
 * REGLA que fija esta clase: *todo lo que el destino sabe de su impresora es
 * DATO, y el dato entra por aqui*. El perfil ESC/POS, la codepagina, el
 * ancho de linea, el soporte de QR y su limite son cosas que declara la
 * tienda: el sistema no las adivina, y el destino sin declararlas no puede
 * componer un ticket.
 *
 * Y EL ANCHO PUEDE NO ESTAR: un destino sin ancho declarado es un destino
 * incompleto, y el renderer lo rechaza en vez de componer un ticket a ciegas.
 */
final readonly class PrintTargetData
{
    /**
     * @param  int  $printerId  La impresora del catalogo que usa el destino.
     * @param  string  $name  El nombre del destino, unico por tienda.
     * @param  string  $transport  `browser`, `lan_agent` o `server_socket`.
     * @param  string|null  $endpoint  Donde se escriben los bytes; `null` en el `browser`.
     * @param  string  $printerProfile  El perfil ESC/POS de la impresora.
     * @param  int|null  $charPerLine  El ancho de linea, en caracteres.
     * @param  string  $charset  La codepagina de la impresora.
     * @param  int  $maxQrPayloadBytes  El limite del payload del QR.
     * @param  bool  $supportsQr  Si la impresora sabe con QR.
     * @param  bool  $isActive  Si el destino recibe tickets.
     */
    public function __construct(
        public int $printerId,
        public string $name,
        public string $transport,
        public ?string $endpoint,
        public string $printerProfile,
        public ?int $charPerLine,
        public string $charset,
        public int $maxQrPayloadBytes,
        public bool $supportsQr,
        public bool $isActive,
    ) {}
}
