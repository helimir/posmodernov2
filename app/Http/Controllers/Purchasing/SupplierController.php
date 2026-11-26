<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\SupplierService;
use App\Http\Controllers\StoreContextController;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Los PROVEEDORES de la tienda: la pagina y el alta.
 *
 * REGLA que fija esta clase: *la pagina es Inertia y las escrituras son
 * JSON*, como en el resto del repositorio.
 *
 * Y EL SALDO del proveedor es DERIVADO de sus hechos (sus compras y sus
 * devoluciones), no un contador que se escribe a mano: el dominio lo deriva y
 * el cache de la ficha de tienda lo refresca en la misma transaccion del
 * hecho.
 */
class SupplierController extends StoreContextController
{
    public function __construct(
        Connection $connection,
        private readonly SupplierService $suppliers,
    ) {
        parent::__construct($connection);
    }

    /**
     * La PAGINA de los proveedores.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/purchasing/suppliers.tsx`:
     *
     *  - `suppliers`: lista de `{ id, name, email, phone, tax_id, balance }`.
     *    Los importes son TEXTO decimal.
     *  - `currency`: la moneda de la tienda.
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

        return Inertia::render('purchasing/suppliers', [
            'suppliers' => $this->proveedores((int) $tienda->id),
            'currency' => $contexto->currency(),
            'business_date' => $contexto->businessDate()->format('Y-m-d'),
            'store' => $this->propsDeTienda($tienda),
        ]);
    }

    /**
     * El ALTA de un proveedor.
     *
     * Responde 201 con `{ id, name, balance }`.
     *
     * Y SI EL PROVEEDOR NO SE PUEDE DAR DE ALTA, no se da de alta: el fallo
     * del dominio (un proveedor sin nombre) es un 422 con su mensaje.
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

            $supplierId = $this->suppliers->register((int) $tienda->id, $datos);
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        // La AUDITORIA: quien dio de alta el proveedor y con que datos.
        $this->auditar(
            $request,
            'supplier.created',
            'supplier',
            $supplierId,
            [
                'name' => trim((string) ($datos['name'] ?? '')),
                'tax_id' => $datos['tax_id'] ?? null,
                'email' => $datos['email'] ?? null,
                'phone' => $datos['phone'] ?? null,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $supplierId,
            'name' => trim((string) ($datos['name'] ?? '')),
            'balance' => $this->suppliers->balance($supplierId, (int) $tienda->id),
        ], 201);
    }

    /**
     * Los PROVEEDORES de la tienda, con su saldo derivado.
     *
     * @return list<array{id: int, name: string, email: string|null, phone: string|null, tax_id: string|null, balance: string}>
     */
    private function proveedores(int $storeId): array
    {
        $filas = $this->connection->table('suppliers')
            ->join('supplier_stores', 'supplier_stores.supplier_id', '=', 'suppliers.id')
            ->where('supplier_stores.store_id', $storeId)
            ->where('supplier_stores.status', 'active')
            ->orderBy('suppliers.name')
            ->get([
                'suppliers.id as id',
                'suppliers.name as name',
                'suppliers.email as email',
                'suppliers.phone as phone',
                'suppliers.tax_id as tax_id',
            ]);

        $proveedores = [];

        foreach ($filas as $fila) {
            $proveedores[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                'email' => $fila->email === null ? null : (string) $fila->email,
                'phone' => $fila->phone === null ? null : (string) $fila->phone,
                'tax_id' => $fila->tax_id === null ? null : (string) $fila->tax_id,
                'balance' => $this->suppliers->balance((int) $fila->id, $storeId),
            ];
        }

        return $proveedores;
    }

    /**
     * VALIDA la FORMA de la entrada del alta.
     *
     * REGLA que fija este metodo: *la FORMA la comprueba la costura y el
     * CONTENIDO el dominio*. El nombre es obligatorio; el resto de los datos
     * del proveedor son opcionales.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validar(array $datos): ?string
    {
        $validador = Validator::make($datos, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'country_id' => ['nullable', 'integer'],
        ]);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }
}
