<?php

use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use Illuminate\Support\Facades\Schema;

/**
 * 0.2.3-T — el producto apunta a una categoría fiscal, no a un porcentaje.
 *
 * Regla que fija: el tratamiento fiscal de un producto es una CATEGORÍA; el
 * porcentaje vive en la tasa, con vigencia y evidencia propias.
 */
test('test_el_catalogo_de_categorias_distingue_las_cinco_categorias_de_iva', function () {
    $currency = Currency::create([
        'code' => 'ZZA',
        'name' => 'Moneda de prueba',
        'decimals' => 2,
    ]);
    $country = Country::create([
        'iso_code' => 'ZZ',
        'name' => 'Pais de prueba',
        'default_currency_id' => $currency->id,
    ]);
    $profile = FiscalProfile::create([
        'country_id' => $country->id,
        'code' => 'ZZ_MAIN',
        'name' => 'Perfil de prueba',
        'signer_model' => 'printer',
    ]);

    $catalogue = [
        'gravable' => ['Gravable', false],
        'exento' => ['Exento', false],
        'no_gravado' => ['No gravado', false],
        'exonerado' => ['Exonerado', false],
        'iva_retenido' => ['IVA retenido', true],
    ];

    foreach ($catalogue as $code => [$name, $withholding]) {
        TaxCategory::create([
            'fiscal_profile_id' => $profile->id,
            'code' => $code,
            'name' => $name,
            'is_withholding' => $withholding,
            'applies_to' => 'both',
            'evidence_status' => 'verified',
            'source_ref' => 'SNAT/2026/00093 art. 8.7',
        ]);
    }

    $categories = $profile->fresh()->categories()->get();

    // Las cinco categorías se distinguen entre sí por su código.
    expect($categories)->toHaveCount(5)
        ->and($categories->pluck('code')->sort()->values()->all())
        ->toBe(['exento', 'exonerado', 'gravable', 'iva_retenido', 'no_gravado']);

    // Sólo el IVA retenido es una categoría de retención.
    expect($categories->where('is_withholding', true)->pluck('code')->all())->toBe(['iva_retenido'])
        ->and($categories->where('is_withholding', false)->pluck('code')->all())->toHaveCount(4);

    // La categoría NO guarda un porcentaje: el porcentaje vive en la tasa, que
    // además declara su vigencia y su evidencia.
    $rate = TaxRate::create([
        'tax_category_id' => $categories->firstWhere('code', 'gravable')->id,
        'percentage' => 16,
        'rounding_rule' => 'line',
        'valid_from' => '2027-01-01',
        'evidence_status' => 'pending_confirmation',
        'source_ref' => 'Decreto del IVA (G.O. 5.363)',
    ]);

    expect(Schema::getColumnListing('tax_categories'))->not->toContain('percentage')
        ->and(Schema::getColumnListing('tax_rates'))->toContain('percentage')
        ->and($rate->fresh()->tax_category_id)->toBe($categories->firstWhere('code', 'gravable')->id)
        ->and($rate->fresh()->evidence_status)->toBe('pending_confirmation');
});
