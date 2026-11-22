<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El CATALOGO de DENOMINACIONES de la tienda de demostracion.
 *
 * REGLA que fija este sembrador: *las denominaciones son DATO del negocio*.
 * El valor de una pieza (el billete de 50, la moneda de 0.10) es del pais
 * que emite la moneda, asi que vive aqui y NO en `app/`: un literal en el
 * codigo seria un fork por pais, y el repositorio no tiene literales de
 * pais en la aplicacion.
 *
 * Y EL CONJUNTO depende de la MONEDA de la tienda: 50 no son lo mismo en una
 * moneda que en otra. Por eso el sembrador RESUELVE la moneda de la tienda
 * (el perfil fiscal y, si no lo declara, el pais) y busca el conjunto que le
 * corresponde. Si la moneda no tiene conjunto, NO se siembra nada: un
 * catalogo equivocado es peor que un catalogo vacio, porque un arqueo
 * desglosado con piezas que no existen es un arqueo que miente.
 *
 * Es IDEMPOTENTE: la tienda, la moneda y el valor son su clave natural, y el
 * catalogo no se repite.
 */
class CashDenominationSeeder extends Seeder
{
    /**
     * La clave natural de la tienda de demostracion.
     */
    private const CODE_NAME = 'DEMO';

    /**
     * Los CONJUNTOS de denominaciones, por CODIGO de moneda.
     *
     * Cada conjunto son las piezas de esa moneda: el valor y si la pieza es
     * un billete o una moneda. El orden no importa: el catalogo se lee de
     * mayor a menor.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const CONJUNTOS = [
        'VES' => [
            ['500.0000', 'billete'],
            ['200.0000', 'billete'],
            ['100.0000', 'billete'],
            ['50.0000', 'billete'],
            ['20.0000', 'billete'],
            ['10.0000', 'billete'],
            ['5.0000', 'billete'],
            ['1.0000', 'moneda'],
            ['0.5000', 'moneda'],
            ['0.2500', 'moneda'],
            ['0.1000', 'moneda'],
            ['0.0500', 'moneda'],
        ],
    ];

    /**
     * Siembra el catalogo de denominaciones de la tienda de demostracion.
     */
    public function run(): void
    {
        $storeId = DB::table('stores')->where('code_name', self::CODE_NAME)->value('id');

        if ($storeId === null) {
            $this->command?->warn('No hay tienda de demostracion: no se siembra el catalogo de denominaciones.');

            return;
        }

        $moneda = $this->monedaDeLaTienda((int) $storeId);

        if ($moneda === null) {
            $this->command?->warn('La tienda de demostracion no tiene moneda: no se siembra el catalogo de denominaciones.');

            return;
        }

        [$currencyId, $codigo] = $moneda;

        $conjunto = self::CONJUNTOS[$codigo] ?? null;

        if ($conjunto === null) {
            $this->command?->warn(
                "No hay conjunto de denominaciones para la moneda {$codigo}: "
                .'la tienda de demostracion se queda sin catalogo de piezas.'
            );

            return;
        }

        $ahora = now();

        foreach ($conjunto as [$valor, $tipo]) {
            $existente = DB::table('cash_denominations')
                ->where('store_id', $storeId)
                ->where('currency_id', $currencyId)
                ->where('value', $valor)
                ->value('id');

            if ($existente === null) {
                DB::table('cash_denominations')->insert([
                    'store_id' => $storeId,
                    'currency_id' => $currencyId,
                    'value' => $valor,
                    'kind' => $tipo,
                    'is_active' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);

                continue;
            }

            DB::table('cash_denominations')->where('id', $existente)->update([
                'kind' => $tipo,
                'is_active' => true,
                'updated_at' => $ahora,
            ]);
        }
    }

    /**
     * La MONEDA de la tienda: la del perfil fiscal que declara, y si no la
     * declara, la del pais.
     *
     * @return array{0: int, 1: string}|null El id y el codigo de la moneda.
     */
    private function monedaDeLaTienda(int $storeId): ?array
    {
        $perfil = DB::table('stores')->where('id', $storeId)->value('fiscal_profile_id');

        if ($perfil !== null) {
            $moneda = DB::table('fiscal_profiles')
                ->where('id', (int) $perfil)
                ->value('default_currency_id');

            if ($moneda !== null) {
                return $this->moneda((int) $moneda);
            }
        }

        $pais = DB::table('stores')->where('id', $storeId)->value('country_id');

        if ($pais === null) {
            return null;
        }

        $moneda = DB::table('countries')
            ->where('id', (int) $pais)
            ->value('default_currency_id');

        if ($moneda === null) {
            return null;
        }

        return $this->moneda((int) $moneda);
    }

    /**
     * La MONEDA por su id: el id y el codigo.
     *
     * @return array{0: int, 1: string}|null
     */
    private function moneda(int $currencyId): ?array
    {
        $codigo = DB::table('currencies')->where('id', $currencyId)->value('code');

        if ($codigo === null) {
            return null;
        }

        return [$currencyId, (string) $codigo];
    }
}
