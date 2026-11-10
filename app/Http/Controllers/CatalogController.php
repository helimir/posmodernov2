<?php

namespace App\Http\Controllers;

use App\Models\Box;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStore;
use App\Models\Store;
use App\Models\TaxCategory;
use App\Models\Unit;
use App\Support\Pos\PosAmount;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * El CATALOGO: el maestro de productos y su precio por tienda.
 *
 * Esta clase es la COSTEZA entre el navegador y el catalogo. No cobra: el
 * dominio es el de las ventas, y el catalogo no lo toca. Lo que hace es
 * ADMINISTRAR el maestro (`products`) y su estado por tienda
 * (`product_stores`), que es donde vive el precio de cada tienda.
 *
 * REGLAS que fija esta clase:
 *
 *  - LA TIENDA ACTIVA: el catalogo es POR TIENDA, y la tienda es la del
 *    contexto (`PosContext`), la misma que usa la caja. El precio que se
 *    edita en la ficha es el de ESA tienda.
 *  - EL PRECIO DE CADA TIENDA ES DE SU TIENDA: la ficha del producto guarda
 *    el precio de la tienda activa y NO toca el de las demas. Editar el
 *    catalogo de una tienda no reescribe el catalogo de otra.
 *  - LA FICHA (`product_stores`) EXISTE EN TODAS LAS TIENDAS ACTIVAS: un
 *    producto nuevo nace con su precio por tienda, para que el precio de
 *    cada tienda exista desde el principio.
 *  - EL STOCK NO SE EDITA A MANO: `quantity_in_stock` es un CACHE derivado de
 *    los lotes (`StockInvariant`). La pantalla lo muestra, pero su lugar es
 *    la compra o el ajuste, no la ficha.
 *  - LA CATEGORIA FISCAL: la que MANDA es el override de la tienda si lo
 *    hay, y si no la del producto. La pantalla muestra las dos y explica
 *    cual manda. Sin categoria fiscal, la caja NO cobra en silencio una
 *    linea sin tratamiento fiscal: la ficha avisa.
 *
 * LOS IMPORTES son TEXTO decimal: las columnas son `numeric(25,4)` y un
 * `float` no es un dinero.
 */
class CatalogController extends Controller
{
    /**
     * Cuantos productos trae el listado.
     *
     * Es el mismo tope que usa la lista de ventas: una pagina que se lee.
     */
    private const PER_PAGE = 50;

    public function __construct(private readonly Connection $connection) {}

    /**
     * El LISTADO del catalogo.
     *
     * PONE las FILTROS: la busqueda por codigo o por nombre (como prefijo,
     * con los comodines del `LIKE` escapados), la categoria y el estado. Y
     * PAGINA: cincuenta productos por pagina.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/catalog/index.tsx`:
     *
     *  - `products`: lista de `{ id, code, name, category, sell_price,
     *    quantity_in_stock, type, is_active }`. El precio es el de la TIENDA
     *    ACTIVA y los importes son TEXTO decimal.
     *  - `pagination`: `{ page, per_page, total, last_page }`.
     *  - `filters`: `{ q, category_id, status }`, tal y como se aplican.
     *  - `categories`: los selectores del filtro.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     *  - `currency`: `{ code, symbol, symbol_position, decimals,
     *    thousand_separator, decimal_separator }`.
     */
    public function index(Request $request): Response
    {
        $escenario = $this->escenario($request);
        $store = $escenario['store'];

        $filtros = $this->filtros($request);
        $listado = $this->listado((int) $store->id, $filtros, $this->pagina($request));

        return Inertia::render('catalog/index', [
            'products' => $listado['products'],
            'pagination' => $listado['pagination'],
            'filters' => $filtros,
            'categories' => $this->selectores($escenario['context'])['categories'],
            'store' => $this->storeProps($store),
            'currency' => $escenario['currency'],
        ]);
    }

