<?php

namespace Database\Seeders;

use App\Domain\Inventory\StockInvariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EL CATALOGO DE DEMOSTRACION: el catalogo que hace operable una instalacion.
 *
 * EL PROBLEMA que este seeder arregla: *una instalacion nueva NO TIENE
 * CATALOGO*. Los sembradores siembran la tienda, su configuracion, su cuenta
 * de caja, el perfil fiscal con sus categorias y sus series: pero no
 * siembran productos, ni fichas de tienda, ni lotes, ni categorias de
 * producto, ni marcas, ni unidades, ni cajas, ni clientes. Una tienda recien
 * sembrada NO PUEDE COBRAR NADA: el error es *"El producto 1 no tiene
 * categoria fiscal en la tienda 1"*.
 *
 * Un POS sin catalogo no es un POS.
 *
 * REGLA que fija este seeder: *una instalacion sembrada tiene un catalogo
 * OPERABLE: productos con su ficha de tienda, con su precio, con su stock REAL
 * y con su tratamiento fiscal*.
 *
 * LO QUE SIEMBRA:
 *
 *  - LAS TABLAS MAESTRO del catalogo (`categories`, `brands`, `units`,
 *    `boxes`): son los SELECTORES de la pantalla de catalogo, y hoy salen
 *    vacios. Las categorias tienen JERARQUIA (una con padre), y las unidades
 *    son las tres que se venden de verdad: una por unidad, una de peso y una
 *    de paquete.
 *  - LOS PRODUCTOS: una gama pequena y util de una tienda corriente (bebidas,
 *    abarrotes, limpieza y panaderia), con su categoria, su marca, su unidad,
 *    su caja, su tratamiento fiscal y su descripcion. Uno de ellos es un
 *    SERVICIO: no tiene almacen.
 *  - LAS FICHAS DE TIENDA (`product_stores`): una por producto, con su precio
 *    de compra y su precio de venta.
 *  - EL STOCK: una COMPRA de demostracion (cabecera, lineas, `purchase_totals`
 *    y un lote por producto). El stock NO se inventa: `inventory_lots.
 *    purchase_item_id` es NOT NULL y UNIQUE (el lote y su linea de compra son
 *    1:1), asi que el stock tiene que venir de una compra real, como en el
 *    legacy y en el comando de humo.
 *  - EL CLIENTE de demostracion, con su ficha en la tienda, su documento
 *    fiscal y su limite de credito.
 *
 * LO QUE NO SIEMBRA, Y POR QUE:
 *
 *  - NO siembra la CATEGORIA FISCAL de los productos: es DATO del perfil
 *    fiscal, y el perfil ya esta sembrado. El seeder la RESUELVE (la del
 *    perfil del pais de la tienda) y la usa; no la inventa ni la duplica.
 *  - NO siembra MEDIOS DE PAGO: los declara cada negocio (el efectivo, la
 *    tarjeta, la transferencia). El unico que siembra la instalacion es el de
 *    CREDITO, y lo hace `PaymentMethodSeeder`.
 *  - NO inventa una AUTORIDAD FISCAL: el perfil sembrado sigue siendo el de
 *    pruebas (`VE.PENDING`), con su tasa `pending_confirmation`.
 *
 * LA PRECEDENCIA DE LA CATEGORIA FISCAL, Y POR QUE EL OVERRIDE SE SIEMBRA EN
 * `null` EN CASI TODOS: la precedencia es del DOMINIO (el override de la
 * linea, el override del puente de la tienda y, si no hay, la categoria del
 * producto). Un sembrador que pusiera el override en todas las fichas
 * esconderia esa regla; aqui se deja en `null` y se SIEMBRA UN PRODUCTO CON
 * SU OVERRIDE PROPIO, para que la regla se vea y se pruebe.
 *
 * Es IDEMPOTENTE: todo se resuelve por su clave natural (`code`, o el vinculo
 * tienda+producto), como hace `DemoStoreSeeder`. Y el STOCK es el caso
 * dificil: sembrar dos veces NO puede crear una segunda compra con un segundo
 * lote del mismo producto. Aqui la COMPRA se identifica por su `invoice_no`
 * (unico por tienda), la LINEA por su par (compra, producto) y el LOTE por su
 * linea de compra (que es 1:1 y UNICA).
 *
 * LOS DATOS son de DEMOSTRACION y no afirman nada: los precios son de ejemplo,
 * el documento del cliente es un dato de ejemplo y las tasas del perfil siguen
 * marcadas como pendientes de confirmacion.
 */
