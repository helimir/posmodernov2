<?php

namespace App\Domain\Accounting;

use App\Support\Pos\PosAmount;
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
 * LAS TRES REGLAS QUE FIJA ESTE SERVICIO:
 *
 *  1. ABRIR DEJA ASIENTO. El saldo de partida es un HECHO CONTABLE, no un
 *     dato suelto: al abrir la caja se escribe un asiento de tipo
 *     `opening_balance` por ese importe, con la cuenta de efectivo de la
 *     tienda y la de capital, y en la MISMA transaccion que la apertura. Si el
 *     asiento falla, la caja NO queda abierta: una caja sin reflejo en el
 *     libro es una caja que no cuadra con nadie.
 *  2. CERRAR SELLA EL Z. El cierre escribe el Z en la MISMA transaccion: si
 *     el snapshot falla, el cierre falla. No hay un dia cerrado sin Z.
 *  3. REABRIR ES UN DESHACER CON MOTIVO. Solo sobre una caja cerrada, con
 *     el motivo obligatorio, y el Z anterior NO se borra: se queda, marcado.
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
     *  5. ABRIR DEJA ASIENTO. El saldo de partida se asienta en la cuenta de
     *     efectivo de la tienda, con la de capital como contrapartida, y en la
     *     MISMA transaccion que la apertura. Una tienda sin plan de cuentas
     *     NO puede abrir la caja: una caja sin cuenta donde asentar no es una
     *     caja.
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
     * @throws RuntimeException Cuando la tienda ya tiene caja para esa fecha,
     *                          o cuando no tiene plan de cuentas.
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

        // Las CUENTAS del plan se resuelven ANTES de abrir: una tienda sin
        // cuenta donde asentar no abre caja.
        $cuentas = $this->cuentasDelPlan($storeId);

        return $this->connection->transaction(function () use (
            $storeId,
            $fecha,
            $saldo,
            $bankAccountId,
            $actorId,
            $businessDate,
            $cuentas
        ): int {
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

            // EL ASIENTO DE APERTURA: en la MISMA transaccion que la caja. Si
            // el asiento falla, la caja NO queda abierta.
            $this->asientaLaPartida(
                $storeId,
                $businessDate,
                (int) $id,
                $saldo,
                $cuentas,
                $actorId
            );

            return (int) $id;
        });
    }

    /**
     * Cierra la caja de una tienda Y SELLA SU Z.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El `closingBalance` es el saldo OBSERVADO al contar: un DATO (el
     *     conteo de una caja es un hecho fisico), no una cuenta derivada.
     *  2. Una caja que NO existe no se cierra en silencio: es un error.
     *  3. Una caja CERRADA no se vuelve a cerrar. El cierre es de UNA unidad:
     *     la actualizacion solo aplica mientras la caja siga ABIERTA.
     *  4. EL Z SE SELLA EN LA MISMA TRANSACCION. El Z es el cierre hecho
     *     HECHO: si el snapshot falla, el cierre falla. No hay un dia cerrado
     *     sin Z.
     *  5. UN DESCUADRE POR ENCIMA DEL UMBRAL NO SE CIERRA SIN MOTIVO. El
     *     motivo se guarda en `cash_registers.note` y en el Z, y el error
     *     dice el descuadre y dice que hace falta el motivo.
     *
     * @param  int  $registerId  Caja que se cierra.
     * @param  string  $closingBalance  Saldo observado al contar. Texto decimal.
     * @param  int|null  $actorId  Quien cierra la caja.
     * @param  string|null  $motivo  Por que se cierra asi. Obligatorio si el
     *                               descuadre supera el umbral.
     *
     * @throws RuntimeException Cuando la caja no existe, ya esta cerrada, el
     *                          descuadre exige un motivo y no lo hay, o el Z
     *                          no se puede sellar.
     */
    public function close(
        int $registerId,
        string $closingBalance,
        ?int $actorId = null,
        ?string $motivo = null,
    ): void {
        $saldo = $this->decimal($closingBalance);

        $this->cierra($registerId, $saldo, null, $actorId, $motivo);
    }

    /**
     * Cierra la caja POR CONTEO DE PIEZAS: el cajero cuenta las piezas y el
     * sistema CALCULA el importe.
     *
     * REGLA que fija este metodo: *el cierre por conteo NO acepta el importe
     * aparte*. Son DOS VERDADES: la que cuenta el cajero y la que se teclea.
     * El sistema calcula el importe a partir de las piezas, y ese importe es
     * el que se guarda. Por eso este metodo y `close()` son DOS CAMINOS y no
     * dos parametros: un cierre tiene una sola verdad.
     *
     * @param  int  $registerId  Caja que se cierra.
     * @param  array<int, int>  $conteo  Las piezas contadas, por id de
     *                                   denominacion.
     * @param  int|null  $actorId  Quien cierra la caja.
     * @param  string|null  $motivo  Por que se cierra asi.
     * @param  int|null  $currencyId  La MONEDA de la tienda: el conteo se
     *                                valida contra el MISMO catalogo que la
     *                                pantalla ensena. `null` significa "el
     *                                de la tienda entera".
     *
     * @throws RuntimeException Cuando una pieza no es del catalogo de la
     *                          tienda, o el descuadre exige un motivo y no
     *                          lo hay.
     */
    public function closeCounted(
        int $registerId,
        array $conteo,
        ?int $actorId = null,
        ?string $motivo = null,
        ?int $currencyId = null,
    ): void {
        $this->cierra($registerId, null, $conteo, $actorId, $motivo, $currencyId);
    }

    /**
     * EL CIERRE: lo que los dos caminos (por importe y por conteo de piezas)
     * tienen en comun.
     *
     * @param  int  $registerId  Caja que se cierra.
     * @param  string|null  $saldo  El saldo contado, cuando viene por importe.
     * @param  array<int, int>|null  $conteo  Las piezas contadas, cuando viene
     *                                        por conteo.
     * @param  int|null  $actorId  Quien cierra la caja.
     * @param  string|null  $motivo  Por que se cierra asi.
     * @param  int|null  $currencyId  La moneda del catalogo del conteo.
     */
    private function cierra(
        int $registerId,
        ?string $saldo,
        ?array $conteo,
        ?int $actorId,
        ?string $motivo,
        ?int $currencyId = null,
    ): void {
        $caja = $this->connection->table('cash_registers')->where('id', $registerId)->first();

        if ($caja === null) {
            throw new RuntimeException("La caja {$registerId} no existe.");
        }

        if ((string) $caja->status === 'closed') {
            throw new RuntimeException("La caja {$registerId} ya esta cerrada.");
        }

        $storeId = (int) $caja->store_id;
        $fecha = (string) $caja->business_date;

        // El CONTEO se convierte en IMPORTE antes de cerrar: el cajero cuenta
        // piezas, y el importe sale de ahi.
        if ($conteo !== null) {
            $denominaciones = new CashDenominations($this->connection);

            $saldo = $denominaciones->countedTotal(
                $conteo,
                $denominaciones->catalog($storeId, $currencyId)
            );
        }

        if ($saldo === null) {
            throw new RuntimeException(
                "El cierre de la caja {$registerId} necesita un saldo contado: "
                .'o un importe, o un conteo de piezas.'
            );
        }

        $razon = $motivo === null ? null : trim($motivo);
        $razon = $razon === '' ? null : $razon;

        $this->connection->transaction(function () use (
            $registerId,
            $saldo,
            $actorId,
            $storeId,
            $fecha,
            $caja,
            $razon,
            $conteo
        ): void {
            // EL ESPERADO: el saldo EN CURSO de la fecha de negocio. Se
            // calcula DENTRO de la transaccion, junto con el descuadre que
            // decide si hace falta un motivo.
            $cuentas = $this->cuentasDelPlan($storeId);
            $dia = new CashDay($this->connection);
            $negocio = new DateTimeImmutable($fecha);

            $enCurso = $dia->runningBalance($storeId, $negocio, $cuentas['cash']);
            $esperado = $dia->expected($enCurso);
            $descuadre = $dia->difference($saldo, $esperado);

            // EL MOTIVO: un descuadre por encima del umbral NO se cierra sin
            // explicacion. Aqui, dentro de la transaccion: si el motivo
            // falta, no se escribe NADA.
            $this->exigeMotivo($descuadre, $razon);

            $afectadas = $this->connection->table('cash_registers')
                ->where('id', $registerId)
                ->where('status', 'open')
                ->update([
                    'status' => 'closed',
                    'closed_at' => $this->instante(),
                    'closing_balance' => $saldo,
                    'closed_by' => $actorId,
                    // El MOTIVO vive en la caja: la columna que ya existia y
                    // que nadie leia.
                    'note' => $razon,
                    'updated_at' => now(),
                ]);

            // Otra caja se cerro entre la lectura y la escritura: el cierre sigue
            // siendo de una sola vez.
            if ($afectadas === 0) {
                throw new RuntimeException("La caja {$registerId} ya esta cerrada.");
            }

            // EL Z: el cierre HECHO, escrito en la misma transaccion. Si el
            // snapshot falla, el cierre falla con el: no hay un dia cerrado
            // sin Z.
            (new CashClose($this->connection))->seal(
                $storeId,
                $fecha,
                $registerId,
                $this->contenidoDelZ(
                    $storeId,
                    $fecha,
                    (string) $caja->opening_balance,
                    $saldo,
                    $esperado
                ),
                $actorId,
                $razon,
                $conteo
            );
        });
    }

    /**
     * EL MOTIVO: un descuadre por encima del umbral NO se cierra sin
     * explicacion.
     *
     * REGLA que fija este metodo: *un descuadre es una EXCEPCION, y una
     * excepcion sin explicacion no es una excepcion*. El umbral sale de la
     * configuracion (`config/cash.php`), y el defecto pide motivo para
     * cualquier descuadre que no sea cero.
     *
     * Y UNA CAJA QUE CUADRA NUNCA PIDE MOTIVO: el descuadre de cero no es un
     * descuadre, y un umbral en cero no puede convertir un arqueo que cuadra
     * en una excepcion.
     *
     * @param  string  $descuadre  El descuadre, con su signo. Texto decimal.
     * @param  string|null  $motivo  El motivo que se ha dado.
     *
     * @throws RuntimeException Cuando el descuadre exige un motivo y no lo
     *                          hay.
     */
    private function exigeMotivo(string $descuadre, ?string $motivo): void
    {
        $magnitud = PosAmount::decimal($descuadre)->abs();

        // Un descuadre de CERO no es un descuadre.
        if ($magnitud->isZero()) {
            return;
        }

        $umbral = PosAmount::decimal($this->umbralDeMotivo());

        // Por DEBAJO del umbral: no hace falta motivo.
        if ($magnitud->compareTo($umbral) < 0) {
            return;
        }

        if ($motivo !== null && trim($motivo) !== '') {
            return;
        }

        throw new RuntimeException(
            'El descuadre de la caja es de '.PosAmount::legible($magnitud->toScale(PosAmount::SCALE, RoundingMode::HALF_UP))
            .' y supera el umbral de '.PosAmount::legible($this->umbralDeMotivo()).': '
            .'un descuadre por encima del umbral no se cierra sin un MOTIVO.'
        );
    }

    /**
     * El UMBRAL a partir del cual un descuadre exige justificacion.
     *
     * REGLA que fija este metodo: *el umbral es un PARAMETRO de operacion*,
     * leido de la configuracion (`config/cash.php`), y no una columna: el
     * esquema de la Fase 0 (`store_settings`) no tiene donde encajar un
     * umbral de caja, y anadir una seria cambiar el esquema de la Fase 0 por
     * una necesidad de la Fase 2.
     *
     * Cuando el esquema admita un umbral POR TIENDA, esta es la lectura que
     * tiene que cambiar: el defecto se queda como el valor de la
     * configuracion.
     */
    private function umbralDeMotivo(): string
    {
        $umbral = config('cash.difference_reason_threshold', '0.0100');

        return PosAmount::text($umbral);
    }

    /**
     * REABRE una caja cerrada, con un MOTIVO.
     *
     * REGLA que fija este metodo: *un cierre se puede deshacer, pero solo con
     * un motivo y queda en el historial*. El motivo es OBLIGATORIO: un dia
     * reabierto sin motivo es un dia que nadie sabe por que se toco.
     *
     * Al reabrir, la fila del Z pasa a REABIERTA (con su motivo y quien) y la
     * caja vuelve a `open`. EL Z ANTERIOR NO SE BORRA: se queda, marcado. Al
     * volver a cerrar se escribe OTRA fila de Z, y la vigente es la ultima
     * fila no reabierta.
     *
     * @param  int  $registerId  Caja que se reabre.
     * @param  string  $motivo  POR QUE se reabre. Obligatorio.
     * @param  int|null  $actorId  Quien reabre.
     *
     * @throws RuntimeException Cuando el motivo falta, la caja no existe, no
     *                          esta cerrada, o no tiene un Z que reabrir.
     */
    public function reopen(int $registerId, string $motivo, ?int $actorId = null): void
    {
        $razon = trim($motivo);

        if ($razon === '') {
            throw new RuntimeException(
                'Un cierre se puede deshacer, pero solo con un MOTIVO: '
                .'un dia reabierto sin motivo es un dia que nadie sabe por que se toco.'
            );
        }

        $caja = $this->connection->table('cash_registers')->where('id', $registerId)->first();

        if ($caja === null) {
            throw new RuntimeException("La caja {$registerId} no existe.");
        }

        if ((string) $caja->status !== 'closed') {
            throw new RuntimeException(
                "La caja {$registerId} no esta cerrada: no hay un cierre que deshacer."
            );
        }

        $storeId = (int) $caja->store_id;
        $fecha = (string) $caja->business_date;

        $this->connection->transaction(function () use (
            $registerId,
            $razon,
            $actorId,
            $storeId,
            $fecha
        ): void {
            $z = (new CashClose($this->connection))->current($storeId, $fecha);

            if ($z === null) {
                throw new RuntimeException(
                    "La caja {$registerId} no tiene un Z sellado que reabrir."
                );
            }

            // EL Z ANTERIOR NO SE BORRA: se marca. El historial es lo que deja
            // ver que el dia se toco.
            (new CashClose($this->connection))->markReopened((int) $z->id, $razon, $actorId);

            // Y la caja vuelve a estar ABIERTA.
            $afectadas = $this->connection->table('cash_registers')
                ->where('id', $registerId)
                ->where('status', 'closed')
                ->update([
                    'status' => 'open',
                    'closed_at' => null,
                    'closed_by' => null,
                    'closing_balance' => null,
                    // El MOTIVO del cierre deshicho sale de la caja: el
                    // cierre ya no esta en pie. El Z anterior lo conserva,
                    // marcado.
                    'note' => null,
                    'updated_at' => now(),
                ]);

            if ($afectadas === 0) {
                throw new RuntimeException("La caja {$registerId} ya esta cerrada.");
            }
        });
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
     * Las CUENTAS del plan que la caja necesita: la de efectivo y la de
     * capital.
     *
     * REGLA que fija este metodo: *el plan de cuentas es un DATO del negocio y
     * no hay un plan por defecto*. Si a la tienda le falta la cuenta donde
     * asentar el saldo de partida, la apertura falla con un mensaje util: una
     * caja sin cuenta donde asentar no es una caja.
     *
     * @return array{cash: string, equity: string}
     *
     * @throws RuntimeException Cuando al plan le falta el rol de la caja o el
     *                          del capital.
     */
    private function cuentasDelPlan(int $storeId): array
    {
        $roles = (new ChartOfAccounts($this->connection))->roles($storeId);

        $efectivo = trim((string) ($roles['cash'] ?? ''));
        $capital = trim((string) ($roles['equity'] ?? ''));

        if ($efectivo === '') {
            throw new RuntimeException(
                "La tienda {$storeId} no tiene cuenta de efectivo en su plan de cuentas: "
                .'una caja sin cuenta donde asentar no es una caja.'
            );
        }

        if ($capital === '') {
            throw new RuntimeException(
                "La tienda {$storeId} no tiene cuenta de capital (el rol 'equity') en su plan de cuentas: "
                .'el saldo de partida necesita su contrapartida en el libro.'
            );
        }

        return ['cash' => $efectivo, 'equity' => $capital];
    }

    /**
     * EL ASIENTO DE APERTURA: el saldo de partida, en el libro.
     *
     * REGLA que fija este metodo: *el saldo de partida es un HECHO CONTABLE*.
     * Se carga a la cuenta de efectivo de la tienda (es un ACTIVO: lo que hay
     * en el cajon) y se abona a la de capital (de donde viene ese dinero), y
     * el importe del asiento es el del dato: si difieren, es un bug.
     *
     * Y UNA PARTIDA DE CERO NO SE ASIENTA: un asiento sin importe no es un
     * asiento, y el libro no admite lineas a cero en los dos sentidos.
     *
     * @param  array{cash: string, equity: string}  $cuentas
     */
    private function asientaLaPartida(
        int $storeId,
        DateTimeImmutable $businessDate,
        int $registerId,
        string $saldo,
        array $cuentas,
        ?int $actorId = null
    ): void {
        if (BigDecimal::of($saldo)->isZero()) {
            return;
        }

        (new LedgerWriter($this->connection))->write(
            entryType: 'opening_balance',
            storeId: $storeId,
            occurredAt: $this->momento(),
            businessDate: $businessDate,
            lines: [
                ['account' => $cuentas['cash'], 'debit' => $saldo, 'credit' => '0'],
                ['account' => $cuentas['equity'], 'debit' => '0', 'credit' => $saldo],
            ],
            referenceType: 'cash_register',
            referenceId: $registerId,
            actorId: $actorId,
            description: 'Saldo de partida de la caja',
        );
    }

    /**
     * EL CONTENIDO del Z: lo que se cerro ese dia.
     *
     * @param  string  $esperado  El esperado ya calculado en la transaccion
     *                            del cierre: no se recalcula aqui, para que
     *                            el Z y el umbral del motivo hablen del
     *                            MISMO numero.
     * @return array<string, mixed>
     */
    private function contenidoDelZ(
        int $storeId,
        string $fecha,
        string $partida,
        string $contado,
        string $esperado
    ): array {
        $dia = new CashDay($this->connection);
        $negocio = new DateTimeImmutable($fecha);

        $totales = $this->totalesDelDia($storeId, $fecha);
        $correlativos = $this->correlativosDelDia($storeId, $fecha);

        return [
            'business_date' => $fecha,
            'opening_balance' => $partida,
            'expected_balance' => $esperado,
            'counted_balance' => $contado,
            'difference' => $dia->difference($contado, $esperado),
            'subtotal' => $totales['subtotal'],
            'tax_total' => $totales['tax_total'],
            'grand_total' => $totales['grand_total'],
            'sales_count' => $totales['sales_count'],
            'first_document_number' => $correlativos['first_document_number'],
            'last_document_number' => $correlativos['last_document_number'],
            'first_control_number' => $correlativos['first_control_number'],
            'last_control_number' => $correlativos['last_control_number'],
            'payments' => $dia->receivedByPaymentMethod($storeId, $negocio),
        ];
    }

    /**
     * Los TOTALES del dia y el numero de ventas.
     *
     * @return array{subtotal: string, tax_total: string, grand_total: string, sales_count: int}
     */
    private function totalesDelDia(int $storeId, string $fecha): array
    {
        $totales = $this->connection->table('sales')
            ->leftJoin('sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->where('sales.store_id', $storeId)
            ->where('sales.sale_date', $fecha)
            // Una venta ANULADA no es una venta del dia: no cuenta ni para el
            // total ni para el numero de ventas.
            ->where('sales.status', '!=', 'cancelled')
            ->selectRaw(
                'COUNT(sales.id) as ventas, '
                .'COALESCE(SUM(sale_totals.sub_total), 0) as subtotal, '
                .'COALESCE(SUM(sale_totals.tax_total), 0) as tax_total, '
                .'COALESCE(SUM(sale_totals.grand_total), 0) as grand_total'
            )
            ->first();

        return [
            'subtotal' => (string) ($totales->subtotal ?? '0'),
            'tax_total' => (string) ($totales->tax_total ?? '0'),
            'grand_total' => (string) ($totales->grand_total ?? '0'),
            'sales_count' => (int) ($totales->ventas ?? 0),
        ];
    }

    /**
     * LOS CORRELATIVOS del dia: el primer y el ultimo documento, y el primer
     * y el ultimo control.
     *
     * El DOCUMENTO es el numero de la venta (`sales.invoice_no`), y el CONTROL
     * es el numero compuesto de la serie de control: el identificador de dos
     * digitos y el secuencial. Los dos salen de las FILAS: el Z no inventa un
     * correlativo.
     *
     * @return array{first_document_number: string|null, last_document_number: string|null, first_control_number: string|null, last_control_number: string|null}
     */
    private function correlativosDelDia(int $storeId, string $fecha): array
    {
        $documentos = $this->connection->table('sales')
            ->where('sales.store_id', $storeId)
            ->where('sales.sale_date', $fecha)
            ->where('sales.status', '!=', 'cancelled')
            ->orderBy('sales.id')
            ->pluck('sales.invoice_no');

        $controles = $this->connection->table('fiscal_document_reservations')
            ->join('fiscal_series', 'fiscal_series.id', '=', 'fiscal_document_reservations.series_id')
            ->join('sales', function ($join): void {
                $join->on('sales.id', '=', 'fiscal_document_reservations.reference_id')
                    ->where('fiscal_document_reservations.reference_type', '=', 'sale');
            })
            ->where('fiscal_document_reservations.series_role', 'control')
            ->where('sales.store_id', $storeId)
            ->where('sales.sale_date', $fecha)
            ->where('sales.status', '!=', 'cancelled')
            ->orderBy('sales.id')
            ->selectRaw(
                "COALESCE(fiscal_series.control_identifier, '00') || '-' || "
                ."lpad(fiscal_document_reservations.number::text, COALESCE(fiscal_series.sequence_length, 8), '0') "
                .'as control_number'
            )
            ->pluck('control_number');

        return [
            'first_document_number' => $this->primeroYUltimo($documentos)[0],
            'last_document_number' => $this->primeroYUltimo($documentos)[1],
            'first_control_number' => $this->primeroYUltimo($controles)[0],
            'last_control_number' => $this->primeroYUltimo($controles)[1],
        ];
    }

    /**
     * El PRIMERO y el ULTIMO valor de una lista de correlativos.
     *
     * @param  iterable<mixed>  $valores
     * @return array{0: string|null, 1: string|null}
     */
    private function primeroYUltimo(iterable $valores): array
    {
        $primero = null;
        $ultimo = null;

        foreach ($valores as $valor) {
            if ($valor === null) {
                continue;
            }

            $texto = trim((string) $valor);

            if ($texto === '') {
                continue;
            }

            $primero ??= $texto;
            $ultimo = $texto;
        }

        return [$primero, $ultimo];
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
     * El instante como `DateTimeImmutable`: lo que pide el escritor de
     * asientos.
     */
    private function momento(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(now());
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
