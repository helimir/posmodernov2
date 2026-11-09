<?php

use Tests\Support\CountryLiteralScanner;

/**
 * 0.9.4-T — cambiar de país es insertar filas, no bifurcar código.
 *
 * Regla que fija: *cambiar de país es insertar filas, no bifurcar código*. El
 * motor fiscal, el de impuestos, el de numeración y el de formatos es DATO:
 * un literal de país en `app/Domain/` es un fork por país, y un fork por país
 * es un sistema que hay que tocar cada vez que se vende en otro sitio.
 *
 * El escaner mira SOLO `app/Domain/`, recursivamente. No mira `tests/`,
 * `config/`, `database/seeders/` ni `app/Providers/Fiscal/`: los adaptadores
 * de canal, los seeders y la configuración llevan literales legítimamente,
 * porque son datos que se siembran y no código que bifurca.
 *
 * Y mira SOLO literales de cadena y comparaciones: un nombre de clase, de
 * método o de constante no es un literal (`VenezuelaFiscalProfile` es un
 * nombre, no un país).
 *
 * El escaner NO es vacío: el fixture `tests/Fixtures/CountryLiteralFixture.php`
 * lleva un literal prohibido a propósito, y este test comprueba que el
 * escaner lo encuentra. Sin esa prueba, un escaner que no escanea nada
 * pasaría en verde y no probaría nada.
 */
test('test_ningun_literal_de_pais_sobrevive_en_app_domain', function () {
    $hallazgos = CountryLiteralScanner::hallazgos();

    expect($hallazgos)->toBeEmpty(CountryLiteralScanner::mensajeDeFallo($hallazgos));

    // El escaner escanea el DOMINIO y solo el dominio.
    expect(str_replace('\\', '/', CountryLiteralScanner::directorioPorDefecto()))
        ->toEndWith('app/Domain');

    // Y NO es vacio: el fixture con un literal prohibido SE DETECTA, con su
    // categoria y con su literal. Sin esto, un escaner roto pasaria en verde.
    $detectados = CountryLiteralScanner::hallazgos(base_path('tests/Fixtures'));

    $delFixture = array_values(array_filter(
        $detectados,
        static fn (array $hallazgo): bool => str_ends_with($hallazgo['ruta'], 'tests/Fixtures/CountryLiteralFixture.php')
    ));

    expect($detectados)->not->toBeEmpty('El escaner no encuentra nada: no escanea.')
        ->and($delFixture)->not->toBeEmpty('El escaner no detecta el literal prohibido del fixture.')
        ->and($delFixture[0]['categoria'])->toBe('country-code')
        ->and($delFixture[0]['literal'])->toBe('VE')
        ->and($delFixture[0]['ruta'])->toBe('tests/Fixtures/CountryLiteralFixture.php');
});
