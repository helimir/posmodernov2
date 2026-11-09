<?php

namespace Tests\Fixtures;

use App\Models\Product;
use App\Models\ProductStore;
use App\Models\User;
use Database\Seeders\DemoStoreSeeder;
use Database\Seeders\FiscalProfileSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\DB;

/**
 * El ESCENARIO de la caja: la tienda, su perfil fiscal, sus series, un
 * producto con stock y un medio de pago.
 *
 * REGLA que fija este fixture: *la caja se prueba con el catalogo DE
 * DEMOSTRACION del repo, no con uno inventado aqui*. Los sembradores son los
 * duenos de esos datos (son idempotentes) y el escenario sale de ellos
 * exactamente igual que sale en el comando de humo y en la aplicacion.
 *
 * El STOCK NO SE INVENTA: `inventory_lots.purchase_item_id` es NOT NULL y
 * UNIQUE (el lote y su linea de compra son 1:1), asi que el stock tiene que
 * venir de una COMPRA real, como en el legacy y en el `SmokeSaleCommand`.
 *
 * La tienda de demostracion es la que siembran los sembradores, y su ficha
 * (`store_settings`) trae la zona horaria y su perfil fiscal trae las dos
 * series (la del documento y la del control).
 */
final class PosFixture
{
    /**
     * El codigo del producto de la caja.
     */
    private const CODIGO_PRODUCTO = 'HUMO-CAJA-001';

    /**
     * El codigo del medio de pago de la caja.
     */
    private const CODIGO_MEDIO_PAGO = 'HUMO-EFECTIVO';

    /**
     * El codigo del perfil fiscal con el que se cobra.
     */
    private const CODIGO_PERFIL = 'VE_MEDIOS_DIGITALES';

    /**
     * El codigo de la categoria fiscal con impuesto.
     */
    private const CODIGO_CATEGORIA = 'gravable';

    /**
     * Las unidades que trae el lote de demostracion.
     */
    private const UNIDADES_LOTE = '10.0000';

    /**
     * El precio de venta del producto de la caja.
     */
    private const PRECIO_VENTA = '25.0000';

    /**
     * El precio de compra del producto de la caja.
     */
    private const PRECIO_COMPRA = '10.0000';

    /**
     * Siembra el escenario completo de la caja.
     *
     * @return array{
     *     store_id: int,
     *     profile_id: int,
     *     document_series_id: int,
     *     control_series_id: int,
     *     tax_category_id: int,
     *     product_id: int,
     *     payment_method_id: int
     * }
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

        $categoryId = (int) DB::table('tax_categories')
            ->where('fiscal_profile_id', $profileId)
            ->where('code', self::CODIGO_CATEGORIA)
            ->value('id');

        $productId = self::producto($storeId, $categoryId);
        self::stock($storeId, $productId);
        $paymentMethodId = self::medioDePago($storeId);

        return [
            'store_id' => $storeId,
            'profile_id' => $profileId,
            'document_series_id' => (int) DB::table('fiscal_series')
                ->where('store_id', $storeId)
                ->where('fiscal_profile_id', $profileId)
                ->where('series_role', 'document')
                ->value('id'),
            'control_series_id' => (int) DB::table('fiscal_series')
                ->where('store_id', $storeId)
                ->where('fiscal_profile_id', $profileId)
                ->where('series_role', 'control')
                ->value('id'),
            'tax_category_id' => $categoryId,
            'product_id' => $productId,
            'payment_method_id' => $paymentMethodId,
        ];
    }

    /**
     * Un USUARIO con su vinculo por la tienda de la caja.
     *
     * El vinculo (`user_stores`) es lo que hace que la caja abra en ESA
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
     * Un SEGUNDO producto, con su ficha en la tienda, para el que no hay
     * stock.
     */
    public static function productoSinStock(int $storeId, int $categoryId): int
    {
        $producto = Product::create([
            'code' => 'HUMO-CAJA-SIN-STOCK',
            'name' => 'Producto sin stock de la caja',
            'tax_category_id' => $categoryId,
            'type' => 'product',
        ]);

        ProductStore::create([
            'product_id' => $producto->id,
            'store_id' => $storeId,
            'purchase_price' => self::PRECIO_COMPRA,
            'sell_price' => self::PRECIO_VENTA,
            'quantity_in_stock' => '0.0000',
            'status' => 'active',
        ]);

        return (int) $producto->id;
    }

    /**
     * Un SERVICIO de la tienda: no tiene almacen, asi que se vende sin
     * stock.
     */
    public static function servicio(int $storeId, int $categoryId): int
    {
        $servicio = Product::create([
            'code' => 'HUMO-CAJA-SERVICIO',
            'name' => 'Servicio de la caja',
            'tax_category_id' => $categoryId,
            'type' => 'service',
        ]);

        ProductStore::create([
            'product_id' => $servicio->id,
            'store_id' => $storeId,
            'purchase_price' => '0.0000',
            'sell_price' => '50.0000',
            'quantity_in_stock' => '0.0000',
            'status' => 'active',
        ]);

        return (int) $servicio->id;
    }

    /**
     * El PRODUCTO de la caja y su ficha en la tienda.
     */
    private static function producto(int $storeId, int $categoryId): int
    {
        $producto = Product::create([
            'code' => self::CODIGO_PRODUCTO,
            'name' => 'Producto de la caja',
            'tax_category_id' => $categoryId,
            'type' => 'product',
        ]);

        ProductStore::create([
            'product_id' => $producto->id,
            'store_id' => $storeId,
            // El override de categoria lo lleva la linea, no la ficha: asi se
            // ve que la precedencia la resuelve el dominio.
            'tax_category_id' => null,
            'purchase_price' => self::PRECIO_COMPRA,
            'sell_price' => self::PRECIO_VENTA,
            'quantity_in_stock' => self::UNIDADES_LOTE,
            'status' => 'active',
        ]);

        return (int) $producto->id;
    }

    /**
     * El STOCK: un lote con unidades disponibles, abierto por una COMPRA.
     */
    private static function stock(int $storeId, int $productId): void
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'store_id' => $storeId,
            'purchase_date' => now()->format('Y-m-d'),
            'status' => 'received',
            'invoice_no' => 'HUMO-CAJA-COMPRA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'product_id' => $productId,
            'store_id' => $storeId,
            'quantity' => self::UNIDADES_LOTE,
            'unit_price' => self::PRECIO_COMPRA,
            'discount' => '0.0000',
            'tax_rate_snapshot' => '16.0000',
            'tax_amount' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_lots')->insert([
            'product_id' => $productId,
            'store_id' => $storeId,
            'purchase_item_id' => $purchaseItemId,
            'quantity_received' => self::UNIDADES_LOTE,
            'quantity_consumed' => '0.0000',
            'quantity_returned' => '0.0000',
            'unit_cost' => self::PRECIO_COMPRA,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * El MEDIO DE PAGO de la caja y su vinculo por tienda.
     */
    private static function medioDePago(int $storeId): int
    {
        $medioId = DB::table('payment_methods')->insertGetId([
            'code' => self::CODIGO_MEDIO_PAGO,
            'name' => 'Efectivo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_method_stores')->insert([
            'payment_method_id' => $medioId,
            'store_id' => $storeId,
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $medioId;
    }
}
