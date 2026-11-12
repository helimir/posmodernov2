<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\CashDay;
use App\Domain\Accounting\CashRegisterService;
use App\Models\CashRegister;
use App\Models\User;
use App\Support\Pos\PosAmount;
use App\Support\Pos\PosContext;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * La CAJA DEL DIA: la pagina con sus tres estados, la apertura y el cierre.
 *
 * Esta clase es la COSTEZA entre el navegador y el dominio de la caja. No
 * decide nada de negocio: la apertura y el cierre los hace
 * `CashRegisterService`, y las cifras del dia las deriva `CashDay`.
 *
 * REGLAS que fija esta clase:
 *
 *  - LA PAGINA es Inertia y las ESCRITURAS son JSON, como en el resto del
 *    repositorio: la pantalla se pide con un GET y lo que se escribe viaja
 *    por la API, con el mismo contrato de siempre (422 con `{"errorMsg"}`).
 *  - LA FECHA DE NEGOCIO la dice el CONTEXTO (`PosContext`), nunca un
 *    `DAY()/MONTH()/YEAR()` calculado: es la fecha de la TIENDA, con su zona
 *    horaria.
 *  - EL SALDO DE PARTIDA y el SALDO CONTADO son DATOS, no cuentas: se
 *    CUENTAN a mano, se validan como texto decimal y no pueden ser
 *    negativos (una caja no arranca con saldo negativo, ni se cierra con
 *    saldo negativo).
 *  - QUIEN ABRE y QUIEN CIERRA son los RESPONSABLES de los dos extremos:
 *    el usuario de la sesion. Y si no se sabe, la columna queda en `null` y
 *    la pantalla ensena un hueco: no se inventa un valor.
 *
 * Los importes viajan como TEXTO decimal (`'120.0000'`), nunca como `float`.
 */
