<?php

namespace App\Domain\Accounting;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * La caja de una tienda, por FECHA DE NEGOCIO.
 *
 * Una tienda tiene UN registro de caja por fecha de negocio: `cash_registers`
 * lleva UNIQUE sobre `(store_id, business_date)`. Eso es lo que hace que "el
 * cierre de caja del dia" sea un HECHO y no una suma.
 *
 * REGLA que fija este servicio: un movimiento cae en el registro de caja de SU
 * fecha de negocio, NO en el de "hoy". La asociacion es por la CLAVE NATURAL
 * (`store_id`, `business_date`): `ledger_entries` ya tiene esas dos columnas y
 * `cash_registers` tiene UNIQUE sobre ellas, asi que la asociacion NO necesita
 * una columna `cash_register_id` anadida: seria redundante y podria
 * desincronizarse de la clave natural.
 *
 * `business_date` es un DATO del llamante, NUNCA un `DAY()/MONTH()/YEAR()`
 * calculado (el legacy filtraba asi: trampa 62, y partia el dia a la
 * medianoche del servidor en vez de al del negocio). El servicio no le
 * pregunta la hora al reloj: recibe la fecha de negocio.
 *
 * El saldo EN CURSO de la caja se DERIVA de los asientos (`ledger_entries` de
 * esa `business_date`), no de un contador. `opening_balance` es el saldo de
 * PARTIDA (el que se cuenta a mano antes de abrir) y `closing_balance` el
 * OBSERVADO al cerrar (el conteo de una caja es un hecho fisico): los dos son
 * DATOS, no cuentas.
 *
 * Quien abre la caja y quien la cierra son los RESPONSABLES de los dos
 * extremos: `opened_by` y `closed_by`. Los dos pueden faltar, y cuando
 * faltan el hueco se ve, porque no se inventa un valor.
 */
