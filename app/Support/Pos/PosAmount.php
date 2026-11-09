<?php

namespace App\Support\Pos;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

/**
 * Los IMPORTES de la caja: texto decimal, nunca `float`.
 *
 * REGLA que fija esta clase: *un importe es un TEXTO decimal*. Las columnas
 * son `numeric(25,4)` y un `float` no es un dinero: `0.1 + 0.2` no es `0.3`
 * en la aritmetica binaria, y un impuesto redondeado por un error de coma
 * flotante es dinero mal.
 *
 * La clase hace dos cosas y ninguna mas: DECLARAR un importe (validando que
 * lo sea) y FORMATEARLO a la escala de la columna (cuatro decimales). No
 * decide nada de negocio ni sabe de ventas: es la frontera entre el texto que
 * llega del navegador y el texto que se escribe en la base de datos.
 */
final class PosAmount
{
    /**
     * La escala de las columnas: `numeric(25,4)`.
     */
    public const SCALE = 4;

    /**
     * DECLARA un importe: lo lee, lo valida y lo devuelve como texto decimal
     * de cuatro decimales.
     *
     * REGLA que fija este metodo: *un importe que no es un numero decimal no
     * es un importe*. Se rechaza con un error claro en vez de coerced a cero
     * o a `null`: un cobro silenciosamente en cero es un cobro que no existe.
     *
     * @param  mixed  $value  El valor recibido: texto decimal, entero o
     *                        numero. Un `float` se acepta como lo que es
     *                        (una aproximacion) y se normaliza, porque el
     *                        navegador puede mandar numeros JSON.
     * @param  string  $label  Como se llama el importe en el error.
     *
     * @throws RuntimeException Cuando el valor no es un numero decimal.
     */
    public static function declare(mixed $value, string $label): string
    {
        if ($value === null || is_bool($value) || is_array($value)) {
            throw new RuntimeException("El importe '{$label}' no es un numero decimal.");
        }

        if (is_float($value)) {
            // Un `float` de PHP no es un dinero: se normaliza por su
            // representacion mas corta y se pasa a decimal exacto a partir de
            // ahi. El importe NUNCA sigue siendo un `float`.
            $value = var_export($value, true);
        }

        $amount = trim((string) $value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new RuntimeException("El importe '{$label}' no es un numero decimal.");
        }

        return (string) BigDecimal::of($amount)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * FORMATEA un importe que ya viene de la base de datos: lo deja como
     * texto decimal de cuatro decimales.
     *
     * @throws RuntimeException Cuando el valor no es un numero decimal.
     */
    public static function text(mixed $value): string
    {
        return self::declare($value, 'de la base de datos');
    }

    /**
     * El importe como `BigDecimal`, para comparar y sumar.
     *
     * @throws RuntimeException Cuando el valor no es un numero decimal.
     */
    public static function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of(self::declare($value, 'de la base de datos'));
    }
}
