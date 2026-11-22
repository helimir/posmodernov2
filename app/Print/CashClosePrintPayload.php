<?php

namespace App\Print;

use App\Domain\Accounting\CashCloseTemplate;
use RuntimeException;

/**
 * El PUENTE entre el modelo del Z y el modelo del ticket.
 *
 * REGLA que fija esta clase: *el Z de la impresora lleva el MISMO contenido
 * que el Z de la pantalla*. Es el mismo puente que `ReceiptPrintPayload` es
 * para el comprobante fiscal: un MODELO (`CashCloseTemplate`) y dos
 * renderizadores (el HTML del papel y el ESC/POS de la impresora).
 *
 * Y EL ANCHO MANDA: el Z tiene MUCHAS columnas (el desglose por medio de pago
 * y el conteo por denominacion) y en 32 caracteres no cabe una tabla de tres
 * columnas. Por eso el Z se compone en DOS COLUMNAS (la etiqueta a la
 * izquierda, el dato a la derecha) y la tabla de denominaciones baja a tres
 * datos en una linea, o a dos lineas si el papel es mas estrecho.
 *
 * EL ANCHO ES EL QUE DECLARA EL DESTINO, y es DATO de la tienda: una
 * termica de 80 mm admite 42 caracteres y una de 58 mm, 32. El sistema no lo
 * adivina.
 *
 * Y NADA SE RECORTA EN SILENCIO: si una etiqueta no cabe, se ENVUELVE en
 * varias lineas; si un dato no cabe, el Z no se compone y se dice por que.
 */
final class CashClosePrintPayload
{
    /**
     * El ANCHO MINIMO de un destino para el Z: por debajo de esto no hay un
     * Z legible, y un Z ilegible no es un Z.
     */
    public const ANCHO_MINIMO = 20;

    /**
     * La DENOMINACION del documento, en el papel y en la impresora.
     */
    public const DENOMINACION = 'CLOSING REPORT (Z)';

    /**
     * El TICKET del Z.
     *
     * @param  int  $charPerLine  El ancho que declara el destino.
     *
     * @throws RuntimeException Si el destino es demasiado estrecho para el Z.
     */
    public static function fromTemplate(CashCloseTemplate $z, int $charPerLine): PrintPayload
    {
        if ($charPerLine < self::ANCHO_MINIMO) {
            throw new RuntimeException(
                'El Z de cierre necesita al menos '.self::ANCHO_MINIMO.' caracteres por linea '
                ."y el destino de impresion solo admite {$charPerLine}: no se puede componer el Z."
            );
        }

        $lineas = [];

        // La IDENTIDAD del Z.
        $lineas[] = ['text' => self::DENOMINACION, 'style' => 'bold'];
        $lineas = array_merge($lineas, self::conEtiqueta('Store', $z->storeName, $charPerLine));
        $lineas = array_merge($lineas, self::conEtiqueta('Business date', $z->businessDate, $charPerLine));

        // La columna de los DATOS: el ancho que ocupan a la derecha. Se
        // calcula con los datos mas largos, para que la columna sea una.
        $valores = [
            $z->monto($z->openingBalance),
            $z->monto($z->expectedBalance),
            $z->monto($z->countedBalance),
            $z->descuadre(),
            $z->monto($z->subtotal),
            $z->monto($z->taxTotal),
            $z->monto($z->grandTotal),
            (string) $z->salesCount,
        ];

        foreach ($z->payments as $pago) {
            $valores[] = $z->monto($pago['amount']);
        }

        $anchoValor = self::anchoDeLaColumna($valores, $charPerLine, $z);

        // Los SALDOS y los TOTALES: las dos columnas.
        $lineas[] = ['text' => ''];
        $lineas[] = ['text' => 'Balances'];
        $lineas = array_merge($lineas, self::dosColumnas('Opening balance', $z->monto($z->openingBalance), $charPerLine, $anchoValor));
        $lineas = array_merge($lineas, self::dosColumnas('Expected balance', $z->monto($z->expectedBalance), $charPerLine, $anchoValor));
        $lineas = array_merge($lineas, self::dosColumnas('Counted balance', $z->monto($z->countedBalance), $charPerLine, $anchoValor));
        $lineas = array_merge($lineas, self::dosColumnas('Difference', $z->descuadre(), $charPerLine, $anchoValor));

        $lineas[] = ['text' => ''];
        $lineas[] = ['text' => 'Totals'];
        $lineas = array_merge($lineas, self::dosColumnas('Subtotal', $z->monto($z->subtotal), $charPerLine, $anchoValor));
        $lineas = array_merge($lineas, self::dosColumnas('Tax', $z->monto($z->taxTotal), $charPerLine, $anchoValor));
        $lineas = array_merge($lineas, self::dosColumnas('Total', $z->monto($z->grandTotal), $charPerLine, $anchoValor));
        $lineas = array_merge($lineas, self::dosColumnas('Sales', (string) $z->salesCount, $charPerLine, $anchoValor));

        // Los CORRELATIVOS: texto libre, que se envuelve si no cabe.
        $lineas[] = ['text' => ''];
        $lineas[] = ['text' => 'Numbering'];
        $lineas = array_merge($lineas, self::conEtiqueta('Documents', $z->rango($z->firstDocumentNumber, $z->lastDocumentNumber), $charPerLine));
        $lineas = array_merge($lineas, self::conEtiqueta('Control', $z->rango($z->firstControlNumber, $z->lastControlNumber), $charPerLine));

        // El DESGLOSE por medio de pago.
        if ($z->payments !== []) {
            $lineas[] = ['text' => ''];
            $lineas[] = ['text' => 'Payments'];

            foreach ($z->payments as $pago) {
                $lineas = array_merge($lineas, self::dosColumnas(
                    $pago['name'] ?? 'Payment',
                    $z->monto($pago['amount']),
                    $charPerLine,
                    $anchoValor,
                ));
            }
        }

        // El CONTEO por DENOMINACION: una tabla que se adapta al papel.
        if ($z->denominations !== []) {
            $lineas[] = ['text' => ''];
            $lineas[] = ['text' => 'Denominations'];
            $lineas = array_merge($lineas, self::tablaDeDenominaciones($z, $charPerLine));
        }

        // QUIEN CERRO, CUANDO y POR QUE.
        $lineas[] = ['text' => ''];
        $lineas[] = ['text' => 'Closing'];
        $lineas = array_merge($lineas, self::conEtiqueta('Closed by', $z->closedByName ?? '-', $charPerLine));
        $lineas = array_merge($lineas, self::conEtiqueta('Closed at', $z->closedAt ?? '-', $charPerLine));

        if ($z->closeReason !== null) {
            $lineas = array_merge($lineas, self::conEtiqueta('Reason', $z->closeReason, $charPerLine));
        }

        // Y LA FIRMA del Z: el SHA-256 partido en las lineas que el papel
        // permite. Es lo ULTIMO, porque es lo que se comprueba.
        $lineas[] = ['text' => ''];
        $lineas[] = ['text' => 'Signature (sha256)'];

        foreach (str_split($z->contentHash, $charPerLine) as $parte) {
            $lineas[] = ['text' => $parte];
        }

        return new PrintPayload(
            lines: $lineas,
            // El Z no lleva QR: su firma es el hash, y el hash es texto.
            qrContent: null,
            title: self::DENOMINACION,
        );
    }

