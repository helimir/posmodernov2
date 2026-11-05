<?php

namespace App\Domain\Credit;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El plan de cuotas de un credito del cliente.
 *
 * REGLA que fija este servicio: *el interes se calcula en el servidor, no en
 * la interfaz*. La interfaz no decide cuanto paga el cliente: dice el
 * principal y el porcentaje, y el servidor responde con las cuotas.
 *
 * El interes es SIMPLE y va sobre el `payable` (el principal):
 * `total = payable + payable x porcentaje / 100`. El `payable` NO es una
 * columna: se CALCULA, porque almacenar un importe derivado es duplicar un
 * dato que se desincroniza del que lo produce (lo mismo que `loans.payable`).
 *
 * El reparto entre las cuotas usa la regla de REDONDEO DECLARADA: la cuota
 * nominal es `total / numero de cuotas` a escala 4 con `HALF_UP`, y la
 * ultima cuota ABSORBE la diferencia de redondeo. Asi la suma de las cuotas
 * reproduce el total EXACTAMENTE, y no hay un céntimo que aparece ni que
 * desaparece.
 *
 * La CADENCIA es MENSUAL: la cuota `n` vence un mes despues de la anterior,
 * a partir de la primera fecha de vencimiento. `installment_schedules` no
 * tiene columna de intervalo, asi que la cadencia la declara el dominio, y
 * no la deduce el nombre de una columna que no existe.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como float.
 */
final readonly class InstallmentService
{
    /** La escala de las columnas: `numeric(25,4)`. */
    private const SCALE = 4;

    public function __construct(private Connection $connection) {}

    /**
     * Crea el plan de cuotas y su calendario, y devuelve el id del plan.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El INTERES es simple y se calcula en el SERVIDOR: `total = payable
     *     + payable x porcentaje / 100`, con el `payable` igual al principal.
     *  2. El REPARTO declara su redondeo: cada cuota vale `total / numero de
     *     cuotas` a escala 4 con `HALF_UP`, y la ULTIMA absorbe la diferencia
     *     de redondeo, de modo que la suma de las cuotas es EXACTAMENTE el
     *     total.
     *  3. El plan y su calendario se escriben en UNA SOLA transaccion: un
     *     plan sin sus cuotas no es un plan.
     *  4. Un plan con menos de una cuota NO es un plan: es un error con
     *     mensaje util, no una fila vacia.
     *
     * @param  string  $principal  El importe a financiar, como texto decimal.
     * @param  string  $interestPercentage  El porcentaje de interes, como texto decimal.
     * @param  int  $count  El numero de cuotas.
     * @param  DateTimeImmutable  $firstDueDate  La primera fecha de vencimiento.
     * @param  string|null  $referenceNo  El comprobante del credito.
     *
     * @throws RuntimeException Cuando el numero de cuotas no es valido.
     */
    public function schedule(
        int $customerId,
        int $storeId,
        string $principal,
        string $interestPercentage,
        int $count,
        DateTimeImmutable $firstDueDate,
        ?string $referenceNo = null,
    ): int {
        if ($count < 1) {
            throw new RuntimeException(
                "Un plan de cuotas necesita al menos una cuota: el numero de cuotas es {$count}."
            );
        }

        $payable = BigDecimal::of($this->decimal($principal));
        $porcentaje = BigDecimal::of($this->decimal($interestPercentage));

        // El interes simple sobre el payable, redondeado a la escala de la
        // columna: el total a financiar es el payable MAS el interes.
        $interes = $payable
            ->multipliedBy($porcentaje)
            ->dividedBy('100', self::SCALE, RoundingMode::HALF_UP);

        $total = $payable->plus($interes)->toScale(self::SCALE, RoundingMode::HALF_UP);

        // La cuota nominal: el total repartido en partes iguales, con el
        // redondeo DECLARADO.
        $nominal = $total->dividedBy((string) $count, self::SCALE, RoundingMode::HALF_UP);

        return $this->connection->transaction(function () use (
            $customerId,
            $storeId,
            $payable,
            $porcentaje,
            $count,
            $total,
            $nominal,
            $firstDueDate,
            $referenceNo
        ): int {
            $planId = (int) $this->connection->table('installment_plans')->insertGetId([
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'reference_no' => $referenceNo,
                'principal' => (string) $payable->toScale(self::SCALE, RoundingMode::HALF_UP),
                'interest_percentage' => (string) $porcentaje->toScale(self::SCALE, RoundingMode::HALF_UP),
                'installment_count' => $count,
                // La fecha del plan es la PRIMERA fecha de vencimiento: el
                // plan arranca cuando empieza a pagar.
                'date' => $firstDueDate->format('Y-m-d'),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rows = [];

            for ($numero = 1; $numero <= $count; $numero++) {
                // La ultima cuota ABSORBE la diferencia de redondeo: la suma
                // de las cuotas es exactamente el total.
                $importe = $numero === $count
                    ? $total->minus($nominal->multipliedBy($count - 1))->toScale(self::SCALE, RoundingMode::HALF_UP)
                    : $nominal;

                $rows[] = [
                    'installment_plan_id' => $planId,
                    'number' => $numero,
                    'due_date' => $this->vencimiento($firstDueDate, $numero - 1),
                    'amount' => (string) $importe,
                    'paid_amount' => '0.0000',
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $this->connection->table('installment_schedules')->insert($rows);

            return $planId;
        });
    }

    /**
     * El vencimiento de la cuota `n`: la primera fecha mas `n` meses.
     *
     * La cadencia es MENSUAL y no se desborda: una cuota que vence el 31 de
     * enero vence el 28 (o 29) de febrero, y no el 3 de marzo.
     */
    private function vencimiento(DateTimeImmutable $primera, int $meses): string
    {
        return CarbonImmutable::instance($primera)
            ->addMonthsNoOverflow($meses)
            ->format('Y-m-d');
    }

    /**
     * Normaliza un importe a la escala de la columna.
     *
     * El importe llega como TEXTO decimal y sale como TEXTO decimal con 4
     * decimales. Un `float` se RECHAZA: un flotante no es dinero.
     *
     * @throws RuntimeException Cuando el importe no es un numero decimal.
     */
    private function decimal(mixed $value): string
    {
        if (is_float($value)) {
            throw new RuntimeException(
                'El importe de la cuota debe ser un texto decimal, nunca un float.'
            );
        }

        $amount = is_string($value) ? trim($value) : (string) $value;

        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new RuntimeException("El importe de la cuota '{$amount}' no es un numero decimal.");
        }

        return (string) BigDecimal::of($amount)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
