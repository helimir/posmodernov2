<?php

use App\Domain\Credit\InstallmentService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;

/**
 * 0.6.8 / 0.6.8-T — la cuota se calcula en el SERVIDOR.
 *
 * Regla que fija: *el interes se calcula en el servidor, no en la
 * interfaz*. El interes es SIMPLE y va sobre el `payable` (el principal):
 * `total = payable + payable x porcentaje / 100`. El total se reparte entre
 * las cuotas y la suma de las cuotas reproduce el total EXACTAMENTE: la
 * ultima cuota absorbe la diferencia de redondeo.
 */

/**
 * 0.6.8-T — el interes simple sobre el payable, repartido entre las cuotas.
 */
test('test_la_cuota_se_calcula_en_el_servidor_con_interes_simple_sobre_el_payable_y_se_reparte_entre_cuotas', function () {
    $storeId = CreditFixture::store();
    $customerId = CreditFixture::customer($storeId);

    $service = new InstallmentService(DB::connection());

    $planId = $service->schedule(
        customerId: $customerId,
        storeId: $storeId,
        principal: '1000.0000',
        interestPercentage: '2.5000',
        count: 3,
        firstDueDate: new DateTimeImmutable('2026-12-01'),
        referenceNo: 'CREDITO-1',
    );

    // 1. El PLAN: el principal, el porcentaje, el numero de cuotas, la
    //    primera fecha de vencimiento y su estado.
    $plan = DB::table('installment_plans')->where('id', $planId)->first();

    expect($plan)->not->toBeNull()
        ->and((string) $plan->principal)->toBe('1000.0000')
        ->and((string) $plan->interest_percentage)->toBe('2.5000')
        ->and((int) $plan->installment_count)->toBe(3)
        ->and((string) $plan->date)->toBe('2026-12-01')
        ->and($plan->status)->toBe('active')
        ->and((string) $plan->reference_no)->toBe('CREDITO-1')
        ->and((int) $plan->store_id)->toBe($storeId)
        ->and((int) $plan->customer_id)->toBe($customerId);

    // 2. El INTERES es SIMPLE y sale del SERVIDOR: 1000,00 x 2,5 % = 25,00.
    //    El total a financiar son 1025,00.
    $esperado = (string) BigDecimal::of('1000.0000')
        ->multipliedBy(BigDecimal::of('2.5000'))
        ->dividedBy('100', 4, RoundingMode::HALF_UP)
        ->plus(BigDecimal::of('1000.0000'))
        ->toScale(4, RoundingMode::HALF_UP);

    $cuotas = DB::table('installment_schedules')
        ->where('installment_plan_id', $planId)
        ->orderBy('number')
        ->get();

    expect($cuotas)->toHaveCount(3);

    // 3. La SUMA de las cuotas es EXACTAMENTE el total: la ultima cuota
    //    absorbe la diferencia de redondeo.
    $suma = '0.0000';

    foreach ($cuotas as $cuota) {
        $suma = bcadd($suma, (string) $cuota->amount, 4);
    }

    expect($suma)->toBe('1025.0000')
        ->and($suma)->toBe($esperado)
        ->and((string) $cuotas[0]->amount)->toBe('341.6667')
        ->and((string) $cuotas[1]->amount)->toBe('341.6667')
        ->and((string) $cuotas[2]->amount)->toBe('341.6666');

    // 4. Las fechas se reparten desde la primera, con cadencia MENSUAL.
    expect((string) $cuotas[0]->due_date)->toBe('2026-12-01')
        ->and((string) $cuotas[1]->due_date)->toBe('2027-01-01')
        ->and((string) $cuotas[2]->due_date)->toBe('2027-02-01');

    // 5. Y las cuotas van numeradas desde 1, todas PENDIENTES.
    expect($cuotas->pluck('number')->map(fn ($numero): int => (int) $numero)->all())->toBe([1, 2, 3])
        ->and($cuotas->pluck('status')->unique()->values()->all())->toBe(['pending'])
        ->and($cuotas->pluck('paid_amount')->map(fn ($pagado): string => (string) $pagado)->all())
        ->toBe(['0.0000', '0.0000', '0.0000']);
});

/**
 * 0.6.8 — un plan sin cuotas NO es un plan.
 *
 * Regla que fija: *una cuota que no existe no se reparte*. Un plan con cero
 * cuotas es un error del dominio, no un plan vacio.
 */
test('test_un_plan_de_cuotas_sin_cuotas_no_se_crea', function () {
    $storeId = CreditFixture::store();
    $customerId = CreditFixture::customer($storeId);

    $service = new InstallmentService(DB::connection());

    expect(fn () => $service->schedule(
        customerId: $customerId,
        storeId: $storeId,
        principal: '1000.0000',
        interestPercentage: '2.5000',
        count: 0,
        firstDueDate: new DateTimeImmutable('2026-12-01'),
    ))->toThrow(RuntimeException::class);

    // Y no queda ningun plan a medias.
    expect(DB::table('installment_plans')->count())->toBe(0)
        ->and(DB::table('installment_schedules')->count())->toBe(0);
});
