<?php

use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\TaxMethod;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxContext;
use App\Domain\Tax\TaxDocumentInput;
use App\Domain\Tax\TaxLineInput;

/**
 * 0.6.1 / 0.6.2 / 0.6.3 — el motor de impuestos.
 *
 * Reglas que fija:
 * - el motor es PURO: sin base de datos, sin red, sin locale y sin reloj. La
 *   misma entrada da el mismo resultado;
 * - la base es el precio COBRADO, no el de catálogo;
 * - el ajuste de redondeo es VISIBLE: `suma de las lineas + roundingAdjustment
 *   = total redondeado`;
 * - los snapshots de la tasa viajan CONGELADOS al documento.
 */
test('test_el_motor_de_impuestos_es_puro_y_deja_visible_el_ajuste_de_redondeo', function () {
    $context = new TaxContext(
        roundingRule: RoundingRule::Line,
        taxInclusiveDefault: false,
        currencyDecimals: 2,
        exemptionMarker: '(E)',
        formalContributorPhrase: 'Contribuyentes Formales del IVA',
        seriesPrefixRequiredToken: 'serie',
    );

    $calculator = new TaxCalculator($context);

    $line = new TaxLineInput(
        taxCategoryId: 1,
        taxRateId: 7,
        taxRateSnapshot: '16.0000',
        evidenceStatus: 'verified',
        method: TaxMethod::Exclusive,
        unitPrice: '10.0050',
        quantity: '1.0000',
    );

    $input = new TaxDocumentInput(
        lines: [$line, $line],
        documentDiscount: '0.0000',
        issuedAt: new DateTimeImmutable('2026-11-03 12:00:00'),
    );

    $result = $calculator->calculate($input);

    // 1. La base es el precio COBRADO: 10.0050 por linea, y el 16 % encima.
    expect($result->lines)->toHaveCount(2)
        ->and($result->lines[0]['base_amount'])->toBe('10.0050')
        ->and($result->lines[0]['tax_amount'])->toBe('1.6008')
        ->and($result->lines[0]['line_total'])->toBe('11.6100');

    // 2. El total redondeado y el AJUSTE visible: las lineas suman 23.22 y el
    //    total redondeado es 23.21, asi que el ajuste es -0.01.
    expect($result->grandTotal)->toBe('23.2100')
        ->and($result->roundingAdjustment)->toBe('-0.0100');

    $sumOfLines = bcadd($result->lines[0]['line_total'], $result->lines[1]['line_total'], 4);

    expect(bcadd($sumOfLines, $result->roundingAdjustment, 4))->toBe($result->grandTotal);

    // 3. Los snapshots viajan CONGELADOS: un cambio posterior de la tasa no
    //    altera el documento emitido.
    expect($result->lines[0]['tax_rate_snapshot'])->toBe('16.0000')
        ->and($result->lines[0]['evidence_status'])->toBe('verified')
        ->and($result->lines[0]['tax_category_id'])->toBe(1)
        ->and($result->lines[0]['tax_rate_id'])->toBe(7);

    // 4. Y el motor es PURO: la misma entrada da el mismo resultado.
    $again = $calculator->calculate($input);

    expect($again->grandTotal)->toBe($result->grandTotal)
        ->and($again->roundingAdjustment)->toBe($result->roundingAdjustment)
        ->and($again->subtotal)->toBe($result->subtotal);

    // 5. El descuento de DOCUMENTO se prorratea a las lineas por base
    //    imponible, no se aplica entero a la primera linea.
    $withDiscount = $calculator->calculate(new TaxDocumentInput(
        lines: [$line, $line],
        documentDiscount: '2.0000',
        issuedAt: new DateTimeImmutable('2026-11-03 12:00:00'),
    ));

    expect($withDiscount->discountTotal)->toBe('2.0000')
        ->and($withDiscount->subtotal)->toBe('18.0100');
});
