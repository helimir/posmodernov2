<?php

use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\FiscalProfileEnvironment;
use App\Models\FiscalProfileField;
use App\Models\FiscalProvider;
use App\Models\FiscalStatusVocabulary;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use Illuminate\Support\Facades\Schema;

/**
 * Capa fiscal (M02): el vocabulario de estados es nativo → genérico, la
 * referencia de verificación es un par valor + esquema y los conjuntos
 * cerrados (`signer_model`, `role`) se validan en el dominio.
 */
function fiscalLayerProfile(): FiscalProfile
{
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

    return FiscalProfile::create([
        'country_id' => $country->id,
        'code' => 'ZZ_MAIN',
        'name' => 'Perfil de prueba',
        'signer_model' => 'printer',
    ]);
}

/**
 * 0.2.2-T — la referencia de verificación de cada país es un par valor + esquema.
 */
test('test_la_referencia_de_verificacion_se_guarda_como_valor_y_esquema_y_no_como_columna_uuid', function () {
    // Ninguna tabla de negocio tiene una columna `uuid`: la referencia no es
    // una columna fija. Las 7 tablas de infraestructura de Laravel quedan
    // fuera (design §A.1): `failed_jobs.uuid` es el identificador de la cola.
    $infrastructure = [
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'migrations',
    ];
    $withUuid = [];
    foreach (Schema::getTableListing() as $table) {
        $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
        if (in_array($name, $infrastructure, true)) {
            continue;
        }
        if (in_array('uuid', Schema::getColumnListing($table), true)) {
            $withUuid[] = $name;
        }
    }

    expect($withUuid)->toBe([]);

    // El esquema de la referencia es un dato del perfil; el valor llega con el
    // documento (M22, `authority_reference`). Ningún país tiene columna propia.
    $profile = fiscalLayerProfile();
    $profile->update([
        'id_scheme_code' => 'ZZ.TAXID',
        'authority_reference_scheme' => 'ZZ.REF',
    ]);

    expect(Schema::hasColumn('fiscal_profiles', 'authority_reference_scheme'))->toBeTrue()
        ->and($profile->fresh()->authority_reference_scheme)->toBe('ZZ.REF')
        ->and($profile->fresh()->id_scheme_code)->toBe('ZZ.TAXID');

    // El estado nativo se guarda como valor + estado genérico, en el vocabulario
    // del perfil: el estado fiscal no es un enum universal.
    $vocabulary = FiscalStatusVocabulary::create([
        'fiscal_profile_id' => $profile->id,
        'native_code' => 'N01',
        'native_label' => 'emitido',
        'generic_status' => 'issued',
        'is_final' => true,
        'is_cancellation' => false,
    ]);

    expect($vocabulary->fresh()->native_code)->toBe('N01')
        ->and($vocabulary->fresh()->generic_status)->toBe('issued')
        ->and($profile->fresh()->statusVocabularies()->pluck('native_code')->all())->toBe(['N01']);
});

/**
 * 0.2.2-T (parte) — los conjuntos cerrados de la capa fiscal se validan en el
 * dominio contra los enums genéricos (`SignerModel`, `EnvironmentRole`,
 * `ProviderKind`, `RoundingRule`).
 */
test('test_los_conjuntos_cerrados_del_perfil_fiscal_se_validan_en_el_dominio', function () {
    $profile = fiscalLayerProfile();

    expect($profile->fresh()->signer_model)->toBe('printer');

    // Un modelo de firma fuera del conjunto cerrado se rechaza.
    expect(fn () => FiscalProfile::create([
        'country_id' => $profile->country_id,
        'code' => 'ZZ_INVALID',
        'name' => 'Perfil invalido',
        'signer_model' => 'autoridad',
    ]))->toThrow(InvalidArgumentException::class);

    // Y el rol del ambiente, igual.
    expect(fn () => FiscalProfileEnvironment::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'PROD',
        'role' => 'auditor',
    ]))->toThrow(InvalidArgumentException::class);

    // Un rol válido sí se persiste.
    $environment = FiscalProfileEnvironment::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'PROD',
        'role' => 'issuer',
    ]);

    expect($environment->fresh()->role)->toBe('issuer');

    // El actor intermedio valida su clase contra el enum `ProviderKind`.
    expect(fn () => FiscalProvider::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'BAD',
        'name' => 'Proveedor invalido',
        'kind' => 'imprenta',
    ]))->toThrow(InvalidArgumentException::class);

    $provider = FiscalProvider::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'PRINTER',
        'name' => 'Imprenta de prueba',
        'kind' => 'printer',
    ]);

    expect($provider->fresh()->kind)->toBe('printer');

    // Y la tasa valida su regla de redondeo contra el enum `RoundingRule`.
    $category = TaxCategory::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'gravable',
        'name' => 'Gravable',
        'evidence_status' => 'verified',
    ]);

    expect(fn () => TaxRate::create([
        'tax_category_id' => $category->id,
        'percentage' => 16,
        'rounding_rule' => 'linea',
        'valid_from' => '2027-01-01',
        'evidence_status' => 'pending_confirmation',
    ]))->toThrow(InvalidArgumentException::class);

    $rate = TaxRate::create([
        'tax_category_id' => $category->id,
        'percentage' => 16,
        'rounding_rule' => 'line',
        'valid_from' => '2027-01-01',
        'evidence_status' => 'pending_confirmation',
    ]);

    expect($rate->fresh()->rounding_rule)->toBe('line');
});

/**
 * 0.2.2-T (parte) — C4: el token normativo obligatorio del prefijo de serie es
 * un dato del catálogo de claves del `jsonb`, no un literal del dominio.
 */
test('test_el_token_del_prefijo_de_serie_es_un_dato_del_catalogo_de_claves', function () {
    $profile = fiscalLayerProfile();

    $field = FiscalProfileField::create([
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

    expect($field->fresh()->key)->toBe('series_prefix_required_token')
        ->and($field->fresh()->value)->toBe('serie')
        ->and($field->fresh()->evidence_status)->toBe('verified')
        ->and($profile->fresh()->fields()->pluck('value', 'key')->all())
        ->toHaveKey('series_prefix_required_token', 'serie');
});
