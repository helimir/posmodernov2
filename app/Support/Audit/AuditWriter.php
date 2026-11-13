<?php

namespace App\Support\Audit;

use Illuminate\Database\Connection;
use Illuminate\Http\Request;

/**
 * El ESCRIBOR de la auditoria de negocio.
 *
 * REGLA que fija esta clase: *todo cambio de la administracion queda
 * escrito, con QUIEN lo hizo y desde DONDE*. La fila dice el actor (el
 * usuario de la sesion), la accion, la entidad y el contexto del cambio, y
 * la IP REAL de la peticion.
 *
 * Y LA AUDITORIA NO SE TRAGA EL CAMBIO: si la escritura falla, el cambio ya
 * esta hecho y no se puede deshacer por no haberlo anotado. Por eso el
 * escritor avisa del fallo con una excepcion y deja que la peticion continue:
 * el cambio esta hecho, y el hueco en la auditoria se ve.
 */
final class AuditWriter
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Anota un cambio.
     *
     * @param  string  $action  Que paso: `user.role_assigned`,
     *                          `role.permissions_updated`,
     *                          `role.store_scope_updated`.
     * @param  string  $entityType  Que se toco: `user`, `role`.
     * @param  int  $entityId  El id de lo que se toco.
     * @param  array<string, mixed>  $context  El detalle del cambio.
     */
    public function record(
        Request $request,
        string $action,
        string $entityType,
        int $entityId,
        array $context = [],
    ): void {
        $this->connection->table('audit_logs')->insert([
            'user_id' => $request->user()?->id,
            'store_id' => null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'context' => json_encode($context === [] ? new \stdClass : $context, JSON_UNESCAPED_UNICODE),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
