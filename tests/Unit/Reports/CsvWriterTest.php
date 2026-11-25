<?php

use App\Support\Reports\CsvWriter;

/**
 * La EXPORTACION a CSV: el fichero que sale del sistema.
 *
 * Reglas que fija:
 *
 *  - el CSV se escribe A MANO, sin dependencias nuevas: un CSV es texto, y
 *    el repositorio no gana un paquete de composer por escribir texto;
 *  - un valor que empieza por `=`, `+`, `-` o `@` SE EJECUTA cuando Excel lo
 *    abre (la inyeccion de formula), asi que el escritor lo NEUTRALIZA con un
 *    apostrofo delante;
 *  - un IMPORTE no se neutraliza: es un numero decimal que el sistema
 *    produce, y un numero no es una formula. Neutralizar un importe negativo
 *    lo volveria texto, y un reporte con los importes en texto no se puede
 *    sumar en la hoja de calculo;
 *  - el separador es la COMA y el separador de lineas es el CRLF, que es lo
 *    que Excel espera de un `.csv` en un equipo que es Windows.
 */

/**
 * Un valor que empieza por `=` queda NEUTRALIZADO: Excel no lo ejecuta.
 */
test('test_la_exportacion_neutraliza_un_valor_que_puede_ejecutar_una_formula', function () {
    // La carga clasica del ataque: un producto que se llama asi.
    $inyeccion = "=cmd|'/c calc'!A1";

    expect(CsvWriter::cell('=1+1'))->toBe("'=1+1")
        ->and(CsvWriter::cell('+1+1'))->toBe("'+1+1")
        ->and(CsvWriter::cell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(CsvWriter::cell($inyeccion))->toBe("'".$inyeccion);
});

/**
 * Un valor que NO empieza por un caracter peligroso sale tal cual: el
 * escritor no toca lo que no es una amenaza.
 */
test('test_la_exportacion_deja_intacto_un_valor_que_no_es_una_formula', function () {
    expect(CsvWriter::cell('Producto de la tienda'))->toBe('Producto de la tienda')
        ->and(CsvWriter::cell('HUMO-001'))->toBe('HUMO-001')
        ->and(CsvWriter::cell(''))->toBe('')
        ->and(CsvWriter::cell(null))->toBe('');
});

/**
 * Un importe es un NUMERO, no una formula: sale tal cual y sigue siendo un
 * numero para la hoja de calculo, aunque sea negativo.
 */
test('test_la_exportacion_deja_los_importes_como_numeros', function () {
    expect(CsvWriter::amount('25.0000'))->toBe('25.0000')
        ->and(CsvWriter::amount('-3.5000'))->toBe('-3.5000');
});

/**
 * Un importe que NO es un numero decimal no se escribe: el exportador no
 * escribe texto donde la hoja espera una cifra.
 */
test('test_la_exportacion_rechaza_un_importe_que_no_es_un_numero_decimal', function () {
    expect(fn () => CsvWriter::amount('=1+1'))
        ->toThrow(RuntimeException::class);
});

/**
 * Una celda con COMA, con comilla o con salto de linea va entrecomillada, con
 * las comillas duplicadas: es lo que RFC 4180 dice y lo que Excel entiende.
 */
test('test_la_exportacion_entrecomilla_las_celdas_que_lo_necesitan', function () {
    expect(CsvWriter::line(['a', 'b,c', 'd"e', "f\ng"]))
        ->toBe('a,"b,c","d""e","f'."\n".'g"');
});

/**
 * El DOCUMENTO son las lineas con CRLF entre medias, y el fichero termina en
 * CRLF: es lo que espera Excel.
 */
test('test_la_exportacion_arma_el_documento_con_crlf_y_lo_cierra', function () {
    $documento = CsvWriter::document([
        ['Code', 'Name'],
        ['HUMO-001', 'Producto'],
    ]);

    expect($documento)->toBe("Code,Name\r\nHUMO-001,Producto\r\n");
});
