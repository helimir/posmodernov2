<?php

namespace App\Support\Pos;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;

/**
 * El CATALOGO de la caja: los productos que se pueden vender en una tienda.
 *
 * REGLA que fija esta clase: *el catalogo es de UNA tienda y el stock que
 * enseña es el REAL*. Son dos reglas distintas y las dos importan:
 *
 *  1. Un producto pertenece a la caja si tiene FICHA en esa tienda
 *     (`product_stores`): su precio de venta y su estado son POR TIENDA. Un
 *     producto del catalogo maestro que no esta en la tienda no se vende
 *     aqui, aunque exista en otra.
 *  2. El stock que se enseña es la SUMA DE LOS LOTES disponibles, no el
 *     cache `product_stores.quantity_in_stock`. El cache tiene una
 *     invariante que mantiene el dominio, pero la verdad son los lotes
 *     (`StockInvariant`): enseñar el cache es enseñar una copia, y una copia
 *     puede estar desfasada.
 *
 * La busqueda es la del legacy (`PRODUCTLIST`) con dos reglas: por NOMBRE
 * como PREFIJO (en mayusculas) y por CODIGO EXACTO. Un escaneo de codigo de
 * barras no acepta un prefijo: el codigo es el codigo.
 */
final readonly class PosCatalog
{
    public function __construct(private Connection $connection) {}

    /**
     * Los productos de la tienda, con su precio y su stock.
     *
     * @param  int  $storeId  La tienda cuyo catalogo se lee.
     * @param  string|null  $query  Lo que se busca; `null` o vacio devuelve
     *                              el catalogo entero.
     * @param  int  $page  La pagina, empezando por 1.
     * @param  int  $pageSize  Cuantos productos trae cada pagina.
     * @return array{products: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function products(int $storeId, ?string $query, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);

        $total = $this->filteredQuery($storeId, $query)->count();

        $rows = $this->filteredQuery($storeId, $query)
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->forPage($page, $pageSize)
            ->get();

        $products = [];

        foreach ($rows as $row) {
            $products[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'sell_price' => PosAmount::text($row->sell_price),
                'quantity_in_stock' => PosAmount::text($row->stock),
                'unit_name' => $row->unit_name === null ? null : (string) $row->unit_name,
            ];
        }

        return [
            'products' => $products,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * La consulta del catalogo CON el filtro de busqueda aplicado.
     */
    private function filteredQuery(int $storeId, ?string $query)
    {
        $base = $this->baseQuery($storeId);

        if ($query === null || trim($query) === '') {
            // Sin busqueda no se filtra: el catalogo entero.
            return $base;
        }

        $termino = trim($query);

        return $base->where(function ($filtro) use ($termino): void {
            // Por NOMBRE, como prefijo.
            $filtro->whereRaw(
                "UPPER(products.name) LIKE ? ESCAPE '\\'",
                [$this->prefixPattern($termino)]
            )
            // Por CODIGO, exacto: no como prefijo.
                ->orWhereRaw('UPPER(products.code) = ?', [mb_strtoupper($termino)]);
        });
    }

    /**
     * La consulta base del catalogo: los productos con ficha en la tienda,
     * activos y con stock real (o servicios, que no tienen almacen).
     */
    private function baseQuery(int $storeId)
    {
        // El stock REAL: la suma de los lotes disponibles del producto en la
        // tienda. Es una subconsulta agrupada, no una por producto: la caja
        // lee el catalogo de un vistazo y no puede pagar N+1 consultas.
        $lotes = $this->connection->table('inventory_lots')
            ->selectRaw('product_id, store_id, SUM(available) AS stock')
            ->where('store_id', $storeId)
            ->groupBy('product_id', 'store_id');

        return $this->connection->table('product_stores')
            ->join('products', 'products.id', '=', 'product_stores.product_id')
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->leftJoinSub($lotes, 'lots', function (JoinClause $join): void {
                $join->on('lots.product_id', '=', 'product_stores.product_id')
                    ->on('lots.store_id', '=', 'product_stores.store_id');
            })
            ->where('product_stores.store_id', $storeId)
            // La ficha de la tienda esta activa.
            ->where('product_stores.status', 'active')
            // Y el producto del catalogo maestro tambien.
            ->where('products.is_active', true)
            ->where(function ($filtro): void {
                // Un SERVICIO no tiene almacen: se vende sin stock.
                $filtro->where('products.type', 'service')
                    ->orWhereRaw('COALESCE(lots.stock, 0) > 0');
            })
            ->selectRaw(
                'products.id as id, '
                .'products.code as code, '
                .'products.name as name, '
                .'products.type as type, '
                .'product_stores.sell_price as sell_price, '
                .'COALESCE(lots.stock, 0) as stock, '
                .'units.name as unit_name'
            );
    }

    /**
     * El patron de busqueda por nombre: el termino en mayusculas y con los
     * comodines del `LIKE` escapados.
     *
     * Un termino de busqueda es DATO del usuario, no un patron: sin escapar,
     * escribir `50%` en el buscador devolveria medio catalogo.
     */
    private function prefixPattern(string $termino): string
    {
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            mb_strtoupper($termino)
        );

        return $escaped.'%';
    }
}
