<?php

use App\Domain\Fiscal\TaxRateResolver;
use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use Illuminate\Support\Facades\DB;

/**
 * 0.6.5 — la tasa que se aplica a una linea es la VIGENTE en la fecha de la
 * venta, y el desempate entre dos tasas vigentes es determinista.
 *
 * Reglas que fija:
 *  - la tasa aplicable es la que esta EN VIGOR en la fecha de emision
 *    (`valid_from <= fecha` y `valid_to` nulo o `>= fecha`): una venta no
 *    puede cobrar una tasa que ya no esta vigente, ni una que todavia no lo
 *    esta;
 *  - `valid_to` es INCLUSIVO: el ultimo dia de vigencia sigue siendo
 *    vigente;
 *  - si hay varias tasas vigentes, gana la de mayor `priority`; a igualdad, la
 *    de `valid_from` mas reciente; y a igualdad, la de `id` mayor. Sin este
 *    desempate, dos ventas del mismo dia podrian tomar tasas distintas;
 *  - una categoria SIN tasa vigente NO es una venta sin impuesto: el
 *    resolvedor devuelve `null` y quien pregunta decide;
 *  - el porcentaje viaja como TEXTO decimal: un `float` no es un porcentaje.
 */
test('test_la_tasa_vigente_es_la_que_esta_en_vigencia_y_el_desempate_es_determinista', function () {
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
        'default_currency_id' => $currency->id,
    ]);

    // Tres categorias para separar los tres criterios de desempate.
    $gravable = TaxCategory::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'gravable',
        'name' => 'Gravable',
        'evidence_status' => 'verified',
    ]);

    $exonerado = TaxCategory::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'exonerado',
        'name' => 'Exonerado',
        'evidence_status' => 'verified',
    ]);

    $noGravado = TaxCategory::create([
        'fiscal_profile_id' => $profile->id,
        'code' => 'no_gravado',
        'name' => 'No gravado',
        'evidence_status' => 'verified',
    ]);

    // 1. La tasa del primer semestre, y la del segundo: el mismo producto
    //    cambia de tasa el dia en que la otra deja de estar vigente.
    TaxRate::create([
        'tax_category_id' => $gravable->id,
        'percentage' => 16,
        'rounding_rule' => 'line',
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-06-30',
        'priority' => 0,
        'evidence_status' => 'verified',
        'source_ref' => 'REF-PRIMERA',
    ]);

    TaxRate::create([
        'tax_category_id' => $gravable->id,
        'percentage' => 18,
        'rounding_rule' => 'line',
        'valid_from' => '2026-07-01',
        'valid_to' => null,
        'priority' => 0,
        'evidence_status' => 'pending_confirmation',
        'source_ref' => 'REF-SEGUNDA',
    ]);

    $resolver = new TaxRateResolver(DB::connection());

    // 2. En marzo manda la tasa que estaba en vigor.
    $vigente = $resolver->resolve($gravable->id, new DateTimeImmutable('2026-03-15'));

    expect($vigente)->not->toBeNull()
        ->and($vigente['id'])->toBeInt()
        ->and($vigente['tax_category_id'])->toBe($gravable->id)
        // El porcentaje es TEXTO decimal, nunca un `float`.
        ->and($vigente['percentage'])->toBeString()
        ->and($vigente['percentage'])->toBe('16.000000')
        ->and($vigente['evidence_status'])->toBe('verified')
        ->and($vigente['source_ref'])->toBe('REF-PRIMERA');

    // 3. `valid_to` es INCLUSIVO: el ultimo dia de vigencia sigue vigente.
    expect($resolver->resolve($gravable->id, new DateTimeImmutable('2026-06-30'))['percentage'])
        ->toBe('16.000000');

    // 4. Y al dia siguiente ya manda la nueva, con SU evidencia congelada.
    $vigente = $resolver->resolve($gravable->id, new DateTimeImmutable('2026-07-01'));

    expect($vigente['percentage'])->toBe('18.000000')
        ->and($vigente['evidence_status'])->toBe('pending_confirmation')
        ->and($vigente['source_ref'])->toBe('REF-SEGUNDA');

    // 5. Una fecha sin ninguna tasa vigente NO es una tasa.
    expect($resolver->resolve($gravable->id, new DateTimeImmutable('2025-12-31')))->toBeNull();

    // 6. Desempate por PRIORIDAD: dos tasas vigentes de la misma categoria,
    //    gana la de mayor `priority`.
    TaxRate::create([
        'tax_category_id' => $exonerado->id,
        'percentage' => 10,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'priority' => 0,
        'evidence_status' => 'verified',
    ]);

    TaxRate::create([
        'tax_category_id' => $exonerado->id,
        'percentage' => 20,
        'valid_from' => '2026-03-01',
        'valid_to' => null,
        'priority' => 5,
        'evidence_status' => 'verified',
    ]);

    expect($resolver->resolve($exonerado->id, new DateTimeImmutable('2026-05-01'))['percentage'])
        ->toBe('20.000000');

    // 7. Desempate por `valid_from`: a igualdad de prioridad, gana la mas
    //    reciente. Es lo que hace que dos ventas del mismo dia لمن la misma
    //    tasa y no dependan del plan de ejecucion.
    TaxRate::create([
        'tax_category_id' => $noGravado->id,
        'percentage' => 5,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'priority' => 0,
        'evidence_status' => 'verified',
    ]);

    TaxRate::create([
        'tax_category_id' => $noGravado->id,
        'percentage' => 7,
        'valid_from' => '2026-03-01',
        'valid_to' => null,
        'priority' => 0,
        'evidence_status' => 'verified',
    ]);

    expect($resolver->resolve($noGravado->id, new DateTimeImmutable('2026-05-01'))['percentage'])
        ->toBe('7.000000');

    // 8. Y la misma fecha devuelve SIEMPRE la misma tasa.
    expect($resolver->resolve($noGravado->id, new DateTimeImmutable('2026-05-01')))
        ->toEqual($resolver->resolve($noGravado->id, new DateTimeImmutable('2026-05-01')));
});
