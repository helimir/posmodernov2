<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Support\Audit\AuditWriter;
use App\Support\Fiscal\StoreAccess;
use App\Support\Pos\PosAmount;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * La SOLICITUD de anulacion: el cajero PIDE que le anulen y quien puede
 * anular ANULA.
 *
 * REGLA que fija esta clase: *anular una venta es una SUPERVISION, y quien
 * la hizo no la deshace*. El cajero que cobro deja la peticion con su
 * MOTIVO, y quien tiene `sales.void` la ejecuta con el endpoint de la
 * anulacion (`SaleVoidController`).
 *
 * Y NO HAY UN TRAMITE DE DOS PASOS: no existe un estado "aprobada". La
 * solicitud esta PENDIENTE mientras la venta sigue en pie, y esta HECHA
 * cuando la venta queda anulada. Lo que hay entre medias no es una
 * aprobacion: es una espera.
 *
 * Y EL MOTIVO DE LA SOLICITUD es el motivo de la ANULACION: quien pide
 * explico por que, y esa explicacion es la que queda escrita en la nota de
 * credito y en la auditoria.
 *
 * Y LA TIENDA ES LA DEL CONTEXTO: la misma que usa la caja, resuelta del
 * contexto de la sesion y nunca del cuerpo de la peticion. Una solicitud de
 * otra tienda no existe para quien pregunta: se responde 404, no 403.
 */
class SaleVoidRequestController extends Controller
{
    /**
     * Cuantas solicitudes mira la pantalla como maximo.
     *
     * El tope esta en el SERVIDOR: un cliente que pide la cola no decide
     * cuanto se le da.
     */
    private const TAMANO_DE_LA_LISTA = 200;

    public function __construct(
        private readonly Connection $connection,
        private readonly StoreAccess $access,
    ) {}

    /**
     * La PANTALLA de las solicitudes pendientes de anulacion.
     *
     * ES LA MESA DE TRABAJO de quien puede anular: por eso la protege su
     * permiso (`sales.void`), y por eso solo enseña las PENDIENTES.
     *
     * EL CONTRATO de los props es el de
     * `resources/js/pages/sales/void-requests.tsx`:
     *
     *  - `requests`: lista de `{ sale_id, invoice_no, sale_date, customer,
     *    grand_total, reason, requested_at, requested_by }`, donde
     *    `requested_by` es `{ id, name }` o `null`.
     *  - `store`: la tienda activa, la misma que usa la caja.
     */
    public function index(Request $request): Response
    {
        $tienda = $this->tienda($request);

        return Inertia::render('sales/void-requests', [
            'requests' => $this->solicitudes((int) $tienda->id),
            'store' => $this->storeProps($tienda),
        ]);
    }

    /**
     * La SOLICITUD de anulacion de una venta, con su MOTIVO.
     *
     * Responde 201 con `{ sale_id, status, reason, requested_at,
     * requested_by }`: el `status` es `pending`, porque la venta sigue en
     * pie (pedir no es anular).
     *
     * Y LA SOLICITUD EXIGE UN MOTIVO: sin motivo no hay solicitud, porque
     * una solicitud sin motivo es una solicitud que nadie sabe por que se
     * hizo. Y el motivo escrito es el que llevara la anulacion.
     */
    public function solicitar(Request $request, int $sale): JsonResponse
    {
        $datos = $request->all();

        $motivo = trim((string) ($datos['reason'] ?? ''));

        if ($motivo === '') {
            return response()->json([
                'errorMsg' => 'Una venta se pide para anular con un MOTIVO: '
                    .'una solicitud sin motivo es una solicitud que nadie sabe por que se hizo.',
            ], 422);
        }

        // LA VENTA. Una venta que no existe, o de una tienda a la que el
        // usuario no tiene acceso, es un 404: no se le dice que existe.
        $venta = $this->connection->table('sales')->where('id', $sale)->first();

        abort_if($venta === null, 404);

        abort_if(
            ! $this->access->allows($request->user()?->id, (int) $venta->store_id),
            404
        );

        // UNA VENTA ANULADA NO ADMITE UNA SOLICITUD: la anulacion es un
        // HECHO, y un hecho no se vuelve a pedir.
        if ((string) $venta->status === 'cancelled') {
            return response()->json([
                'errorMsg' => 'La venta '.$sale.' ya esta anulada: no hay nada que pedir.',
            ], 422);
        }

        // UNA VENTA TIENE UNA SOLICITUD PENDIENTE: la segunda no pisa la
        // primera. El motivo del primero es el que explica la anulacion, y
        // pisarlo seria dejar la anulacion sin explicacion.
        if (($venta->void_requested_at ?? null) !== null) {
            return response()->json([
                'errorMsg' => 'La venta '.$sale.' ya tiene una solicitud de anulacion pendiente: '
                    .'no se puede pedir dos veces.',
            ], 422);
        }

        $actorId = $request->user()?->id;

        $pedidaEl = now();

        $this->connection->table('sales')->where('id', $sale)->update([
            'void_requested_at' => $pedidaEl,
            'void_request_reason' => $motivo,
            'void_requested_by' => $actorId,
            'updated_at' => $pedidaEl,
        ]);

        // LA AUDITORIA: quien pidio la anulacion, de que venta, en que
        // tienda y por que. Una solicitud sin rastro es una solicitud que
        // nadie puede defender despues.
        (new AuditWriter($this->connection))->recordFor(
            $request,
            'sale.void_requested',
            'sale',
            (int) $sale,
            [
                'reason' => $motivo,
                'invoice_no' => (string) ($venta->invoice_no ?? ''),
                'requested_at' => $pedidaEl->toIso8601String(),
            ],
            (int) $venta->store_id
        );

        return response()->json([
            'sale_id' => (int) $sale,
            'status' => 'pending',
            'reason' => $motivo,
            'requested_at' => $pedidaEl->toIso8601String(),
            'requested_by' => $this->responsable($actorId),
        ], 201);
    }

