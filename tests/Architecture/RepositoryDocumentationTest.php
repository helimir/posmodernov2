<?php

/**
 * 0.9.9 — cada decisión estructural queda documentada.
 *
 * Regla que fija: *cada decisión estructural queda documentada*. El repositorio
 * expone los SEIS ADR de la fase (uno por decisión) y el README de arranque.
 *
 * El test comprueba la EXISTENCIA y la FORMA de cada ADR (estado, decisión,
 * contexto y consecuencias), no su redacción: un ADR que no dice por qué se
 * decidió lo que se decidió no documenta nada.
 */
test('test_el_repositorio_contiene_los_seis_adr_y_el_readme_de_arranque', function () {
    // 1. Los SEIS ADR, uno por cada decisión estructural de la fase.
    $adrs = glob(base_path('docs/adr/*.md')) ?: [];

    expect($adrs)->toHaveCount(6);

    foreach ($adrs as $adr) {
        $contenido = (string) file_get_contents($adr);

        // El estado: la decisión está ACEPTADA.
        expect($contenido)->toContain('**Estado**: Aceptado');

        // Y las tres partes: el motivo (contexto), la decisión y sus
        // consecuencias.
        expect($contenido)->toContain('## Contexto')
            ->and($contenido)->toContain('## Decisión')
            ->and($contenido)->toContain('## Consecuencias');
    }

    // 2. Y el README de arranque, con las precondiciones y las credenciales.
    $readme = base_path('README.md');

    expect(file_exists($readme))->toBeTrue('El repositorio no tiene README de arranque.');

    $contenido = (string) file_get_contents($readme);

    expect($contenido)->toContain('npm ci')
        ->and($contenido)->toContain('npm run build')
        ->and($contenido)->toContain('php artisan migrate:fresh --seed')
        ->and($contenido)->toContain('test@example.com')
        ->and($contenido)->toContain('PostgreSQL')
        ->and($contenido)->toContain('Redis');
});
