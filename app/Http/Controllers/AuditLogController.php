<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La CONSULTA de la auditoria de negocio.
 *
 * REGLA que fija esta clase: *la auditoria se CONSULTA, no se edita*. Las
 * filas son append-only (el trigger de la tabla lo impide), asi que esta
 * pantalla solo puede leer, filtrar y paginar.
 *
 * Y LOS FILTROS SON LOS QUE PREGUNTA UN CONTADOR: **quien** lo hizo, **que**
 * hizo, **sobre que** y **cuando**. La tienda sale sola, porque la
 * auditoria es de la tienda activa.
 *
 * Y EL `leftJoin` DE LOS USUARIOS: una fila anotada sin usuario (un proceso
 * automatico, una accion del sistema) **no puede desaparecer** de la
 * pantalla. Un `join` la borraria de la vista, que es peor que no
 * auditarla.
 */
class AuditLogController extends Controller
{
    /**
     * La pagina de la auditoria, con sus filtros.
     */
    public function index(Request $request): Response
    {
        $filtros = [
            'action' => trim((string) ($request->query('action') ?? '')),
            'entity_type' => trim((string) ($request->query('entity_type') ?? '')),
            'user_id' => $request->query('user_id') !== null && $request->query('user_id') !== ''
                ? (int) $request->query('user_id')
                : null,
            'from' => trim((string) ($request->query('from') ?? '')),
            'to' => trim((string) ($request->query('to') ?? '')),
        ];

        $porPagina = 50;
        $pagina = max(1, (int) ($request->query('page') ?? 1));

        $consulta = $this->consulta($filtros);

        $total = (clone $consulta)->count();

        $filas = $consulta
            ->orderByDesc('audit_logs.id')
            ->forPage($pagina, $porPagina)
            ->get()
            ->map(fn (object $fila): array => [
                'id' => (int) $fila->id,
                'action' => (string) $fila->action,
                'entity_type' => (string) $fila->entity_type,
                'entity_id' => $fila->entity_id === null ? null : (int) $fila->entity_id,
                'context' => $this->contexto($fila->context),
                'ip_address' => $fila->ip_address,
                'created_at' => (string) $fila->created_at,
                'user_name' => $fila->user_name,
                'store_name' => $fila->store_name,
            ])
            ->all();

        return Inertia::render('accounting/audit', [
            'logs' => $filas,
            'filters' => $filtros,
            'pagination' => [
                'page' => $pagina,
                'per_page' => $porPagina,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $porPagina)),
            ],
            'actors' => DB::table('users')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (object $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
                ->all(),
            'actions' => DB::table('audit_logs')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->map(fn (string $accion): string => $accion)
                ->all(),
            'entity_types' => DB::table('audit_logs')
                ->distinct()
                ->orderBy('entity_type')
                ->pluck('entity_type')
                ->map(fn (string $tipo): string => $tipo)
                ->all(),
        ]);
    }

    /**
     * El CONTEXTO de una anotacion, ya decodificado.
     *
     * La columna es `jsonb`, asi que la base la devuelve como texto; si se
     * deja como texto, la pantalla mostraria el JSON **escapado** dentro de
     * otro JSON. Se decodifica aqui, y un contexto que no es JSON valido se
     * devuelve como texto plano para que se vea igual.
     */
    private function contexto(mixed $contexto): array|string|null
    {
        if ($contexto === null || $contexto === '') {
            return null;
        }

        if (is_array($contexto)) {
            return $contexto;
        }

        $decodificado = json_decode((string) $contexto, true);

        return is_array($decodificado) ? $decodificado : (string) $contexto;
    }

    /**
     * La consulta con los filtros puestos.
     *
     * @param  array{action: string, entity_type: string, user_id: int|null, from: string, to: string}  $filtros
     */
    private function consulta(array $filtros): Builder
    {
        $consulta = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->leftJoin('stores', 'stores.id', '=', 'audit_logs.store_id')
            ->select([
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.entity_type',
                'audit_logs.entity_id',
                'audit_logs.context',
                'audit_logs.ip_address',
                'audit_logs.created_at',
                'users.name as user_name',
                'stores.name as store_name',
            ]);

        if ($filtros['action'] !== '') {
            $consulta->where('audit_logs.action', $filtros['action']);
        }

        if ($filtros['entity_type'] !== '') {
            $consulta->where('audit_logs.entity_type', $filtros['entity_type']);
        }

        if ($filtros['user_id'] !== null) {
            $consulta->where('audit_logs.user_id', $filtros['user_id']);
        }

        if ($filtros['from'] !== '') {
            $consulta->where('audit_logs.created_at', '>=', $filtros['from'].' 00:00:00');
        }

        if ($filtros['to'] !== '') {
            $consulta->where('audit_logs.created_at', '<=', $filtros['to'].' 23:59:59');
        }

        return $consulta;
    }
}
