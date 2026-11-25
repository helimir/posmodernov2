<?php

namespace App\Domain\Sales;

use App\Support\Pos\PosAmount;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;

/**
 * Las SOLICITUDES de anulacion de una venta: el HISTORIAL y la VIVA.
 *
 * REGLA que fija esta clase: *la peticion es una FILA, y la venta solo sabe
 * si tiene una peticion VIVA*. Por eso las solicitudes viven en
 * `sale_void_requests` y no en la cabecera de la venta: una venta puede
 * tener varias (una rechazada y otra nueva), y el historial es el modelo.
 *
 * Y EL HISTORIAL SE LEE ENTERO: de la mas nueva a la mas vieja. La ultima
 * es la que mira la pantalla, pero ninguna se borra: una peticion que
 * desaparece es una peticion que nadie puede defender despues.
 *
 * Y EL ESTADO QUE SE ENSENA NO ES EL QUE SE GUARDA: la peticion guardada
 * dice si fue rechazada o retirada, y si no esta pendiente. El cuarto
 * desenlace —la peticion ATENDIDA, con la venta anulada— lo dice la venta
 * (`sales.status`), que es donde vive ese hecho.
 *
 * Y LA COLA ES POR TIENDA: la peticion es de la tienda donde se pidio, y la
 * cola de las solicitudes es la de la tienda del contexto.
 */
