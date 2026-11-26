<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\SaleReturnService;
use App\Http\Controllers\StoreContextController;
use App\Support\Pos\PosAmount;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Las DEVOLUCIONES DE MERCADERIA a los clientes: la pagina y el alta.
 *
 * REGLA que fija esta clase: *la pagina es Inertia y las escrituras son
 * JSON*, como en el resto del repositorio.
 *
 * Y ESTO NO ES LA ANULACION de la venta: la anulacion (F3-07) es fiscal y
 * contable, y la mercaderia NO vuelve al almacen. Aqui la mercaderia SI
 * vuelve, y por eso el stock se repone, en el LOTE EXACTO del que salio.
 *
 * Y EL REINTEGRO lo registra el libro del cliente (`registerReturn()`): es un
 * reintegro, con su tipo propio, y no un cobro. Y si el cliente queda con un
 * saldo A FAVOR, la tienda le DEBE: ese credito es un pasivo, y sin pasivo el
 * asiento NO se escribe (el hecho no se bloquea, y la contabilidad se avisa).
 */
class SaleReturnController extends StoreContextController
{
    /**
     * Cuantas devoluciones devuelve la lista.
     */
    private const LIMITE = 50;

    /**
     * Cuantas ventas trae el selector de la devolucion.
     */
    private const LIMITE_VENTAS = 50;

    public function __construct(
        Connection $connection,
        private readonly SaleReturnService $returns,
    ) {
        parent::__construct($connection);
    }

    /**
     * La PAGINA de las devoluciones.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/sales/returns.tsx`:
     *
     *  - `returns`: lista de `{ id, sale_id, invoice_no, return_date, total,
     *    product_name, quantity }`. Los importes son TEXTO decimal.
     *  - `sales`: las ventas recientes de la tienda, para elegir de cual se
     *    devuelve la mercaderia.
     *  - `currency`: la moneda de la tienda.
     *  - `business_date`: la fecha de NEGOCIO de la tienda.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     */
    public function index(Request $request): Response
    {
        try {
            $tienda = $this->tienda($request);
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }

        $contexto = $this->contexto($request);

        return Inertia::render('sales/returns', [
            'returns' => $this->devoluciones((int) $tienda->id),
            'sales' => $this->ventas((int) $tienda->id),
            'currency' => $contexto->currency(),
            'business_date' => $contexto->businessDate()->format('Y-m-d'),
            'store' => $this->propsDeTienda($tienda),
        ]);
    }