    /**
     * El ANCHO de la columna de los DATOS.
     *
     * REGLA que fija este metodo: *la columna se mide con los datos mas
     * largos*, para que todos los datos terminen en la misma columna. Y si un
     * dato no cabe NI CON la etiqueta minima, el Z no se compone: un Z que no
     * cabe no se recorta en silencio.
     *
     * @param  list<string>  $valores
     *
     * @throws RuntimeException Si un dato no cabe en el ancho declarado.
     */
    private static function anchoDeLaColumna(array $valores, int $charPerLine, CashCloseTemplate $z): int
    {
        $largos = array_map(static fn (string $valor): int => mb_strlen($valor), $valores);

        $masLargo = $largos === [] ? 0 : max($largos);

        // El dato mas largo, mas una etiqueta minima y el espacio que los
        // separa.
        if ($masLargo + 3 > $charPerLine) {
            throw new RuntimeException(
                'El Z de cierre no cabe en el destino de impresion: el dato mas largo ocupa '
                .$masLargo.' caracteres y el destino solo admite '.$charPerLine.' por linea.'
            );
        }

        // La etiqueta conserva al menos once caracteres: por debajo de eso no
        // hay dos columnas, hay una.
        return max(1, min($masLargo, $charPerLine - 12));
    }

    /**
     * Una fila de DOS COLUMNAS: la etiqueta a la izquierda y el dato a la
     * derecha.
     *
     * Y SI LA ETIQUETA NO CABE, se ENVUELVE: el dato se queda en la ultima
     * linea y la etiqueta ocupa las de arriba. No se recorta: un Z que dice
     * medio nombre no dice nada.
     *
     * @return list<array{text: string, style?: string}>
     */
    private static function dosColumnas(string $etiqueta, string $valor, int $ancho, int $anchoValor): array
    {
        if (mb_strlen($etiqueta) + 1 + $anchoValor <= $ancho) {
            $hueco = $ancho - mb_strlen($etiqueta) - $anchoValor;

            return [['text' => $etiqueta.str_repeat(' ', max(1, $hueco)).$valor]];
        }

        $anchoEtiqueta = max(1, $ancho - $anchoValor - 1);

        $partes = self::envuelve($etiqueta, $anchoEtiqueta);

        $lineas = [];

        foreach ($partes as $parte) {
            $lineas[] = ['text' => $parte];
        }

        $ultima = array_pop($lineas);

        $hueco = $ancho - mb_strlen((string) $ultima['text']) - $anchoValor;

        $lineas[] = ['text' => (string) $ultima['text'].str_repeat(' ', max(1, $hueco)).$valor];

        return $lineas;
    }

