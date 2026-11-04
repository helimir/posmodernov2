<?php

namespace App\Domain\Sales;

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
 * REGLA que fija: `paid` si los pagos cubren EXACTAMENTE el total, y
 * `confirmed` si no. La comparacion es de EXACTITUD, no de "mayor o igual":
 * un pago parcial es `confirmed`, y un sobrepago tambien, porque el dinero
 * sobrante es un hecho distinto del pago de la venta.
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
     * @param  list<object{amount: string}>  $payments  Los pagos de la venta:
     *                                                  cada uno expone el
     *                                                  importe cobrado como
     *                                                  texto decimal.
     */
    public function status(array $payments, string $grandTotal): string
    {
        $cobrado = BigDecimal::zero()->toScale(4);

        foreach ($payments as $payment) {
            $cobrado = $cobrado->plus(
                BigDecimal::of((string) $payment->amount)->toScale(4, RoundingMode::HALF_UP)
            );
        }

        $total = BigDecimal::of($grandTotal)->toScale(4, RoundingMode::HALF_UP);

        return $cobrado->isEqualTo($total) ? 'paid' : 'confirmed';
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
            ->where('sale_id', $saleId)
            ->orderBy('id')
            ->get()
            ->all();

        return $this->status($payments, (string) $total);
    }
}