    /**
     * El FORMULARIO de alta.
     *
     * Es la MISMA pantalla que la ficha, con el producto a `null`: una sola
     * pantalla de producto, con el producto o sin el.
     */
    public function create(Request $request): Response
    {
        $escenario = $this->escenario($request);

        return Inertia::render('catalog/form', [
            'product' => null,
            ...$this->selectores($escenario['context']),
            'stores' => $this->storesProps($escenario['store']),
            'store' => $this->storeProps($escenario['store']),
            'currency' => $escenario['currency'],
        ]);
    }

    /**
     * El ALTA de un producto.
     *
     * Crea el maestro y abre su ficha en TODAS las tiendas activas, con
     * precio CERO, y deja el precio de la TIENDA ACTIVA en el que se ha
     * mandado.
     *
     * Todo dentro de UNA transaccion: un producto con la ficha a medias es un
     * producto que la caja no puede vender.
     */
    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $escenario = $this->escenario($request);
        $store = $escenario['store'];

        $producto = $this->connection->transaction(function () use ($datos, $store): Product {
            $producto = new Product;
            $producto->fill($this->camposDelMaestro($datos));
            // La CATEGORIA FISCAL del maestro: la ficha nueva no tiene
            // override, asi que la categoria que manda es la del producto.
            $producto->tax_category_id = $datos['tax_category_id'] ?? null;
            $producto->save();

            // La FICHA en todas las tiendas activas: el precio por tienda
            // existe desde el principio. El STOCK no se escribe: es un cache
            // de los lotes, y un producto nuevo no tiene lotes.
            foreach ($this->tiendasActivas() as $tienda) {
                ProductStore::create([
                    'product_id' => $producto->id,
                    'store_id' => $tienda->id,
                    'tax_category_id' => null,
                    'purchase_price' => '0.0000',
                    'sell_price' => '0.0000',
                    'status' => 'active',
                    'sort_order' => 0,
                ]);
            }

            // Y el precio de la TIENDA ACTIVA.
            $this->aplicaPrecios($producto, $store, $datos);

            return $producto;
        });

