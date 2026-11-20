<?php

namespace App\Print;

/**
 * El ANCHO de la linea: cuantas CARACTERES caben en el papel de la
 * impresora.
 *
 * REGLA que fija esta clase: *una linea que no cabe en el ancho NO se recorta
 * en silencio*. Un comprobante fiscal no puede ir recortado: la linea diria
 * una cosa y el papel otra. Y no puede faltar una linea: un comprobante al
 * que le falta una linea es un comprobante falso.
 *
 * Y EL ANCHO ES EL QUE DECLARA EL DESTINO: `print_targets.char_per_line` es
 * un DATO de la tienda, no una constante del codigo. El ancho en caracteres
 * depende de la codepagina y del perfil, asi que la libreria no puede
 * inventarlo: lo declara quien conoce la impresora.
 *
 * Y EL ESTILO DOBLA EL ANCHO: en ESC/POS, `setTextSize(2, 2)` hace que cada
 * caracter ocupe DOS columnas. Una linea en doble ancho no cabe en el papel
 * si no caben la mitad de sus caracteres, y el renderer lo tiene que saber
 * antes de escribir una sola letra.
 */
final readonly class PrintWidth
{
    /**
     * El ancho MINIMO de un destino: un caracter por linea es un destino que
     * no imprime un ticket, pero es un dato declarado, y el sistema no lo
     * adivina.
     */
    public const ANCHO_MINIMO = 1;

    /**
     * El estilo que DOBLA el ancho de la linea.
     */
    public const ESTILO_DOBLE = 'double';

    /**
     * La CAPACIDAD de la linea, en caracteres, con su estilo.
     *
     * @param  int  $charPerLine  El ancho declarado por el destino.
     * @param  string|null  $estilo  El estilo de la linea, si lo tiene.
     */
    public static function capacidad(int $charPerLine, ?string $estilo = null): int
    {
        if ($estilo === self::ESTILO_DOBLE) {
            return intdiv(max(0, $charPerLine), 2);
        }

        return max(0, $charPerLine);
    }

    /**
     * ¿La linea EXCEDE el ancho del destino?
     *
     * @param  string|null  $estilo  El estilo de la linea, si lo tiene.
     */
    public static function excede(string $texto, int $charPerLine, ?string $estilo = null): bool
    {
        return mb_strlen($texto) > self::capacidad($charPerLine, $estilo);
    }

    /**
     * El MENSAJE de la linea que no cabe.
     *
     * Dice QUE linea es, CUANTO mide y CUANTO admite la impresora: un
     * mensaje que no dice eso deja al que lo lee con la sensacion de que el
     * sistema no sabe lo que hace.
     *
     * @param  string|null  $estilo  El estilo de la linea, si lo tiene.
     */
    public static function mensaje(string $texto, int $charPerLine, ?string $estilo, string $destino): string
    {
        $largo = mb_strlen($texto);
        $admite = self::capacidad($charPerLine, $estilo);

        $extra = $estilo === self::ESTILO_DOBLE
            ? " ({$charPerLine} en letra normal, porque la linea va en doble ancho)"
            : '';

        return "La linea «{$texto}» mide {$largo} caracteres y no cabe en el destino «{$destino}»: "
            ."la impresora admite {$admite} caracteres por linea{$extra}.";
    }
}
