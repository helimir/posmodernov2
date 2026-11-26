<?php

namespace Tests\Fixtures;

use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Credit\InstallmentService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El ESCENARIO de las cuotas: la venta a credito, el plan y sus cuotas.
 *
 * REGLA que fija este fixture: *el escenario se apoya en la tienda de
 * demostracion del repo* (`PosFixture`), y no inventa una tienda: el cobro de
 * una cuota necesita el plan de cuentas de la tienda (la caja donde entra el
 * dinero y la cartera que se cancela) y la caja del dia ABIERTA.
 *
 * Y LA VENTA A CREDITO se monta como la monta la caja: la cabecera, sus
 * totales, su pago de tipo `credit` (que NO es dinero) y el movimiento del
 * libro del cliente con el TOTAL de la venta (positivo, porque lo que el
 * cliente debe). Es la misma cuenta que hace `PosController::trasLaVenta()`.
 */
final class InstallmentFixture
{
    /**
     * Una VENTA A CREDITO: la venta que el cliente no paga y que el plan de
     * cuotas va a financiar.
     *
     * @param  string  $total  El total de la venta, como texto decimal.
     */
    public static function ventaACredito(
        int $storeId,
        int $customerId,
        string $total = '1000.0000',
        ?string $invoiceNo = null
    ): int {
        $invoiceNo ??= 'CUO-'.uniqid();
        $fecha = PosFixture::businessDate($storeId);

        $saleId = (int) DB::table('sales')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'invoice_no' => $invoiceNo,
            'sale_date' => $fecha,
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_totals')->insert([
            'sale_id' => $saleId,
            'sub_total' => $total,
            'discount' => '0.0000',
            'tax_total' => '0.0000',
            'rounding_adjustment' => '0.0000',
            'grand_total' => $total,
            'evidence_status' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // El PAGO es de tipo `credit`: no es dinero, es deuda. Lo que se
        // aplica a la venta es CERO.
        DB::table('sale_payments')->insert([
            'sale_id' => $saleId,
            'payment_method_id' => null,
            'amount' => $total,
            'applied' => '0.0000',
            'details' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Y el LIBRO DEL CLIENTE: la venta suma por el total.
        (new CustomerBalanceService(DB::connection()))->registerSale(
            customerId: $customerId,
            storeId: $storeId,
            amount: $total,
            referenceNo: $invoiceNo,
            entryDate: new DateTimeImmutable($fecha),
            saleId: $saleId,
        );

        return $saleId;
    }

    /**
     * Un PLAN DE CUOTAS con su calendario.
     *
     * Lo crea el DOMINIO (`InstallmentService`), que es quien sabe repartir el
     * total entre las cuotas con el redondeo declarado: el escenario no
     * reimplementa el reparto.
     */
    public static function plan(
        int $storeId,
        int $customerId,
        string $principal = '1000.0000',
        string $interestPercentage = '0.0000',
        int $count = 3,
        string $firstDueDate = '2026-12-01',
        ?int $saleId = null,
        ?string $referenceNo = null
    ): int {
        return (new InstallmentService(DB::connection()))->schedule(
            customerId: $customerId,
            storeId: $storeId,
            principal: $principal,
            interestPercentage: $interestPercentage,
            count: $count,
            firstDueDate: new DateTimeImmutable($firstDueDate),
            referenceNo: $referenceNo,
            saleId: $saleId,
        );
    }

    /**
     * Las CUOTAS de un plan, por numero.
     *
     * @return list<array<string, mixed>>
     */
    public static function cuotas(int $planId): array
    {
        return DB::table('installment_schedules')
            ->where('installment_plan_id', $planId)
            ->orderBy('number')
            ->get()
            ->map(static fn ($cuota): array => (array) $cuota)
            ->all();
    }

    /**
     * El id de la CUOTA `numero` de un plan.
     */
    public static function cuota(int $planId, int $numero): int
    {
        $id = DB::table('installment_schedules')
            ->where('installment_plan_id', $planId)
            ->where('number', $numero)
            ->value('id');

        return (int) $id;
    }
}
