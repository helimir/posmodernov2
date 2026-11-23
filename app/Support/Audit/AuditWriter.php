<?php

namespace App\Support\Audit;

use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Throwable;

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
 * escritor **devuelve si pudo escribir** y **deja que la peticion continue**:
 * el cambio esta hecho, y el hueco en la auditoria se ve. **REPORTAR** el
 * fallo al log es lo que hace que el hueco no sea silencioso.
 *
 * Y EL ESCRITOR NO DEPENDE DE UNA PETICION: `record()` recibe DATOS (el
 * actor, la tienda y la IP), para que tambien se pueda auditar **desde el
 * dominio**, donde no hay una peticion HTTP: una venta se escribe en el
 * dominio, y una venta que no queda auditada es una venta que nadie puede
 * defender despues.
 */
final class AuditWriter
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Anota un cambio.
     *
     * REGLA que fija este metodo: *si la escritura falla, el cambio NO se
     * deshace y la peticion NO se corta*: se avisa y se sigue.
     *
     * @param  string  $action  Que paso: `user.role_assigned`,
     *                          `role.permissions_updated`,
     *                          `role.store_scope_updated`,
     *                          `sale.created`, `cash_register.opened`, ...
     * @param  string  $entityType  Que se toco: `user`, `role`, `sale`, ...
     * @param  int|null  $entityId  El id de lo que se toco.
     * @param  array<string, mixed>  $context  El detalle del cambio.
     * @param  int|null  $userId  Quien lo hizo.
     * @param  int|null  $storeId  En que tienda.
     * @param  string|null  $ipAddress  Desde donde.
     * @return bool `true` si se escribio, `false` si no se pudo escribir.
     */
    public function record(
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $context = [],
        ?int $userId = null,
        ?int $storeId = null,
        ?string $ipAddress = null,
    ): bool {
        try {
            $this->connection->table('audit_logs')->insert([
                'user_id' => $userId,
                'store_id' => $storeId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'context' => json_encode($context === [] ? new \stdClass : $context, JSON_UNESCAPED_UNICODE),
                'ip_address' => $ipAddress,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (Throwable $fallo) {
            // EL HUECO SE VE: el fallo queda en el log, con la accion que no
            // se pudo anotar. La peticion sigue: el cambio ya esta hecho.
            report($fallo);

            return false;
        }
    }

    /**
     * Anota un cambio hecho desde una PETICION.
     *
     * El actor y la IP salen de la peticion; la tienda la declara quien
     * sabe cual es (el que escribe en el mostrador, el de la tienda, ...).
     *
     * @param  array<string, mixed>  $context
     */
    public function recordFor(
        Request $request,
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $context = [],
        ?int $storeId = null,
    ): bool {
        return $this->record(
            $action,
            $entityType,
            $entityId,
            $context,
            $request->user()?->id,
            $storeId,
            $request->ip(),
        );
    }
}
