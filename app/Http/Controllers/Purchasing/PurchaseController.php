<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\PurchaseService;
use App\Domain\Purchasing\SupplierService;
use App\Http\Controllers\StoreContextController;
use App\Support\Pos\PosAmount;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Las COMPRAS de la tienda: la pagina, el alta, la anulacion y la
 * devolucion al proveedor.
 *
 * REGLA que fija esta clase: *la pagina es Inertia y las escrituras son
 * JSON*, como en el resto del repositorio. Y el stock NUNCA se escribe a
 * mano: entra con la compra, por sus lotes, y el cache se refresca desde
 * ellos.
 *
 * Y LA COMPRA NO ES UN GASTO: es existencias y deuda con el proveedor. El
 * dominio decide que cuentas se cargan y se acreditan, y aqui no se replica
 * esa aritmetica.
 *
 * Y LA COMPRA NO SE BORRA: se anula (con su motivo) o se devuelve al
 * proveedor. Las dos son HECHOS, con su asiento y con su auditoria.
 */
class PurchaseController extends StoreContextController
{
    /**
     * Cuantas compras devuelve la lista.
     *
     * Es un tope, no una paginacion: la lista es el RECENTRO de la tienda, y
     * una pagina que trae cincuenta filas es la que se lee.
     */
    private const LIMITE = 50;

    /**
     * Cuantos productos trae el formulario de la compra.
     *
     * Es el catalogo de la tienda, y el formulario lo filtra. El limite
     * evita que la pantalla cargue el catalogo entero de un negocio grande.
     */
    private const LIMITE_PRODUCTOS = 200;

    public function __construct(
        Connection $connection,
        private readonly PurchaseService $purchases,
        private readonly SupplierService $suppliers,
    ) {
        parent::__construct($connection);
    }

