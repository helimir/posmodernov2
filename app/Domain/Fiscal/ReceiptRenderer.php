<?php

namespace App\Domain\Fiscal;

/**
 * Render del comprobante fiscal a HTML.
 *
 * Consume el MISMO modelo que el renderer ESC/POS: el dominio decide QUE se
 * imprime (los datos y los tokens del perfil) y cada renderer decide COMO.
 *
 * El contenido es el del comprobante: denominacion, fecha, hora, las partes
 * con su identificativo fiscal, la base imponible y el impuesto discriminados
 * por alicuota, el valor total, el marcador de exento y la frase del
 * contribuyente formal. En moneda extranjera van ambas cantidades y el tipo
 * de cambio.
 *
 * La tipografia no es decorativa: los datos de la imprenta van al menos a 6
 * puntos, y la identificacion del emisor y el numero de control al menos a 8.
 */
final class ReceiptRenderer
{
    /** Los datos de la imprenta, al menos 6 puntos. */
    private const PRINTER_POINT_SIZE = '6pt';

    /** La identificacion del emisor y el numero de control, al menos 8. */
    private const IDENTIFICATION_POINT_SIZE = '8pt';

    public function render(ReceiptTemplate $template): string
    {
        $html = '<section class="receipt">';

        // La denominacion: el nombre del documento, tal como lo declara el
        // perfil.
        $html .= '<h1 class="receipt__denomination">'.$this->escape($template->denomination).'</h1>';

        // Fecha y hora: la fecha en DDMMAAAA y la hora en HH.MM.SS con
        // a.m/p.m.
        $html .= '<p class="receipt__datetime">'
            .$template->issuedAt->format('d/m/Y')
            .' '.$this->clock($template->issuedAt)
            .'</p>';

        // El numero de documento y el de control: el MISMO en todas las
        // paginas.
        $html .= '<p class="receipt__numbers" style="font-size:'.self::IDENTIFICATION_POINT_SIZE.'">'
            .'N&#186; '.$this->escape($template->documentNumber);
        if ($template->controlNumber !== null) {
            $html .= ' &middot; N&#186; Control '.$this->escape($template->controlNumber);
        }
        $html .= '</p>';

        // Las partes: emisor y receptor, con su identificativo fiscal o, si
        // no lo tienen, el documento de identidad.
        foreach ($template->parties as $party) {
            $html .= '<div class="receipt__party receipt__party--'.$this->escape($party['role']).'"'
                .' style="font-size:'.self::IDENTIFICATION_POINT_SIZE.'">';
            $html .= '<span class="receipt__party-name">'.$this->escape($party['name']).'</span>';
            $html .= $this->identifier($party);
            $html .= '</div>';
        }

        // Las lineas, con el marcador de exento al lado cuando la linea no
        // causa impuesto.
        if ($template->lines !== []) {
            $html .= '<table class="receipt__lines"><tbody>';
            foreach ($template->lines as $line) {
                $html .= '<tr><td>'.$this->escape((string) $line['description']).'</td>';
                $html .= '<td>'.$this->escape((string) ($line['quantity'] ?? '')).'</td>';
                $html .= '<td>'.$this->escape((string) ($line['amount'] ?? '')).'</td>';
                if (($line['exempt'] ?? false) === true && $template->exemptionMarker !== null) {
                    $html .= '<td class="receipt__exempt">'.$this->escape($template->exemptionMarker).'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        // La base imponible y el impuesto, DISCRIMINADOS por alicuota.
        foreach ($template->taxes as $tax) {
            $html .= '<p class="receipt__tax">'
                .'Base: '.$this->escape($tax['base_amount']);
            if ($tax['tax_rate_snapshot'] !== null) {
                $html .= ' &middot; Impuesto '.$this->escape($this->percentage($tax['tax_rate_snapshot'])).'%: '
                    .$this->escape($tax['tax_amount']);
            }
            // La alicuota CERO es la exenta: lleva el marcador al lado.
            if ($this->isExemptRate($tax) && $template->exemptionMarker !== null) {
                $html .= ' <span class="receipt__exempt">'.$this->escape($template->exemptionMarker).'</span>';
            }
            $html .= '</p>';
        }

        // El valor total. En moneda extranjera, ambas cantidades y el tipo de
        // cambio.
        $html .= '<p class="receipt__total">Total: '.$this->escape($template->totalAmount);
        if ($template->currencyCode !== null) {
            $html .= ' '.$this->escape($template->currencyCode);
        }
        if ($template->originalCurrencyCode !== null && $template->exchangeRate !== null) {
            $html .= ' ('.$this->escape($template->totalAmount).' '.$this->escape($template->originalCurrencyCode)
                .' a '.$this->escape($template->exchangeRate).')';
        }
        $html .= '</p>';

        // La frase del contribuyente formal: solo cuando el perfil la declara
        // y hay operaciones exentas o exoneradas.
        if ($template->formalContributorPhrase !== null && $this->hasExemptOperations($template)) {
            $html .= '<p class="receipt__formal-contributor">'.$this->escape($template->formalContributorPhrase).'</p>';
        }

        // Los datos de la imprenta y la conformidad con la providencia.
        if ($template->printerName !== null || $template->printerTaxId !== null) {
            $html .= '<p class="receipt__printer" style="font-size:'.self::PRINTER_POINT_SIZE.'">';
            if ($template->printerName !== null) {
                $html .= $this->escape($template->printerName).' ';
            }
            if ($template->printerTaxId !== null) {
                $html .= $this->escape($template->printerTaxId).' ';
            }
            $html .= '</p>';
        }

        if ($template->provisionReference !== null) {
            $html .= '<p class="receipt__provision">'
                .'Emitido conforme a '.$this->escape($template->provisionReference)
                .'</p>';
        }

        return $html.'</section>';
    }

    /**
     * La hora en HH.MM.SS con a.m/p.m.
     */
    private function clock(\DateTimeImmutable $issuedAt): string
    {
        $hour = (int) $issuedAt->format('G');
        $meridiem = $hour < 12 ? 'a.m' : 'p.m';

        return $issuedAt->format('h.i.s').' '.$meridiem;
    }

    /**
     * El identificativo de la parte: el fiscal si lo tiene, si no el
     * documento de identidad, que es lo que la norma admite para una persona
     * natural sin factura a efectos tributarios.
     */
    private function identifier(array $party): string
    {
        if (! empty($party['fiscal_id'])) {
            return ' <span class="receipt__fiscal-id">'.$this->escape((string) $party['fiscal_id']).'</span>';
        }

        if (! empty($party['fiscal_id_kind'])) {
            return ' <span class="receipt__identity-document">'
                .$this->escape((string) $party['fiscal_id_kind']).'</span>';
        }

        return '';
    }

    /**
     * El porcentaje aplicable, sin decimales cuando es entero: la norma pide
     * indicar el porcentaje, no la escala del tipo de la columna.
     */
    private function percentage(string $rate): string
    {
        $value = (float) $rate;

        if (fmod($value, 1.0) === 0.0) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * ¿Hay operaciones exentas o exoneradas? Solo entonces va la frase del
     * contribuyente formal. La alícuota cero es la exenta.
     *
     * @param  array<string, mixed>  $tax
     */
    private function isExemptRate(array $tax): bool
    {
        if ($tax['tax_rate_snapshot'] === null) {
            return false;
        }

        return (float) $tax['tax_rate_snapshot'] === 0.0;
    }

    /**
     * @param  array<string, mixed>  $tax
     */
    private function hasExemptOperations(ReceiptTemplate $template): bool
    {
        foreach ($template->taxes as $tax) {
            if ($this->isExemptRate($tax)) {
                return true;
            }
        }

        return false;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