class CashDayController extends Controller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CashRegisterService $registers,
        private readonly CashDay $day,
    ) {}

    /**
     * La PAGINA de la caja del dia.
     *
     * El CONTRATO de los props es el de `resources/js/pages/cash/day.tsx`:
     *
     *  - `state`: `not_opened` (no hay caja), `open` (esta abierta) o
     *    `closed` (esta cerrada). Los tres estados de la misma pagina.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     *  - `business_date`: `'Y-m-d'`, la fecha de NEGOCIO de la tienda.
     *  - `currency`: `{ code, symbol, symbol_position, decimals,
     *    thousand_separator, decimal_separator }`.
     *  - `register`: `null` si no hay caja, y si la hay
     *    `{ id, status, opened_at, opening_balance, closed_at,
     *    closing_balance, opened_by, closed_by }`. Los dos responsables son
     *    `{ id, name }` o `null`: el hueco se ve, no se inventa.
     *  - `opening_balance`: el saldo de PARTIDA (un dato). Texto decimal.
     *  - `running_balance`: el saldo EN CURSO (un derivado de los asientos).
     *    Texto decimal.
     *  - `expected_balance`: la partida mas el en curso. Texto decimal.
     *  - `counted_balance`: el saldo CONTADO (un dato), o `null` si la caja
     *    todavia no se ha cerrado. Texto decimal.
     *  - `difference`: el DESCUADRE (el contado menos el esperado, con su
     *    signo), o `null` si la caja todavia no se ha cerrado. Texto decimal.
     *  - `payments`: lo que entro en la caja por cada medio de pago:
     *    `{ id, name, type, amount, is_cash }`. Los importes son TEXTO
     *    decimal.
     *  - `cash_total`: lo que es dinero de la caja (todo lo que no es un
     *    pago a credito). Texto decimal.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('cash/day', $this->escenario($request));
    }

    /**
     * La APERTURA de la caja del dia, con su saldo de partida.
     *
     * El saldo de partida es un DATO: el dinero que hay en la caja al
     * empezar, contado a mano. Quien abre es el usuario de la sesion.
     *
     * Si la tienda ya tiene caja para esa fecha de negocio, el dominio falla
     * y la respuesta es un 422 con su mensaje: una tienda tiene UNA caja por
     * fecha de negocio.
     */
    public function abrir(Request $request): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validar($datos, 'opening_balance');

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        $contexto = new PosContext($this->connection, $request->user()?->id);

        try {
            $store = $contexto->store();
            $fecha = $contexto->businessDate();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        try {
            $id = $this->registers->open(
                (int) $store->id,
                $fecha,
                PosAmount::declare($datos['opening_balance'], 'saldo de partida'),
                null,
                $request->user()?->id
            );
        } catch (RuntimeException $fallo) {
            // La tienda ya tiene caja para esa fecha, o el saldo de partida
            // no es un numero decimal: es un fallo de la OPERACION, no del
            // servidor.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $caja = CashRegister::query()->findOrFail($id);

        return response()->json([
            'id' => (int) $caja->id,
            'status' => (string) $caja->status,
            'business_date' => $fecha->format('Y-m-d'),
            'opening_balance' => PosAmount::text($caja->opening_balance),
            'opened_by' => $this->responsable($caja->openedBy),
        ], 201);
    }

    /**
     * El CIERRE de la caja del dia, con su saldo contado.
     *
     * El saldo contado es un DATO: el conteo de una caja es un hecho fisico.
     * Quien cierra es el usuario de la sesion.
     *
     * Si no hay caja para esa fecha de negocio, o si ya esta cerrada, el
     * dominio falla y la respuesta es un 422 con su mensaje.
     */
    public function cerrar(Request $request): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validar($datos, 'closing_balance');

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        $contexto = new PosContext($this->connection, $request->user()?->id);

        try {
            $store = $contexto->store();
            $fecha = $contexto->businessDate();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $caja = CashRegister::query()
            ->where('store_id', (int) $store->id)
            ->where('business_date', $fecha->format('Y-m-d'))
            ->first();

        if ($caja === null) {
            return response()->json([
                'errorMsg' => 'La tienda '.$store->id.' no tiene caja para la fecha de negocio '
                    .$fecha->format('Y-m-d').': no hay nada que cerrar.',
            ], 422);
        }

        try {
            $this->registers->close(
                (int) $caja->id,
                PosAmount::declare($datos['closing_balance'], 'saldo contado'),
                $request->user()?->id
            );
        } catch (RuntimeException $fallo) {
            // La caja ya esta cerrada: el cierre es de UNA unidad.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $cerrada = CashRegister::query()->findOrFail((int) $caja->id);

        try {
            $cifras = $this->cifras(
                (int) $store->id,
                $fecha,
                $this->cuentaDeEfectivo($contexto),
                $cerrada
            );
        } catch (RuntimeException $fallo) {
            // El plan de cuentas de la tienda esta incompleto: el cierre no
            // puede contrastar un saldo que no existe.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        return response()->json([
            'id' => (int) $cerrada->id,
            'status' => (string) $cerrada->status,
            'business_date' => $fecha->format('Y-m-d'),
            'closing_balance' => PosAmount::text($cerrada->closing_balance),
            'expected_balance' => $cifras['expected_balance'],
            'difference' => $cifras['difference'],
            'closed_by' => $this->responsable($cerrada->closedBy),
        ], 201);
    }

    /**
     * El ESCENARIO de la pagina: la tienda, la moneda y las cifras del dia.
     *
     * @return array<string, mixed>
     */
    private function escenario(Request $request): array
    {
        $contexto = new PosContext($this->connection, $request->user()?->id);

        try {
            $store = $contexto->store();
            $moneda = $contexto->currency();
        } catch (RuntimeException $fallo) {
            // Sin tienda activa o sin moneda la pagina NO abre: no se sirve
            // una pantalla de una tienda que no existe.
            abort(409, $fallo->getMessage());
        }

        $fecha = $contexto->businessDate();
        $storeId = (int) $store->id;

        $caja = CashRegister::query()
            ->where('store_id', $storeId)
            ->where('business_date', $fecha->format('Y-m-d'))
            ->first();

        try {
            $cifras = $this->cifras($storeId, $fecha, $this->cuentaDeEfectivo($contexto), $caja);
        } catch (RuntimeException $fallo) {
            // El plan de cuentas de la tienda esta incompleto: sin la cuenta de
            // efectivo no hay saldo en curso, y una pagina sin saldo en curso
            // es una pagina que miente.
            abort(409, $fallo->getMessage());
        }

        $pagos = $this->day->receivedByPaymentMethod($storeId, $fecha);

        return [
            'state' => $this->estado($caja),
            'store' => [
                'id' => $storeId,
                'name' => (string) $store->name,
                'code_name' => $store->code_name,
            ],
            'business_date' => $fecha->format('Y-m-d'),
            'currency' => $moneda,
            'register' => $caja === null ? null : $this->cajaProps($caja),
            ...$cifras,
            'payments' => $pagos,
            'cash_total' => $this->day->cashTotal($pagos),
        ];
    }

    /**
     * LAS CIFRAS del dia: la partida, el en curso, el esperado, el contado y
     * el descuadre.
     *
     * @return array{opening_balance: string, running_balance: string, expected_balance: string, counted_balance: string|null, difference: string|null}
     */
    private function cifras(
        int $storeId,
        DateTimeImmutable $fecha,
        string $cuentaDeEfectivo,
        ?CashRegister $caja
    ): array {
        // El SALDO EN CURSO: un DERIVADO de los asientos de esta fecha de
        // negocio.
        $enCurso = $this->day->runningBalance($storeId, $fecha, $cuentaDeEfectivo);

        // El SALDO DE PARTIDA: un DATO, el que se conto a mano.
        $partida = $caja === null ? '0.0000' : PosAmount::text($caja->opening_balance);

        // El ESPERADO: la partida mas el en curso.
        $esperado = $this->day->expected($partida, $enCurso);

        // El CONTADO y el DESCUADRE: solo hay counted cuando la caja esta
        // cerrada, porque antes no hay nada que contar.
        $contado = null;
        $descuadre = null;

        if ($caja !== null && $caja->closing_balance !== null) {
            $contado = PosAmount::text($caja->closing_balance);
            $descuadre = $this->day->difference($contado, $esperado);
        }

        return [
            'opening_balance' => $partida,
            'running_balance' => $enCurso,
            'expected_balance' => $esperado,
            'counted_balance' => $contado,
            'difference' => $descuadre,
        ];
    }

    /**
     * La CUENTA DE EFECTIVO de la tienda: la que dice el plan de cuentas.
     *
     * Sin ella el saldo en curso NO se puede derivar, y un saldo en curso
     * inventado es peor que una pagina que no abre.
     *
     * @throws RuntimeException Cuando el plan de cuentas no declara la cuenta
     *                          de efectivo.
     */
    private function cuentaDeEfectivo(PosContext $contexto): string
    {
        $cuenta = $contexto->accounts()['cash'] ?? null;

        if ($cuenta === null || trim($cuenta) === '') {
            throw new RuntimeException(
                'La tienda '.$contexto->store()->id.' no tiene cuenta de efectivo en su plan de cuentas: '
                .'sin ella no se puede derivar el saldo en curso de la caja.'
            );
        }

        return trim($cuenta);
    }

    /**
     * El ESTADO de la pagina: los tres estados de la misma pantalla.
     */
    private function estado(?CashRegister $caja): string
    {
        if ($caja === null) {
            return 'not_opened';
        }

        return (string) $caja->status === 'closed' ? 'closed' : 'open';
    }

    /**
     * La CAJA, tal y como la ensena la pantalla.
     *
     * @return array<string, mixed>
     */
    private function cajaProps(CashRegister $caja): array
    {
        return [
            'id' => (int) $caja->id,
            'status' => (string) $caja->status,
            'opened_at' => $caja->opened_at?->toIso8601String(),
            'opening_balance' => PosAmount::text($caja->opening_balance),
            'closed_at' => $caja->closed_at?->toIso8601String(),
            'closing_balance' => $caja->closing_balance === null
                ? null
                : PosAmount::text($caja->closing_balance),
            // Los dos RESPONSABLES: los que abrieron y los que cerraron. Si
            // no se sabe, el hueco se ve.
            'opened_by' => $this->responsable($caja->openedBy),
            'closed_by' => $this->responsable($caja->closedBy),
        ];
    }

    /**
     * Un RESPONSABLE de un extremo de la caja.
     *
     * @return array{id: int, name: string}|null
     */
    private function responsable(?User $usuario): ?array
    {
        if ($usuario === null) {
            return null;
        }

        return [
            'id' => (int) $usuario->id,
            'name' => (string) $usuario->name,
        ];
    }

    /**
     * VALIDA un importe de la caja.
     *
     * REGLA que fija este metodo: *un saldo de caja es un TEXTO decimal, y no
     * puede ser negativo*. El saldo de partida se cuenta a mano y el saldo
     * contado tambien: los dos son DATOS, y un dato que no es un numero
     * decimal no es un dato.
     *
     * @param  array<string, mixed>  $datos
     * @param  string  $campo  El campo que se valida.
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validar(array $datos, string $campo): ?string
    {
        $validador = Validator::make($datos, [
            $campo => ['required', 'string', 'decimal:0,4', 'min:0'],
        ]);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }
}