class DemoCatalogSeeder extends Seeder
{
    /**
     * El codigo de la tienda de demostracion.
     */
    private const CODIGO_TIENDA = 'DEMO';

    /**
     * El `invoice_no` de la COMPRA de demostracion.
     *
     * Es la clave natural de la compra: es lo que hace que sembrar dos veces
     * NO cree una segunda compra con un segundo lote de cada producto.
     */
    private const INVOICE_COMPRA = 'DEMO-COMPRA-APERTURA';

    /**
     * La FECHA de la compra de demostracion.
     *
     * Es FIJA a proposito (como el saldo de apertura): la compra de
     * aprovisionamiento es un hecho CONGELADO, y una semilla que tomara la
     * fecha del reloj sembraria una compra distinta cada dia, con un lote
     * distinto, y el FIFO no seria el mismo.
     */
    private const FECHA_COMPRA = '2026-11-01';

    /**
     * El INSTANTE en que se recibio el lote de demostracion.
     *
     * Tambien es fijo: el FIFO ordena por antiguedad y, a igualdad, por id, y
     * un instante fijo hace que el orden sea el mismo en cualquier siembra.
     */
    private const RECIBIDO = '2026-11-01 08:00:00';

    /**
     * El DOCUMENTO FISCAL del cliente de demostracion.
     *
     * Es un dato de EJEMPLO, no un documento real: no hay ninguna autoridad
     * detrás de el.
     */
    private const TAX_ID_CLIENTE = 'DEMO-00000000';

    /**
     * El LIMITE DE CREDITO del cliente de demostracion.
     *
     * Es un tope RAZONABLE: ni cero (que bloquearia toda venta a credito) ni
     * ausente (que no bloquearia ninguna). Es la cifra que permite probar el
     * limite que bloquea.
     */
    private const LIMITE_CREDITO = '500.0000';

    /**
     * Siembra el catalogo de demostracion de la tienda.
     */
    public function run(): void
    {
        $now = now();

        $tienda = $this->tienda();
        $categorias = $this->categoriasFiscales($tienda);
        $maestros = $this->maestros($now);
        $productos = $this->productos($maestros, $categorias, $now);

        $this->fichas($tienda, $productos, $categorias, $now);
        $this->stock($tienda, $productos, $now);
        $this->cliente($tienda, $now);
    }

    /**
     * La TIENDA de demostracion.
     *
     * @throws RuntimeException Cuando la tienda no existe: el catalogo es de
     *                          una tienda, y sin tienda no hay donde vender.
     */
    private function tienda(): int
    {
        $id = DB::table('stores')->where('code_name', self::CODIGO_TIENDA)->value('id');

        if ($id === null) {
            throw new RuntimeException(
                'La tienda de demostracion no existe: ejecuta `php artisan db:seed` '
                .'(que siembra la tienda) antes de sembrar el catalogo.'
            );
        }

        return (int) $id;
    }

