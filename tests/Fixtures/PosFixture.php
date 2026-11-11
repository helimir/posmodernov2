<?php

namespace Tests\Fixtures;

use App\Domain\Accounting\CashRegisterService;
use App\Models\Product;
use App\Models\ProductStore;
use App\Models\User;
use App\Support\Pos\PosContext;
use Database\Seeders\DemoStoreSeeder;
use Database\Seeders\FiscalProfileSeeder;
use Database\Seeders\PaymentMethodSeeder;
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
     * El codigo de la categoria fiscal EXENTA: la que no grava, y la que
     * permite una venta de importe redondo (100,0000) sin impuesto.
     */
    private const CODIGO_CATEGORIA_EXENTA = 'exento';

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
     *     exempt_tax_category_id: int,
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

        $exemptCategoryId = (int) DB::table('tax_categories')
            ->where('fiscal_profile_id', $profileId)
            ->where('code', self::CODIGO_CATEGORIA_EXENTA)
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
            'exempt_tax_category_id' => $exemptCategoryId,
            'product_id' => $productId,
            'payment_method_id' => $paymentMethodId,
        ];
    }

    /**
     * Un USUARIO con su vinculo por la tienda de la caja, CON la caja del dia
     * ABIERTA.
     *
     * REGLA que fija este fixture: *el escenario de la caja trae la caja del
     * dia abierta*. No se cobra sin caja abierta (regla de la unidad 1.5), y
     * un escenario que no la trae obliga a cada test a abrirla antes de
     * cobrar: el mismo paso repetido en treinta sitios es el mismo paso
     * olvidado en uno.
     *
     * El SALDO DE PARTIDA es CERO: es un DATO (el dinero que hay en la caja al
     * empezar, contado a mano) y el escenario no cuenta dinero, solo deja la
     * caja lista para cobrar.
     */
    public static function usuario(int $storeId): User
    {
        $usuario = self::creaUsuario($storeId);

        self::abreLaCajaDelDia($usuario);

        return $usuario;
    }

    /**
     * Un USUARIO con su vinculo por la tienda de la caja y SIN caja del dia.
     *
     * Es el escenario del cobro SIN CAJA: la regla que dice que no se cobra
     * sin caja abierta necesita un usuario sin caja, y por eso existe esta
     * variante y no una bandera.
     */
    public static function usuarioSinCaja(int $storeId): User
    {
        return self::creaUsuario($storeId);
    }

    /**
     * Un USUARIO con su vinculo por la tienda de la caja.
     *
     * El vinculo (`user_stores`) es lo que hace que la caja abra en ESA
     * tienda: sin el, la tienda activa seria la primera del sistema.
     */
    private static function creaUsuario(int $storeId): User
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
     * Abre la caja del dia de la tienda del usuario.
     *
     * La FECHA DE NEGOCIO la dice el CONTEXTO (`PosContext`), no un
     * `DAY()/MONTH()/YEAR()` calculado: es la fecha de la TIENDA, con su
     * zona horaria, y el reloj del servidor no tiene nada que ver con el
     * negocio.
     */
    private static function abreLaCajaDelDia(User $usuario): void
    {
        $contexto = new PosContext(DB::connection(), (int) $usuario->id);

        (new CashRegisterService(DB::connection()))->open(
            (int) $contexto->store()->id,
            $contexto->businessDate(),
            '0.0000',
            null,
            (int) $usuario->id
        );
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
     * Un producto con una cantidad de stock CONCRETA, abierto por una compra.
     *
     * El CACHE de la tienda (`product_stores.quantity_in_stock`) se escribe a
     * PROPOSITO con otro numero: el reporte de stock bajo tiene que enseñar
     * el stock REAL, que es la suma de los lotes, y no el cache. Un cache que
     * dice la verdad no distingue una cosa de la otra.
     */
    public static function productoConStock(int $storeId, int $categoryId, string $stock): int
    {
        $producto = Product::create([
            'code' => 'HUMO-CAJA-STOCK-'.str_replace('.', '-', $stock),
            'name' => 'Producto con stock de la caja',
            'tax_category_id' => $categoryId,
            'type' => 'product',
        ]);

        ProductStore::create([
            'product_id' => $producto->id,
            'store_id' => $storeId,
            'purchase_price' => self::PRECIO_COMPRA,
            'sell_price' => self::PRECIO_VENTA,
            // El cache miente: el reporte enseña los lotes.
            'quantity_in_stock' => '999.0000',
            'status' => 'active',
        ]);

        self::lote($storeId, (int) $producto->id, $stock);

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
     * Un SERVICIO EXENTO: su categoria fiscal no grava, asi que dos unidades
     * de 50,0000 son una venta de 100,0000 EXACTOS.
     *
     * El escenario de la caja necesita una venta redonda para comprobar que
     * la parte a credito queda como deuda y la cobrada no.
     */
    public static function servicioExento(int $storeId, int $categoryId): int
    {
        $servicio = Product::create([
            'code' => 'HUMO-CAJA-SERVICIO-EXENTO',
            'name' => 'Servicio exento de la caja',
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
     * El MEDIO DE PAGO DE CREDITO de la tienda.
     *
     * Es el metodo cuyo TIPO es `credit`: un pago con el no es un cobro, es
     * deuda. Lo siembra el mismo seeder que siembra la instalacion, y su
     * vinculo por tienda se busca antes de escribirse (esa tabla no tiene
     * restriccion unica).
     */
    public static function medioDePagoDeCredito(int $storeId): int
    {
        $medioId = PaymentMethodSeeder::medioDeCredito();

        $vinculo = DB::table('payment_method_stores')
            ->where('payment_method_id', $medioId)
            ->where('store_id', $storeId)
            ->value('id');

        if ($vinculo === null) {
            DB::table('payment_method_stores')->insert([
                'payment_method_id' => $medioId,
                'store_id' => $storeId,
                'status' => 'active',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $medioId;
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
        self::lote($storeId, $productId, self::UNIDADES_LOTE);
    }

    /**
     * Un LOTE con una cantidad dada, abierto por una COMPRA.
     *
     * El lote no se inventa: `inventory_lots.purchase_item_id` es NOT NULL y
     * UNIQUE (el lote y su linea de compra son 1:1), asi que el stock tiene
     * que venir de una compra real, como en el legacy y en el
     * `SmokeSaleCommand`.
     */
    private static function lote(int $storeId, int $productId, string $cantidad): void
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
            'quantity' => $cantidad,
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
            'quantity_received' => $cantidad,
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
