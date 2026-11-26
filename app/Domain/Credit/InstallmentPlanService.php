<?php

namespace App\Domain\Credit;

use App\Domain\Accounting\LedgerWriter;
use App\Domain\Sales\SalePayments;
use App\Support\Pos\PosAmount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * El PLAN DE CUOTAS de una venta a credito: el calendario que declara como
 * paga el cliente lo que le quedo a deber.
 *
 * REGLA que fija esta clase: *el plan es el CALENDARIO de una venta a
 * credito, y el interes que el plan cobra es DEUDA del cliente e INGRESO de la
 * tienda*.
 *
 * Y LA VENTA A CREDITO YA EXISTE: el checkout cobra con un medio de pago de
 * tipo `credit` (eso es deuda, no dinero) y el libro del cliente registra la
 * venta por su TOTAL. El plan no inventa la venta a credito: la calendariza, y
 * por eso su PRINCIPAL es lo que la venta dejo SIN COBRAR.
 *
 * EL REPARTO NO SE REIMPLEMENTA: lo hace `InstallmentService`, que ya sabe
 * repartir el total entre las cuotas con el redondeo declarado (la ultima
 * cuota absorbe la diferencia). Aqui solo se le dice QUE plan se quiere y se
 * REGISTRA lo que el plan genera.
 *
 * Y EL INTERES NO SE INVENTA: el total del plan es `principal + interes`, y
 * ese total es lo que el cliente paga. Por eso el interes SUBE la deuda del
 * cliente (un movimiento de tipo `adjustment`, que es el tercero del libro) y
 * es INGRESO de la tienda (un asiento que debita la cartera y acredita el
 * ingreso). Sin ese asiento, el cobro de las cuotas dejaria al cliente con un
 * CREDITO A FAVOR por el interes que pago, y el libro del cliente y el
 * contador dirian cosas distintas.
 *
 * Y LA CUENTA DEL INGRESO es la que el PLAN DE CUENTAS declara: el repositorio
 * no tiene un rol de ingreso financiero, asi que el interes va a la cuenta de
 * ingresos de la tienda, que es la unica que el plan declara para el ingreso.
 * Un negocio que quiera una cuenta propia para los intereses necesita un rol
 * nuevo, y eso es una decision pendiente, no de esta unidad.
 *
 * Y EL LIMITE DE CREDITO NO SE COMPRUEBA AQUI: el limite bloquea lo que
 * AUMENTA la deuda en una VENTA (lo cumple `CustomerBalanceService::
 * assertCreditLimit()`), y el plan no es una venta: es el calendario de una
 * deuda que ya existe. Bloquear el plan dejaria a un cliente que ya debe mas
 * de su limite sin poder acordar como pagar.
 *
 * Y EL PLAN, SU CALENDARIO Y EL INTERES se escriben en UNA transaccion: un
 * plan sin su interes, o un interes sin su plan, son dos hechos a medias.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class InstallmentPlanService
{
    private const SCALE = 4;

    public function __construct(
        private Connection $connection,
        private InstallmentService $installments,
        private CustomerBalanceService $balances,
        private LedgerWriter $ledger,
    ) {}

    /**
     * CREA el plan de cuotas de una venta a credito.
     *
     * @param  int  $saleId  La venta que el plan financia.
     * @param  int  $storeId  La TIENDA de la venta.
     * @param  string  $interestPercentage  El porcentaje de interes, como texto decimal.
     * @param  int  $count  El numero de cuotas.
     * @param  DateTimeImmutable  $firstDueDate  La primera fecha de vencimiento.
     * @param  DateTimeImmutable  $businessDate  La FECHA DE NEGOCIO del plan.
     * @param  DateTimeImmutable  $occurredAt  El instante en que se acuerda.
     * @param  array<string, string>  $accounts  El plan de cuentas: rol => codigo.
     * @param  string|null  $referenceNo  El comprobante del credito.
     * @param  int|null  $actorId  Quien acuerda el plan.
     *
     * @throws RuntimeException Cuando el plan no se puede crear.
     */
    public function create(
        int $saleId,
        int $storeId,
        string $interestPercentage,
        int $count,
        DateTimeImmutable $firstDueDate,
        DateTimeImmutable $businessDate,
        DateTimeImmutable $occurredAt,
        array $accounts,
        ?string $referenceNo = null,
        ?int $actorId = null,
    ): InstallmentPlanOutcome {
        $venta = $this->ventaDeLaTienda($saleId, $storeId);

        if ((string) $venta->status === 'cancelled') {
            throw new RuntimeException(
                "La venta {$saleId} esta anulada: no hay nada que financiar."
            );
        }

        if ($venta->customer_id === null) {
            throw new RuntimeException(
                "La venta {$saleId} no tiene cliente: una deuda sin destino no se financia."
            );
        }

        $customerId = (int) $venta->customer_id;

        $this->exigeQueLaVentaNoTengaPlan($saleId);

        // EL PRINCIPAL es lo que la venta dejo SIN COBRAR: el plan financia la
        // deuda, no la venta entera.
        $principal = $this->pendienteDeLaVenta($venta);

        if ($principal->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new RuntimeException(
                "La venta {$saleId} no tiene importe pendiente: no hay nada que financiar."
            );
        }

        $referencia = $this->referencia($referenceNo, $venta);

        return $this->connection->transaction(function () use (
            $saleId,
            $storeId,
            $customerId,
            $principal,
            $interestPercentage,
            $count,
            $firstDueDate,
            $businessDate,
            $occurredAt,
            $accounts,
            $referencia,
            $actorId
        ): InstallmentPlanOutcome {
            // 1. EL PLAN Y SU CALENDARIO: los reparte el dominio.
            try {
                $planId = $this->installments->schedule(
                    customerId: $customerId,
                    storeId: $storeId,
                    principal: (string) $principal,
                    interestPercentage: $interestPercentage,
                    count: $count,
                    firstDueDate: $firstDueDate,
                    referenceNo: $referencia,
                    saleId: $saleId,
                );
            } catch (UniqueConstraintViolationException) {
                // DOS PLANES A LA VEZ sobre la misma venta: el indice UNIQUE
                // lo prohibe, y el mensaje sigue siendo el del dominio, no el
                // de PostgreSQL. La excepcion revierte la transaccion entera.
                throw new RuntimeException(
                    "La venta {$saleId} ya tiene un plan de cuotas: una venta tiene un solo plan."
                );
            }

            // 2. EL TOTAL del plan es la SUMA de sus cuotas (no se recalcula:
            //    la ultima cuota absorbe la diferencia de redondeo, y su suma
            //    es el total EXACTO).
            $total = $this->totalDelPlan($planId);

            // 3. EL INTERES: lo que el plan cobra de mas, y lo que la deuda
            //    del cliente sube.
            $interes = $total->minus($principal)->toScale(self::SCALE, RoundingMode::HALF_UP);

            $entryId = null;

            if ($interes->isPositive()) {
                $entryId = $this->registraElInteres(
                    planId: $planId,
                    saleId: $saleId,
                    storeId: $storeId,
                    customerId: $customerId,
                    interes: (string) $interes,
                    referencia: $referencia,
                    businessDate: $businessDate,
                    occurredAt: $occurredAt,
                    accounts: $accounts,
                    actorId: $actorId,
                );
            }

            return new InstallmentPlanOutcome(
                planId: $planId,
                saleId: $saleId,
                principal: (string) $principal,
                interest: (string) $interes,
                total: (string) $total,
                count: $count,
                firstDueDate: $firstDueDate->format('Y-m-d'),
                entryId: $entryId,
                balance: $this->balances->balance($customerId, $storeId),
                due: $this->balances->due($customerId, $storeId),
            );
        });
    }

    /**
     * REGISTRA el interes del plan: la deuda del cliente y el ingreso de la
     * tienda.
     *
     * Y LAS DOS ESCRITURAS VAN JUNTAS: el movimiento del libro del cliente
     * (un `adjustment`, que suma a la deuda) y el asiento contable (que debita
     * la cartera y acredita el ingreso). El libro del cliente y el contador
     * tienen que decir lo mismo.
     */
    private function registraElInteres(
        int $planId,
        int $saleId,
        int $storeId,
        int $customerId,
        string $interes,
        string $referencia,
        DateTimeImmutable $businessDate,
        DateTimeImmutable $occurredAt,
        array $accounts,
        ?int $actorId,
    ): int {
        $cartera = $this->account($storeId, $accounts, 'receivable');
        $ingresos = $this->account($storeId, $accounts, 'revenue');

        // LA DEUDA DEL CLIENTE sube por el interes: el plan dice que paga el
        // total, y el total es el principal MAS el interes.
        $this->balances->registerAdjustment(
            customerId: $customerId,
            storeId: $storeId,
            amount: $interes,
            referenceNo: $referencia,
            entryDate: $businessDate,
            saleId: $saleId,
        );

        // Y EL INGRESO de la tienda: la cartera se debita (la deuda es real)
        // y el ingreso se acredita.
        //
        // EL TIPO es `adjustment`: el catalogo de tipos de asiento es un
        // conjunto CERRADO de ocho y no tiene un tipo para el interes
        // financiero de un plan de cuotas.
        return $this->ledger->write(
            entryType: 'adjustment',
            storeId: $storeId,
            occurredAt: $occurredAt,
            businessDate: $businessDate,
            lines: [
                ['account' => $cartera, 'debit' => $interes, 'credit' => '0'],
                ['account' => $ingresos, 'debit' => '0', 'credit' => $interes],
            ],
            referenceType: 'installment_plan',
            referenceId: $planId,
            actorId: $actorId,
            description: 'Interes del plan de cuotas '.$referencia,
        );
    }

    /**
     * LA VENTA de la TIENDA: la venta y sus totales.
     *
     * REGLA que fija este metodo: *la venta se busca POR SU TIENDA*. Un plan
     * de otra tienda no existe para esta tienda (P0 #3), y el error lo dice.
     *
     * @throws RuntimeException Cuando la venta no es de la tienda.
     */
    private function ventaDeLaTienda(int $saleId, int $storeId): object
    {
        $venta = $this->connection->table('sales')
            ->join('sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->where('sales.id', $saleId)
            ->where('sales.store_id', $storeId)
            ->first([
                'sales.id as id',
                'sales.store_id as store_id',
                'sales.customer_id as customer_id',
                'sales.invoice_no as invoice_no',
                'sales.status as status',
                'sale_totals.grand_total as grand_total',
            ]);

        if ($venta === null) {
            throw new RuntimeException(
                "La venta {$saleId} no existe en la tienda {$storeId}."
            );
        }

        return $venta;
    }

    /**
     * UNA VENTA NO TIENE DOS PLANES: dos planes sobre la misma venta
     * cobrarian el interes dos veces.
     *
     * @throws RuntimeException Cuando la venta ya tiene un plan.
     */
    private function exigeQueLaVentaNoTengaPlan(int $saleId): void
    {
        $tiene = $this->connection->table('installment_plans')
            ->where('sale_id', $saleId)
            ->exists();

        if ($tiene) {
            throw new RuntimeException(
                "La venta {$saleId} ya tiene un plan de cuotas: una venta tiene un solo plan."
            );
        }
    }

    /**
     * LO QUE LA VENTA DEJO SIN COBRAR: su total menos lo aplicado por sus
     * pagos.
     *
     * Y LO APLICADO se lee con el CRITERIO de `AppliedAmount` (lo aplicado de
     * una fila sin `applied` es su bruto): es el mismo criterio que usan el
     * estado de pago y el desglose de la caja.
     */
    private function pendienteDeLaVenta(object $venta): BigDecimal
    {
        $total = PosAmount::decimal($venta->grand_total);

        $cobrado = (new SalePayments($this->connection))
            ->summary((int) $venta->id, (string) $total)
            ->appliedTotal;

        return $total->minus(PosAmount::decimal($cobrado))->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * EL TOTAL DEL PLAN: la suma de sus cuotas.
     */
    private function totalDelPlan(int $planId): BigDecimal
    {
        $suma = $this->connection->table('installment_schedules')
            ->where('installment_plan_id', $planId)
            ->sum('amount');

        return PosAmount::decimal($suma ?? '0');
    }

    /**
     * LA REFERENCIA del plan: la que se le da, o el numero de la venta.
     */
    private function referencia(?string $referenceNo, object $venta): string
    {
        $dado = $referenceNo === null ? '' : trim($referenceNo);

        if ($dado !== '') {
            return $dado;
        }

        $deLaVenta = $venta->invoice_no === null ? '' : trim((string) $venta->invoice_no);

        return $deLaVenta === '' ? 'PLAN-'.$venta->id : $deLaVenta;
    }

    /**
     * La cuenta contable de un rol del plan de cuentas.
     *
     * Sin ella el interes NO se registra: un interes que no se asienta deja
     * el libro del cliente y el contador diciendo cosas distintas, y eso es
     * peor que un plan que no se puede crear.
     *
     * @param  array<string, string>  $accounts
     *
     * @throws RuntimeException Cuando el plan de cuentas no declara la cuenta.
     */
    private function account(int $storeId, array $accounts, string $role): string
    {
        $codigo = $accounts[$role] ?? null;

        if ($codigo === null || trim($codigo) === '') {
            throw new RuntimeException(
                "La tienda {$storeId} no tiene cuenta contable de '{$role}' en su plan de cuentas: "
                .'sin ella el interes del plan de cuotas no se puede asentar.'
            );
        }

        return trim($codigo);
    }
}
