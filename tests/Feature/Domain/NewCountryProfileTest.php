<?php

use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxDocumentInput;
use App\Domain\Tax\TaxLineInput;
use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\TaxCategory;
use App\Models\TaxRate;

/**
 * 0.9.11 — el motor de impuestos es DATO, y un país nuevo se añade con filas.
 *
 * Regla que fija: *el motor de impuestos es puro y agnóstico*. Un país que no
 * existe todavía se añade INSERTANDO FILAS: su moneda, su perfil fiscal, sus
 * categorías y sus tasas. El motor de impuestos no se toca, no se bifurca y
 * no se configura: sigue siendo el mismo motor, y lo que cambia es el dato.
 *
 * Aquí se comprueba con DOS países inventados, sembrados en la prueba, con
 * tipos de impuesto distintos: el MISMO motor calcula los dos.
 */
test('test_un_pais_nuevo_se_anade_sembrando_filas_sin_tocar_el_motor_de_impuestos', function () {
    /**
     * Un país nuevo: moneda, perfil, categoría y tasa. SOLO filas.
     */
    $pais = function (string $codigo, string $moneda, string $porcentaje): array {
        $divisa = Currency::create([
            'code' => $moneda,
            'name' => 'Moneda de '.$codigo,
            'decimals' => 2,
        ]);

        $pais = Country::create([
            'iso_code' => $codigo,
            'name' => 'Pais '.$codigo,
            'default_currency_id' => $divisa->id,
        ]);

        $perfil = FiscalProfile::create([
            'country_id' => $pais->id,
            'code' => $codigo.'_MAIN',
            'name' => 'Perfil de '.$codigo,
            'signer_model' => 'printer',
            'rounding_rule' => 'line',
            'tax_inclusive_default' => false,
            'default_currency_id' => $divisa->id,
        ]);

        $categoria = TaxCategory::create([
            'fiscal_profile_id' => $perfil->id,
            'code' => 'standard',
            'name' => 'Gravado',
            'is_withholding' => false,
            'applies_to' => 'both',
            'evidence_status' => 'verified',
        ]);

        $tasa = TaxRate::create([
            'tax_category_id' => $categoria->id,
            'percentage' => $porcentaje,
            'rounding_rule' => 'line',
            'valid_from' => '2026-01-01',
            'priority' => 1,
            'evidence_status' => 'verified',
            'source_ref' => 'Fila de prueba',
        ]);

        return [$perfil->fresh(), $divisa->fresh(), $categoria, $tasa];
    };

    // 1. DOS países nuevos, sembrados solo con filas.
    [$perfilA, $divisaA, $categoriaA, $tasaA] = $pais('ZX', 'ZXA', '21');
    [$perfilB, $divisaB, $categoriaB, $tasaB] = $pais('ZY', 'ZYB', '5');

    expect($perfilA->country->iso_code)->toBe('ZX')
        ->and($perfilB->country->iso_code)->toBe('ZY')
        ->and($perfilA->country_id)->not->toBe($perfilB->country_id);

    // 2. El MISMO motor, con los DATOS de cada pais.
    $calcular = function (FiscalProfile $perfil, Currency $divisa, TaxCategory $categoria, TaxRate $tasa) {
        $contexto = $perfil->taxContext($divisa);

        $linea = new TaxLineInput(
            taxCategoryId: (int) $categoria->id,
            taxRateId: (int) $tasa->id,
            // El porcentaje CONGELADO sale de la fila de la tasa: no de un
            // literal del test ni del codigo.
            taxRateSnapshot: (string) $tasa->percentage,
            evidenceStatus: (string) $tasa->evidence_status,
            method: $contexto->taxMethod(),
            unitPrice: '100.0000',
            quantity: '1.0000',
        );

        return (new TaxCalculator($contexto))->calculate(new TaxDocumentInput(
            lines: [$linea],
            documentDiscount: '0.0000',
            issuedAt: new DateTimeImmutable('2026-11-03 12:00:00'),
        ));
    };

    $resultadoA = $calcular($perfilA, $divisaA, $categoriaA, $tasaA);
    $resultadoB = $calcular($perfilB, $divisaB, $categoriaB, $tasaB);

    // 3. Cada pais sale con SU tipo: el 21 % y el 5 %.
    expect($resultadoA->lines[0]['tax_rate_snapshot'])->toBe('21.000000')
        ->and($resultadoA->lines[0]['tax_amount'])->toBe('21.0000')
        ->and($resultadoA->grandTotal)->toBe('121.0000');

    expect($resultadoB->lines[0]['tax_rate_snapshot'])->toBe('5.000000')
        ->and($resultadoB->lines[0]['tax_amount'])->toBe('5.0000')
        ->and($resultadoB->grandTotal)->toBe('105.0000');

    // 4. Y el motor es el MISMO: el resultado sale del DATO, no de una rama.
    //    Los dos paises no se parecen en nada y el motor no los distingue.
    expect(get_class(new TaxCalculator($perfilA->taxContext($divisaA))))
        ->toBe(TaxCalculator::class)
        ->and($resultadoA->grandTotal)->not->toBe($resultadoB->grandTotal);
});
