<?php

namespace Tests\Fixtures;

use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DemoStoreSeeder;
use Database\Seeders\FiscalProfileSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\DB;

/**
 * El ESCENARIO del catalogo: la tienda de demostracion con su perfil fiscal
 * (y sus categorias de IVA), una segunda tienda y un cajero con su vinculo
 * por la tienda.
 *
 * REGLA que fija este fixture: *el catalogo se prueba con los datos DE
 * DEMOSTRACION del repo, no con unos inventados aqui*. Los sembradores son
 * los duenos de la tienda, del pais, de la moneda y de las categorias
 * fiscales, y son idempotentes: sembrar dos veces actualiza las mismas filas.
 *
 * A diferencia de `PosFixture`, aqui NO se crea ningun producto: el catalogo
 * es lo que se prueba, y un producto sembrado de mas se colaria en el
 * listado y ensuciaria las aserciones.
 */
final class CatalogFixture
{
    /**
     * El codigo del perfil fiscal con el que se cobra.
     */
    private const CODIGO_PERFIL = 'VE_MEDIOS_DIGITALES';

    /**
     * El codigo de la categoria fiscal con impuesto.
     */
    private const CODIGO_CATEGORIA = 'gravable';

    /**
     * Siembra el escenario del catalogo.
     *
     * @return array{store_id: int, profile_id: int, tax_category_id: int}
     */
    public static function escenario(): array
    {
        (new TaxonomySeeder)->run();
        (new DemoStoreSeeder)->run();
        (new FiscalProfileSeeder)->run();

        $storeId = (int) DB::table('stores')->where('code_name', 'DEMO')->value('id');

        $profileId = (int) DB::table('fiscal_profiles')
            ->where('code', self::CODIGO_PERFIL)
            ->value('id');

        $taxCategoryId = (int) DB::table('tax_categories')
            ->where('fiscal_profile_id', $profileId)
            ->where('code', self::CODIGO_CATEGORIA)
            ->value('id');

        return [
            'store_id' => $storeId,
            'profile_id' => $profileId,
            'tax_category_id' => $taxCategoryId,
        ];
    }

    /**
     * Una TIENDA: la activa o la cerrada.
     *
     * La tienda cerrada (`status = false`) no es una tienda donde se vende:
     * por eso el catalogo no le abre ficha a un producto nuevo.
     */
    public static function tienda(string $nombre, string $codeName, bool $activa = true): Store
    {
        return Store::create([
            'name' => $nombre,
            'code_name' => $codeName,
            'status' => $activa,
            'sort_order' => 0,
        ]);
    }

    /**
     * Un USUARIO con su vinculo por la tienda.
     *
     * El vinculo (`user_stores`) es lo que hace que el catalogo abra en ESA
     * tienda: sin el, la tienda activa seria la primera del sistema.
     */
    public static function usuario(int $storeId): User
    {
        $usuario = User::factory()->create();

        DB::table('user_stores')->insert([
            'user_id' => $usuario->id,
            'store_id' => $storeId,
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $usuario;
    }

    /**
     * Una CATEGORIA del catalogo maestro.
     */
    public static function categoria(string $code, string $nombre): Category
    {
        return Category::create([
            'code' => $code,
            'name' => $nombre,
        ]);
    }
}
