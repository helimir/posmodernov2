<?php

namespace App\Domain\Sales;

use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;

/**
 * Los PAGOS de una venta, con sus TRES cifras: lo RECIBIDO, lo APLICADO y el
 * CAMBIO.
 *
 * REGLA que fija esta clase: *un pago se enseña con sus tres cifras, y lo
 * APLICADO es la verdad*.
 *
 *  - LO RECIBIDO (`sale_payments.amount`) es lo que el cliente entrego: el
 *    dato del cajero;
 *  - LO APLICADO (`sale_payments.applied`) es lo que el pago CUBRE de la
 *    venta: el dato del dominio. Es la verdad, y es la que cuadra con el
 *    total de la venta (y con lo que dice el comprobante fiscal);
 *  - EL CAMBIO (`sale_payments.details.change`) es lo que se devolvio: solo
 *    hay cambio si el cliente dio de mas.
 *
 * EL FALLBACK es el de siempre: lo aplicado de una fila sin `applied` es su
 * BRUTO (`AppliedAmount`), porque una fila sin `applied` no tiene cambio
 * registrado. Y el cambio de esa fila es CERO: lo recibido y lo aplicado son
 * el mismo numero.
 *
 * ESTA CLASE NO CALCULA LA ARITMETICA DEL COBRO: la calcula el dominio del
 * checkout (`CheckoutService`) y la guarda. Aqui solo se LEE, con el mismo
 * criterio que usa la caja y que usa el estado de pago.
 *
 * Los importes viajan como TEXTO decimal (`'58.0000'`), nunca como float.
 */
