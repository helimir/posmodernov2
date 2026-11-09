<?php

use Tests\Support\SuiteInventory;

/**
 * 0.9.7 — la suite debe estar en verde sin fallos preexistentes.
 *
 * Regla que fija: *la suite debe estar en verde sin fallos preexistentes*. Un
 * verde con pruebas saltadas no es un verde: es un verde falso. Y una suite a
 * la que le han borrado pruebas tampoco.
 *
 * Un test NO puede lanzar la suite completa desde dentro de la suite (seria
 * una recursion infinita), asi que esta comprobacion mira el ARBOL de los
 * tests: que no haya ninguna prueba marcada como `skip` o `todo`, que todas
 * aserten algo, y que el numero de tests declarados sea el esperado.
 */
test('test_la_suite_no_deja_fallos_preexistentes_sin_aislar', function () {
    $tests = SuiteInventory::tests();

    // 1. El numero de tests DECLARADOS es el esperado: el baseline de la
    //    fase (176) mas los que la fase anade. Un numero menor significa que
    //    alguien borro pruebas para que el verde saliera.
    expect(count($tests))->toBeGreaterThanOrEqual(176);

    // 2. Y NINGUN test se salta: ni `skip`, ni `todo`, ni "incompleto". Un
    //    test saltado no es una garantia, y el CI no puede avisar de algo que
    //    no se ejecuta.
    //
    //    Este fichero queda fuera del barrido: el NOMBRE de los marcadores
    //    esta escrito en el, y se encontraria a si mismo.
    $marcadores = ['->skip(', '->todo(', '->skipWith(', 'markTestSkipped', 'markTestIncomplete'];

    $saltados = [];

    foreach ($tests as $test) {
        if ($test['fichero'] === 'tests/Architecture/SuiteBaselineTest.php') {
            continue;
        }

        foreach ($marcadores as $marcador) {
            if (str_contains($test['cuerpo'], $marcador)) {
                $saltados[] = $test['nombre'].' ('.$marcador.')';
            }
        }
    }

    expect($saltados)->toBe([]);

    // 3. Y todos ASERTAN: un test sin asercion no prueba nada, y ocupa el
    //    sitio de uno que si la prueba.
    $sinAserciones = array_values(array_filter(
        $tests,
        static fn (array $test): bool => ! str_contains($test['cuerpo'], 'expect(')
            && ! str_contains($test['cuerpo'], 'assert')
    ));

    expect($sinAserciones)->toBe([]);

    // 4. Y el numero de tests EJECUTADOS es el DECLARADO: como ninguno se
    //    salta, lo que se declara es lo que corre.
    $apariciones = SuiteInventory::apariciones();

    expect(array_sum(array_map('count', $apariciones)))->toBe(count($tests));
});