    /**
     * Una fila de TEXTO LIBRE: la etiqueta, dos puntos y el texto.
     *
     * @return list<array{text: string, style?: string}>
     */
    private static function conEtiqueta(string $etiqueta, string $valor, int $ancho): array
    {
        $prefijo = $etiqueta.': ';

        if (mb_strlen($prefijo.$valor) <= $ancho) {
            return [['text' => $prefijo.$valor]];
        }

        $anchoTexto = max(1, $ancho - mb_strlen($prefijo));

        $lineas = [];

        foreach (self::envuelve($valor, $anchoTexto) as $parte) {
            $lineas[] = ['text' => $prefijo.$parte];
            $prefijo = str_repeat(' ', mb_strlen($etiqueta) + 2);
        }

        return $lineas;
    }

    /**
     * La TABLA de las denominaciones contadas: el valor de la pieza, cuantas
     * y cuanto dinero.
     *
     * REGLA que fija este metodo: *la tabla se adapta al papel*. Con ancho
     * va una linea por pieza; sin ancho, la pieza y su importe se separan en
     * dos lineas. Y el CONTEO es el del Z sellado: aqui no se cuenta nada.
     *
     * @return list<array{text: string, style?: string}>
     */
    private static function tablaDeDenominaciones(CashCloseTemplate $z, int $ancho): array
    {
        $valorW = 0;
        $cuantasW = 0;
        $importeW = 0;

        foreach ($z->denominations as $denominacion) {
            $valorW = max($valorW, mb_strlen($z->monto($denominacion['value'])));
            $cuantasW = max($cuantasW, mb_strlen((string) $denominacion['count']));
            $importeW = max($importeW, mb_strlen($z->monto($denominacion['amount'])));
        }

        $anchoAlineado = $valorW + 3 + $cuantasW + 3 + $importeW;
        $anchoCompacto = $valorW + 2 + $cuantasW + 3 + $importeW;

        $lineas = [];

        foreach ($z->denominations as $denominacion) {
            $valor = $z->monto($denominacion['value']);
            $cuantas = (string) $denominacion['count'];
            $importe = $z->monto($denominacion['amount']);

            if ($anchoAlineado <= $ancho) {
                $lineas[] = ['text' => self::rellenaDerecha($valor, $valorW)
                    .' x '.self::rellenaDerecha($cuantas, $cuantasW)
                    .' = '.self::rellenaDerecha($importe, $importeW)];

                continue;
            }

            if ($anchoCompacto <= $ancho) {
                $lineas[] = ['text' => $valor.' x'.$cuantas.' = '.$importe];

                continue;
            }

            // El papel es mas estrecho que la tabla: la pieza con su cantidad
            // en una linea y su importe en la de abajo.
            $lineas[] = ['text' => $valor.' x'.$cuantas];
            $lineas[] = ['text' => self::rellenaDerecha($importe, $ancho)];
        }

        return $lineas;
    }

    /**
     * ENVUELVE un texto en varias lineas, sin perder nada.
     *
     * REGLA que fija este metodo: *no se recorta*. Una palabra que no cabe
     * sola se parte, y una linea que se pasa del ancho se baja.
     *
     * @return list<string>
     */
    private static function envuelve(string $texto, int $ancho): array
    {
        $palabras = preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $lineas = [];
        $actual = '';

        foreach ($palabras as $palabra) {
            // Una palabra MAS LARGA que el ancho: se parte sola.
            while (mb_strlen($palabra) > $ancho) {
                if ($actual !== '') {
                    $lineas[] = $actual;
                    $actual = '';
                }

                $lineas[] = mb_substr($palabra, 0, $ancho);
                $palabra = mb_substr($palabra, $ancho);
            }

            if ($actual === '') {
                $actual = $palabra;

                continue;
            }

            if (mb_strlen($actual) + 1 + mb_strlen($palabra) <= $ancho) {
                $actual .= ' '.$palabra;

                continue;
            }

            $lineas[] = $actual;
            $actual = $palabra;
        }

        if ($actual !== '') {
            $lineas[] = $actual;
        }

        return $lineas === [] ? [''] : $lineas;
    }

    /**
     * RELLENA a la DERECHA, contando CARACTERES y no bytes.
     */
    private static function rellenaDerecha(string $texto, int $ancho): string
    {
        $faltan = $ancho - mb_strlen($texto);

        return $faltan > 0 ? str_repeat(' ', $faltan).$texto : $texto;
    }
}
