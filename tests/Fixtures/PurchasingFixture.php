<?php

namespace Tests\Fixtures;

use App\Models\Product;
use App\Models\ProductStore;
use Illuminate\Support\Facades\DB;

/**
 * El ESCENARIO de las compras: el proveedor con su ficha en la tienda y los
 * productos que la tienda compra.
 *
 * REGLA que fija este fixture: *el escenario se apoya en la tienda de
 * demostracion del repo* (`PosFixture`), y no inventa una tienda: las
 * compras son de la tienda que ya tiene su plan de cuentas, y sin plan no hay
 * donde asentar la existencias y la deuda.
 *
 * Y EL PRODUCTO NO TIENE STOCK: la compra es la que lo hace entrar. Un
 * producto que ya tuviera lotes probaria otra cosa.
 */
final class PurchasingFixture
{
    /**
     * Un PROVEEDOR con su ficha en la tienda.
     */
    public static function proveedor(int $storeId, string $nombre = 'Distribuidora del escenario'): int
    {
        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'name' => $nombre,
            'email' => 'ventas@distribuidora.test',
            'phone' => '0412-0000000',
            'tax_id' => 'J-00000000-0',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_stores')->insert([
            'supplier_id' => $supplierId,
            'store_id' => $storeId,
            'balance' => '0.0000',
            'due' => '0.0000',
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $supplierId;
    }

    /**
     * Un PRODUCTO del catalogo de la tienda, SIN stock: la compra es la que
     * lo hace entrar.
     */
    public static function producto(int $storeId, int $taxCategoryId, string $codigo): int
    {
        $producto = Product::create([
            'code' => $codigo,
            'name' => 'Producto de compra '.$codigo,
            'tax_category_id' => $taxCategoryId,
            'type' => 'product',
        ]);

        ProductStore::create([
            'product_id' => $producto->id,
            'store_id' => $storeId,
            'purchase_price' => '0.0000',
            'sell_price' => '0.0000',
            'quantity_in_stock' => '0.0000',
            'status' => 'active',
        ]);

        return (int) $producto->id;
    }
}