final readonly class CashRegisterService
{
    public function __construct(private Connection $connection) {}

    /**
     * Abre la caja de una tienda para una fecha de negocio.
     *
     * REGLAS que fija este metodo:
     *
     *  1. Una tienda tiene UNA caja por fecha de negocio. Abrir dos veces la
     *     misma fecha es un ERROR con mensaje util, no un error de PostgreSQL:
     *     la UNIQUE de la base lo impide, pero el mensaje lo dice el dominio.
     *  2. El `openingBalance` es el saldo de PARTIDA que se CUENTA A MANO: es
     *     un DATO, no un calculo. Viaja como TEXTO decimal, nunca como float.
     *  3. `businessDate` es la FECHA DE NEGOCIO y es un DATO del llamante.
     *  4. Quien ABRE la caja queda en `opened_by`, igual que quien la cierra
     *     queda en `closed_by`: los dos son responsables del mismo hecho. Si
     *     no se sabe quien abre, la columna queda en `null`: el hueco se ve.
     *
     * @param  int  $storeId  Tienda que abre la caja.
     * @param  DateTimeImmutable  $businessDate  Fecha de negocio: un dato del llamante.
     * @param  string  $openingBalance  Saldo de partida, contado a mano. Texto decimal.
     * @param  int|null  $bankAccountId  Cuenta bancaria que sostiene la caja.
     * @param  int|null  $actorId  Quien abre la caja. Se guarda en
     *                             `opened_by`: el responsable de la apertura
     *                             es tan responsable como el del cierre. Si
     *                             es `null`, la columna queda en `null`: no se
     *                             inventa un valor.
     *
     * @throws RuntimeException Cuando la tienda ya tiene caja para esa fecha.
     */
    public function open(
        int $storeId,
        DateTimeImmutable $businessDate,
        string $openingBalance = '0.0000',
        ?int $bankAccountId = null,
        ?int $actorId = null,
    ): int {
        $fecha = $businessDate->format('Y-m-d');
        $saldo = $this->decimal($openingBalance);

        if ($this->existeCaja($storeId, $fecha)) {
            throw new RuntimeException(
                "La tienda {$storeId} ya tiene una caja abierta para la fecha de negocio {$fecha}."
            );
        }

        try {
            $id = $this->connection->table('cash_registers')->insertGetId([
                'store_id' => $storeId,
                'business_date' => $fecha,
                'bank_account_id' => $bankAccountId,
                'opened_at' => $this->instante(),
                'opening_balance' => $saldo,
                'status' => 'open',
                'opened_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos cajas se abrieron a la vez: la UNIQUE lo impide, pero el
            // mensaje sigue siendo el del dominio, no el de PostgreSQL.
            throw new RuntimeException(
                "La tienda {$storeId} ya tiene una caja abierta para la fecha de negocio {$fecha}."
            );
        }

        return (int) $id;
    }

    /**
     * Cierra la caja de una tienda.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El `closingBalance` es el saldo OBSERVADO al contar: un DATO (el
     *     conteo de una caja es un hecho fisico), no una cuenta derivada.
     *  2. Una caja que NO existe no se cierra en silencio: es un error.
     *  3. Una caja CERRADA no se vuelve a cerrar. El cierre es de UNA unidad:
     *     la actualizacion solo aplica mientras la caja siga ABIERTA.
     *
     * @param  int  $registerId  Caja que se cierra.
     * @param  string  $closingBalance  Saldo observado al contar. Texto decimal.
     * @param  int|null  $actorId  Quien cierra la caja.
     *
     * @throws RuntimeException Cuando la caja no existe o ya esta cerrada.
     */
    public function close(int $registerId, string $closingBalance, ?int $actorId = null): void
    {
        $saldo = $this->decimal($closingBalance);

        $caja = $this->connection->table('cash_registers')->where('id', $registerId)->first();

        if ($caja === null) {
            throw new RuntimeException("La caja {$registerId} no existe.");
        }

        if ((string) $caja->status === 'closed') {
            throw new RuntimeException("La caja {$registerId} ya esta cerrada.");
        }

        $afectadas = $this->connection->table('cash_registers')
            ->where('id', $registerId)
            ->where('status', 'open')
            ->update([
                'status' => 'closed',
                'closed_at' => $this->instante(),
                'closing_balance' => $saldo,
                'closed_by' => $actorId,
                'updated_at' => now(),
            ]);

        // Otra caja se cerro entre la lectura y la escritura: el cierre sigue
        // siendo de una sola vez.
        if ($afectadas === 0) {
            throw new RuntimeException("La caja {$registerId} ya esta cerrada.");
        }
    }

    /**
     * El registro de caja de UNA tienda en UNA fecha de negocio.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El registro se resuelve por la CLAVE NATURAL `(store_id,
     *     business_date)`: por eso un movimiento cae en el registro de SU
     *     fecha de negocio y no en el de "hoy".
     *  2. Una caja que no existe para esa fecha NO es un fallo silencioso: el
     *     error nombra la tienda y la fecha.
     *  3. Una caja CERRADA no admite mas movimientos.
     *
     * @param  int  $storeId  Tienda de la caja.
     * @param  DateTimeImmutable  $businessDate  Fecha de negocio: un dato del llamante.
     * @return object La fila de `cash_registers` de esa fecha de negocio.
     *
     * @throws RuntimeException Cuando no hay caja para esa fecha, o esta cerrada.
     */
    public function registerFor(int $storeId, DateTimeImmutable $businessDate): object
    {
        $fecha = $businessDate->format('Y-m-d');

        $caja = $this->connection->table('cash_registers')
            ->where('store_id', $storeId)
            ->where('business_date', $fecha)
            ->first();

        if ($caja === null) {
            throw new RuntimeException(
                "La tienda {$storeId} no tiene caja para la fecha de negocio {$fecha}."
            );
        }

        if ((string) $caja->status === 'closed') {
            throw new RuntimeException(
                "La caja de la tienda {$storeId} para la fecha de negocio {$fecha} ya esta cerrada."
            );
        }

        return $caja;
    }

    /**
     * Si la tienda ya tiene caja para esa fecha de negocio.
     */
    private function existeCaja(int $storeId, string $fecha): bool
    {
        return $this->connection->table('cash_registers')
            ->where('store_id', $storeId)
            ->where('business_date', $fecha)
            ->exists();
    }

    /**
     * El instante, con su desplazamiento: `opened_at` y `closed_at` son
     * `timestamptz` y no pueden perder la zona.
     */
    private function instante(): string
    {
        return now()->format('Y-m-d H:i:sP');
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
                'El importe de la caja debe ser un texto decimal, nunca un float.'
            );
        }

        $amount = is_string($value) ? trim($value) : (string) $value;

        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new RuntimeException("El importe de la caja '{$amount}' no es un numero decimal.");
        }

        return (string) BigDecimal::of($amount)->toScale(4, RoundingMode::HALF_UP);
    }
}
