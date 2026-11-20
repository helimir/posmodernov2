<?php

namespace App\Http\Controllers;

use App\Models\PrintJob;
use App\Models\Store;
use App\Print\PrintJobReprinter;
use App\Print\PrintJobStateMachine;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * La COLA de trabajos de impresion de la tienda.
 *
 * REGLA que fija esta clase: *una reimpresion reimprime los MISMOS bytes: no
 * se vuelve a componer el ticket*.
 *
 * Los bytes CONGELADOS son la verdad del ticket: son lo que se recibio en el
 * acuse. Si se recompone, el ticket puede salir DISTINTO del que se emitio, y
 * un comprobante que cambia es un comprobante que miente. Por eso la
 * reimpresion COPIA los bytes del original, y el reintento NO toca los bytes:
 * un trabajo que fallo al ESCRIBIR se reintenta con los MISMOS bytes.
 *
 * Y LA COLA ES POR TIENDA: la pantalla y la API enseñan los trabajos de la
 * tienda del contexto, la misma que usa la caja. Un trabajo de otra tienda no
 * existe para quien pregunta: se responde 404, no 403, porque un 403 le
 * confirmaria al cajero que el trabajo esta ahi.
 *
 * Y LOS ESTADOS LOS MUEVE LA MAQUINA DE ESTADOS: este controlador no escribe
 * un estado a mano. El reintento es `requeue()` y el barrido de leases es
 * `releaseExpiredLeases()`.
 */
class PrintJobQueueController extends Controller
{
    /**
     * Cuantos trabajos mira la pantalla como maximo.
     *
     * La cola es de los ultimos: lo que importa es lo que esta fallido o en
     * curso, no lo que salio hace un ano. Y el tope esta en el SERVIDOR: un
     * cliente que pide la cola no decide cuanto se le da.
     */
    private const TAMANO_DE_LA_COLA = 200;

    public function __construct(
        private readonly Connection $connection,
        private readonly PrintJobReprinter $reprinter,
        private readonly PrintJobStateMachine $machine,
    ) {}

    /**
     * La PANTALLA de la cola de trabajos de la tienda.
     *
     * EL CONTRATO de los props es el de
     * `resources/js/pages/printing/jobs.tsx`:
     *
     *  - `jobs`: lista de `{ id, status, target_id, target_name, transport,
     *    attempts, reason, reprint_of_job_id, last_error, lease_expires_at,
     *    created_at, can_retry, can_reprint }`.
     *  - `store`: la tienda activa, la misma que usa la caja.
     */
    public function jobs(Request $request): Response
    {
        $tienda = $this->tienda($request);

        return Inertia::render('printing/jobs', [
            'jobs' => $this->trabajos((int) $tienda->id),
            'store' => $this->storeProps($tienda),
        ]);
    }

