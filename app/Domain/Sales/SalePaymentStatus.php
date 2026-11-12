<?php

namespace App\Domain\Sales;

use App\Models\PaymentMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El estado de pago de una venta.
 *
 * `sales.status` es UN solo enum (`draft` | `confirmed` | `paid` |
 * `cancelled` | `fiscal_failed`): el estado del pago y el estado fiscal
 * COMPARTEN la misma columna. Por eso la regla vive aqui, en UN sitio, y no
 * duplicada en el checkout y en la emision: dos copias de la misma regla
 * divergen, y el reintento dejaria la venta en un estado que no corresponde
 * con lo cobrado.
 *
 * REGLA que fija: *`paid` si lo COBRADO cubre el total, y `confirmed` si
 * queda algo por cobrar*.
 *
 *  - LO COBRADO es la suma de lo APLICADO por los pagos que NO son de tipo
 *    `credit`: un pago a credito es DEUDA, no dinero, y no cuenta como
 *    cobertura. Una venta a credito no esta pagada, por mucho que el pago
 *    cubra el total;
 *  - la cobertura es "MAYOR O IGUAL": el SOBREPAGO es el caso NORMAL (el
 *    cliente entrega un billete de 100 por una compra de 58, veinte veces al
 *    dia), y lo que sobra es el CAMBIO, no un pago pendiente. Una venta
 *    cobrada esta pagada;
 *  - `confirmed` significa "QUEDA ALGO POR COBRAR": un pago parcial, o una
 *    venta a credito.
 *
 * LO QUE SE COMPARA es lo APLICADO (`sale_payments.applied`), no lo RECIBIDO
 * (`sale_payments.amount`): lo aplicado es lo que CUBRE la venta y el bruto es
 * lo que entro al mostrador. Comparar los BRUTOS hacia que un sobrepago
 * dejara la venta sin cobrar y que una venta a credito quedara cobrada: las
 * dos cosas son el mismo defecto.
 *
 * Los importes viajan como TEXTO decimal y se comparan con `BigDecimal` a
 * escala 4: un `float` no es un dinero.
 */
final readonly class SalePaymentStatus
{
    public function __construct(private Connection $connection) {}

    /**
     * El estado de pago de una venta, a partir de sus pagos.
     *
     * Cada pago expone lo APLICADO a la venta y el TIPO de su medio de pago:
     * `credit` es DEUDA, no cobro, y no cuenta como cobertura.
     *
     * @param  list<object{applied: string, type: string|null}>  $payments  Los pagos.
     */
    public function status(array $payments, string $grandTotal): string
    {
        $cobrado = BigDecimal::zero()->toScale(4, RoundingMode::HALF_UP);

        foreach ($payments as $payment) {
            // Un pago de tipo `credit` NO es dinero: es DEUDA. No cubre la
            // venta, y por eso una venta a credito no esta pagada.
            if ($payment->type === PaymentMethod::TYPE_CREDIT) {
                continue;
            }

            $cobrado = $cobrado->plus(
                BigDecimal::of((string) $payment->applied)->toScale(4, RoundingMode::HALF_UP)
            );
        }

        $total = BigDecimal::of($grandTotal)->toScale(4, RoundingMode::HALF_UP);

        // La cobertura es "MAYOR O IGUAL": lo que sobra del pago es el
        // CAMBIO, y una venta cobrada esta pagada.
        return $cobrado->isGreaterThanOrEqualTo($total) ? 'paid' : 'confirmed';
    }

    /**
     * El estado de pago de una venta, LEIDO de la base de datos.
     *
     * Es la MISMA regla que `status()`, con los pagos tomados de
     * `sale_payments` y el total de `sale_totals`. El reintento de la
     * emision la usa porque `fiscal_failed` PISA el estado de pago anterior:
     * hay que RECALCULARLO desde los pagos, no restaurarlo desde un campo
     * que ya no existe.
     */
    public function forSale(int $saleId): string
    {
        $total = $this->connection->table('sale_totals')
            ->where('sale_id', $saleId)
            ->value('grand_total');

        if ($total === null) {
            throw new RuntimeException(
                "La venta {$saleId} no tiene totales: no se puede calcular su estado de pago."
            );
        }

        $payments = $this->connection->table('sale_payments')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'sale_payments.payment_method_id')
            ->where('sale_payments.sale_id', $saleId)
            ->orderBy('sale_payments.id')
            ->selectRaw(
                // El FALLBACK de las filas VIEJAS (las que no tienen
                // `applied`): lo aplicado de una fila sin `applied` es su
                // BRUTO, porque una fila sin `applied` no tiene cambio
                // registrado (la razon esta en la migracion de la columna).
                // El CRITERIO es uno solo (`AppliedAmount`): el mismo que
                // usan la caja y la pantalla de ventas.
                AppliedAmount::sql('sale_payments').' as applied, '
                .'payment_methods.type as type'
            )
            ->get();

        $pagos = [];

        foreach ($payments as $payment) {
            $pagos[] = (object) [
                'applied' => (string) $payment->applied,
                // El TIPO es DATO de la fila del medio de pago, y su DEFECTO es
                // el efectivo: un medio de pago sin tipo declarado cobra en
                // efectivo.
                'type' => $payment->type === null ? null : (string) $payment->type,
            ];
        }

        return $this->status($pagos, (string) $total);
    }
}
