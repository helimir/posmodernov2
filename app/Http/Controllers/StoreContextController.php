<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Support\Audit\AuditWriter;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * La BASE de las pantallas que trabajan con el CONTEXTO de la tienda: las
 * compras, los proveedores y las devoluciones de mercaderia.
 *
 * Lo que comparten es lo MISMO de siempre: la TIENDA del contexto (la de la
 * sesion, no la primera del sistema), la FECHA DE NEGOCIO de esa tienda
 * (nunca un `DAY()/MONTH()/YEAR()` calculado) y la AUDITORIA de lo que se
 * escribe.
 *
 * Y LA AUDITORIA la escribe UNA SOLA VEZ: el `AuditWriter` es su dueno, y aqui
 * solo se le pasa QUE paso (quien, cuanto, cuando y por que). Una escritura
 * sin anotacion es una escritura que nadie puede defender despues.
 *
 * NO decide nada de negocio: las reglas las cumplen los servicios del
 * dominio (`PurchaseService`, `SupplierService`, `SaleReturnService`), y aqui
 * solo se traduce su fallo al contrato de la API (422 con `{"errorMsg"}`).
 */
abstract class StoreContextController extends Controller
{
    public function __construct(protected readonly Connection $connection) {}

    /**
     * La TIENDA del contexto de la peticion.
     *
     * @throws RuntimeException Cuando no hay tienda activa.
     */
    protected function tienda(Request $request): Store
    {
        return $this->contexto($request)->store();
    }

    /**
     * El CONTEXTO de la peticion.
     *
     * Se crea aqui y no en el constructor: el controlador puede sobrevivir a
     * una peticion, y un contexto guardado en la instancia seria el contexto
     * de la peticion ANTERIOR.
     */
    protected function contexto(Request $request): PosContext
    {
        return new PosContext($this->connection, $request->user()?->id);
    }

    /**
     * La AUDITORIA de un cambio del modulo: quien lo hizo, cuanto, cuando y por
     * que.
     *
     * @param  array<string, mixed>  $contexto  El detalle del cambio.
     */
    protected function auditar(
        Request $request,
        string $accion,
        string $entidad,
        ?int $entidadId,
        array $contexto,
        int $storeId
    ): void {
        (new AuditWriter($this->connection))->recordFor(
            $request,
            $accion,
            $entidad,
            $entidadId,
            $contexto,
            $storeId
        );
    }

    /**
     * La RESPUESTA de un fallo del dominio: un 422 con su mensaje.
     *
     * REGLA que fija esta clase: *el fallo del dominio se enseña con SU
     * mensaje*. El dominio sabe mas del motivo que el navegador, y un 422 sin
     * motivo es un error sin informacion.
     */
    protected function fallo(RuntimeException $fallo): JsonResponse
    {
        return response()->json(['errorMsg' => $fallo->getMessage()], 422);
    }

    /**
     * La TIENDA para la pantalla: su id, su nombre y su clave natural.
     *
     * @return array{id: int, name: string, code_name: string|null}
     */
    protected function propsDeTienda(Store $tienda): array
    {
        return [
            'id' => (int) $tienda->id,
            'name' => (string) $tienda->name,
            'code_name' => $tienda->code_name,
        ];
    }
}