final readonly class SalePayments
{
    public function __construct(private Connection $connection) {}

    /**
     * Los PAGOS de una venta, con las TRES cifras.
     *
     * @return list<array{
     *     id: int,
     *     payment_method_id: int|null,
     *     payment_method: string|null,
     *     type: string,
     *     received: string,
     *     applied: string,
     *     change: string
     * }>
     */
    public function forSale(int $saleId): array
    {
        $filas = $this->connection->table('sale_payments')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'sale_payments.payment_method_id')
            ->where('sale_payments.sale_id', $saleId)
            ->orderBy('sale_payments.id')
            ->selectRaw(
                'sale_payments.id as id, '
                .'sale_payments.payment_method_id as payment_method_id, '
                .'payment_methods.name as payment_method, '
                .'payment_methods.type as type, '
                .'sale_payments.amount as received, '
                // El FALLBACK de las filas VIEJAS: lo aplicado de una fila sin
                // `applied` es su BRUTO. Es el criterio de `AppliedAmount`, el
                // mismo que usan el estado de pago y el desglose de la caja.
                .AppliedAmount::sql('sale_payments').' as applied, '
                .'sale_payments.details as details'
            )
            ->get();

        $pagos = [];

        foreach ($filas as $fila) {
            $recibido = PosAmount::text($fila->received);
            $aplicado = PosAmount::text($fila->applied);

            $pagos[] = [
                'id' => (int) $fila->id,
                'payment_method_id' => $fila->payment_method_id === null ? null : (int) $fila->payment_method_id,
                'payment_method' => $fila->payment_method === null ? null : (string) $fila->payment_method,
                'type' => $this->tipo($fila->type),
                'received' => $recibido,
                'applied' => $aplicado,
                'change' => $this->cambio($fila->details, $recibido, $aplicado),
            ];
        }

        return $pagos;
    }

    /**
     * El RESUMEN de los pagos de una venta: las sumas y el DESCUADRE.
     *
     * REGLA que fija este metodo: *la suma de lo APLICADO se compara con el
     * total de la venta, y la diferencia se ve*. Una venta a credito entrega
     * lo aplicado igual al total (la cubre entera), y una venta cobrada
     * tambien: en los dos casos no hay nada que avisar. Una venta cuyo pago
     * no cubre el total deja un hueco, y el hueco sale con su cifra.
     *
     * @param  int  $saleId  La venta.
     * @param  string|null  $grandTotal  El total de la venta; `null` si no
     *                                   lo tiene, y entonces no hay nada con
     *                                   que comparar.
     */
    public function summary(int $saleId, ?string $grandTotal): SalePaymentSummary
    {
        $recibido = $this->cero();
        $aplicado = $this->cero();
        $cambio = $this->cero();

        foreach ($this->forSale($saleId) as $pago) {
            $recibido = $recibido->plus(PosAmount::decimal($pago['received']));
            $aplicado = $aplicado->plus(PosAmount::decimal($pago['applied']));
            $cambio = $cambio->plus(PosAmount::decimal($pago['change']));
        }

        if ($grandTotal === null) {
            return new SalePaymentSummary(
                receivedTotal: $this->texto($recibido),
                appliedTotal: $this->texto($aplicado),
                changeTotal: $this->texto($cambio),
                total: null,
                difference: null,
                matchesTotal: null,
            );
        }

        $total = PosAmount::decimal($grandTotal);
        $diferencia = $this->texto($aplicado->minus($total));

        return new SalePaymentSummary(
            receivedTotal: $this->texto($recibido),
            appliedTotal: $this->texto($aplicado),
            changeTotal: $this->texto($cambio),
            total: $this->texto($total),
            difference: $diferencia,
            matchesTotal: PosAmount::decimal($diferencia)->isZero(),
        );
    }

    /**
     * La SUMA de lo APLICADO de las ventas dadas.
     *
     * Es la MISMA agregacion que la del resumen, pero de VARIAS ventas: el
     * listado es el RECENTRO de la caja, y preguntar por lo aplicado de
     * cincuenta ventas es UNA consulta, no cincuenta.
     *
     * @param  list<int>  $saleIds
     * @return array<int, string>
     */
    public function appliedTotals(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        $filas = $this->connection->table('sale_payments')
            ->whereIn('sale_id', $saleIds)
            ->groupBy('sale_id')
            ->selectRaw(
                'sale_id, SUM('.AppliedAmount::sql('sale_payments').') as applied_total'
            )
            ->get();

        $totales = [];

        foreach ($filas as $fila) {
            $totales[(int) $fila->sale_id] = PosAmount::text($fila->applied_total);
        }

        return $totales;
    }

    /**
     * El CAMBIO de un pago: el que esta REGISTRADO, y, si no hay ninguno, el
     * que falta entre lo recibido y lo aplicado.
     *
     * El cambio lo REGISTRA el checkout en `details.change`. Para una fila
     * VIEJA no hay cambio registrado, y el cambio es la diferencia entre lo
     * recibido y lo aplicado: con el fallback de `AppliedAmount` (lo aplicado
     * es su bruto), esa diferencia es CERO.
     *
     * @param  mixed  $details  El `details` del pago (jsonb).
     */
    private function cambio(mixed $details, string $recibido, string $aplicado): string
    {
        $registrado = $this->detalles($details)['change'] ?? null;

        if ($registrado !== null) {
            return PosAmount::text($registrado);
        }

        return $this->texto(
            PosAmount::decimal($recibido)->minus(PosAmount::decimal($aplicado))
        );
    }

    /**
     * El DETALLE de un pago (jsonb), como arreglo.
     *
     * @return array<string, mixed>
     */
    private function detalles(mixed $valor): array
    {
        if ($valor === null) {
            return [];
        }

        if (is_array($valor)) {
            return $valor;
        }

        $decodificado = json_decode((string) $valor, true);

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * El TIPO del medio de pago.
     *
     * El TIPO es DATO de la fila, y su DEFECTO es el efectivo: un medio de
     * pago sin tipo declarado cobra en efectivo.
     */
    private function tipo(mixed $valor): string
    {
        return $valor === null ? 'cash' : (string) $valor;
    }

    /**
     * Un CERO con la escala de las columnas.
     */
    private function cero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * Un importe, como TEXTO decimal con la escala de las columnas.
     */
    private function texto(BigDecimal $importe): string
    {
        return (string) $importe->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
    }
}