        return redirect()->route('catalogo.show', $producto->id);
    }

    /**
     * La FICHA de un producto: el maestro, su stock y sus precios por
     * tienda.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/catalog/form.tsx`:
     *
     *  - `product`: `{ id, code, name, description, type, is_active,
     *    category_id, brand_id, unit_id, box_id, tax_category_id,
     *    tax_category_name, store_tax_category_id, store_tax_category_name,
     *    effective_tax_category_id, effective_tax_category_name,
     *    missing_tax_category, quantity_in_stock, prices }`.
     *  - `product.prices`: una fila por tienda activa, con `{ store_id,
     *    store_name, store_code_name, is_active_store, sell_price,
     *    purchase_price, quantity_in_stock, tax_category_id,
     *    tax_category_name }`.
     *  - `categories`, `brands`, `units`, `boxes` y `tax_categories`: los
     *    selectores.
     *  - `stores`: las tiendas activas, con la del contexto marcada.
     *  - `store` y `currency`: la tienda del contexto y su moneda.
     */
    public function show(Request $request, Product $product): Response
    {
        $escenario = $this->escenario($request);

        return Inertia::render('catalog/form', [
            'product' => $this->productProps($product, $escenario['store'], $escenario['context']),
            ...$this->selectores($escenario['context']),
            'stores' => $this->storesProps($escenario['store']),
            'store' => $this->storeProps($escenario['store']),
            'currency' => $escenario['currency'],
        ]);
    }

    /**
     * La EDICION de un producto.
     *
     * Guarda el maestro y el precio de la TIENDA ACTIVA. El precio de otra
     * tienda NO se toca: es de su tienda, y se cambia desde su tienda (o con
     * la API de precios).
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        $datos = $this->validar($request, $product);
        $escenario = $this->escenario($request);
        $store = $escenario['store'];

        $this->connection->transaction(function () use ($datos, $product, $store): void {
            $ficha = ProductStore::query()
                ->where('product_id', $product->id)
                ->where('store_id', $store->id)
                ->first();

            $product->fill($this->camposDelMaestro($datos));

            // La CATEGORIA FISCAL: se guarda donde MANDA. Si la tienda tiene
            // un override, el override es el que cambia; si no, cambia la
            // del producto, y las demas tiendas la siguen.
            if ($ficha === null || $ficha->tax_category_id === null) {
                $product->tax_category_id = $datos['tax_category_id'] ?? null;
            }

            $product->save();

            $this->aplicaPrecios($product, $store, $datos, $ficha);
        });

        return redirect()->route('catalogo.show', $product->id);
    }

    /**
     * Los PRECIOS del producto por tienda.
     *
     * Es la API que necesita la caja: el precio de SU tienda, que el
     * catalogo edita. Responde JSON con una fila por tienda activa.
     */
    public function prices(Request $request, Product $product): JsonResponse
    {
        $escenario = $this->escenario($request);

        return response()->json([
            'product_id' => (int) $product->id,
            'store' => $this->storeProps($escenario['store']),
            'currency' => $escenario['currency'],
            'prices' => $this->precios($product, $escenario['store']),
        ]);
    }

    /**
     * GUARDA el precio de UNA tienda.
     *
     * A diferencia de la ficha, esta API si puede cambiar el precio de una
     * tienda que no es la del contexto: es la via administrativa de poner
     * precios. Lo que NO hace es tocar el maestro del producto.
     */
    public function updatePrices(Request $request, Product $product): JsonResponse
    {
        $datos = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'sell_price' => ['required', 'decimal:0,4', 'min:0'],
            'purchase_price' => ['nullable', 'decimal:0,4', 'min:0'],
            'tax_category_id' => ['nullable', 'integer', 'exists:tax_categories,id'],
        ]);

        $ficha = ProductStore::query()
            ->where('product_id', $product->id)
            ->where('store_id', (int) $datos['store_id'])
            ->first();

        if ($ficha === null) {
            $ficha = new ProductStore;
            $ficha->product_id = $product->id;
            $ficha->store_id = (int) $datos['store_id'];
            // El STOCK no se escribe: es el cache de los lotes.
            $ficha->status = 'active';
            $ficha->sort_order = 0;
        }

        $ficha->sell_price = PosAmount::declare($datos['sell_price'], 'precio de venta');
        $ficha->purchase_price = PosAmount::declare($datos['purchase_price'] ?? '0', 'precio de compra');

        // El override de la categoria fiscal SOLO se cambia si viene en la
        // peticion: un guardado de precios no borra el tratamiento fiscal de
        // la tienda.
        if (array_key_exists('tax_category_id', $datos)) {
            $ficha->tax_category_id = $datos['tax_category_id'];
        }

        $ficha->save();

        return response()->json([
            'product_id' => (int) $product->id,
            'store_id' => (int) $ficha->store_id,
            'sell_price' => (string) $ficha->sell_price,
            'purchase_price' => (string) $ficha->purchase_price,
            'tax_category_id' => $ficha->tax_category_id,
        ]);
    }

    /**
     * El ESCENARIO de la peticion: la tienda activa y su moneda.
     *
     * Sin tienda activa o sin moneda el catalogo NO abre: no se sirve una
     * pantalla de una tienda que no existe.
     *
     * @return array{context: PosContext, store: Store, currency: array<string, mixed>}
     */
    private function escenario(Request $request): array
    {
        $context = $this->context($request);

        try {
            $store = $context->store();
            $currency = $context->currency();
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }

        return ['context' => $context, 'store' => $store, 'currency' => $currency];
    }

    /**
     * El CONTEXTO de la peticion.
     *
     * Se crea aqui y no en el constructor: el controlador puede sobrevivir a
     * una peticion, y un contexto guardado en la instancia seria el contexto
     * de la peticion ANTERIOR.
     */
    private function context(Request $request): PosContext
    {
        return new PosContext($this->connection, $request->user()?->id);
    }

    /**
     * VALIDA la entrada del catalogo.
     *
     * REGLA que fija este metodo: *el codigo es UNIQUE y obligatorio, y el
     * nombre es obligatorio*. El resto de los campos del maestro son
     * opcionales, y el STOCK NO SE VALIDA: no se edita a mano.
     *
     * @param  Product|null  $producto  El producto que se edita, para que su
     *                                  propio codigo no choque consigo mismo.
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Product $producto = null): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'code')->ignore($producto?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', Rule::in(['product', 'service'])],
            'is_active' => ['required', 'boolean'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'box_id' => ['nullable', 'integer', 'exists:boxes,id'],
            'tax_category_id' => ['nullable', 'integer', 'exists:tax_categories,id'],
            'sell_price' => ['required', 'decimal:0,4', 'min:0'],
            'purchase_price' => ['nullable', 'decimal:0,4', 'min:0'],
        ]);
    }

    /**
     * Los CAMPOS del maestro, tal y como se escriben.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function camposDelMaestro(array $datos): array
    {
        return [
            'code' => trim((string) $datos['code']),
            'name' => trim((string) $datos['name']),
            'description' => $datos['description'] ?? null,
            'type' => (string) $datos['type'],
            'is_active' => (bool) $datos['is_active'],
            'category_id' => $datos['category_id'] ?? null,
            'brand_id' => $datos['brand_id'] ?? null,
            'unit_id' => $datos['unit_id'] ?? null,
            'box_id' => $datos['box_id'] ?? null,
        ];
    }

    /**
     * APLICA el precio de la TIENDA ACTIVA y su override fiscal.
     *
     * El precio se guarda en la ficha de ESA tienda. El de las demas no se
     * toca: es de su tienda.
     *
     * @param  array<string, mixed>  $datos
     */
    private function aplicaPrecios(
        Product $producto,
        Store $tienda,
        array $datos,
        ?ProductStore $ficha = null
    ): void {
        if ($ficha === null) {
            $ficha = ProductStore::query()
                ->where('product_id', $producto->id)
                ->where('store_id', $tienda->id)
                ->first();
        }

        if ($ficha === null) {
            $ficha = new ProductStore;
            $ficha->product_id = $producto->id;
            $ficha->store_id = $tienda->id;
            // El STOCK no se escribe: es el cache de los lotes.
            $ficha->status = 'active';
            $ficha->sort_order = 0;
        }

        $ficha->sell_price = PosAmount::declare($datos['sell_price'], 'precio de venta');
        $ficha->purchase_price = PosAmount::declare($datos['purchase_price'] ?? '0', 'precio de compra');

        // El OVERRIDE de la categoria fiscal de la tienda manda sobre la del
        // producto: si la tienda tiene uno, se cambia el override, y no la
        // categoria del maestro.
        if ($ficha->tax_category_id !== null) {
            $ficha->tax_category_id = $datos['tax_category_id'] ?? null;
        }

        $ficha->save();
    }

    /**
     * Los FILTROS de la peticion, ya normalizados.
     *
     * @return array{q: string, category_id: int|null, status: string|null}
     */
    private function filtros(Request $request): array
    {
        $query = $request->query('q');
        $categoryId = $request->query('category_id');
        $status = $request->query('status');

        return [
            'q' => is_string($query) ? $query : '',
            'category_id' => is_numeric($categoryId) ? (int) $categoryId : null,
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : null,
        ];
    }

    /**
     * La PAGINA de la peticion.
     */
    private function pagina(Request $request): int
    {
        $page = $request->query('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }

    /**
     * El LISTADO: los productos del maestro con el precio y el stock de la
     * tienda activa.
     *
     * @param  array{q: string, category_id: int|null, status: string|null}  $filtros
     * @return array{products: list<array<string, mixed>>, pagination: array<string, int>}
     */
    private function listado(int $storeId, array $filtros, int $page): array
    {
        $total = $this->consultaListado($storeId, $filtros)->count('products.id');

        $rows = $this->consultaListado($storeId, $filtros)
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->forPage($page, self::PER_PAGE)
            ->selectRaw(
                'products.id as id, '
                .'products.code as code, '
                .'products.name as name, '
                .'products.type as type, '
                .'products.is_active as is_active, '
                .'categories.name as category, '
                .'product_stores.sell_price as sell_price, '
                .'COALESCE(lots.stock, 0) as stock'
            )
            ->get();

        $products = [];

        foreach ($rows as $row) {
            $products[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'category' => $row->category === null ? null : (string) $row->category,
                'sell_price' => $this->importe($row->sell_price),
                'quantity_in_stock' => $this->importe($row->stock),
                'type' => (string) $row->type,
                'is_active' => (bool) $row->is_active,
            ];
        }

        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        return [
            'products' => $products,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    /**
     * La CONSULTA del listado, con los filtros aplicados.
     *
     * @param  array{q: string, category_id: int|null, status: string|null}  $filtros
     */
    private function consultaListado(int $storeId, array $filtros)
    {
        // El STOCK REAL: la suma de los lotes disponibles del producto en la
        // tienda. El cache (`product_stores.quantity_in_stock`) lo mantiene el
        // dominio, pero la verdad son los lotes.
        $lotes = $this->connection->table('inventory_lots')
            ->selectRaw('product_id, store_id, SUM(available) AS stock')
            ->where('store_id', $storeId)
            ->groupBy('product_id', 'store_id');

        $consulta = $this->connection->table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('product_stores', function (JoinClause $join) use ($storeId): void {
                $join->on('product_stores.product_id', '=', 'products.id')
                    ->where('product_stores.store_id', '=', $storeId);
            })
            ->leftJoinSub($lotes, 'lots', function (JoinClause $join): void {
                $join->on('lots.product_id', '=', 'product_stores.product_id')
                    ->on('lots.store_id', '=', 'product_stores.store_id');
            });

        $query = trim($filtros['q']);

        if ($query !== '') {
            $patron = $this->patronPrefijo($query);

            $consulta->where(function ($filtro) use ($patron): void {
                // Por CODIGO y por NOMBRE, como PREFIJO: escribir `50%` no
                // devuelve medio catalogo, porque el comodin va escapado.
                $filtro->whereRaw('UPPER(products.code) LIKE ?', [$patron])
                    ->orWhereRaw('UPPER(products.name) LIKE ?', [$patron]);
            });
        }

        if ($filtros['category_id'] !== null) {
            $consulta->where('products.category_id', $filtros['category_id']);
        }

        if ($filtros['status'] === 'active') {
            $consulta->where('products.is_active', true);
        } elseif ($filtros['status'] === 'inactive') {
            $consulta->where('products.is_active', false);
        }

        return $consulta;
    }

    /**
     * El PATRON del `LIKE`: el termino en mayusculas y con los comodines
     * escapados.
     *
     * REGLA que fija este metodo: *el escape es el de POR DEFECTO de
     * PostgreSQL, la barra invertida, y no un `ESCAPE` explicito*.
     *
     * El motivo es TECNICO y verificado: el analizador de PDO cuenta los
     * parametros de la sentencia, y `ESCAPE '\'` le engaña (la barra
     * invertida parece escapar el cierre de la cadena). Una sentencia con
     * DOS `LIKE ... ESCAPE '\'` se prepara con menos parametros de los que
     * lleva y el error es "Invalid parameter number". Con el escape por
     * defecto, la barra invertida viaja en el VALOR del parametro y no en el
     * texto de la sentencia, y el patron se comporta igual.
     *
     * Un termino de busqueda es DATO del usuario, no un patron: sin escapar,
     * escribir `50%` devolveria medio catalogo.
     */
    private function patronPrefijo(string $termino): string
    {
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            mb_strtoupper($termino)
        );

        return $escaped.'%';
    }

    /**
     * El PRODUCTO de la ficha, con su stock y sus precios por tienda.
     *
     * @return array<string, mixed>
     */
    private function productProps(Product $product, Store $tienda, PosContext $context): array
    {
        $ficha = ProductStore::query()
            ->where('product_id', $product->id)
            ->where('store_id', $tienda->id)
            ->first();

        $categoriaProducto = $product->tax_category_id;
        $categoriaTienda = $ficha?->tax_category_id;

        // La que MANDA: el override de la tienda si lo hay, si no la del
        // producto.
        $categoriaEfectiva = $categoriaTienda ?? $categoriaProducto;

        return [
            'id' => (int) $product->id,
            'code' => (string) $product->code,
            'name' => (string) $product->name,
            'description' => $product->description,
            'type' => (string) $product->type,
            'is_active' => (bool) $product->is_active,
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'unit_id' => $product->unit_id,
            'box_id' => $product->box_id,
            'tax_category_id' => $categoriaProducto,
            'tax_category_name' => $this->nombreCategoria($categoriaProducto),
            'store_tax_category_id' => $categoriaTienda,
            'store_tax_category_name' => $this->nombreCategoria($categoriaTienda),
            'effective_tax_category_id' => $categoriaEfectiva,
            'effective_tax_category_name' => $this->nombreCategoria($categoriaEfectiva),
            'missing_tax_category' => $categoriaEfectiva === null,
            // El STOCK REAL: la suma de los lotes. Se MUESTRA, pero no se
            // edita: su lugar es la compra o el ajuste.
            'quantity_in_stock' => $this->stockDe((int) $product->id, (int) $tienda->id),
            'prices' => $this->precios($product, $tienda),
        ];
    }

    /**
     * Los PRECIOS del producto, una fila por tienda activa.
     *
     * @return list<array<string, mixed>>
     */
    private function precios(Product $product, Store $tiendaActiva): array
    {
        $fichas = ProductStore::query()
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('store_id');

        $stockPorTienda = $this->connection->table('inventory_lots')
            ->selectRaw('store_id, SUM(available) AS stock')
            ->where('product_id', $product->id)
            ->groupBy('store_id')
            ->pluck('stock', 'store_id');

        $precios = [];

        foreach ($this->tiendasActivas() as $tienda) {
            $ficha = $fichas->get($tienda->id);

            $precios[] = [
                'store_id' => (int) $tienda->id,
                'store_name' => (string) $tienda->name,
                'store_code_name' => $tienda->code_name,
                'is_active_store' => (int) $tienda->id === (int) $tiendaActiva->id,
                'sell_price' => $ficha === null ? '0.0000' : $this->importe($ficha->sell_price),
                'purchase_price' => $ficha === null ? '0.0000' : $this->importe($ficha->purchase_price),
                'quantity_in_stock' => $this->importe($stockPorTienda->get($tienda->id)),
                'tax_category_id' => $ficha?->tax_category_id,
                'tax_category_name' => $this->nombreCategoria($ficha?->tax_category_id),
            ];
        }

        return $precios;
    }

    /**
     * El STOCK real de un producto en una tienda: la suma de los lotes.
     */
    private function stockDe(int $productId, int $storeId): string
    {
        $stock = $this->connection->table('inventory_lots')
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->sum('available');

        return $this->importe($stock);
    }

    /**
     * El NOMBRE de una categoria fiscal.
     */
    private function nombreCategoria(?int $categoriaId): ?string
    {
        if ($categoriaId === null) {
            return null;
        }

        $nombre = TaxCategory::query()->where('id', $categoriaId)->value('name');

        return $nombre === null ? null : (string) $nombre;
    }

    /**
     * Los SELECTORES: lo que hay en el catalogo maestro.
     *
     * Las categorias fiscales son POR PERFIL FISCAL, y se ofrecen las del
     * perfil de la tienda: una categoria de otro pais no es un tratamiento
     * fiscal de esta tienda.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function selectores(PosContext $context): array
    {
        $perfilId = $this->perfilFiscal($context);

        $categorias = $this->connection->table('tax_categories')
            ->leftJoin('fiscal_profiles', 'fiscal_profiles.id', '=', 'tax_categories.fiscal_profile_id')
            ->when(
                $perfilId !== null,
                fn ($consulta) => $consulta->where('tax_categories.fiscal_profile_id', $perfilId)
            )
            ->orderBy('tax_categories.fiscal_profile_id')
            ->orderBy('tax_categories.code')
            ->get([
                'tax_categories.id as id',
                'tax_categories.code as code',
                'tax_categories.name as name',
                'fiscal_profiles.code as profile_code',
            ]);

        return [
            'categories' => $this->opciones(Category::query()->orderBy('name')->get(['id', 'name'])),
            'brands' => $this->opciones(Brand::query()->orderBy('name')->get(['id', 'name'])),
            'units' => $this->opciones(Unit::query()->orderBy('name')->get(['id', 'name'])),
            'boxes' => $this->opciones(Box::query()->orderBy('name')->get(['id', 'name'])),
            'tax_categories' => $categorias->map(fn ($row): array => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'profile_code' => $row->profile_code === null ? null : (string) $row->profile_code,
            ])->values()->all(),
        ];
    }

    /**
     * El PERFIL FISCAL de la tienda: el de su serie de documento.
     */
    private function perfilFiscal(PosContext $context): ?int
    {
        $serieId = $context->documentSeriesId();

        if ($serieId === null) {
            return null;
        }

        $perfilId = $this->connection->table('fiscal_series')
            ->where('id', $serieId)
            ->value('fiscal_profile_id');

        return $perfilId === null ? null : (int) $perfilId;
    }

    /**
     * Las OPCIONES de un selector del maestro.
     *
     * @param  Collection<int, object>  $filas
     * @return list<array{id: int, name: string}>
     */
    private function opciones($filas): array
    {
        return $filas->map(fn ($fila): array => [
            'id' => (int) $fila->id,
            'name' => (string) $fila->name,
        ])->values()->all();
    }

    /**
     * Las TIENDAS ACTIVAS: las que pueden tener ficha y precio.
     *
     * @return list<Store>
     */
    private function tiendasActivas(): array
    {
        return Store::query()
            ->where('status', true)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Las tiendas de la ficha, con la del contexto marcada.
     *
     * @return list<array<string, mixed>>
     */
    private function storesProps(Store $tiendaActiva): array
    {
        return array_map(fn (Store $tienda): array => [
            'id' => (int) $tienda->id,
            'name' => (string) $tienda->name,
            'code_name' => $tienda->code_name,
            'is_active_store' => (int) $tienda->id === (int) $tiendaActiva->id,
        ], $this->tiendasActivas());
    }

    /**
     * La TIENDA del contexto, para la pantalla.
     *
     * @return array{id: int, name: string, code_name: string|null}
     */
    private function storeProps(Store $tienda): array
    {
        return [
            'id' => (int) $tienda->id,
            'name' => (string) $tienda->name,
            'code_name' => $tienda->code_name,
        ];
    }

    /**
     * Un IMPORTE como texto decimal de cuatro decimales.
     *
     * Un `null` es CERO: una tienda sin ficha tiene el precio a cero, no un
     * precio desconocido.
     */
    private function importe(mixed $valor): string
    {
        if ($valor === null) {
            return '0.0000';
        }

        return PosAmount::text($valor);
    }
}
