<?php

use App\Domain\Fiscal\Enums\EnvironmentRole;
use App\Domain\Fiscal\Enums\ProviderKind;
use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\SignerModel;
use App\Domain\Fiscal\Enums\TaxMethod;
use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;

/**
 * Literales de cadena prohibidos en `app/Domain/` (design §E.3): códigos de
 * país, nombres de país, monedas, autoridades y tokens normativos locales.
 * Sólo se miran los LITERALES: los nombres de clase, método o propiedad no
 * son literales y no constituyen un fork por país.
 */
function domainLiterals(?string $directory = null): array
{
    $categories = [
        'country-code' => ['VE', 'CL', 'MX', 'CO', 'PA', 'AR', 'PE', 'EC', 'BO', 'UY', 'GT', 'CR', 'PY', 'DO', 'PR', 'BR'],
        'country-name' => ['Venezuela', 'Chile', 'Mexico', 'México', 'Colombia', 'Panama', 'Panamá', 'Argentina', 'Peru', 'Perú', 'Bolivia', 'Ecuador', 'Uruguay', 'Guatemala', 'Costa Rica', 'Paraguay', 'Dominicana', 'Brazil', 'Brasil'],
        'currency' => ['USD', 'VES', 'MXN', 'CLP', 'COP', 'PAB', 'Bs.'],
        'authority' => ['seniat', 'sii', 'sat', 'dian', 'dgi', 'RIF', 'RUC', 'NIT'],
        'locale-token' => ['Contribuyentes Formales del IVA', '(E)'],
    ];

    $hallazgos = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory ?? app_path('Domain')));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
        $code = (string) preg_replace('#//[^\n]*#', '', $code);

        preg_match_all('/\'([^\'\\\\]*)\'|"([^"\\\\]*)"/', $code, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $literal = ($match[2] ?? '') !== '' ? $match[2] : $match[1];

            foreach ($categories as $category => $tokens) {
                foreach ($tokens as $token) {
                    $found = $category === 'country-code'
                        ? $literal === $token
                        : stripos($literal, $token) !== false;

                    if ($found) {
                        $hallazgos[] = $file->getPathname().' · '.$category.' · '.$token;
                    }
                }
            }
        }
    }

    return $hallazgos;
}

/**
 * 0.2.5-T — el dominio ramifica sobre enums genéricos leídos de filas, nunca
 * sobre un país.
 */
test('test_los_enums_del_dominio_fiscal_no_contienen_ningun_codigo_de_pais', function () {
    // Los cinco enums genéricos del dominio fiscal, con su conjunto cerrado.
    expect(array_column(ProviderKind::cases(), 'value'))->toBe(['printer', 'pac', 'portal'])
        ->and(array_column(SignerModel::cases(), 'value'))->toBe(['authority', 'psp', 'printer', 'legal_signature'])
        ->and(array_column(EnvironmentRole::cases(), 'value'))->toBe(['issuer', 'printer'])
        ->and(array_column(TaxMethod::cases(), 'value'))->toBe(['inclusive', 'exclusive'])
        ->and(array_column(RoundingRule::cases(), 'value'))->toBe(['line', 'total']);

    // Ningún literal de `app/Domain/` es un código de país, una moneda, una
    // autoridad ni un token normativo local.
    expect(domainLiterals())->toBe([]);

    // El escáner no es vacuo: detecta un literal prohibido.
    $detectados = domainLiterals(base_path('tests/Fixtures'));

    expect($detectados)->not->toBeEmpty()
        ->and(implode(' ', $detectados))->toContain('country-code');

    // Los enums aceptan EXACTAMENTE los valores que aceptaban las constantes
    // del perfil: las filas ya sembradas siguen siendo válidas.
    $currency = Currency::create(['code' => 'ZZA', 'name' => 'Moneda de prueba', 'decimals' => 2]);
    $country = Country::create(['iso_code' => 'ZZ', 'name' => 'Pais de prueba', 'default_currency_id' => $currency->id]);

    $profile = FiscalProfile::create([
        'country_id' => $country->id,
        'code' => 'ZZ_MAIN',
        'name' => 'Perfil de prueba',
        'signer_model' => SignerModel::Printer->value,
    ]);

    expect($profile->fresh()->signer_model)->toBe('printer')
        ->and(SignerModel::from('printer'))->toBe(SignerModel::Printer)
        ->and(ProviderKind::from('printer'))->toBe(ProviderKind::Printer)
        ->and(EnvironmentRole::from('issuer'))->toBe(EnvironmentRole::Issuer);
});