    /**
     * Las CATEGORIAS FISCALES del perfil de la tienda.
     *
     * Son DATO del perfil fiscal, y el perfil se busca por el PAIS de la
     * tienda: no hay ningun pais escrito aqui, y el catalogo de demostracion
     * acompana al perfil que la tienda tenga.
     *
     * @return array<string, int> Codigo de la categoria => identificador.
     *
     * @throws RuntimeException Cuando el perfil no tiene las categorias que el
     *                          catalogo necesita.
     */
    private function categoriasFiscales(int $tienda): array
    {
        $pais = DB::table('stores')->where('id', $tienda)->value('country_id');

        if ($pais === null) {
            throw new RuntimeException(
                "La tienda {$tienda} no tiene pais: su perfil fiscal no se puede resolver."
            );
        }

        $perfil = DB::table('fiscal_profiles')->where('country_id', $pais)->value('id');

        if ($perfil === null) {
            throw new RuntimeException(
                "La tienda {$tienda} no tiene perfil fiscal: el catalogo necesita sus categorias fiscales."
            );
        }

        $categorias = DB::table('tax_categories')
            ->where('fiscal_profile_id', $perfil)
            ->pluck('id', 'code')
            ->all();

        foreach (['gravable', 'exento'] as $codigo) {
            if (! isset($categorias[$codigo])) {
                throw new RuntimeException(
                    "El perfil fiscal {$perfil} no tiene la categoria '{$codigo}': "
                    .'el catalogo de demostracion necesita una categoria gravable y una exenta.'
                );
            }
        }

        return $categorias;
    }

