<?php

use App\Models\Country;
use App\Models\Currency;
use App\Models\NumberFormat;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 0.2.1-T — el formato de la numeración se cambia insertando una fila.
 */
test('test_el_formato_del_correlativo_es_un_dato_sembrado_y_no_una_constante_de_codigo', function () {
    // El formato vive en una columna de `number_formats`, no en el código.
    expect(Schema::hasTable('number_formats'))->toBeTrue()
        ->and(Schema::hasColumn('number_formats', 'invoice_number_format'))->toBeTrue()
        ->and(Schema::hasColumn('number_formats', 'date_format'))->toBeTrue()
        ->and(Schema::hasColumn('number_formats', 'time_format'))->toBeTrue();

    // La semilla es una fila: se inserta y se lee desde ella.
    $currency = Currency::create([
        'code' => 'ZZA',
        'name' => 'Moneda de prueba',
        'decimals' => 2,
        'symbol' => 'Zz',
        'thousand_separator' => ',',
        'decimal_separator' => '.',
    ]);
    $country = Country::create([
        'iso_code' => 'ZZ',
        'name' => 'Pais de prueba',
        'locale' => 'es-ZZ',
        'default_currency_id' => $currency->id,
    ]);
    $format = NumberFormat::create([
        'country_id' => $country->id,
        'date_format' => 'DD/MM/YYYY',
        'time_format' => 'HH.MM.SS',
        'invoice_number_format' => '000-00000000',
    ]);

    expect($format->fresh()->invoice_number_format)->toBe('000-00000000')
        ->and($format->fresh()->country->iso_code)->toBe('ZZ')
        ->and($format->fresh()->country->defaultCurrency->decimals)->toBe(2);

    // Cambiar el formato es cambiar la fila: ningún fichero de código cambia.
    $format->update(['invoice_number_format' => 'ABC-000000']);

    expect($format->fresh()->invoice_number_format)->toBe('ABC-000000')
        ->and($format->fresh()->country->numberFormat->invoice_number_format)->toBe('ABC-000000');

    // Triangulación: dos países con formatos distintos conviven a la vez.
    $otherCurrency = Currency::create([
        'code' => 'ZZB',
        'name' => 'Moneda de prueba 2',
        'decimals' => 0,
    ]);
    $otherCountry = Country::create([
        'iso_code' => 'ZY',
        'name' => 'Otro pais de prueba',
        'default_currency_id' => $otherCurrency->id,
    ]);
    NumberFormat::create([
        'country_id' => $otherCountry->id,
        'invoice_number_format' => '000000',
    ]);

    expect(NumberFormat::where('country_id', $otherCountry->id)->first()->invoice_number_format)->toBe('000000')
        ->and($format->fresh()->invoice_number_format)->toBe('ABC-000000');

    // El formato no está compilado en el dominio: se busca en `app/`.
    $compiled = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->filter(fn (SplFileInfo $file) => str_contains((string) file_get_contents($file->getPathname()), '000-00000000'))
        ->map(fn (SplFileInfo $file) => $file->getPathname())
        ->all();

    expect($compiled)->toBe([]);
});