final readonly class SaleVoidRequests
{
    /**
     * Cuantas solicitudes mira la cola como maximo.
     *
     * El tope esta en el SERVIDOR: un cliente que pide la cola no decide
     * cuanto se le da.
     */
    private const TAMANO_DE_LA_COLA = 200;

    public function __construct(private Connection $connection) {}

    /**
     * La peticion PENDIENTE de una venta, si la tiene.
     *
     * REGLA que fija este metodo: *la peticion viva es la unica que espera*.
     * Una peticion rechazada o cancelada esta cerrada, y no se devuelve
     * como si ожиosa: el que la busca la quiere para decidir sobre ella.
     */
    public function pending(int $saleId): ?object
    {
        return $this->connection->table('sale_void_requests')
            ->where('sale_id', $saleId)
            ->where('status', SaleVoidRequestStatus::Pending->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * El HISTORIAL de las peticiones de una venta, de la mas nueva a la mas
     * vieja.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $saleId): array
    {
        $anulada = $this->ventaAnulada($saleId);

        $filas = $this->connection->table('sale_void_requests')
            ->leftJoin('users as solicitantes', 'solicitantes.id', '=', 'sale_void_requests.requested_by')
            ->leftJoin('users as rechazantes', 'rechazantes.id', '=', 'sale_void_requests.rejected_by')
            ->leftJoin('users as cancelantes', 'cancelantes.id', '=', 'sale_void_requests.cancelled_by')
            ->where('sale_void_requests.sale_id', $saleId)
            ->orderByDesc('sale_void_requests.id')
            ->get([
                'sale_void_requests.id as id',
                'sale_void_requests.status as status',
                'sale_void_requests.reason as reason',
                'sale_void_requests.requested_at as requested_at',
                'sale_void_requests.requested_by as requested_by',
                'sale_void_requests.rejected_at as rejected_at',
                'sale_void_requests.rejected_by as rejected_by',
                'sale_void_requests.rejection_reason as rejection_reason',
                'sale_void_requests.cancelled_at as cancelled_at',
                'sale_void_requests.cancelled_by as cancelled_by',
                'sale_void_requests.cancellation_reason as cancellation_reason',
                'solicitantes.name as requested_by_name',
                'rechazantes.name as rejected_by_name',
                'cancelantes.name as cancelled_by_name',
            ]);

        $historial = [];

        foreach ($filas as $fila) {
            $historial[] = $this->forma($fila, $anulada);
        }

        return $historial;
    }

    /**
     * La COLA de las peticiones PENDIENTES de una tienda.
     *
     * Y SOLO LAS PENDIENTES: una peticion rechazada o cancelada ya no
     * espera nada, y una venta anulada tampoco. La cola es la MESA DE
     * TRABAJO de quien puede anular, y una mesa de trabajo con lo ya
     * resuelto no es una mesa de trabajo.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(int $storeId): array
    {
        $filas = $this->connection->table('sale_void_requests')
            ->join('sales', 'sales.id', '=', 'sale_void_requests.sale_id')
            ->leftJoin('sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('users as solicitantes', 'solicitantes.id', '=', 'sale_void_requests.requested_by')
            ->where('sale_void_requests.store_id', $storeId)
            ->where('sale_void_requests.status', SaleVoidRequestStatus::Pending->value)
            ->where('sales.status', '!=', 'cancelled')
            ->orderBy('sale_void_requests.requested_at')
            ->orderBy('sale_void_requests.id')
            ->limit(self::TAMANO_DE_LA_COLA)
            ->get([
                'sale_void_requests.id as request_id',
                'sale_void_requests.reason as reason',
                'sale_void_requests.requested_at as requested_at',
                'sale_void_requests.requested_by as requested_by',
                'solicitantes.name as requested_by_name',
                'sales.id as sale_id',
                'sales.invoice_no as invoice_no',
                'sales.sale_date as sale_date',
                'sales.customer_id as customer_id',
                'sale_totals.grand_total as grand_total',
                'customers.name as customer_name',
            ]);

        $cola = [];

        foreach ($filas as $fila) {
            $cola[] = [
                'request_id' => (int) $fila->request_id,
                'sale_id' => (int) $fila->sale_id,
                'invoice_no' => $fila->invoice_no === null ? null : (string) $fila->invoice_no,
                'sale_date' => $fila->sale_date === null ? null : (string) $fila->sale_date,
                'customer' => $fila->customer_name === null ? null : (string) $fila->customer_name,
                'grand_total' => $fila->grand_total === null ? null : PosAmount::text($fila->grand_total),
                // EL MOTIVO: el que escribio quien pidio.
                'reason' => $fila->reason === null ? null : (string) $fila->reason,
                'requested_at' => $this->instante($fila->requested_at),
                'requested_by' => $this->responsable(
                    $fila->requested_by === null ? null : (int) $fila->requested_by,
                    $fila->requested_by_name === null ? null : (string) $fila->requested_by_name,
                ),
            ];
        }

        return $cola;
    }

    /**
     * ¿La VENTA quedo anulada?
     *
     * REGLA que fija este metodo: *el estado de la venta es el de la
     * VENTA*. Es lo que dice si una peticion esta ATENDIDA, y lo lee la
     * venta, no la peticion.
     */
    private function ventaAnulada(int $saleId): bool
    {
        $estado = $this->connection->table('sales')->where('id', $saleId)->value('status');

        return $estado !== null && (string) $estado === 'cancelled';
    }

    /**
     * La FORMA de una peticion, tal como la ve la pantalla.
     *
     * Y LOS HUECOS SE VEN: si no se sabe quien pidio o quien rechazo, el
     * campo queda en `null` y la pantalla ensena un hueco. No se inventa un
     * valor.
     *
     * @return array<string, mixed>
     */
    private function forma(object $fila, bool $anulada): array
    {
        $estado = SaleVoidRequestStatus::from((string) $fila->status);

        return [
            'id' => (int) $fila->id,
            // El estado EFECTIVO: el guardado, y el de la venta cuando la
            // peticion esta pendiente y la venta quedo anulada.
            'status' => $estado->efectivo($anulada),
            'reason' => $fila->reason === null ? null : (string) $fila->reason,
            'requested_at' => $this->instante($fila->requested_at),
            'requested_by' => $this->responsable(
                $fila->requested_by === null ? null : (int) $fila->requested_by,
                $fila->requested_by_name === null ? null : (string) $fila->requested_by_name,
            ),
            'rejected_at' => $this->instante($fila->rejected_at),
            'rejected_by' => $this->responsable(
                $fila->rejected_by === null ? null : (int) $fila->rejected_by,
                $fila->rejected_by_name === null ? null : (string) $fila->rejected_by_name,
            ),
            'rejection_reason' => $fila->rejection_reason === null ? null : (string) $fila->rejection_reason,
            'cancelled_at' => $this->instante($fila->cancelled_at),
            'cancelled_by' => $this->responsable(
                $fila->cancelled_by === null ? null : (int) $fila->cancelled_by,
                $fila->cancelled_by_name === null ? null : (string) $fila->cancelled_by_name,
            ),
            'cancellation_reason' => $fila->cancellation_reason === null ? null : (string) $fila->cancellation_reason,
        ];
    }

    /**
     * Un RESPONSABLE: quien pidio, quien rechazo o quien cancelo.
     *
     * @return array{id: int, name: string}|null
     */
    private function responsable(?int $userId, ?string $nombre = null): ?array
    {
        if ($userId === null || $nombre === null) {
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
    private function instante(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return Carbon::parse((string) $valor)->toIso8601String();
    }
}
