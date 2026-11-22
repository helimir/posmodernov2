<?php

namespace App\Domain\Accounting;

/**
 * El Z en HTML: el PAPEL de la impresora termica.
 *
 * REGLA que fija esta clase: *el papel dice lo mismo que el Z*. Cada fila
 * sale del MODELO (`CashCloseTemplate`), que a su vez sale de la fila del Z
 * sellado: no hay una segunda aritmetica.
 *
 * CADA FILA lleva su `data-field`: el nombre del dato que enseña. No es
 * decoracion: es el contrato que permite comprobar que el papel dice la
 * partida, el esperado, el contado, el descuadre, los totales, el desglose por
 * medio de pago, el conteo por denominacion, los correlativos y la firma, sin
 * tener que leer el papel entero.
 *
 * Y LA TIPOGRAFIA la pone la vista (el CSS de la impresora termica), no el
 * render: aqui solo se compone el contenido.
 */
final class CashCloseRenderer
{
    public function render(CashCloseTemplate $z): string
    {
        $html = '<section class="z">';

        $html .= '<h1 class="z__denomination">Closing report (Z)</h1>';

        $html .= '<table class="z__rows"><tbody>';

        // La IDENTIDAD del Z: la tienda y la fecha de negocio.
        $html .= $this->seccion('Closing');
        $html .= $this->fila('store', 'Store', $z->storeName);
        $html .= $this->fila('business_date', 'Business date', $z->businessDate);

        // Los SALDOS: la partida, el esperado, el contado y el DESCUADRE.
        $html .= $this->seccion('Balances');
        $html .= $this->fila('opening_balance', 'Opening balance', $z->monto($z->openingBalance));
        $html .= $this->fila('expected_balance', 'Expected balance', $z->monto($z->expectedBalance));
        $html .= $this->fila('counted_balance', 'Counted balance', $z->monto($z->countedBalance));
        $html .= $this->fila('difference', 'Difference', $z->descuadre(), true);

        // Los TOTALES del dia y el numero de ventas.
        $html .= $this->seccion('Totals');
        $html .= $this->fila('subtotal', 'Subtotal', $z->monto($z->subtotal));
        $html .= $this->fila('tax_total', 'Tax', $z->monto($z->taxTotal));
        $html .= $this->fila('grand_total', 'Total', $z->monto($z->grandTotal), true);
        $html .= $this->fila('sales_count', 'Sales', (string) $z->salesCount);

        // Los CORRELATIVOS: el rango de documentos y el de controles del dia.
        $html .= $this->seccion('Numbering');
        $html .= $this->fila('documents', 'Documents', $z->rango($z->firstDocumentNumber, $z->lastDocumentNumber));
        $html .= $this->fila('control', 'Control', $z->rango($z->firstControlNumber, $z->lastControlNumber));

        // El DESGLOSE por medio de pago: lo que entro en la caja y por donde.
        if ($z->payments !== []) {
            $html .= $this->seccion('Payments by method');

            foreach ($z->payments as $pago) {
                $campo = 'payment_'.($pago['id'] ?? 'sin_id');

                // El pago de EFECTIVO se destaca: es el dinero que hay en el
                // cajon y el que el cajero cuenta. Un pago a credito es
                // deuda, no dinero de la caja.
                $html .= $this->fila(
                    $campo,
                    $pago['name'] ?? 'Payment',
                    $z->monto($pago['amount']),
                    (bool) $pago['is_cash'],
                );
            }
        }

        // El CONTEO por DENOMINACION: el valor de la pieza, cuantas y cuanto
        // dinero representan.
        if ($z->denominations !== []) {
            $html .= $this->seccion('Counted denominations');

            foreach ($z->denominations as $denominacion) {
                $html .= '<tr class="z__row z__row--denomination" data-field="denomination_'.$denominacion['id'].'">'
                    .'<td class="z__cell z__cell--number">'.$this->escape($z->monto($denominacion['value'])).'</td>'
                    .'<td class="z__cell z__cell--number">'.(int) $denominacion['count'].'</td>'
                    .'<td class="z__cell z__cell--number">'.$this->escape($z->monto($denominacion['amount'])).'</td>'
                    .'</tr>';
            }
        }

        // QUIEN CERRO, CUANDO, POR QUE y la FIRMA del Z.
        $html .= $this->seccion('Closing');
        $html .= $this->fila('closed_by', 'Closed by', $z->closedByName ?? '-');
        $html .= $this->fila('closed_at', 'Closed at', $z->closedAt ?? '-');

        if ($z->closeReason !== null) {
            $html .= $this->fila('close_reason', 'Reason', $z->closeReason);
        }

        $html .= '<tr class="z__row z__row--signature" data-field="signature">'
            .'<td class="z__cell z__cell--label">Signature (sha256)</td>'
            .'<td class="z__cell z__cell--hash">'.$this->escape($z->contentHash).'</td>'
            .'</tr>';

        $html .= '</tbody></table>';

        // Y la FIRMA que NO CUADRA se dice en el papel: un Z alterado no es un
        // Z, y el papel no lo disimula.
        if (! $z->verified) {
            $html .= '<p class="z__warning">'
                .'The signature of this closing does not match its content: somebody altered a sealed closing.'
                .'</p>';
        }

        return $html.'</section>';
    }

    /**
     * El CABECERO de una seccion del Z.
     */
    private function seccion(string $titulo): string
    {
        return '<tr class="z__section"><td class="z__cell" colspan="2">'.$this->escape($titulo).'</td></tr>';
    }

    /**
     * Una FILA del Z: la etiqueta y el dato.
     *
     * @param  bool  $destacado  La fila se destaca (el total y el descuadre).
     */
    private function fila(string $campo, string $etiqueta, string $valor, bool $destacado = false): string
    {
        $clase = $destacado ? 'z__row z__row--strong' : 'z__row';

        return '<tr class="'.$clase.'" data-field="'.$this->escape($campo).'">'
            .'<td class="z__cell z__cell--label">'.$this->escape($etiqueta).'</td>'
            .'<td class="z__cell z__cell--value">'.$this->escape($valor).'</td>'
            .'</tr>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
