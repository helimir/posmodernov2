<?php

/**
 * 0.9.8 — el typecheck está en el gate, no fuera.
 *
 * Regla que fija: *el typecheck está en el gate, no fuera*. La puerta de
 * calidad ejecuta el typecheck, y lo ejecuta COMO ETAPA del workflow: un
 * typecheck en el pipeline local y no en el gate no es un gate.
 *
 * El test mira el workflow, no el `package.json`: lo que importa es lo que CI
 * ejecuta, no lo que se puede ejecutar a mano.
 */
test('test_la_puerta_de_calidad_declara_typecheck_y_build_como_etapas_del_gate', function () {
    $workflow = base_path('.github/workflows/tests.yml');

    expect(file_exists($workflow))->toBeTrue('El repositorio no tiene el workflow de la puerta de calidad.');

    $contenido = (string) file_get_contents($workflow);

    // 1. Los COMANDOS que el workflow ejecuta: un paso con su `run:`.
    //    Un comando comentado no es una etapa.
    $comandos = [];

    foreach (explode("\n", $contenido) as $linea) {
        if (preg_match('/^\s*(?:-\s*)?run:\s*(.+)$/', $linea, $coincidencia) === 1) {
            $comandos[] = trim($coincidencia[1], " '\"");
        }
    }

    expect($comandos)->toContain('npm run build')
        ->and($comandos)->toContain('npm run typecheck')
        ->and($comandos)->toContain('npm run lint:check')
        ->and($comandos)->toContain('./vendor/bin/pest');

    // 2. Y el BUILD PRECEDE a la suite: las pruebas de página necesitan el
    //    manifiesto de Vite.
    $posicionDelBuild = array_search('npm run build', $comandos, true);
    $posicionDeLaSuite = array_search('./vendor/bin/pest', $comandos, true);

    expect($posicionDelBuild)->not->toBeFalse()
        ->and($posicionDeLaSuite)->not->toBeFalse()
        ->and($posicionDelBuild)->toBeLessThan($posicionDeLaSuite);

    // 3. Y el typecheck es una ETAPA DEL GATE, no un job aparte ni un
    //    comentario: esta en el mismo workflow, junto a la suite.
    expect($comandos)->toContain('npm run typecheck')
        ->and($contenido)->toContain('name: Type Check');
});
