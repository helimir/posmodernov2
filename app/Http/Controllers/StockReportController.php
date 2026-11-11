<?php

namespace App\Http\Controllers;

use App\Support\Pos\PosAmount;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * El REPORTE DE STOCK BAJO: los productos de la tienda que se estan acabando.
 *
 * REGLA que fija esta clase: *el reporte mira el stock REAL*. La verdad del
 * stock son los LOTES (`inventory_lots.available`), no el cache
 * `product_stores.quantity_in_stock`: el cache es una copia, y una copia
 * puede estar desfasada. Un reporte que ensena la copia es un reporte que
 * puede no avisar de lo que se acaba.
 *
 * LAS OTRAS REGLAS:
 *
 *  - SOLO productos ACTIVOS y con FICHA en la tienda activa: un producto que
 *    no se vende en esa tienda no tiene nada que reponer alli, y el stock de
 *    otra tienda es de otra tienda.
 *  - Un producto SIN LOTES tiene stock CERO, y APARECE: lo que no tiene
 *    stock no se puede vender, y esconderlo seria un fallo del reporte.
 *  - Un SERVICIO no tiene almacen: se vende sin stock, asi que no entra. Un
 *    servicio no se repone.
 *  - El ORDEN es por stock ASCENDENTE: lo mas urgente primero.
 *  - El UMBRAL sale de la configuracion (`config/pos.php`), no de un literal
 *    de la pantalla.
 *
 * Los importes viajan como TEXTO decimal (`'25.0000'`), nunca como `float`.
 */
class StockReportController extends Controller
{
    /**
     * Cuantos productos trae cada pagina.
     */
    private const PER_PAGE = 50;

    public function __construct(private readonly Connection $connection) {}

    /**
     * El REPORTE de stock bajo.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/reports/stock.tsx`:
     *
     *  - `products`: lista de `{ id, code, name, sell_price, stock }`. El
     *    stock es el REAL (la suma de los lotes) y el precio es el de la
     *    TIENDA ACTIVA. Los importes son TEXTO decimal.
     *  - `pagination`: `{ page, per_page, total, last_page }`.
     *  - `threshold`: el umbral de la configuracion, en unidades.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     *  - `currency`: `{ code, symbol, symbol_position, decimals,
     *    thousand_separator, decimal_separator }`.
     */
    public function index(Request $request): Response
    {
        $contexto = new PosContext($this->connection, $request->user()?->id);

        try {
            $store = $contexto->store();
            $moneda = $contexto->currency();
        } catch (RuntimeException $fallo) {
            // Sin tienda activa o sin moneda la pagina NO abre: no se sirve
            // una pantalla de una tienda que no existe.
            abort(409, $fallo->getMessage());
        }

        $storeId = (int) $store->id;
        $umbral = max(0, (int) config('pos.low_stock_threshold', 5));
        $page = $this->pagina($request);

        $consulta = $this->consulta($storeId, $umbral);

        $total = $consulta->count('products.id');

        $filas = $consulta
            ->orderByRaw('COALESCE(lots.stock, 0) ASC')
            ->orderBy('products.name')
            ->orderBy('products.id')
            ->forPage($page, self::PER_PAGE)
            ->selectRaw(
                'products.id as id, '
                .'products.code as code, '
                .'products.name as name, '
                .'product_stores.sell_price as sell_price, '
                .'COALESCE(lots.stock, 0) as stock'
            )
            ->get();

        $productos = [];

        foreach ($filas as $fila) {
            $productos[] = [
                'id' => (int) $fila->id,
                'code' => (string) $fila->code,
                'name' => (string) $fila->name,
                'sell_price' => PosAmount::text($fila->sell_price),
                'stock' => PosAmount::text($fila->stock),
            ];
        }

        return Inertia::render('reports/stock', [
            'products' => $productos,
            'pagination' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
            ],
            'threshold' => $umbral,
            'store' => [
                'id' => $storeId,
                'name' => (string) $store->name,
                'code_name' => $store->code_name,
            ],
            'currency' => $moneda,
        ]);
    }

    /**
     * La CONSULTA del reporte: los productos de la tienda con stock por
     * debajo del umbral.
     *
     * El STOCK REAL es una subconsulta agrupada, no una por producto: el
     * reporte se lee de un vistazo y no puede pagar N+1 consultas.
     */
    private function consulta(int $storeId, int $umbral)
    {
        $lotes = $this->connection->table('inventory_lots')
            ->selectRaw('product_id, store_id, SUM(available) AS stock')
            ->where('store_id', $storeId)
            ->groupBy('product_id', 'store_id');

        return $this->connection->table('product_stores')
            ->join('products', 'products.id', '=', 'product_stores.product_id')
            ->leftJoinSub($lotes, 'lots', function (JoinClause $join): void {
                $join->on('lots.product_id', '=', 'product_stores.product_id')
                    ->on('lots.store_id', '=', 'product_stores.store_id');
            })
            ->where('product_stores.store_id', $storeId)
            // La ficha de la tienda esta activa.
            ->where('product_stores.status', 'active')
            // Y el producto del catalogo maestro tambien.
            ->where('products.is_active', true)
            // Un SERVICIO no tiene almacen: no hay nada que reponer.
            ->where('products.type', 'product')
            // Y el stock REAL por debajo del umbral. Un producto sin lotes
            // tiene stock CERO, y entra: lo que no tiene stock no se puede
            // vender.
            ->whereRaw('COALESCE(lots.stock, 0) < ?', [$umbral]);
    }

    /**
     * La PAGINA de la peticion.
     */
    private function pagina(Request $request): int
    {
        $page = $request->query('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }
}
