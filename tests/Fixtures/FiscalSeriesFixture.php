<?php

namespace Tests\Fixtures;

use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\FiscalProvider;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Contexto y series fiscales de prueba.
 *
 * El contexto (moneda, país, perfil, tienda e imprenta) se crea UNA vez por
 * test: `currencies.code` y `countries.iso_code` son únicos. Las series se
 * crean sobre ese contexto.
 */
final class FiscalSeriesFixture
{
    /**
     * @return array{profile_id: int, store_id: int, provider_id: int}
     */
    public static function context(): array
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

        $profile = FiscalProfile::create([
            'country_id' => $country->id,
            'code' => 'ZZ_MAIN',
            'name' => 'Perfil de prueba',
            'signer_model' => 'printer',
        ]);

        $store = Store::create(['name' => 'Tienda de prueba']);

        $provider = FiscalProvider::create([
            'fiscal_profile_id' => $profile->id,
            'code' => 'PRINTER',
            'name' => 'Imprenta de prueba',
            'kind' => 'printer',
        ]);

        return [
            'profile_id' => $profile->id,
            'store_id' => $store->id,
            'provider_id' => $provider->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $attributes
     */
    public static function series(array $context, array $attributes = []): int
    {
        return DB::table('fiscal_series')->insertGetId(array_merge([
            'fiscal_profile_id' => $context['profile_id'],
            'store_id' => $context['store_id'],
            'provider_id' => $context['provider_id'],
            // Rol canónico: `document` (del emisor) | `control` (de la imprenta
            // digital) | `retention_receipt`. `series_kind` es sólo un alias
            // documentado: no existe como columna (design §F.2, C2).
            'series_role' => 'document',
            // Autoridad que asigna el número.
            'assigned_by' => 'issuer',
            'prefix' => 'ZZ',
            'next_number' => 100,
            'from_number' => 100,
            'to_number' => 200,
            'valid_from' => '2027-01-01',
            'valid_to' => '2027-12-31',
            'credential_ref' => null,
            'control_identifier' => null,
            'sequence_length' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /**
     * Aísla el test sin envolverlo en una transacción: las filas se commitean
     * (la segunda sesión PostgreSQL las ve) y se vacían al terminar.
     */
    public static function truncate(): void
    {
        DB::statement(
            'TRUNCATE users, fiscal_document_reservations, fiscal_series, fiscal_providers, '
            .'fiscal_profiles, stores, countries, currencies RESTART IDENTITY CASCADE'
        );
    }

    /**
     * Migraciones que crean una tabla: cada tabla tiene UN solo origen.
     *
     * @return array<int, string>
     */
    public static function migrationsCreating(string $table): array
    {
        $creating = [];

        foreach ((array) glob(database_path('migrations/*.php')) as $file) {
            $source = (string) file_get_contents($file);

            if (str_contains($source, "Schema::create('{$table}'")) {
                $creating[] = basename((string) $file);
            }
        }

        sort($creating);

        return $creating;
    }
}
