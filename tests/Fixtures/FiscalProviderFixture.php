<?php

namespace Tests\Fixtures;

use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\FiscalProvider;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * El ESCENARIO del CANAL FISCAL: un perfil, su tienda, su proveedor, su
 * entorno de emision y sus DOS series (la del documento y la del control).
 *
 * REGLA que fija este fixture: *el canal fiscal se monta con FILAS, no con
 * codigo*. El proveedor, el entorno, el vocabulario y las series son DATOS:
 * el resolutor los lee y de ellos deduce la implementacion. Un escenario
 * montado en el codigo probaria el codigo, y no la RESOLUCION, que es lo que
 * hay que probar.
 *
 * Y el perfil de prueba declara `authority_reference_scheme = 'ZZ.PENDING'`:
 * un esquema de referencia PENDIENTE, como el de un pais que todavia no tiene
 * autoridad conectada. El valor es un DATO de la fila, asi que el test puede
 * comprobar que el proveedor lo USA y que no lo reescribe.
 */
final class FiscalProviderFixture
{
    /**
     * El codigo del perfil de prueba.
     */
    private const CODIGO_PERFIL = 'ZZ_MAIN';

    /**
     * El contexto: la moneda, el pais, el perfil, la tienda y el proveedor.
     *
     * @return array{profile_id: int, store_id: int, provider_id: int, currency_id: int}
     */
    public static function contexto(): array
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
            'code' => self::CODIGO_PERFIL,
            'name' => 'Perfil de prueba',
            'signer_model' => 'printer',
            'rounding_rule' => 'line',
            'tax_inclusive_default' => false,
            'default_currency_id' => $currency->id,
            // El perfil EXIGE numero de control: es el caso de rol dual, en el
            // que el sistema es tambien la imprenta digital.
            'requires_control_number' => true,
            // Y su esquema de referencia esta PENDIENTE: no hay autoridad
            // conectada que emita una referencia.
            'authority_reference_scheme' => 'ZZ.PENDING',
        ]);

        $store = Store::create(['name' => 'Tienda del canal fiscal']);

        $provider = FiscalProvider::create([
            'fiscal_profile_id' => $profile->id,
            'code' => 'LOCAL',
            'name' => 'Imprenta local del sistema',
            'kind' => 'printer',
            'is_active' => true,
        ]);

        return [
            'profile_id' => (int) $profile->id,
            'store_id' => (int) $store->id,
            'provider_id' => (int) $provider->id,
            'currency_id' => (int) $currency->id,
        ];
    }

    /**
     * El ENTORNO de emision del perfil.
     *
     * @param  array<string, mixed>  $contexto
     * @param  array<string, mixed>  $atributos
     */
    public static function entorno(array $contexto, array $atributos = []): int
    {
        return DB::table('fiscal_profile_environments')->insertGetId(array_merge([
            'fiscal_profile_id' => $contexto['profile_id'],
            'code' => 'LOCAL_TESTING',
            'label' => 'Entorno local de pruebas',
            'role' => 'issuer',
            'provider_id' => $contexto['provider_id'],
            'base_url' => null,
            'auth_scheme' => null,
            'is_testing' => true,
            'is_production' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $atributos));
    }

    /**
     * La SERIE del DOCUMENTO: la que numera el emisor.
     *
     * @param  array<string, mixed>  $contexto
     * @param  array<string, mixed>  $atributos
     */
    public static function serieDocumento(array $contexto, array $atributos = []): int
    {
        return DB::table('fiscal_series')->insertGetId(array_merge([
            'fiscal_profile_id' => $contexto['profile_id'],
            'store_id' => $contexto['store_id'],
            'provider_id' => $contexto['provider_id'],
            'series_role' => 'document',
            'assigned_by' => 'issuer',
            'prefix' => 'ZZ',
            'next_number' => 100,
            'from_number' => 100,
            'to_number' => 200,
            'valid_from' => null,
            'valid_to' => null,
            'credential_ref' => null,
            'control_identifier' => null,
            'sequence_length' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $atributos));
    }

    /**
     * La SERIE del CONTROL: la que numera la imprenta.
     *
     * El identificador de dos digitos y la longitud del secuencial son DATOS
     * de la serie: el generador los lee, no los inventa.
     *
     * @param  array<string, mixed>  $contexto
     * @param  array<string, mixed>  $atributos
     */
    public static function serieControl(array $contexto, array $atributos = []): int
    {
        return DB::table('fiscal_series')->insertGetId(array_merge([
            'fiscal_profile_id' => $contexto['profile_id'],
            'store_id' => $contexto['store_id'],
            'provider_id' => $contexto['provider_id'],
            'series_role' => 'control',
            'assigned_by' => 'printer',
            'prefix' => null,
            'next_number' => 1,
            'from_number' => 1,
            'to_number' => 99999999,
            'valid_from' => null,
            'valid_to' => null,
            'credential_ref' => null,
            'control_identifier' => '00',
            'sequence_length' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ], $atributos));
    }

    /**
     * El VOCABULARIO de estados del perfil.
     *
     * El codigo NATIVO se declara aqui para que la prueba no dependa de lo
     * que el codigo tenga escrito: si el proveedor devolviera un literal, el
     * test lo compararia con la fila y fallaria.
     *
     * @param  array<string, mixed>  $contexto
     */
    public static function vocabulario(array $contexto, bool $conEmitido = true): void
    {
        $estados = [
            ['native_code' => 'draft', 'generic_status' => 'draft'],
            ['native_code' => 'void', 'generic_status' => 'void'],
        ];

        if ($conEmitido) {
            $estados[] = ['native_code' => 'ZZ-EMITIDO', 'generic_status' => 'issued'];
        }

        foreach ($estados as $estado) {
            DB::table('fiscal_status_vocabularies')->insert([
                'fiscal_profile_id' => $contexto['profile_id'],
                'native_code' => $estado['native_code'],
                'native_label' => 'Estado de prueba',
                'generic_status' => $estado['generic_status'],
                'is_final' => false,
                'is_cancellation' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Una CLAVE del catalogo del perfil (`fiscal_profile_fields`).
     *
     * @param  array<string, mixed>  $contexto
     * @param  array<string, mixed>  $atributos
     */
    public static function clave(array $contexto, string $key, ?string $value, array $atributos = []): void
    {
        DB::table('fiscal_profile_fields')->insert(array_merge([
            'fiscal_profile_id' => $contexto['profile_id'],
            'key' => $key,
            'data_type' => 'string',
            'label' => 'Clave de prueba',
            'value' => $value,
            'required' => false,
            'applies_to' => 'document',
            'evidence_status' => 'verified',
            'source_ref' => 'REF-PRUEBA',
            'created_at' => now(),
            'updated_at' => now(),
        ], $atributos));
    }
}
