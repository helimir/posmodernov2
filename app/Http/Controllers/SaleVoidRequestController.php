<?php

namespace App\Http\Controllers;

use App\Domain\Sales\SaleVoidRequests;
use App\Models\Store;
use App\Models\User;
use App\Support\Audit\AuditWriter;
use App\Support\Fiscal\StoreAccess;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * La SOLICITUD de anulacion: el cajero PIDE que le anulen, quien puede
 * anular ANULA, y la peticion tiene que poder TERMINAR.
 *
 * REGLA que fija esta clase: *anular una venta es una SUPERVISION, y quien
 * la hizo no la deshace*. El cajero que cobro deja la peticion con su
 * MOTIVO, y quien tiene `sales.void` la ejecuta con el endpoint de la
 * anulacion (`SaleVoidController`).
 *
 * Y UNA PETICION QUE NO PUEDE TERMINAR NO ES UNA PETICION: sin salida, la
 * cola se queda pendiente para siempre y el cajero no puede retirar la
 * suya. Por eso la peticion tiene TRES finales, y son los tres del
 * dominio:
 *
 *  - SE ANULA (lo hace quien tiene `sales.void`, con `SaleVoidController`):
 *    la venta queda anulada, y esa peticion esta ATENDIDA;
 *  - SE RECHAZA (lo hace quien tiene `sales.void`, con su MOTIVO): es el
 *    "no lo hago", y el cajero lo ve en la ficha de su venta con el motivo,
 *    quien lo rechazo y cuando;
 *  - SE CANCELA (lo hace quien la PIDIO, con su MOTIVO): es el "ya no la
 *    pido", y nadie puede cancelar la peticion de otro.
 *
 * Y EL ESTADO DE LA PETICION NO ES EL DE LA VENTA: rechazar NO es anular.
 * Rechazar es un hecho de la peticion; anular es un hecho de la venta, con
 * su nota de credito y su correlativo.
 *
 * Y EL HISTORIAL ES EL MODELO: una peticion son FILAS, no columnas de la
 * cabecera de la venta. Una venta puede tener una peticion rechazada y otra
 * nueva, y las dos existen: el cajero puede tener razon la segunda vez, y la
 * primera no se pierde.
 *
 * Y LA TIENDA ES LA DEL CONTEXTO: la misma que usa la caja, resuelta del
 * contexto de la sesion y nunca del cuerpo de la peticion. Una peticion de
 * otra tienda no existe para quien pregunta: se responde 404, no 403.
 */
