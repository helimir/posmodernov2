<?php

use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\TaxMethod;
use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\FiscalProfileDocumentType;
use App\Models\FiscalProfileField;

/**
 * 0.2.7-T — el token normativo obligatorio es un dato, no un literal del
 * dominio.
 *
 * Regla que fija: el prefijo obligatorio y la frase del contribuyente formal
 * se LEEN del perfil; el dominio compara contra el valor leído.
 */
test('test_el_prefijo_de_serie_se_toma_del_perfil_y_no_del_codigo', function () {
    $currency = Currency::create(['code' => 'ZZA', 'name' => 'Moneda de prueba', 'decimals' => 2]);
    $country = Country::create(['iso_code' => 'ZZ', 'name' => 'Pais de prueba', 'default_currency_id' => $currency->id]);

    $profile = FiscalProfile::create([
        'country_id' => $country->id,
        'code' => 'ZZ_MAIN',
        'name' => 'Perfil de prueba',
        'signer_model' => 'printer',
        'rounding_rule' => 'total',
        'tax_inclusive_default' => true,
    ]);

    FiscalProfileField::create([
        'fiscal_profile_id' => $profile->id,
        'key' => 'series_prefix_required_token',
        'data_type' => 'string',
        'label' => 'Token obligatorio del prefijo de serie',
        'value' => 'serie',
        'required' => true,
        'applies_to' => 'document',
        'evidence_status' => 'verified',
        'source_ref' => 'SNAT/2026/00093 art. 20',
    ]);
    FiscalProfileField::create([
        'fiscal_profile_id' => $profile->id,
        'key' => 'formal_contributor_phrase',
        'data_type' => 'string',
        'label' => 'Frase del contribuyente formal del IVA',
        'value' => 'Contribuyentes Formales del IVA',
        'required' => false,
        'applies_to' => 'document',
        'evidence_status' => 'verified',
        'source_ref' => 'SNAT/2026/00093 art. 8 parágrafo único',
    ]);

    $documentType = FiscalProfileDocumentType::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'invoice',
        'name' => 'Factura',
        'direction' => 'sale',
        'tax_treatment' => 'standard',
        'exemption_marker' => '(E)',
    ]);

    // El contexto se construye desde las filas del perfil.
    $context = $profile->fresh()->taxContext($currency, $documentType->exemption_marker);

    expect($context->roundingRule)->toBe(RoundingRule::Total)
        ->and($context->taxInclusiveDefault)->toBeTrue()
        ->and($context->taxMethod())->toBe(TaxMethod::Inclusive)
        ->and($context->currencyDecimals)->toBe(2)
        ->and($context->exemptionMarker)->toBe('(E)')
        ->and($context->formalContributorPhrase)->toBe('Contribuyentes Formales del IVA')
        ->and($context->seriesPrefixRequiredToken)->toBe('serie');

    // El token se COMPARA contra el valor leído: un prefijo que no lo lleva falla.
    expect($context->requiresSeriesPrefix('serie-001'))->toBeTrue()
        ->and($context->requiresSeriesPrefix('001'))->toBeFalse();

    // Y el dominio no contiene el literal: el token vive en la fila.
    $source = (string) file_get_contents(app_path('Domain/Tax/TaxContext.php'));

    expect($source)->not->toContain("'serie'")
        ->and($source)->not->toContain('"serie"');
});