    /**
     * Las LINEAS de una venta que se pueden devolver.
     *
     * Y CADA LINEA DICE CUANTO SE PUEDE DEVOLVER: lo que se vendio menos lo
     * que ya se devolvio. No se repone mercaderia que no salio.
     */
    public function lines(Request $request, int $sale): JsonResponse
    {
        try {
            $tienda = $this->tienda($request);
            $this->ventaDeLaTienda((int) $tienda->id, $sale);
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        return response()->json([
            'lines' => $this->lineasDeLaVenta($sale),
        ]);
    }

    /**
     * El ALTA de una devolucion.
     *
     * Responde 201 con `{ id, return_id, sale_id, total, balance, credit,
     * entry_written, entry_id, warning }`.
     *
     * Y SI LA DEVOLUCION NO SE PUEDE REGISTRAR, no se registra: el fallo del
     * dominio (una linea que no es de la venta, mas mercaderia de la que
     * salio) es un 422 con su mensaje, y no queda ni la devolucion ni el
     * stock repuesto.
     */
    public function store(Request $request, int $sale): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validar($datos);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $tienda = $this->tienda($request);
            $contexto = $this->contexto($request);
            $negocio = $contexto->businessDate();

            $this->ventaDeLaTienda((int) $tienda->id, $sale);

            $resultado = $this->returns->register(
                storeId: (int) $tienda->id,
                saleId: $sale,
                lineas: $this->lineas($datos['lines'] ?? []),
                businessDate: $negocio,
                occurredAt: $contexto->issuedAt(),
                referenceNo: $this->texto($datos['reference_no'] ?? null),
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        if ($resultado->warning !== null) {
            Log::warning('devolucion sin asiento contable: la tienda no tiene las cuentas', [
                'store_id' => (int) $tienda->id,
                'sale_id' => $sale,
                'user_id' => $request->user()?->id,
                'warning' => $resultado->warning,
            ]);
        }

        $this->auditar(
            $request,
            'sale.returned',
            'sale_return',
            $resultado->returnId,
            [
                'sale_id' => $sale,
                'total' => $resultado->total,
                'balance' => $resultado->balance,
                'credit' => $resultado->credit,
                'business_date' => $negocio->format('Y-m-d'),
                'entry_written' => $resultado->entryWritten,
                'entry_id' => $resultado->entryId,
                'warning' => $resultado->warning,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $resultado->returnId,
            'return_id' => $resultado->returnId,
            'sale_id' => $sale,
            'total' => $resultado->total,
            'balance' => $resultado->balance,
            'credit' => $resultado->credit,
            'entry_written' => $resultado->entryWritten,
            'entry_id' => $resultado->entryId,
            'warning' => $resultado->warning,
        ], 201);
    }

    /**
     * La venta es de la TIENDA: una venta de otra tienda no se ve, ni se
     * devuelve.
     *
     * @throws RuntimeException Cuando la venta no es de la tienda.
     */
    private function ventaDeLaTienda(int $storeId, int $saleId): void
    {
        $existe = $this->connection->table('sales')
            ->where('id', $saleId)
            ->where('store_id', $storeId)
            ->exists();

        if ($existe) {
            return;
        }

        throw new RuntimeException("La venta {$saleId} no existe en esta tienda.");
    }

    /**
     * Las DEVOLUCIONES de la tienda, las mas recientes primero.
     *
     * @return list<array<string, mixed>>
     */
    private function devoluciones(int $storeId): array
    {
        $filas = $this->connection->table('sale_returns')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->leftJoin('sale_return_items', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
            ->leftJoin('products', 'products.id', '=', 'sale_return_items.product_id')
            ->where('sale_returns.store_id', $storeId)
            ->orderByDesc('sale_returns.return_date')
            ->orderByDesc('sale_returns.id')
            ->limit(self::LIMITE)
            ->get([
                'sale_returns.id as id',
                'sale_returns.sale_id as sale_id',
                'sale_returns.return_date as return_date',
                'sale_returns.reference_no as reference_no',
                'sale_returns.total as total',
                'sales.invoice_no as invoice_no',
                'products.name as product_name',
                'sale_return_items.quantity as quantity',
            ]);

        $devoluciones = [];

        foreach ($filas as $fila) {
            $devoluciones[] = [
                'id' => (int) $fila->id,
                'sale_id' => (int) $fila->sale_id,
                'invoice_no' => $fila->invoice_no === null ? null : (string) $fila->invoice_no,
                'reference_no' => $fila->reference_no === null ? null : (string) $fila->reference_no,
                'return_date' => (string) $fila->return_date,
                'total' => PosAmount::text($fila->total),
                'product_name' => $fila->product_name === null ? null : (string) $fila->product_name,
                'quantity' => $fila->quantity === null ? null : PosAmount::text($fila->quantity),
            ];
        }

        return $devoluciones;
    }

    /**
     * Las VENTAS recientes de la tienda, para elegir de cual se devuelve la
     * mercaderia.
     *
     * @return list<array{id: int, invoice_no: string|null, sale_date: string, total: string}>
     */
    private function ventas(int $storeId): array
    {
        $filas = $this->connection->table('sales')
            ->join('sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', '!=', 'cancelled')
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->limit(self::LIMITE_VENTAS)
            ->get([
                'sales.id as id',
                'sales.invoice_no as invoice_no',
                'sales.sale_date as sale_date',
                'sale_totals.grand_total as total',
            ]);

        $ventas = [];

        foreach ($filas as $fila) {
            $ventas[] = [
                'id' => (int) $fila->id,
                'invoice_no' => $fila->invoice_no === null ? null : (string) $fila->invoice_no,
                'sale_date' => (string) $fila->sale_date,
                'total' => PosAmount::text($fila->total),
            ];
        }

        return $ventas;
    }

    /**
     * Las LINEAS de una venta, con lo que se puede devolver de cada una.
     *
     * @return list<array<string, mixed>>
     */
    private function lineasDeLaVenta(int $saleId): array
    {
        $filas = $this->connection->table('sale_items')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sale_items.sale_id', $saleId)
            ->orderBy('sale_items.id')
            ->get([
                'sale_items.id as id',
                'sale_items.quantity as quantity',
                'sale_items.unit_price as unit_price',
                'sale_items.base_amount as base_amount',
                'sale_items.tax_amount as tax_amount',
                'sale_items.line_total as line_total',
                'products.name as product_name',
                'products.code as product_code',
            ]);

        $devueltas = $this->connection->table('sale_return_items')
            ->whereIn('sale_item_id', $filas->isEmpty() ? [0] : $filas->pluck('id')->all())
            ->groupBy('sale_item_id')
            ->selectRaw('sale_item_id, SUM(quantity) as returned')
            ->get()
            ->keyBy('sale_item_id');

        $lineas = [];

        foreach ($filas as $fila) {
            $vendido = PosAmount::text($fila->quantity);
            $devuelto = $devueltas->get((int) $fila->id) === null
                ? '0.0000'
                : PosAmount::text($devueltas->get((int) $fila->id)->returned);

            $disponible = bccomp($vendido, $devuelto, 4) > 0
                ? bcsub($vendido, $devuelto, 4)
                : '0.0000';

            $lineas[] = [
                'id' => (int) $fila->id,
                'product_name' => (string) $fila->product_name,
                'product_code' => (string) $fila->product_code,
                'quantity' => $vendido,
                'returned' => $devuelto,
                'available' => $disponible,
                'unit_price' => PosAmount::text($fila->unit_price),
                'base_amount' => PosAmount::text($fila->base_amount),
                'tax_amount' => PosAmount::text($fila->tax_amount),
                'line_total' => PosAmount::text($fila->line_total),
            ];
        }

        return $lineas;
    }

    /**
     * Las LINEAS de la devolucion, como las espera el dominio.
     *
     * @return list<array{sale_item_id: int, quantity: string}>
     */
    private function lineas(mixed $lineas): array
    {
        if (! is_array($lineas)) {
            return [];
        }

        $preparadas = [];

        foreach ($lineas as $linea) {
            if (! is_array($linea)) {
                continue;
            }

            $preparadas[] = [
                'sale_item_id' => (int) ($linea['sale_item_id'] ?? 0),
                'quantity' => (string) ($linea['quantity'] ?? '0'),
            ];
        }

        return $preparadas;
    }

    /**
     * Un TEXTO de la peticion, o `null` si no hay.
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * VALIDA la FORMA de la entrada del alta.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validar(array $datos): ?string
    {
        $validador = Validator::make($datos, [
            'reference_no' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'string', 'decimal:0,4'],
        ]);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }
}
