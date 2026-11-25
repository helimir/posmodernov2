<?php

namespace App\Support\Reports;

use RuntimeException;

/**
 * El ESCRITOR de CSV: el fichero que sale del sistema.
 *
 * REGLA que fija esta clase: *el CSV se escribe A MANO*. Un CSV es texto: una
 * coma, unas comillas y un salto de linea. El repositorio no gana una
 * dependencia de composer por escribir texto, y el paquete que lo haria
 * (league/csv) seria una dependencia mas que auditar para algo que son treinta
 * lineas.
 *
 * Y LA REGLA DE LA INYECCION DE FORMULA: *un valor que empieza por `=`, `+`,
 * `-` o `@` SE EJECUTA cuando Excel lo abre*. Un producto que se llame
 * `=cmd|'/c calc'!A1` no es un nombre de producto: es una orden que se
 * ejecuta en el equipo de quien abre el reporte. Por eso el escritor
 * NEUTRALIZA esos valores con un apostrofo delante, que es la marca con la
 * que Excel sabe que el contenido es TEXTO y no una orden.
 *
 * Y EL APOSTROFO NO SE PONE A TODO: un IMPORTE es un numero decimal que el
 * sistema produce y valida, y un numero no es una formula. Neutralizar un
 * importe negativo lo volveria texto, y un reporte con los importes en texto
 * no se puede sumar en la hoja de calculo. Por eso hay DOS caminos: `cell()`
 * para el TEXTO (que neutraliza) y `amount()` para el IMPORTE (que valida y
 * sale tal cual).
 *
 * EL FORMATO es el de RFC 4180: coma como separador, CRLF como fin de linea,
 * y las celdas que lo necesitan entrecomilladas con las comillas duplicadas
 * dentro. Excel y LibreOffice lo leen sin que nadie tenga que abrir el
 * fichero a mano para ver que hay dentro.
 */
final class CsvWriter
{
    /**
     * El SEPARADOR de campos: la coma.
     */
    public const SEPARADOR = ',';

    /**
     * El FIN DE LINEA: el CRLF de RFC 4180.
     */
    public const FIN_DE_LINEA = "\r\n";

    /**
     * Los CARACTERES con los que Excel empieza a LEER una formula.
     *
     * Los cuatro primeros son los que dice el ataque clasico; el tabulador y
     * el retorno de carro tambien los ejecuta Excel, asi que tambien se
     * neutralizan.
     *
     * @var list<string>
     */
    private const INICIOS_DE_FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Una CELDA de TEXTO, neutralizada si puede ser una formula.
     *
     * REGLA que fija este metodo: *el texto que viene del negocio se
     * neutraliza SIEMPRE que pueda ser una orden*. Se mira el PRIMER
     * caracter no blanco: Excel ignora los espacios que delante del signo, y
     * un espacio no vuelve inocuo un `=`.
     *
     * @param  mixed  $value  El valor de la celda.
     */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $texto = (string) $value;

        if ($texto === '') {
            return '';
        }

        // El TABULADOR y el RETORNO DE CARRO se miran ANTES de limpiar: si se
        // limpian primero, el tabulador desaparece y la celda parece inocua.
        if ($texto[0] === "\t" || $texto[0] === "\r") {
            return "'".$texto;
        }

        $limpio = ltrim($texto, " \n");

        if ($limpio === '') {
            return $texto;
        }

        if (in_array($limpio[0], self::INICIOS_DE_FORMULA, true)) {
            return "'".$texto;
        }

        return $texto;
    }

    /**
     * Una CELDA de IMPORTE: un numero decimal, tal cual.
     *
     * REGLA que fija este metodo: *el importe es un numero y sale como
     * numero*. Se VALIDA con la misma regla que el resto del sistema (un
     * decimal con signo y hasta cuatro decimales), y un valor que no la
     * cumple no se escribe: un importe que no es un numero no es un importe.
     *
     * @throws RuntimeException Cuando el valor no es un numero decimal.
     */
    public static function amount(string $value): string
    {
        $texto = trim($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $texto) !== 1) {
            throw new RuntimeException(
                "El importe '{$value}' de la exportacion no es un numero decimal."
            );
        }

        return $texto;
    }

    /**
     * Una LINEA del CSV: las celdas separadas por comas.
     *
     * @param  list<mixed>  $cells  Las celdas de la linea.
     */
    public static function line(array $cells): string
    {
        $partes = [];

        foreach ($cells as $celda) {
            $texto = (string) $celda;

            if (preg_match('/[",\r\n]/', $texto) === 1) {
                $texto = '"'.str_replace('"', '""', $texto).'"';
            }

            $partes[] = $texto;
        }

        return implode(self::SEPARADOR, $partes);
    }

    /**
     * El DOCUMENTO entero: las lineas con CRLF entre medias.
     *
     * @param  iterable<list<mixed>>  $lines  Las lineas del documento.
     */
    public static function document(iterable $lines): string
    {
        $partes = [];

        foreach ($lines as $linea) {
            $partes[] = self::line($linea);
        }

        if ($partes === []) {
            return '';
        }

        return implode(self::FIN_DE_LINEA, $partes).self::FIN_DE_LINEA;
    }
}
