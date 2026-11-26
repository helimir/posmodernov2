<?php

namespace App\Domain\Credit;

use App\Domain\Accounting\CashRegisterService;
use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El COBRO de una cuota: el dinero que entra, la cuota que se marca y el
 * saldo del cliente que baja.
 *
 * REGLA que fija esta clase: *el cobro de una cuota es un COBRO del libro del
 * cliente, y es UNA escritura*. La cuota cobrada, el plan, el movimiento del
 * libro del cliente y el asiento contable se escriben en la MISMA transaccion:
 * si algo falla a mitad, no queda ni la cuota cobrada ni el saldo movido.
 *
 * EL COBRO NO SE REIMPLEMENTA: lo registra `CustomerPaymentService`, que es
 * el camino de siempre (el movimiento del libro del cliente con su `balance_after`
 * recalculado, el cache de la ficha refrescado y el asiento `sale_payment`).
 * Aqui solo se decide QUE cuota se cobra y CUANDO se cobra.
 *
 * Y LOS TRES P0 DEL LEGACY QUE ESTA UNIDAD CIERRA:
 *
 *  - **#2** (el `UPDATE installment_orders` SIN `WHERE invoice_id`): aqui el
 *    cobro marca **UNA** cuota, la que se cobra, y actualiza **UN** plan, el
 *    de esa cuota. Las demas cuotas del mismo plan y las de otros planes no se
 *    tocan: la escritura va con su `WHERE` (id de cuota Y id de plan).
 *  - **#3** (el `UPDATE selling_price` SIN `store_id`): aqui el cobro es **DE
 *    LA TIENDA DE LA CUOTA**. La cuota se busca por su plan, y el plan por la
 *    tienda del contexto: una cuota de otra tienda no existe para esta tienda
 *    y el cobro se rechaza. Y el movimiento del libro del cliente y el
 *    asiento se escriben en ESA tienda.
 *  - **#4** (la cascada sobre ocho tablas SIN transaccion): aqui la cascada
 *    son cuatro escrituras (la cuota, el plan, el movimiento del cliente y su
 *    asiento) y van en UNA transaccion.
 *
 * EL COBRO ES DE LA CUOTA COMPLETA: no hay pago parcial ni cobro de mas de
 * una cuota a la vez. El repositorio no escribe la regla del pago parcial y
 * esta unidad no la inventa.
 *
 * EL DINERO DE LA CUOTA ENTRA A LA CAJA: el cobro es un cobro EN EFECTIVO (el
 * asiento `sale_payment` debita la cuenta de efectivo de la tienda), y por
 * eso EXIGE la caja del dia ABIERTA, con el mismo criterio que el gasto en
 * efectivo de la F3-07: el saldo en curso de la caja se deriva de los asientos
 * de esa fecha de negocio, y un cobro de un dia sin caja abierta moveria un
 * saldo que no existe.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class InstallmentCollectionService
{
    private const SCALE = 4;

    public function __construct(
        private Connection $connection,
        private CustomerBalanceService $balances,
        private CustomerPaymentService $payments,
    ) {}

    /**
     * COBRA una cuota y devuelve lo que el cobro dejo escrito.
     *
     * @param  int  $scheduleId  La cuota que se cobra.
     * @param  int  $storeId  La TIENDA del cobro: la de la cuota.
     * @param  DateTimeImmutable  $businessDate  La FECHA DE NEGOCIO del cobro.
     * @param  DateTimeImmutable  $occurredAt  El instante del cobro.
     * @param  array<string, string>  $accounts  El plan de cuentas: rol => codigo.
     * @param  int|null  $actorId  Quien cobra.
     *
     * @throws RuntimeException Cuando la cuota no se puede cobrar.
     */
    public function collect(
        int $scheduleId,
        int $storeId,
        DateTimeImmutable $businessDate,
        DateTimeImmutable $occurredAt,
        array $accounts,
        ?int $actorId = null,
    ): InstallmentCollectionOutcome {
        $cuota = $this->cuotaDeLaTienda($scheduleId, $storeId);

        if ((string) $cuota->schedule_status === 'paid') {
            throw new RuntimeException(
                "La cuota {$scheduleId} ya esta cobrada: una cuota no se cobra dos veces."
            );
        }

        if ((string) $cuota->plan_status === 'cancelled') {
            throw new RuntimeException(
                "El plan de cuotas {$cuota->plan_id} esta cancelado: no se cobra una cuota de un plan cancelado."
            );
        }

        // LO QUE FALTA COBRAR de la cuota: el resto de su importe.
        $importe = $this->pendiente($cuota);

        if ($importe->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new RuntimeException(
                "La cuota {$scheduleId} no tiene importe pendiente: no hay nada que cobrar."
            );
        }

        // EL DINERO ENTRA A LA CAJA: la caja del dia tiene que estar ABIERTA.
        // Se comprueba ANTES de escribir nada, como el gasto en efectivo de la
        // F3-07.
        (new CashRegisterService($this->connection))->registerFor($storeId, $businessDate);

        return $this->connection->transaction(function () use (
            $cuota,
            $scheduleId,
            $storeId,
            $importe,
            $businessDate,
            $occurredAt,
            $accounts,
            $actorId
        ): InstallmentCollectionOutcome {
            // 1. LA CUOTA: SOLO ESA, con su plan en el `WHERE` (P0 #2).
            $this->connection->table('installment_schedules')
                ->where('id', $scheduleId)
                ->where('installment_plan_id', (int) $cuota->plan_id)
                ->update([
                    'paid_amount' => (string) $cuota->amount,
                    'status' => 'paid',
                    'updated_at' => now(),
                ]);

            // 2. EL PLAN: SOLO ESE, y su estado lo dicen sus cuotas.
            $pendientes = $this->connection->table('installment_schedules')
                ->where('installment_plan_id', (int) $cuota->plan_id)
                ->where('status', '!=', 'paid')
                ->count();

            $estadoDelPlan = $pendientes === 0 ? 'paid' : 'active';

            $this->connection->table('installment_plans')
                ->where('id', (int) $cuota->plan_id)
                ->where('store_id', $storeId)
                ->update([
                    'status' => $estadoDelPlan,
                    'updated_at' => now(),
                ]);

            // 3. EL COBRO: el camino de siempre (el libro del cliente y el
            //    asiento `sale_payment`), en la MISMA transaccion.
            $entryId = $this->payments->collect(
                customerId: (int) $cuota->customer_id,
                storeId: $storeId,
                amount: (string) $importe,
                referenceNo: $this->referencia($cuota),
                entryDate: $businessDate,
                accounts: $accounts,
                saleId: $cuota->sale_id === null ? null : (int) $cuota->sale_id,
                actorId: $actorId,
                occurredAt: $occurredAt,
            );

            return new InstallmentCollectionOutcome(
                scheduleId: $scheduleId,
                planId: (int) $cuota->plan_id,
                amount: (string) $importe,
                planStatus: $estadoDelPlan,
                entryId: $entryId,
                balance: $this->balances->balance((int) $cuota->customer_id, $storeId),
                due: $this->balances->due((int) $cuota->customer_id, $storeId),
            );
        });
    }

    /**
     * LA CUOTA de la TIENDA: la cuota y su plan, de la tienda del cobro.
     *
     * REGLA que fija este metodo: *la cuota se busca POR SU TIENDA*. La
     * fila viene del plan, y el plan se filtra por `store_id`: una cuota de
     * otra tienda no existe para esta tienda (P0 #3), y el error lo dice.
     *
     * @throws RuntimeException Cuando la cuota no es de la tienda.
     */
    private function cuotaDeLaTienda(int $scheduleId, int $storeId): object
    {
        $cuota = $this->connection->table('installment_schedules')
            ->join('installment_plans', 'installment_plans.id', '=', 'installment_schedules.installment_plan_id')
            ->leftJoin('sales', 'sales.id', '=', 'installment_plans.sale_id')
            ->where('installment_schedules.id', $scheduleId)
            ->where('installment_plans.store_id', $storeId)
            ->first([
                'installment_schedules.id as schedule_id',
                'installment_schedules.installment_plan_id as plan_id',
                'installment_schedules.number as number',
                'installment_schedules.amount as amount',
                'installment_schedules.paid_amount as paid_amount',
                'installment_schedules.status as schedule_status',
                'installment_plans.store_id as store_id',
                'installment_plans.customer_id as customer_id',
                'installment_plans.sale_id as sale_id',
                'installment_plans.status as plan_status',
                'installment_plans.reference_no as plan_reference_no',
                'sales.invoice_no as invoice_no',
            ]);

        if ($cuota === null) {
            throw new RuntimeException(
                "La cuota {$scheduleId} no existe en la tienda {$storeId}."
            );
        }

        return $cuota;
    }

    /**
     * LO QUE FALTA de la cuota: su importe menos lo ya cobrado.
     */
    private function pendiente(object $cuota): BigDecimal
    {
        $importe = BigDecimal::of(PosAmount::text($cuota->amount));
        $cobrado = BigDecimal::of(PosAmount::text($cuota->paid_amount));

        return $importe->minus($cobrado)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * LA REFERENCIA del cobro: la del plan, o el numero de la venta que
     * financia, o la de la cuota.
     */
    private function referencia(object $cuota): string
    {
        $delPlan = $cuota->plan_reference_no === null ? '' : trim((string) $cuota->plan_reference_no);

        if ($delPlan !== '') {
            return $delPlan;
        }

        $deLaVenta = $cuota->invoice_no === null ? '' : trim((string) $cuota->invoice_no);

        if ($deLaVenta !== '') {
            return $deLaVenta;
        }

        return 'CUOTA-'.(int) $cuota->schedule_id;
    }
}