    /**
     * La PAGINA de las compras.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/purchasing/purchases.tsx`:
     *
     *  - `purchases`: lista de `{ id, purchase_date, invoice_no, reference_no,
     *    status, total, supplier, cancellation_reason, cancelled_at,
     *    entry_written }`. Los importes son TEXTO decimal.
     *  - `suppliers`: los proveedores de la tienda con su saldo.
     *  - `products`: el catalogo de la tienda, con su precio de compra.
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

        return Inertia::render('purchasing/purchases', [
            'purchases' => $this->compras((int) $tienda->id),
            'suppliers' => $this->proveedores((int) $tienda->id),
            'products' => $this->productos((int) $tienda->id),
            'currency' => $contexto->currency(),
            'business_date' => $contexto->businessDate()->format('Y-m-d'),
            'store' => $this->propsDeTienda($tienda),
        ]);
    }

    /**
     * El ALTA de una compra.
     *
     * Responde 201 con `{ id, total, entry_written, entry_id, warning }`: el
     * `warning` viene cuando la tienda no tiene las cuentas que el asiento
     * necesita. La compra esta escrita igual: el hecho no se bloquea, y lo
     * que se avisa es la contabilidad.
     *
     * Y SI LA COMPRA NO SE PUEDE REGISTRAR, no se registra: el fallo del
     * dominio (una linea sin cantidad, un proveedor sin ficha, un producto
     * que no esta en la tienda) es un 422 con su mensaje, y no queda ni la
     * compra ni sus lotes.
     */
    public function store(Request $request): JsonResponse
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
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        try {
            $resultado = $this->purchases->register(
                storeId: (int) $tienda->id,
                supplierId: (int) $datos['supplier_id'],
                invoiceNo: $this->texto($datos['invoice_no'] ?? null),
                referenceNo: $this->texto($datos['reference_no'] ?? null),
                businessDate: $negocio,
                occurredAt: $contexto->issuedAt(),
                lineas: $this->lineas($datos['lines'] ?? []),
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        $this->avisaElAsiento($request, $resultado->warning, (int) $tienda->id, $resultado->purchaseId);

        // La AUDITORIA: quien registro la compra, que proveedor, que
        // mercaderia y cuanto debe la tienda.
        $this->auditar(
            $request,
            'purchase.created',
            'purchase',
            $resultado->purchaseId,
            [
                'supplier_id' => (int) $datos['supplier_id'],
                'invoice_no' => $this->texto($datos['invoice_no'] ?? null),
                'total' => $resultado->total,
                'business_date' => $negocio->format('Y-m-d'),
                'lines' => count($datos['lines'] ?? []),
                'entry_written' => $resultado->entryWritten,
                'entry_id' => $resultado->entryId,
                'warning' => $resultado->warning,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $resultado->purchaseId,
            'total' => $resultado->total,
            'entry_written' => $resultado->entryWritten,
            'entry_id' => $resultado->entryId,
            'warning' => $resultado->warning,
        ], 201);
    }

    /**
     * La ANULACION de una compra: la marca y revierte el stock de TODAS sus
     * lineas.
     *
     * Y EL MOTIVO es OBLIGATORIO: una compra anulada sin motivo es una compra
     * que nadie sabe por que se toco.
     */
    public function cancel(Request $request, int $purchase): JsonResponse
    {
        $motivo = trim((string) ($request->all()['reason'] ?? ''));

        if ($motivo === '') {
            return response()->json([
                'errorMsg' => 'Una compra se anula con un MOTIVO: una compra anulada sin motivo es una compra '
                    .'que nadie sabe por que se toco.',
            ], 422);
        }

        try {
            $tienda = $this->tienda($request);
            $this->comprasDeLaTienda($tienda->id, $purchase);

            $resultado = $this->purchases->cancel($purchase, $motivo, $request->user()?->id);
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        $this->avisaElAsiento($request, $resultado->warning, (int) $tienda->id, $purchase);

        $this->auditar(
            $request,
            'purchase.cancelled',
            'purchase',
            $purchase,
            [
                'reason' => $motivo,
                'entry_written' => $resultado->entryWritten,
                'entry_id' => $resultado->entryId,
                'warning' => $resultado->warning,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $purchase,
            'status' => 'cancelled',
            'cancellation_reason' => $motivo,
            'entry_written' => $resultado->entryWritten,
            'entry_id' => $resultado->entryId,
            'warning' => $resultado->warning,
        ]);
    }

    /**
     * La DEVOLUCION DE MERCADERIA al proveedor: la mercaderia sale de la
     * tienda y la deuda baja.
     */
    public function returnToSupplier(Request $request, int $purchase): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validarDevolucion($datos);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $tienda = $this->tienda($request);
            $contexto = $this->contexto($request);
            $negocio = $contexto->businessDate();

            $this->comprasDeLaTienda($tienda->id, $purchase);

            $resultado = $this->purchases->returnToSupplier(
                purchaseId: $purchase,
                lineas: $this->lineasDevueltas($datos['lines'] ?? []),
                businessDate: $negocio,
                occurredAt: $contexto->issuedAt(),
                referenceNo: $this->texto($datos['reference_no'] ?? null),
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        $this->avisaElAsiento($request, $resultado->warning, (int) $tienda->id, $purchase);

        $this->auditar(
            $request,
            'purchase.returned',
            'purchase',
            $purchase,
            [
                'return_id' => $resultado->returnId,
                'total' => $resultado->total,
                'business_date' => $negocio->format('Y-m-d'),
                'entry_written' => $resultado->entryWritten,
                'entry_id' => $resultado->entryId,
                'warning' => $resultado->warning,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $purchase,
            'return_id' => $resultado->returnId,
            'total' => $resultado->total,
            'entry_written' => $resultado->entryWritten,
            'entry_id' => $resultado->entryId,
            'warning' => $resultado->warning,
        ], 201);
    }

    /**
     * La compra es de la TIENDA: una compra de otra tienda no se ve, ni se
     * anula, ni se devuelve.
     *
     * @throws RuntimeException Cuando la compra no es de la tienda.
     */
    private function comprasDeLaTienda(int $storeId, int $purchaseId): void
    {
        $existe = $this->connection->table('purchases')
            ->where('id', $purchaseId)
            ->where('store_id', $storeId)
            ->exists();

        if ($existe) {
            return;
        }

        throw new RuntimeException("La compra {$purchaseId} no existe en esta tienda.");
    }

    /**
     * El AVISO de un asiento que no se pudo escribir: queda en el log, para
     * que el hueco no sea silencioso.
     */
    private function avisaElAsiento(Request $request, ?string $warning, int $storeId, int $purchaseId): void
    {
        if ($warning === null) {
            return;
        }

        Log::warning('compra sin asiento contable: la tienda no tiene las cuentas', [
            'store_id' => $storeId,
            'purchase_id' => $purchaseId,
            'user_id' => $request->user()?->id,
            'warning' => $warning,
        ]);
    }

    /**
     * Las COMPRAS de la tienda, las mas recientes primero.
     *
     * @return list<array<string, mixed>>
     */
    private function compras(int $storeId): array
    {
        $filas = $this->connection->table('purchases')
            ->join('purchase_totals', 'purchase_totals.purchase_id', '=', 'purchases.id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.store_id', $storeId)
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchases.id')
            ->limit(self::LIMITE)
            ->get([
                'purchases.id as id',
                'purchases.purchase_date as purchase_date',
                'purchases.invoice_no as invoice_no',
                'purchases.reference_no as reference_no',
                'purchases.status as status',
                'purchases.supplier_id as supplier_id',
                'purchases.cancellation_reason as cancellation_reason',
                'purchases.cancelled_at as cancelled_at',
                'purchase_totals.grand_total as total',
                'suppliers.name as supplier_name',
            ]);

        // El ASIENTO de cada compra: la compra que no tiene asiento en el
        // libro es una compra que la contabilidad no ve, y hay que decirlo.
        $conAsiento = $this->connection->table('ledger_entries')
            ->where('reference_type', 'purchase')
            ->where('store_id', $storeId)
            ->pluck('reference_id')
            ->all();

        $compras = [];

        foreach ($filas as $fila) {
            $compras[] = [
                'id' => (int) $fila->id,
                'purchase_date' => (string) $fila->purchase_date,
                'invoice_no' => $fila->invoice_no === null ? null : (string) $fila->invoice_no,
                'reference_no' => $fila->reference_no === null ? null : (string) $fila->reference_no,
                'status' => (string) $fila->status,
                'total' => PosAmount::text($fila->total),
                'cancellation_reason' => $fila->cancellation_reason === null
                    ? null
                    : (string) $fila->cancellation_reason,
                'cancelled_at' => $fila->cancelled_at === null
                    ? null
                    : Carbon::parse((string) $fila->cancelled_at)->toIso8601String(),
                'entry_written' => in_array((int) $fila->id, array_map('intval', $conAsiento), true),
                'supplier' => $fila->supplier_id === null ? null : [
                    'id' => (int) $fila->supplier_id,
                    'name' => (string) ($fila->supplier_name ?? ''),
                ],
            ];
        }

        return $compras;
    }

    /**
     * Los PROVEEDORES de la tienda, con su saldo derivado.
     *
     * @return list<array{id: int, name: string, balance: string}>
     */
    private function proveedores(int $storeId): array
    {
        $filas = $this->connection->table('suppliers')
            ->join('supplier_stores', 'supplier_stores.supplier_id', '=', 'suppliers.id')
            ->where('supplier_stores.store_id', $storeId)
            ->where('supplier_stores.status', 'active')
            ->where('suppliers.is_active', true)
            ->orderBy('suppliers.name')
            ->get(['suppliers.id as id', 'suppliers.name as name']);

        $proveedores = [];

        foreach ($filas as $fila) {
            $proveedores[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                // El saldo es DERIVADO de los hechos del proveedor (sus
                // compras y sus devoluciones), no un contador.
                'balance' => $this->suppliers->balance((int) $fila->id, $storeId),
            ];
        }

        return $proveedores;
    }

    /**
     * El CATALOGO de la tienda, para el formulario de la compra.
     *
     * @return list<array{id: int, code: string, name: string, purchase_price: string}>
     */
    private function productos(int $storeId): array
    {
        $filas = $this->connection->table('product_stores')
            ->join('products', 'products.id', '=', 'product_stores.product_id')
            ->where('product_stores.store_id', $storeId)
            ->where('product_stores.status', 'active')
            ->orderBy('products.name')
            ->limit(self::LIMITE_PRODUCTOS)
            ->get([
                'products.id as id',
                'products.code as code',
                'products.name as name',
                'product_stores.purchase_price as purchase_price',
            ]);

        $productos = [];

        foreach ($filas as $fila) {
            $productos[] = [
                'id' => (int) $fila->id,
                'code' => (string) $fila->code,
                'name' => (string) $fila->name,
                'purchase_price' => PosAmount::text($fila->purchase_price),
            ];
        }

        return $productos;
    }

    /**
     * Las LINEAS de la compra, como las espera el dominio.
     *
     * @return list<array{product_id: int, quantity: string, unit_price: string, discount: string}>
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
                'product_id' => (int) ($linea['product_id'] ?? 0),
                'quantity' => (string) ($linea['quantity'] ?? '0'),
                'unit_price' => (string) ($linea['unit_price'] ?? '0'),
                'discount' => (string) ($linea['discount'] ?? '0'),
            ];
        }

        return $preparadas;
    }

    /**
     * Las LINEAS de la devolucion, como las espera el dominio.
     *
     * @return list<array{purchase_item_id: int, quantity: string}>
     */
    private function lineasDevueltas(mixed $lineas): array
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
                'purchase_item_id' => (int) ($linea['purchase_item_id'] ?? 0),
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
     * REGLA que fija este metodo: *la FORMA la comprueba la costura y el
     * CONTENIDO el dominio*. El proveedor tiene que estar, y las lineas
     * tienen que traer producto, cantidad y precio.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validar(array $datos): ?string
    {
        $validador = Validator::make($datos, [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'invoice_no' => ['nullable', 'string', 'max:100'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'string', 'decimal:0,4'],
            'lines.*.unit_price' => ['required', 'string', 'decimal:0,4'],
            'lines.*.discount' => ['nullable', 'string', 'decimal:0,4'],
        ]);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }

    /**
     * VALIDA la FORMA de la entrada de la devolucion.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validarDevolucion(array $datos): ?string
    {
        $validador = Validator::make($datos, [
            'reference_no' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'string', 'decimal:0,4'],
        ]);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }
}