class SaleVoidRequestController extends Controller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly StoreAccess $access,
        private readonly SaleVoidRequests $requests,
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
     *  - `requests`: lista de `{ request_id, sale_id, invoice_no, sale_date,
     *    customer, grand_total, reason, requested_at, requested_by }`, donde
     *    `requested_by` es `{ id, name }` o `null`.
     *  - `store`: la tienda activa, la misma que usa la caja.
     */
    public function index(Request $request): Response
    {
        $tienda = $this->tienda($request);

        return Inertia::render('sales/void-requests', [
            'requests' => $this->requests->queue((int) $tienda->id),
            'store' => $this->storeProps($tienda),
        ]);
    }

    /**
     * La SOLICITUD de anulacion de una venta, con su MOTIVO.
     *
     * Responde 201 con `{ sale_id, request_id, status, reason, requested_at,
     * requested_by }`: el `status` es `pending`, porque la venta sigue en
     * pie (pedir no es anular).
     *
     * Y LA SOLICITUD EXIGE UN MOTIVO: sin motivo no hay solicitud, porque
     * una solicitud sin motivo es una solicitud que nadie sabe por que se
     * hizo. Y el motivo escrito es el que llevara la anulacion.
     *
     * Y UNA VENTA TIENE UNA SOLICITUD PENDIENTE, no una solicitud: la
     * segunda no pisa la primera (el motivo del primero es el que explica la
     * anulacion). Pero una peticion CERRADA (rechazada o cancelada) no
     * bloquea la venta: el cajero puede pedir la anulacion otra vez, con un
     * motivo nuevo, y la peticion anterior se queda en el historial.
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
        //
        // Y UNA PETICION CERRADA NO BLOQUEA: el cajero puede tener razon la
        // segunda vez, y la peticion anterior se queda en el historial.
        if ($this->requests->pending($sale) !== null) {
            return response()->json([
                'errorMsg' => 'La venta '.$sale.' ya tiene una solicitud de anulacion pendiente: '
                    .'no se puede pedir dos veces.',
            ], 422);
        }

        $actorId = $request->user()?->id;

        $pedidaEl = now();

        try {
            $requestId = (int) $this->connection->table('sale_void_requests')->insertGetId([
                'sale_id' => $sale,
                'store_id' => (int) $venta->store_id,
                'status' => 'pending',
                'reason' => $motivo,
                'requested_by' => $actorId,
                'requested_at' => $pedidaEl,
                'created_at' => $pedidaEl,
                'updated_at' => $pedidaEl,
            ]);
        } catch (QueryException $fallo) {
            // DOS PETICIONES A LA VEZ: el esquema dice que una venta tiene
            // UNA solicitud pendiente, y el indice unico parcial lo
            // prohibe. Una peticion que llega mientras la otra se escribe no
            // es un fallo del servidor: es la misma regla de siempre, la de
            // que no se pide dos veces.
            if ($this->esViolacionDeUnicidad($fallo)) {
                return response()->json([
                    'errorMsg' => 'La venta '.$sale.' ya tiene una solicitud de anulacion pendiente: '
                        .'no se puede pedir dos veces.',
                ], 422);
            }

            throw $fallo;
        }

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
                'request_id' => $requestId,
            ],
            (int) $venta->store_id
        );

        return response()->json([
            'sale_id' => (int) $sale,
            'request_id' => $requestId,
            'status' => 'pending',
            'reason' => $motivo,
            'requested_at' => $pedidaEl->toIso8601String(),
            'requested_by' => $this->responsable($actorId),
        ], 201);
    }

    /**
     * El RECHAZO de una solicitud de anulacion, con su MOTIVO.
     *
     * Responde 200 con `{ sale_id, request_id, status, reason,
     * rejection_reason, requested_at, requested_by, rejected_at,
     * rejected_by }`: el `status` es `rejected`, y el `reason` es el de la
     * PETICION (la voz de quien pidio, que el rechazo no reescribe).
     *
     * Y RECHAZAR NO ES ANULAR: la venta sigue en pie, sin nota de credito y
     * sin tocar su estado. El rechazo es un "no lo hago" y la anulacion es
     * un "lo hago": son hechos distintos, con estados distintos.
     *
     * Y EL RECHAZO EXIGE UN MOTIVO: un "no" sin motivo no le dice nada a
     * quien pidio la anulacion, y una peticion que se cierra sin
     * explicacion no se puede defender despues.
     *
     * Y SOLO SE RECHAZA UNA VEZ, Y SOLO LO QUE ESTA PENDIENTE: el rechazo
     * es un HECHO, y un hecho repetido no es una segunda opinion. Y una
     * venta ya anulada no tiene nada que rechazar.
     */
    public function rechazar(Request $request, int $sale): JsonResponse
    {
        $datos = $request->all();

        $motivo = trim((string) ($datos['reason'] ?? ''));

        if ($motivo === '') {
            return response()->json([
                'errorMsg' => 'Una solicitud se rechaza con un MOTIVO: '
                    .'un rechazo sin motivo no le dice nada a quien la pidio.',
            ], 422);
        }

        $venta = $this->connection->table('sales')->where('id', $sale)->first();

        abort_if($venta === null, 404);

        abort_if(
            ! $this->access->allows($request->user()?->id, (int) $venta->store_id),
            404
        );

        // UNA VENTA ANULADA NO TIENE NADA QUE RECHAZAR: la anulacion es un
        // HECHO, y la peticion ya no espera respuesta.
        if ((string) $venta->status === 'cancelled') {
            return response()->json([
                'errorMsg' => 'La venta '.$sale.' ya esta anulada: no hay nada que rechazar.',
            ], 422);
        }

        $solicitud = $this->requests->pending($sale);

        if ($solicitud === null) {
            return response()->json([
                'errorMsg' => 'La venta '.$sale.' no tiene ninguna solicitud de anulacion pendiente: '
                    .'no hay nada que rechazar.',
            ], 422);
        }

        $actorId = $request->user()?->id;

        $rechazadaEl = now();

        // EL RECHAZO ES UN HECHO Y SE ESCRIBE UNA VEZ: la escritura lleva
        // el estado, asi que dos rechazos a la vez no pueden pasar los dos.
        $afectadas = $this->connection->table('sale_void_requests')
            ->where('id', (int) $solicitud->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'rejected_by' => $actorId,
                'rejected_at' => $rechazadaEl,
                'rejection_reason' => $motivo,
                'updated_at' => $rechazadaEl,
            ]);

        if ($afectadas === 0) {
            return response()->json([
                'errorMsg' => 'La solicitud de anulacion de la venta '.$sale.' ya no esta pendiente: '
                    .'no se rechaza dos veces.',
            ], 422);
        }

        $solicitante = $this->responsable(
            $solicitud->requested_by === null ? null : (int) $solicitud->requested_by,
        );

        // LA AUDITORIA: el rechazo, con quien pidio, por que pidio, que
        // venta era, cuando se pidio, cuando se rechazo y por que se
        // rechazo. Un rechazo sin rastro es un rechazo que nadie puede
        // defender despues.
        (new AuditWriter($this->connection))->recordFor(
            $request,
            'sale.void_request_rejected',
            'sale',
            (int) $sale,
            [
                'request_id' => (int) $solicitud->id,
                'invoice_no' => (string) ($venta->invoice_no ?? ''),
                'requested_by' => $solicitante,
                'request_reason' => (string) $solicitud->reason,
                'requested_at' => $this->instanteDeLaFila($solicitud->requested_at),
                'rejected_by' => $this->responsable($actorId),
                'rejected_at' => $rechazadaEl->toIso8601String(),
                'rejection_reason' => $motivo,
            ],
            (int) $venta->store_id
        );

        return response()->json([
            'sale_id' => (int) $sale,
            'request_id' => (int) $solicitud->id,
            'status' => 'rejected',
            'reason' => (string) $solicitud->reason,
            'rejection_reason' => $motivo,
            'requested_at' => $this->instanteDeLaFila($solicitud->requested_at),
            'requested_by' => $solicitante,
            'rejected_at' => $rechazadaEl->toIso8601String(),
            'rejected_by' => $this->responsable($actorId),
        ]);
    }

    /**
     * La CANCELACION de una solicitud de anulacion, con su MOTIVO.
     *
     * Responde 200 con `{ sale_id, request_id, status, reason,
     * cancellation_reason, requested_at, requested_by, cancelled_at,
     * cancelled_by }`.
     *
     * Y SOLO QUIEN PIDIO CANCELA LA SUYA: el permiso de pedir es el de
     * retirar la peticion, pero la FILA dice de quien es. Nadie cancela la
     * peticion de otro: una peticion retirada en nombre de otro es una
     * peticion que el otro no hizo.
     *
     * Y LA CANCELACION EXIGE UN MOTIVO: una peticion que se retira sin
     * explicacion no dice por que se retiro.
     *
     * Y CANCELAR NO ES ANULAR: la venta sigue en pie, sin nota de credito y
     * sin tocar su estado.
     */
    public function cancelar(Request $request, int $sale): JsonResponse
    {
        $datos = $request->all();

        $motivo = trim((string) ($datos['reason'] ?? ''));

        if ($motivo === '') {
            return response()->json([
                'errorMsg' => 'Una solicitud se cancela con un MOTIVO: '
                    .'una peticion que se retira sin explicacion no dice por que se retira.',
            ], 422);
        }

        $venta = $this->connection->table('sales')->where('id', $sale)->first();

        abort_if($venta === null, 404);

        abort_if(
            ! $this->access->allows($request->user()?->id, (int) $venta->store_id),
            404
        );

        $solicitud = $this->requests->pending($sale);

        if ($solicitud === null) {
            return response()->json([
                'errorMsg' => 'La venta '.$sale.' no tiene ninguna solicitud de anulacion pendiente: '
                    .'no hay nada que cancelar.',
            ], 422);
        }

        $actorId = $request->user()?->id;

        // SOLO QUIEN PIDIO: la peticion es de quien la pidio.
        if ($solicitud->requested_by === null || (int) $solicitud->requested_by !== (int) $actorId) {
            return response()->json([
                'errorMsg' => 'La solicitud de anulacion de la venta '.$sale.' no es tuya: '
                    .'solo quien la pidio puede retirar su peticion.',
            ], 422);
        }

        $canceladaEl = now();

        // LA CANCELACION ES UN HECHO Y SE ESCRIBE UNA VEZ.
        $afectadas = $this->connection->table('sale_void_requests')
            ->where('id', (int) $solicitud->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'cancelled_by' => $actorId,
                'cancelled_at' => $canceladaEl,
                'cancellation_reason' => $motivo,
                'updated_at' => $canceladaEl,
            ]);

        if ($afectadas === 0) {
            return response()->json([
                'errorMsg' => 'La solicitud de anulacion de la venta '.$sale.' ya no esta pendiente: '
                    .'no se cancela dos veces.',
            ], 422);
        }

        $solicitante = $this->responsable(
            $solicitud->requested_by === null ? null : (int) $solicitud->requested_by,
        );

        // LA AUDITORIA: la cancelacion, con quien pidio, por que pidio, que
        // venta era, cuando se pidio, cuando se cancelo, quien la cancelo y
        // por que se cancelo.
        (new AuditWriter($this->connection))->recordFor(
            $request,
            'sale.void_request_cancelled',
            'sale',
            (int) $sale,
            [
                'request_id' => (int) $solicitud->id,
                'invoice_no' => (string) ($venta->invoice_no ?? ''),
                'requested_by' => $solicitante,
                'request_reason' => (string) $solicitud->reason,
                'requested_at' => $this->instanteDeLaFila($solicitud->requested_at),
                'cancelled_by' => $this->responsable($actorId),
                'cancelled_at' => $canceladaEl->toIso8601String(),
                'cancellation_reason' => $motivo,
            ],
            (int) $venta->store_id
        );

        return response()->json([
            'sale_id' => (int) $sale,
            'request_id' => (int) $solicitud->id,
            'status' => 'cancelled',
            'reason' => (string) $solicitud->reason,
            'cancellation_reason' => $motivo,
            'requested_at' => $this->instanteDeLaFila($solicitud->requested_at),
            'requested_by' => $solicitante,
            'cancelled_at' => $canceladaEl->toIso8601String(),
            'cancelled_by' => $this->responsable($actorId),
        ]);
    }

    /**
     * ¿El fallo es una VIOLACION DE UNICIDAD?
     *
     * REGLA que fija este metodo: *el esquema prohibe dos solicitudes
     * pendientes de la misma venta, y esa prohibicion es la que convierte una
     * peticion duplicada en un 422 y no en un error del servidor*. El
     * SQLSTATE de PostgreSQL para la unicidad violada es `23505`.
     */
    private function esViolacionDeUnicidad(QueryException $fallo): bool
    {
        return $fallo->getCode() === '23505';
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
     * Un RESPONSABLE: quien pidio, quien rechazo o quien cancelo.
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
