<?php

use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\TaxMethod;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxContext;
use App\Domain\Tax\TaxDocumentInput;
use App\Domain\Tax\TaxLineInput;
use Tests\Support\CountryLiteralScanner;

/**
 * 0.9.6 — el motor de impuestos es PURO y AGNÓSTICO.
 *
 * Regla que fija: *el motor de impuestos es puro y agnóstico*. El MISMO motor
 * calcula el resultado de DOS perfiles distintos: lo que cambia entre ellos
 * (el redondeo, el método del impuesto, los decimales de la moneda) son
 * DATOS del perfil, no ramas del código.
 *
 * Por eso el motor no puede contener un literal de país: si lo contuviera,
 * el resultado de un perfil dependería del país y no de sus datos.
 */
test('test_el_mismo_motor_calcula_el_resultado_de_dos_perfiles_sin_ramificar_por_pais', function () {
    // PERFIL A: impuesto EXCLUSIVO, redondeo por línea, moneda de 2 decimales.
    $perfilA = new TaxContext(
        roundingRule: RoundingRule::Line,
        taxInclusiveDefault: false,
        currencyDecimals: 2,
        exemptionMarker: null,
        formalContributorPhrase: null,
        seriesPrefixRequiredToken: null,
    );

    // PERFIL B: impuesto INCLUSIVO, redondeo por total, moneda de 2 decimales.
    $perfilB = new TaxContext(
        roundingRule: RoundingRule::Total,
        taxInclusiveDefault: true,
        currencyDecimals: 2,
        exemptionMarker: null,
        formalContributorPhrase: null,
        seriesPrefixRequiredToken: null,
    );

    $lineaA = new TaxLineInput(
        taxCategoryId: 1,
        taxRateId: 7,
        taxRateSnapshot: '16.0000',
        evidenceStatus: 'verified',
        method: TaxMethod::Exclusive,
        unitPrice: '10.0050',
        quantity: '1.0000',
    );

    // La MISMA línea, pero con el precio INCLUDING el impuesto: eso es lo que
    // el perfil B declara.
    $lineaB = new TaxLineInput(
        taxCategoryId: 1,
        taxRateId: 7,
        taxRateSnapshot: '16.0000',
        evidenceStatus: 'verified',
        method: TaxMethod::Inclusive,
        unitPrice: '10.0050',
        quantity: '1.0000',
    );

    $documento = fn (TaxLineInput $linea): TaxDocumentInput => new TaxDocumentInput(
        lines: [$linea],
        documentDiscount: '0.0000',
        issuedAt: new DateTimeImmutable('2026-11-03 12:00:00'),
    );

    // 1. El MISMO motor, con el perfil A.
    $resultadoA = (new TaxCalculator($perfilA))->calculate($documento($lineaA));

    expect($resultadoA->lines[0]['base_amount'])->toBe('10.0050')
        ->and($resultadoA->lines[0]['tax_amount'])->toBe('1.6008')
        ->and($resultadoA->lines[0]['line_total'])->toBe('11.6100')
        ->and($resultadoA->grandTotal)->toBe('11.6100')
        ->and($resultadoA->roundingAdjustment)->toBe('0.0000');

    // 2. Y con el perfil B: el resultado es OTRO, y sale de los DATOS.
    $resultadoB = (new TaxCalculator($perfilB))->calculate($documento($lineaB));

    expect($resultadoB->lines[0]['base_amount'])->toBe('8.6250')
        ->and($resultadoB->lines[0]['tax_amount'])->toBe('1.3800')
        // El redondeo por TOTAL deja la linea sin redondear y el ajuste
        // visible: 10.01 - 10.0050 = 0.0050.
        ->and($resultadoB->lines[0]['line_total'])->toBe('10.0050')
        ->and($resultadoB->grandTotal)->toBe('10.0100')
        ->and($resultadoB->roundingAdjustment)->toBe('0.0050');

    // 3. Los dos resultados son DISTINTOS: el motor no devuelve lo mismo
    //    siempre, y el perfil es lo que decide.
    expect($resultadoA->grandTotal)->not->toBe($resultadoB->grandTotal);

    // 4. Y el motor NO puede ramificar por pais: no hay ningun literal de
    //    pais en su codigo. Sin esta comprobacion, "agnostico" seria una
    //    promesa y no un hecho.
    $hallazgos = CountryLiteralScanner::hallazgos(CountryLiteralScanner::raiz().'/app/Domain/Tax');

    expect($hallazgos)->toBeEmpty(CountryLiteralScanner::mensajeDeFallo($hallazgos));
});
