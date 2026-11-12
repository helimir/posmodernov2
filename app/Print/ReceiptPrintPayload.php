<?php

namespace App\Print;

use App\Domain\Fiscal\ReceiptTemplate;

/**
 * El PUENTE entre el modelo fiscal y el modelo del ticket.
 *
 * REGLA que fija esta clase: *el mismo modelo de plantilla alimenta a los dos
 * renderizadores*. `ReceiptTemplate` es el modelo FISCAL (la denominacion,
 * las partes, las lineas, los impuestos, los tokens del perfil) y
 * `PrintPayload` es el modelo del TICKET (las lineas de texto con su estilo
 * y, si lo hay, el QR). Sin este puente eran dos modelos sin conexion, y un
 * ticket con un numero y un comprobante con otro es el defecto que la
 * unidad 1.7 acabo de arreglar: no se puede reintroducir por otro lado.
 *
 * EL CONTENIDO ES EL DEL COMPROBANTE: el ticket lleva el MISMO numero de
 * documento, el MISMO numero de control, el mismo total y el mismo QR. Lo
 * que cambia es COMO se compone: aqui no hay HTML, hay lineas de texto.
 *
 * Y LAS ETIQUETAS SON LAS DEL COMPROBANTE: el ticket ESC/POS es el
 * comprobante en otro papel, y un ticket que llama "Total" a lo que el
 * comprobante llama "Total" y "Base" a lo que el comprobante llama "Base" es
 * el mismo documento.
 */
final class ReceiptPrintPayload
{
    /**
     * El TICKET del comprobante fiscal.
     */
    public static function fromTemplate(ReceiptTemplate $template): PrintPayload
    {
        $lineas = [];

        // 1. La fecha y la hora, en el formato del comprobante: DDMMAAAA y
        //    HH.MM.SS con a.m/p.m.
        $lineas[] = ['text' => $template->issuedAt->format('d/m/Y').' '.self::reloj($template)];

        // 2. El numero de documento y el de control: los MISMOS que lleva el
        //    comprobante.
        $lineas[] = ['text' => 'Nº '.$template->documentNumber, 'style' => 'bold'];

        if ($template->controlNumber !== null) {
            $lineas[] = ['text' => 'Nº Control '.$template->controlNumber, 'style' => 'bold'];
        }

        // 3. Las partes, con su identificativo.
        foreach ($template->parties as $parte) {
            $lineas[] = ['text' => self::parte($parte)];
        }

        // 4. Las lineas de la venta, con el marcador de exento al lado.
        foreach ($template->lines as $linea) {
            $texto = (string) $linea['description']
                .' x '.(string) $linea['quantity']
                .' '.(string) $linea['amount'];

            if (($linea['exempt'] ?? false) === true && $template->exemptionMarker !== null) {
                $texto .= ' '.$template->exemptionMarker;
            }

            $lineas[] = ['text' => $texto];
        }

        // 5. La base imponible y el impuesto, discriminados por alicuota.
        foreach ($template->taxes as $impuesto) {
            $texto = 'Base: '.(string) $impuesto['base_amount'];

            if ($impuesto['tax_rate_snapshot'] !== null) {
                $texto .= ' · Impuesto '.self::porcentaje((string) $impuesto['tax_rate_snapshot']).'%: '
                    .(string) $impuesto['tax_amount'];
            }

            if (self::esExenta($impuesto) && $template->exemptionMarker !== null) {
                $texto .= ' '.$template->exemptionMarker;
            }

            $lineas[] = ['text' => $texto];
        }

        // 6. El valor total, con la moneda.
        $total = 'Total: '.$template->totalAmount;

        if ($template->currencyCode !== null) {
            $total .= ' '.$template->currencyCode;
        }

        $lineas[] = ['text' => $total, 'style' => 'bold'];

        // 7. La frase del contribuyente formal, cuando hay operaciones exentas.
        if ($template->formalContributorPhrase !== null && self::tieneExentas($template)) {
            $lineas[] = ['text' => $template->formalContributorPhrase];
        }

        // 8. Y la conformidad con la providencia.
        if ($template->provisionReference !== null) {
            $lineas[] = ['text' => 'Emitido conforme a '.$template->provisionReference];
        }

        return new PrintPayload(
            lines: $lineas,
            // El QR viaja si el documento lo trae: es opcional por canal, y un
            // QR inventado es peor que ningun QR.
            qrContent: $template->qrContent,
            title: $template->denomination,
        );
    }

    /**
     * La HORA en HH.MM.SS con a.m/p.m, como en el comprobante.
     */
    private static function reloj(ReceiptTemplate $template): string
    {
        $hora = (int) $template->issuedAt->format('G');

        return $template->issuedAt->format('h.i.s').' '.($hora < 12 ? 'a.m' : 'p.m');
    }

    /**
     * La PARTE, con su identificativo fiscal o, si no lo tiene, su documento
     * de identidad.
     *
     * @param  array<string, mixed>  $parte
     */
    private static function parte(array $parte): string
    {
        $texto = (string) $parte['name'];

        if (! empty($parte['fiscal_id'])) {
            return $texto.' '.(string) $parte['fiscal_id'];
        }

        if (! empty($parte['fiscal_id_kind'])) {
            return $texto.' '.(string) $parte['fiscal_id_kind'];
        }

        return $texto;
    }

    /**
     * El PORCENTAJE aplicable, sin decimales cuando es entero.
     */
    private static function porcentaje(string $alicuota): string
    {
        $valor = (float) $alicuota;

        if (fmod($valor, 1.0) === 0.0) {
            return (string) (int) $valor;
        }

        return rtrim(rtrim(number_format($valor, 4, '.', ''), '0'), '.');
    }

    /**
     * ¿El impuesto es de alicuota CERO? La alicuota cero es la exenta.
     *
     * @param  array<string, mixed>  $impuesto
     */
    private static function esExenta(array $impuesto): bool
    {
        if ($impuesto['tax_rate_snapshot'] === null) {
            return false;
        }

        return (float) $impuesto['tax_rate_snapshot'] === 0.0;
    }

    /**
     * ¿Hay operaciones exentas o exoneradas?
     */
    private static function tieneExentas(ReceiptTemplate $template): bool
    {
        foreach ($template->taxes as $impuesto) {
            if (self::esExenta($impuesto)) {
                return true;
            }
        }

        return false;
    }
}