    /**
     * La TIENDA del contexto: la misma que usa la caja.
     */
    private function tienda(Request $request): Store
    {
        $contexto = new PosContext($this->connection, $request->user()?->id);

        try {
            return $contexto->store();
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }
    }

    /**
     * Las SOLICITUDES PENDIENTES de la tienda, con quien las pidio y por que.
     *
     * Y LA FILTRACION ES DE LA BASE, NO DE LA PANTALLA: lo que no llega no se
     * puede enseñar. Y solo las PENDIENTES: una venta ya anulada no esta
     * pendiente de nada.
     *
     * @return list<array<string, mixed>>
     */
    private function solicitudes(int $storeId): array
    {
        $filas = $this->connection->table('sales')
            ->leftJoin('sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('users', 'users.id', '=', 'sales.void_requested_by')
            ->where('sales.store_id', $storeId)
            ->whereNotNull('sales.void_requested_at')
            ->where('sales.status', '!=', 'cancelled')
            ->orderBy('sales.void_requested_at')
            ->orderBy('sales.id')
            ->limit(self::TAMANO_DE_LA_LISTA)
            ->get([
                'sales.id as id',
                'sales.invoice_no as invoice_no',
                'sales.sale_date as sale_date',
                'sales.customer_id as customer_id',
                'sales.void_requested_at as requested_at',
                'sales.void_request_reason as reason',
                'sales.void_requested_by as requested_by',
                'sale_totals.grand_total as grand_total',
                'customers.name as customer_name',
                'users.name as requested_by_name',
            ]);

        $solicitudes = [];

        foreach ($filas as $fila) {
            $solicitudes[] = [
                'sale_id' => (int) $fila->id,
                'invoice_no' => $fila->invoice_no === null ? null : (string) $fila->invoice_no,
                'sale_date' => $fila->sale_date === null ? null : (string) $fila->sale_date,
                'customer' => $fila->customer_name === null ? null : (string) $fila->customer_name,
                'grand_total' => $fila->grand_total === null ? null : PosAmount::text($fila->grand_total),
                // EL MOTIVO: el que escribio quien pidio.
                'reason' => $fila->reason === null ? null : (string) $fila->reason,
                'requested_at' => $this->instanteDeLaFila($fila->requested_at),
                'requested_by' => $this->responsable(
                    $fila->requested_by === null ? null : (int) $fila->requested_by,
                    $fila->requested_by_name === null ? null : (string) $fila->requested_by_name
                ),
            ];
        }

        return $solicitudes;
    }

    /**
     * Un RESPONSABLE: quien pidio la anulacion.
     *
     * Y SI NO SE SABE, EL HUECO SE VE: la columna queda en `null` y la
     * pantalla ensena un hueco. No se inventa un valor.
     *
     * @return array{id: int, name: string}|null
     */
    private function responsable(?int $userId, ?string $nombre = null): ?array
    {
        if ($userId === null) {
            return null;
        }

        if ($nombre === null) {
            $usuario = User::query()->find($userId);

            $nombre = $usuario === null ? null : (string) $usuario->name;
        }

        if ($nombre === null) {
            return null;
        }

        return [
            'id' => $userId,
            'name' => $nombre,
        ];
    }

    /**
     * Un INSTANTE de la base, en ISO 8601.
     */
    private function instanteDeLaFila(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return Carbon::parse((string) $valor)->toIso8601String();
    }

    /**
     * La TIENDA activa, para la pantalla.
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
}