    /**
     * LAS TABLAS MAESTRO: las categorias, las marcas, las unidades y las
     * cajas.
     *
     * Son los selectores de la pantalla de catalogo, y hoy salen vacios.
     *
     * @return array<string, array<string, int>> Maestro => codigo => identificador.
     */
    private function maestros($now): array
    {
        $categorias = $this->categorias();

        DB::table('categories')->upsert(
            array_map(static fn (array $categoria): array => [
                'code' => $categoria['code'],
                'name' => $categoria['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $categorias),
            ['code'],
            ['name', 'updated_at'],
        );

        // La JERARQUIA va en una segunda pasada: el padre tiene que existir
        // antes de que un hijo lo apunte.
        $identificadores = DB::table('categories')->pluck('id', 'code');

        foreach ($categorias as $categoria) {
            if ($categoria['parent'] === null) {
                continue;
            }

            DB::table('categories')
                ->where('id', $identificadores[$categoria['code']])
                ->update([
                    'parent_id' => $identificadores[$categoria['parent']],
                    'updated_at' => $now,
                ]);
        }

        $marcas = $this->marcas();
        $unidades = $this->unidades();
        $cajas = $this->cajas();

        DB::table('brands')->upsert(
            array_map(static fn (array $marca): array => [
                'code' => $marca['code'],
                'name' => $marca['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $marcas),
            ['code'],
            ['name', 'updated_at'],
        );

        DB::table('units')->upsert(
            array_map(static fn (array $unidad): array => [
                'code' => $unidad['code'],
                'name' => $unidad['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $unidades),
            ['code'],
            ['name', 'updated_at'],
        );

        DB::table('boxes')->upsert(
            array_map(static fn (array $caja): array => [
                'code' => $caja['code'],
                'name' => $caja['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $cajas),
            ['code'],
            ['name', 'updated_at'],
        );

        return [
            'categorias' => $identificadores->all(),
            'marcas' => DB::table('brands')->pluck('id', 'code')->all(),
            'unidades' => DB::table('units')->pluck('id', 'code')->all(),
            'cajas' => DB::table('boxes')->pluck('id', 'code')->all(),
        ];
    }

    /**
     * LOS PRODUCTOS y sus fichas de tienda.
     *
     * @param  array<string, array<string, int>>  $maestros
     * @param  array<string, int>  $categorias
     * @return list<array<string, mixed>> El catalogo, con el identificador de
     *                                    cada producto ya resuelto.
     */
    private function productos(array $maestros, array $categorias, $now): array
    {
        $catalogo = $this->catalogoDeProductos();

        $filas = [];

        foreach ($catalogo as $producto) {
            $filas[] = [
                'code' => $producto['code'],
                'name' => $producto['name'],
                'description' => $producto['description'],
                'category_id' => $maestros['categorias'][$producto['category']],
                'brand_id' => $maestros['marcas'][$producto['brand']],
                'unit_id' => $maestros['unidades'][$producto['unit']],
                'box_id' => $maestros['cajas'][$producto['box']],
                // La CATEGORIA FISCAL del producto: la del perfil de la tienda.
                'tax_category_id' => $categorias[$producto['tax']],
                'type' => $producto['type'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('products')->upsert(
            $filas,
            ['code'],
            [
                'name', 'description', 'category_id', 'brand_id', 'unit_id', 'box_id',
                'tax_category_id', 'type', 'is_active', 'updated_at',
            ],
        );

        $identificadores = DB::table('products')
            ->whereIn('code', array_column($filas, 'code'))
            ->pluck('id', 'code');

        foreach ($catalogo as $indice => $producto) {
            $catalogo[$indice]['id'] = (int) $identificadores[$producto['code']];
        }

        return $catalogo;
    }

    /**
     * LAS FICHAS DE TIENDA: una por producto, con su precio.
     *
     * El OVERRIDE de la categoria fiscal de la tienda va en `null` en casi
     * todos: la precedencia la resuelve el DOMINIO (el override si existe, si
     * no, la del producto), y un sembrador que lo llenara en todas las fichas
     * esconderia esa regla.
     *
     * @param  list<array<string, mixed>>  $productos
     * @param  array<string, int>  $categorias
     */
    private function fichas(int $tienda, array $productos, array $categorias, $now): void
    {
        $filas = [];

        foreach ($productos as $indice => $producto) {
            $filas[] = [
                'product_id' => $producto['id'],
                'store_id' => $tienda,
                'tax_category_id' => $producto['override'] === null
                    ? null
                    : $categorias[$producto['override']],
                'purchase_price' => $producto['purchase_price'],
                'sell_price' => $producto['sell_price'],
                // El STOCK es un CACHE: lo refrescan los lotes, no la semilla.
                'quantity_in_stock' => '0.0000',
                'status' => 'active',
                'sort_order' => $indice,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('product_stores')->upsert(
            $filas,
            ['product_id', 'store_id'],
            [
                'tax_category_id', 'purchase_price', 'sell_price', 'status',
                'sort_order', 'updated_at',
            ],
        );
    }

    /**
     * EL STOCK: una compra de demostracion que abre un lote por producto.
     *
     * REGLA que fija este metodo: *el stock de demostracion sale de LOTES
     * reales, no de un numero escrito a mano*. El lote y su linea de compra
     * son 1:1, asi que el stock tiene que venir de una COMPRA: cabecera, linea,
     * `purchase_totals` y lote.
     *
     * Y EL CACHE se refresca DESPUES, desde los lotes: el cache es un
     * DERIVADO, y escribirlo a mano seria escribir una mentira.
     *
     * @param  list<array<string, mixed>>  $productos
     */
    private function stock(int $tienda, array $productos, $now): void
    {
        $compra = $this->compra($tienda, $now);

        foreach ($productos as $producto) {
            // Un SERVICIO no tiene almacen: ni compra, ni linea, ni lote.
            if ($producto['stock'] === null) {
                continue;
            }

            $linea = $this->lineaDeCompra($compra, $tienda, $producto, $now);

            $this->lote($linea, $tienda, $producto, $now);
        }

        $this->totalesDeLaCompra($compra, $productos, $now);

        $invariant = new StockInvariant(DB::connection());

        foreach ($productos as $producto) {
            $invariant->refresh((int) $producto['id'], $tienda);
        }
    }

    /**
     * La COMPRA de demostracion: la cabecera, buscada por su clave natural.
     *
     * REGLA que fija este metodo: *la compra se identifica por su
     * `invoice_no`*. `purchases` no tiene restriccion unica sobre esa
     * columna, asi que la clave natural se respeta A BUSCAR antes de escribir:
     * sembrar dos veces no puede dejar dos compras de aprovisionamiento.
     */
    private function compra(int $tienda, $now): int
    {
        $existente = DB::table('purchases')
            ->where('store_id', $tienda)
            ->where('invoice_no', self::INVOICE_COMPRA)
            ->value('id');

        if ($existente !== null) {
            return (int) $existente;
        }

        return (int) DB::table('purchases')->insertGetId([
            'store_id' => $tienda,
            'purchase_date' => self::FECHA_COMPRA,
            'status' => 'received',
            'invoice_no' => self::INVOICE_COMPRA,
            'reference_no' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * La LINEA de la compra, buscada por su par (compra, producto).
     *
     * @param  array<string, mixed>  $producto
     */
    private function lineaDeCompra(int $compra, int $tienda, array $producto, $now): int
    {
        $existente = DB::table('purchase_items')
            ->where('purchase_id', $compra)
            ->where('product_id', $producto['id'])
            ->value('id');

        if ($existente !== null) {
            return (int) $existente;
        }

        return (int) DB::table('purchase_items')->insertGetId([
            'purchase_id' => $compra,
            'product_id' => $producto['id'],
            'store_id' => $tienda,
            'quantity' => $producto['stock'],
            'unit_price' => $producto['purchase_price'],
            'discount' => '0.0000',
            // La compra de APROVISIONAMIENTO no lleva impuesto: no es una
            // venta, y el impuesto se paga al vender, no al comprar.
            'tax_category_id' => null,
            'tax_rate_id' => null,
            'tax_rate_snapshot' => null,
            'tax_amount' => '0.0000',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * El LOTE que abre la linea de compra.
     *
     * REGLA que fija este metodo: *el lote y su linea de compra son 1:1*. El
     * lote se busca por su linea (`purchase_item_id` es UNIQUE), asi que sembrar
     * dos veces no puede dejar dos lotes del mismo producto.
     *
     * @param  array<string, mixed>  $producto
     */
    private function lote(int $linea, int $tienda, array $producto, $now): void
    {
        $existente = DB::table('inventory_lots')
            ->where('purchase_item_id', $linea)
            ->value('id');

        if ($existente !== null) {
            return;
        }

        DB::table('inventory_lots')->insert([
            'product_id' => $producto['id'],
            'store_id' => $tienda,
            'purchase_item_id' => $linea,
            'quantity_received' => $producto['stock'],
            'quantity_consumed' => '0.0000',
            'quantity_returned' => '0.0000',
            'unit_cost' => $producto['purchase_price'],
            'received_at' => self::RECIBIDO,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Los TOTALES de la compra: la suma de sus lineas.
     *
     * @param  list<array<string, mixed>>  $productos
     */
    private function totalesDeLaCompra(int $compra, array $productos, $now): void
    {
        $subtotal = BigDecimal::zero()->toScale(4, RoundingMode::HALF_UP);

        foreach ($productos as $producto) {
            if ($producto['stock'] === null) {
                continue;
            }

            $subtotal = $subtotal->plus(
                BigDecimal::of($producto['stock'])->multipliedBy($producto['purchase_price'])
            );
        }

        $importe = (string) $subtotal->toScale(4, RoundingMode::HALF_UP);

        DB::table('purchase_totals')->upsert([
            [
                'purchase_id' => $compra,
                'sub_total' => $importe,
                'discount' => '0.0000',
                // Sin impuesto: la compra de aprovisionamiento no lo lleva.
                'tax_total' => '0.0000',
                'rounding_adjustment' => '0.0000',
                'grand_total' => $importe,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['purchase_id'], [
            'sub_total', 'discount', 'tax_total', 'rounding_adjustment', 'grand_total', 'updated_at',
        ]);
    }

    /**
     * El CLIENTE de demostracion y su ficha en la tienda.
     *
     * REGLA que fija este metodo: *el cliente se identifica por su documento
     * fiscal*. `customers` no tiene restriccion unica sobre el nombre, asi que
     * la clave natural se respeta A BUSCAR antes de escribir: sembrar dos
     * veces no puede dejar dos clientes de demostracion.
     */
    private function cliente(int $tienda, $now): void
    {
        $pais = DB::table('stores')->where('id', $tienda)->value('country_id');

        $fila = [
            'name' => 'Cliente de demostracion',
            'email' => 'cliente@demostracion.test',
            'phone' => '0000-0000000',
            'mobile' => null,
            'address' => null,
            'city' => null,
            'zip_code' => null,
            'customer_country' => $pais === null ? null : (int) $pais,
            'customer_state' => null,
            // El DOCUMENTO FISCAL: un dato de ejemplo, no un documento real.
            'tax_id' => self::TAX_ID_CLIENTE,
            // El LIMITE de credito: un tope, no un saldo.
            'credit_limit' => self::LIMITE_CREDITO,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $existente = DB::table('customers')
            ->where('tax_id', self::TAX_ID_CLIENTE)
            ->value('id');

        if ($existente === null) {
            $clienteId = (int) DB::table('customers')->insertGetId($fila);
        } else {
            $clienteId = (int) $existente;

            DB::table('customers')->where('id', $clienteId)->update([
                'name' => $fila['name'],
                'email' => $fila['email'],
                'phone' => $fila['phone'],
                'customer_country' => $fila['customer_country'],
                'tax_id' => $fila['tax_id'],
                'credit_limit' => $fila['credit_limit'],
                'is_active' => $fila['is_active'],
                'updated_at' => $now,
            ]);
        }

        $vinculo = DB::table('customer_stores')
            ->where('customer_id', $clienteId)
            ->where('store_id', $tienda)
            ->value('id');

        if ($vinculo === null) {
            DB::table('customer_stores')->insert([
                'customer_id' => $clienteId,
                'store_id' => $tienda,
                'status' => 'active',
                'sort_order' => 0,
                // El SALDO es un CACHE: se deriva de los movimientos del
                // cliente, y el cliente de demostracion no debe nada.
                'balance' => '0.0000',
                'due' => '0.0000',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('customer_stores')->where('id', $vinculo)->update([
            'status' => 'active',
            'updated_at' => $now,
        ]);
    }

    /**
     * Las CATEGORIAS de producto, con su jerarquia.
     *
     * @return list<array{code: string, name: string, parent: string|null}>
     */
    private function categorias(): array
    {
        return [
            ['code' => 'DEMO-CAT-ALIMENTOS', 'name' => 'Alimentos', 'parent' => null],
            ['code' => 'DEMO-CAT-BEBIDAS', 'name' => 'Bebidas', 'parent' => 'DEMO-CAT-ALIMENTOS'],
            ['code' => 'DEMO-CAT-ABARROTES', 'name' => 'Abarrotes', 'parent' => 'DEMO-CAT-ALIMENTOS'],
            ['code' => 'DEMO-CAT-PANADERIA', 'name' => 'Panaderia', 'parent' => 'DEMO-CAT-ALIMENTOS'],
            ['code' => 'DEMO-CAT-LIMPIEZA', 'name' => 'Limpieza', 'parent' => null],
            ['code' => 'DEMO-CAT-SERVICIOS', 'name' => 'Servicios', 'parent' => null],
        ];
    }

    /**
     * Las MARCAS de demostracion.
     *
     * @return list<array{code: string, name: string}>
     */
    private function marcas(): array
    {
        return [
            ['code' => 'DEMO-BRAND-GENERAL', 'name' => 'Marca general'],
            ['code' => 'DEMO-BRAND-LOCAL', 'name' => 'Marca local'],
            ['code' => 'DEMO-BRAND-PROPIA', 'name' => 'Marca propia de la tienda'],
        ];
    }

    /**
     * Las UNIDADES de venta: una por unidad, una de peso y una de paquete.
     *
     * @return list<array{code: string, name: string}>
     */
    private function unidades(): array
    {
        return [
            ['code' => 'DEMO-UNIT-UNIDAD', 'name' => 'Unidad'],
            ['code' => 'DEMO-UNIT-KILOGRAMO', 'name' => 'Kilogramo'],
            ['code' => 'DEMO-UNIT-PAQUETE', 'name' => 'Paquete'],
            ['code' => 'DEMO-UNIT-LITRO', 'name' => 'Litro'],
        ];
    }

    /**
     * Las CAJAS de demostracion.
     *
     * @return list<array{code: string, name: string}>
     */
    private function cajas(): array
    {
        return [
            ['code' => 'DEMO-BOX-UNIDAD', 'name' => 'Caja de unidad'],
            ['code' => 'DEMO-BOX-PAQUETE', 'name' => 'Caja de paquete'],
            ['code' => 'DEMO-BOX-SACO', 'name' => 'Caja de saco'],
        ];
    }

    /**
     * El CATALOGO de productos de demostracion.
     *
     * REGLA que fija este catalogo: *una gama PEQUENA y UTIL de una tienda
     * corriente*. Cada producto tiene su categoria, su marca, su unidad, su
     * caja, su tratamiento fiscal y su precio. La MAYORIA es gravable y UNO es
     * exento (para que se vea el marcador `(E)` del comprobante), y UNO lleva
     * el OVERRIDE de la tienda, para que se vea la precedencia.
     *
     * El `stock` en `null` es un SERVICIO: no tiene almacen.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogoDeProductos(): array
    {
        return [
            [
                'code' => 'DEMO-COLA-500',
                'name' => 'Bebida de cola 500 ml',
                'description' => 'Botella de 500 ml. Producto de demostracion.',
                'category' => 'DEMO-CAT-BEBIDAS',
                'brand' => 'DEMO-BRAND-GENERAL',
                'unit' => 'DEMO-UNIT-UNIDAD',
                'box' => 'DEMO-BOX-PAQUETE',
                'tax' => 'gravable',
                'override' => null,
                'type' => 'product',
                'purchase_price' => '1.2000',
                'sell_price' => '2.0000',
                'stock' => '24.0000',
            ],
            [
                'code' => 'DEMO-AGUA-600',
                'name' => 'Agua mineral 600 ml',
                'description' => 'Botella de 600 ml. Producto de demostracion.',
                'category' => 'DEMO-CAT-BEBIDAS',
                'brand' => 'DEMO-BRAND-GENERAL',
                'unit' => 'DEMO-UNIT-UNIDAD',
                'box' => 'DEMO-BOX-PAQUETE',
                'tax' => 'gravable',
                'override' => null,
                'type' => 'product',
                'purchase_price' => '0.8000',
                'sell_price' => '1.5000',
                'stock' => '36.0000',
            ],
            [
                'code' => 'DEMO-JUGO-1L',
                'name' => 'Jugo de naranja 1 L',
                'description' => 'Botella de 1 litro. Producto de demostracion.',
                'category' => 'DEMO-CAT-BEBIDAS',
                'brand' => 'DEMO-BRAND-LOCAL',
                'unit' => 'DEMO-UNIT-LITRO',
                'box' => 'DEMO-BOX-PAQUETE',
                'tax' => 'gravable',
                'override' => null,
                'type' => 'product',
                'purchase_price' => '2.0000',
                'sell_price' => '3.5000',
                'stock' => '12.0000',
            ],
            [
                'code' => 'DEMO-ARROZ-1KG',
                'name' => 'Arroz blanco 1 kg',
                'description' => 'Saco de 1 kg. Producto de demostracion.',
                'category' => 'DEMO-CAT-ABARROTES',
                'brand' => 'DEMO-BRAND-GENERAL',
                'unit' => 'DEMO-UNIT-KILOGRAMO',
                'box' => 'DEMO-BOX-SACO',
                'tax' => 'gravable',
                'override' => null,
                'type' => 'product',
                'purchase_price' => '3.0000',
                'sell_price' => '4.5000',
                'stock' => '20.0000',
            ],
            [
                'code' => 'DEMO-AZUCAR-1KG',
                'name' => 'Azucar 1 kg',
                'description' => 'Saco de 1 kg. Producto de demostracion.',
                'category' => 'DEMO-CAT-ABARROTES',
                'brand' => 'DEMO-BRAND-GENERAL',
                'unit' => 'DEMO-UNIT-KILOGRAMO',
                'box' => 'DEMO-BOX-SACO',
                'tax' => 'gravable',
                'override' => null,
                'type' => 'product',
                'purchase_price' => '2.5000',
                'sell_price' => '3.8000',
                'stock' => '15.0000',
            ],
            [
                'code' => 'DEMO-HARINA-1KG',
                'name' => 'Harina de trigo 1 kg',
                'description' => 'Saco de 1 kg. Producto de demostracion.',
                'category' => 'DEMO-CAT-ABARROTES',
                'brand' => 'DEMO-BRAND-PROPIA',
                'unit' => 'DEMO-UNIT-KILOGRAMO',
                'box' => 'DEMO-BOX-SACO',
                'tax' => 'gravable',
                'override' => null,
                'type' => 'product',
                'purchase_price' => '2.2000',
                'sell_price' => '3.4000',
                'stock' => '18.0000',
            ],
            [
                'code' => 'DEMO-PAN-001',
                'name' => 'Pan de caja',
                'description' => 'Producto de demostracion con tratamiento fiscal exento.',
                'category' => 'DEMO-CAT-PANADERIA',
                'brand' => 'DEMO-BRAND-PROPIA',
                'unit' => 'DEMO-UNIT-UNIDAD',
                'box' => 'DEMO-BOX-UNIDAD',
                'tax' => 'exento',
                'override' => null,
                'type' => 'product',
                'purchase_price' => '1.5000',
                'sell_price' => '2.5000',
                'stock' => '10.0000',
            ],
            [
                'code' => 'DEMO-PASTEL-001',
                'name' => 'Pastel de queso',
                'description' => 'Producto de demostracion con override fiscal de la tienda.',
                'category' => 'DEMO-CAT-PANADERIA',
                'brand' => 'DEMO-BRAND-PROPIA',
                'unit' => 'DEMO-UNIT-UNIDAD',
                'box' => 'DEMO-BOX-UNIDAD',
                // El producto es GRAVABLE...
                'tax' => 'gravable',
                // ...pero la TIENDA lo vende como EXENTO: el override manda.
                'override' => 'exento',
                'type' => 'product',
                'purchase_price' => '3.0000',
                'sell_price' => '5.0000',
                'stock' => '8.0000',
            ],
            [
                'code' => 'DEMO-JABON-1KG',
                'name' => 'Jabon en polvo 1 kg',
                'description' => 'Paquete de 1 kg. Producto de demostracion.',
                'category' => 'DEMO-CAT-LIMPIEZA',
                'brand' => 'DEMO-BRAND-GENERAL',
                'unit' => 'DEMO-UNIT-KILOGRAMO',
                'box' => 'DEMO-BOX-PAQUETE',
                'tax' => 'gravable',
                'override' => null,
                'type' => 'product',
                'purchase_price' => '4.0000',
                'sell_price' => '6.5000',
                'stock' => '14.0000',
            ],
            [
                'code' => 'DEMO-DETERGENTE-1L',
                'name' => 'Detergente liquido 1 L',
                'description' => 'Botella de 1 litro. Producto de demostracion.',
                'category' => 'DEMO-CAT-LIMPIEZA',
                'brand' => 'DEMO-BRAND-LOCAL',
                'unit' => 'DEMO-UNIT-LITRO',
                'box' => 'DEMO-BOX-PAQUETE',
                'tax' => 'gravable',
                'override' => null,
                'type' => 'product',
                'purchase_price' => '3.5000',
                'sell_price' => '5.5000',
                'stock' => '9.0000',
            ],
            [
                'code' => 'DEMO-SERVICIO-ENVIO',
                'name' => 'Servicio de envio a domicilio',
                'description' => 'Servicio de demostracion: no tiene almacen.',
                'category' => 'DEMO-CAT-SERVICIOS',
                'brand' => 'DEMO-BRAND-GENERAL',
                'unit' => 'DEMO-UNIT-UNIDAD',
                'box' => 'DEMO-BOX-UNIDAD',
                'tax' => 'gravable',
                'override' => null,
                // Un SERVICIO no genera lote ni consume stock.
                'type' => 'service',
                'purchase_price' => '0.0000',
                'sell_price' => '5.0000',
                'stock' => null,
            ],
        ];
    }
}
