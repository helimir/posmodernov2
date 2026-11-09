<?php

use Tests\Support\CanonicalTestNames;
use Tests\Support\SuiteInventory;

/**
 * 0.9.6-T — el test es la documentacion de la regla.
 *
 * Regla que fija: *el test es la documentacion de la regla*. Cada nombre
 * canonico del design enuncia la regla que fija y es legible sin abrir el
 * cuerpo, y ningun test queda SIN nombre canonico.
 *
 * El BARRIDO recorre los ficheros de `tests/` y cuenta las apariciones de
 * cada nombre:
 *
 *  - los **44** canonicos de §E.5 existen **exactamente una vez**: cero veces
 *    es un requisito sin nombre, mas de una es el mismo nombre portant dos
 *    reglas (y una de las dos se queda sin documentacion);
 *  - los **6** transversales de §E.2 existen.
 *
 * El recuento mira las DECLARACIONES (`test('...')`), no cualquier aparicion
 * de la cadena: la lista de nombres canonicos vive en
 * `tests/Support/CanonicalTestNames.php`, fuera del arbol que se cuenta, y
 * un nombre citado en un mensaje de fallo tampoco es una declaracion.
 */
test('test_cada_test_de_la_suite_declara_la_regla_de_negocio_que_fija', function () {
    $apariciones = SuiteInventory::apariciones();

    $faltan = [];
    $repetidos = [];

    foreach (CanonicalTestNames::canonicos() as $nombre) {
        $cuantas = count($apariciones[$nombre] ?? []);

        if ($cuantas === 0) {
            $faltan[] = $nombre;
        } elseif ($cuantas > 1) {
            $repetidos[$nombre] = $apariciones[$nombre];
        }
    }

    $transversalesFaltan = [];

    foreach (CanonicalTestNames::transversales() as $nombre) {
        if (count($apariciones[$nombre] ?? []) === 0) {
            $transversalesFaltan[] = $nombre;
        }
    }

    // El mensaje dice EXACTAMENTE que falta y que sobra, y donde.
    $mensaje = 'La suite no declara los nombres canonicos del design (§E.5 y §E.2).';

    foreach ($faltan as $nombre) {
        $mensaje .= "\n  falta (0 veces): {$nombre}";
    }

    foreach ($transversalesFaltan as $nombre) {
        $mensaje .= "\n  falta el transversal (0 veces): {$nombre}";
    }

    foreach ($repetidos as $nombre => $lugares) {
        $mensaje .= "\n  repetido (".count($lugares)." veces): {$nombre} -> ".implode(', ', $lugares);
    }

    expect($faltan)->toBe([], $mensaje)
        ->and($transversalesFaltan)->toBe([], $mensaje)
        ->and($repetidos)->toBe([], $mensaje);

    // Y el barrido NO es vacio: la suite tiene los 50 nombres y todos los
    // tests que finds tienen nombre canonico o transversal.
    expect(count($apariciones))->toBeGreaterThanOrEqual(50)
        ->and(array_sum(array_map('count', $apariciones)))->toBe(count($apariciones));
});
