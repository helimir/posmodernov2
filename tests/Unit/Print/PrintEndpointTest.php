<?php

use App\Print\PrintEndpoint;

/**
 * F2-06 — el ENDPOINT de un destino: la regla que decide COMO se escribe.
 *
 * REGLA que fija: *el endpoint dice donde se escriben los bytes, y solo se
 * admiten las dos formas que el esquema admite*.
 *
 *  - la IMPRESORA DE RED: `tcp://servidor:9100`, el puerto RAW del estandar;
 *  - la IMPRESORA COMPARTIDA de Windows: `smb://servidor/impresora` o el
 *    recurso UNC `\\servidor\impresora`, que son la MISMA cola escrita de dos
 *    maneras.
 *
 * Y LA CONVERSION ES EXPLICITA: el endpoint trae el esquema, y la regla dice
 * como se quita. Un endpoint que no esta en la lista admitida NO se escribe a
 * ciegas: falla con un mensaje que dice que formas se admiten.
 *
 * Este test es de `tests/Unit`: la regla es pura, sin base de datos y sin
 * red.
 */
test('test_un_endpoint_tcp_de_la_impresora_de_red_es_la_direccion_del_socket', function () {
    $endpoint = PrintEndpoint::deTexto('tcp://192.168.1.50:9100');

    expect($endpoint->esSocket())->toBeTrue()
        ->and($endpoint->esColaDeWindows())->toBeFalse()
        ->and($endpoint->direccionDeSocket())->toBe('tcp://192.168.1.50:9100')
        ->and($endpoint->host)->toBe('192.168.1.50');
});

test('test_un_endpoint_smb_se_convierte_en_la_ruta_unc_de_windows', function () {
    // 1. El caso simple: el recurso es el nombre de la impresora.
    expect(PrintEndpoint::deTexto('smb://SERVIDOR/IMPRESORA')->rutaDeCola())
        ->toBe('\\\\SERVIDOR\\IMPRESORA');

    // 2. Y el recurso puede estar en una carpeta: la cola es la ruta entera.
    expect(PrintEndpoint::deTexto('smb://SERVIDOR/CARPETA/IMPRESORA')->rutaDeCola())
        ->toBe('\\\\SERVIDOR\\CARPETA\\IMPRESORA');

    // 3. La barra final no es parte del recurso.
    expect(PrintEndpoint::deTexto('smb://SERVIDOR/IMPRESORA/')->rutaDeCola())
        ->toBe('\\\\SERVIDOR\\IMPRESORA');
});

test('test_un_recurso_unc_ya_es_la_ruta_de_windows', function () {
    $endpoint = PrintEndpoint::deTexto('\\\\SERVIDOR\\IMPRESORA');

    expect($endpoint->esColaDeWindows())->toBeTrue()
        ->and($endpoint->esSocket())->toBeFalse()
        ->and($endpoint->rutaDeCola())->toBe('\\\\SERVIDOR\\IMPRESORA')
        ->and($endpoint->host)->toBe('SERVIDOR');
});

test('test_un_endpoint_que_no_esta_en_la_lista_admitida_falla_con_un_mensaje_util', function () {
    // 1. El USB directo: el esquema lo rechaza, y el escritor tambien.
    expect(fn () => PrintEndpoint::deTexto('usb://localhost/printers/RAW'))
        ->toThrow(RuntimeException::class, 'no es un destino admitido');

    // 2. Un endpoint vacio: no hay donde escribir.
    expect(fn () => PrintEndpoint::deTexto(null))
        ->toThrow(RuntimeException::class, 'no declara endpoint');

    // 3. Una impresora de red en un puerto que no es el RAW.
    expect(fn () => PrintEndpoint::deTexto('tcp://192.168.1.50:9101'))
        ->toThrow(RuntimeException::class, 'no es un destino admitido');

    // 4. Una cola de Windows sin recurso: el esquema la admite (todo lo que
    //    empieza por `smb://` entra), pero no dice QUE impresora es.
    expect(fn () => PrintEndpoint::deTexto('smb://SERVIDOR'))
        ->toThrow(RuntimeException::class, 'no nombra una impresora compartida');
});