    /**
     * La COLA de trabajos de la tienda activa, en JSON.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): JsonResponse
    {
        $tienda = $this->tienda($request);

        return response()->json([
            'jobs' => $this->trabajos((int) $tienda->id),
            'store' => $this->storeProps($tienda),
        ]);
    }

    /**
     * La REIMPRESION de un trabajo que ya salio.
     *
     * Crea un trabajo NUEVO con los MISMOS bytes y el mismo hash, con
     * `reason = 'reprint'` y el puntero al original. CERO reservas: reimprimir
     * un comprobante NO es emitir otro documento fiscal.
     *
     * Y SOLO UN TRABAJO QUE YA SALIO: un trabajo en cola todavia no ha salido
     * de la impresora, y reimprimirlo duplicaria el ticket.
     */
    public function reprint(Request $request, int $id): JsonResponse
    {
        $tienda = $this->tienda($request);

        $trabajo = $this->trabajoDeLaTienda((int) $tienda->id, $id);

        try {
            $nuevoId = $this->reprinter->reprint((int) $trabajo->id, $request->user()?->id);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $nuevo = PrintJob::find($nuevoId);

        return response()->json([
            'id' => (int) $nuevo->id,
            'reprint_of_job_id' => (int) $nuevo->reprint_of_job_id,
            'reason' => (string) $nuevo->reason,
            'status' => (string) $nuevo->status,
            'payload_sha256' => (string) $nuevo->payload_sha256,
        ], 201);
    }

    /**
     * El REINTENTO de un trabajo que fallo.
     *
     * El trabajo vuelve a la cola y cuenta un intento mas. Y NO VUELVE A
     * COMPONER EL TICKET: los bytes son los congelados.
     */
    public function retry(Request $request, int $id): JsonResponse
    {
        $tienda = $this->tienda($request);

        $trabajo = $this->trabajoDeLaTienda((int) $tienda->id, $id);

        try {
            $this->machine->requeue((int) $trabajo->id);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $reintentado = PrintJob::find((int) $trabajo->id);

        return response()->json([
            'id' => (int) $reintentado->id,
            'status' => (string) $reintentado->status,
            'attempts' => (int) $reintentado->attempts,
        ]);
    }

    /**
     * La TIENDA del contexto: la misma que usa la caja.
     */
    private function tienda(Request $request): Store
    {
        $context = new PosContext($this->connection, $request->user()?->id);

        try {
            return $context->store();
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }
    }

    /**
     * El TRABAJO de la tienda, o 404.
     *
     * REGLA que fija este metodo: *un trabajo de otra tienda no existe*.
     * Un 403 le diria al cajero que el trabajo esta ahi, en el sistema: eso
     * ya es informacion de otra tienda.
     */
    private function trabajoDeLaTienda(int $storeId, int $id): PrintJob
    {
        $trabajo = PrintJob::query()->where('store_id', $storeId)->find($id);

        if ($trabajo === null) {
            abort(404, 'El trabajo de impresion no existe.');
        }

        return $trabajo;
    }

    /**
     * Los TRABAJOS de la tienda, con su destino, su motivo de fallo y su
     * motivo de reimpresion.
     *
     * @return list<array<string, mixed>>
     */
    private function trabajos(int $storeId): array
    {
        $filas = $this->connection->table('print_jobs')
            ->join('print_targets', 'print_targets.id', '=', 'print_jobs.print_target_id')
            ->where('print_jobs.store_id', $storeId)
            ->orderByDesc('print_jobs.id')
            ->limit(self::TAMANO_DE_LA_COLA)
            ->get([
                'print_jobs.id as id',
                'print_jobs.status as status',
                'print_jobs.attempts as attempts',
                'print_jobs.reason as reason',
                'print_jobs.reprint_of_job_id as reprint_of_job_id',
                'print_jobs.last_error as last_error',
                'print_jobs.lease_expires_at as lease_expires_at',
                'print_jobs.created_at as created_at',
                'print_jobs.print_target_id as print_target_id',
                'print_targets.name as target_name',
                'print_targets.transport as transport',
            ]);

        $trabajos = [];

        foreach ($filas as $fila) {
            $estado = (string) $fila->status;

            $trabajos[] = [
                'id' => (int) $fila->id,
                'status' => $estado,
                'target_id' => (int) $fila->print_target_id,
                'target_name' => (string) $fila->target_name,
                'transport' => (string) $fila->transport,
                'attempts' => (int) $fila->attempts,
                // POR QUE existe el trabajo: `reprint` para una reimpresion.
                'reason' => $fila->reason === null ? null : (string) $fila->reason,
                'reprint_of_job_id' => $fila->reprint_of_job_id === null
                    ? null
                    : (int) $fila->reprint_of_job_id,
                'last_error' => $fila->last_error === null ? null : (string) $fila->last_error,
                'lease_expires_at' => $this->instante($fila->lease_expires_at),
                'created_at' => $this->instante($fila->created_at),
                // QUE SE PUEDE HACER con este trabajo: el reintento es del
                // fallido, y la reimpresion es de lo que ya salio.
                'can_retry' => $estado === 'failed',
                'can_reprint' => in_array($estado, ['printed', 'failed'], true),
            ];
        }

        return $trabajos;
    }

    /**
     * Un INSTANTE de la base, en ISO 8601.
     */
    private function instante(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return Carbon::parse($valor)->toIso8601String();
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
