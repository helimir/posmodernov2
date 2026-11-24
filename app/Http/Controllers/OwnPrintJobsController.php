<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Los TICKETS DEL CAJERO: los trabajos de impresion PROPIOS de quien
 * pregunta.
 *
 * REGLA que fija esta clase: *el cajero ve SUS trabajos de impresion, y solo
 * los suyos*.
 *
 * Los que el mismo creo (`print_jobs.created_by` = el usuario de la sesion) y
 * los que NO TIENEN ACTOR, que son los del sistema. NUNCA los de otro cajero,
 * y NUNCA los de otra tienda: un trabajo que no es suyo no existe para quien
 * pregunta, y por eso no se responde 403 (eso le confirmaria que el trabajo
 * esta ahi) sino que no se sirve.
 *
 * Y LA TIENDA ES LA DEL CONTEXTO: la misma que usa la caja, resuelta del
 * contexto de la sesion y nunca del cuerpo de la peticion. La pantalla no
 * acepta un parametro de tienda: no hay puerta por la que entrar en otra.
 *
 * Y LA PANTALLA ES DE LECTURA: no reimprime y no reintenta. El dato NO
 * distingue "el papel no salio" de "el papel salio y el reporte fallo" (los
 * dos fallos dejan la MISMA fila: la maquina de estados borra el origen del
 * fallo y solo queda un `last_error` de texto libre), asi que un boton de
 * reimprimir aqui PODRIA DUPLICAR un ticket que si salio. Lo que decide eso
 * es el operador, en la cola general, con el permiso de la cola.
 */
class OwnPrintJobsController extends Controller
{
    /**
     * Cuantos trabajos mira la pantalla como maximo.
     *
     * El tope esta en el SERVIDOR: un cliente que pide sus tickets no decide
     * cuanto se le da.
     */
    private const TAMANO_DEL_LISTADO = 200;

    public function __construct(private readonly Connection $connection) {}

    /**
     * La PANTALLA de los tickets del cajero.
     *
     * EL CONTRATO de los props es el de
     * `resources/js/pages/printing/own-jobs.tsx`:
     *
     *  - `jobs`: lista de `{ id, status, target_id, target_name, last_error,
     *    created_at }`.
     *  - `store`: la tienda activa, la misma que usa la caja.
     */
    public function index(Request $request): Response
    {
        $tienda = $this->tienda($request);

        return Inertia::render('printing/own-jobs', [
            'jobs' => $this->trabajos((int) $tienda->id, $this->actor($request)),
            'store' => $this->storeProps($tienda),
        ]);
    }

    /**
     * Los TICKETS DEL CAJERO, en JSON.
     *
     * @return array<string, mixed>
     */
    public function jobs(Request $request): JsonResponse
    {
        $tienda = $this->tienda($request);

        return response()->json([
            'jobs' => $this->trabajos((int) $tienda->id, $this->actor($request)),
            'store' => $this->storeProps($tienda),
        ]);
    }

    /**
     * EL ACTOR: el usuario de la sesion, que es de quien son los trabajos.
     */
    private function actor(Request $request): int
    {
        $actorId = $request->user()?->id;

        abort_if($actorId === null, 403, 'La pantalla de los tickets necesita un usuario en la sesion.');

        return (int) $actorId;
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
     * Los TRABAJOS DEL ACTOR, con su destino y su motivo de fallo.
     *
     * Y LA FILTRACION ES DE LA BASE, NO DE LA PANTALLA: la pantalla no
     * filtra nada, porque lo que no llega no se puede enseñar.
     *
     * @return list<array<string, mixed>>
     */
    private function trabajos(int $storeId, int $actorId): array
    {
        $filas = $this->connection->table('print_jobs')
            ->join('print_targets', 'print_targets.id', '=', 'print_jobs.print_target_id')
            ->where('print_jobs.store_id', $storeId)
            // LOS SUYOS y los del SISTEMA (los que no tienen actor).
            ->where(function ($consulta) use ($actorId): void {
                $consulta->where('print_jobs.created_by', $actorId)
                    ->orWhereNull('print_jobs.created_by');
            })
            ->orderByDesc('print_jobs.id')
            ->limit(self::TAMANO_DEL_LISTADO)
            ->get([
                'print_jobs.id as id',
                'print_jobs.status as status',
                'print_jobs.last_error as last_error',
                'print_jobs.created_at as created_at',
                'print_jobs.print_target_id as print_target_id',
                'print_targets.name as target_name',
            ]);

        $trabajos = [];

        foreach ($filas as $fila) {
            $trabajos[] = [
                // El NUMERO del trabajo: el que el cajero ve en la caja.
                'id' => (int) $fila->id,
                // El ESTADO: el que escribe la maquina de estados.
                'status' => (string) $fila->status,
                // El DESTINO: donde se imprimio, o donde se imprimira.
                'target_id' => (int) $fila->print_target_id,
                'target_name' => (string) $fila->target_name,
                // EL MOTIVO del fallo, si fallo. Es texto libre: el que lo
                // escribio lo escribio para que se lea.
                'last_error' => $fila->last_error === null ? null : (string) $fila->last_error,
                // LA FECHA: cuando se encolo el trabajo.
                'created_at' => $this->instante($fila->created_at),
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
