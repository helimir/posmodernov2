<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\CashClose;
use App\Domain\Accounting\CashDay;
use App\Domain\Accounting\CashDenominations;
use App\Domain\Accounting\CashRegisterService;
use App\Models\CashRegister;
use App\Models\PrintTarget;
use App\Models\User;
use App\Support\Audit\AuditWriter;
use App\Support\Pos\PosAmount;
use App\Support\Pos\PosContext;
use App\Support\Store\StoreThresholds;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 *  - EL Z SELLADO es un HECHO: cuando el dia esta cerrado, sus cifras se
 *    LEEN, no se recalculan. Y REABRIR un dia exige un MOTIVO y deja el Z
 *    anterior en el historial, marcado.
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
     *  - `expected_balance`: el saldo en curso, y SOLO el saldo en curso: el
     *    saldo de partida ya esta en el libro, con su asiento de apertura.
     *    Texto decimal.
     *  - `counted_balance`: el saldo CONTADO (un dato), o `null` si la caja
     *    todavia no se ha cerrado. Texto decimal.
     *  - `difference`: el DESCUADRE (el contado menos el esperado, con su
     *    signo), o `null` si la caja todavia no se ha cerrado. Texto decimal.
     *  - `payments`: lo que entro en la caja por cada medio de pago:
     *    `{ id, name, type, amount, is_cash }`. Los importes son TEXTO
     *    decimal.
     *  - `cash_total`: lo que es dinero de la caja (todo lo que no es un
     *    pago a credito). Texto decimal.
     *  - `z`: el Z SELLADO del dia, o `null` si el dia no esta cerrado. Trae
     *    su contenido, quien lo cerro, cuando, su firma (`content_hash`) y si
     *    su firma SIGUE CUADRANDO (`verified`).
     *  - `z_history`: el historial de Z del dia: cada cierre, con su firma y
     *    con la marca de si se reabrió (y por que).
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
     * EL CIERRE TIENE UNA SOLA VERDAD: o viene el IMPORTE que el cajero
     * teclea, o viene el CONTEO DE PIEZAS que el cajero cuenta (y el sistema
     * calcula el importe a partir de las piezas). Los dos a la vez se
     * rechazan: son dos verdades incompatibles y el cierre solo puede tener
     * una.
     *
     * Y EL MOTIVO: un descuadre por encima del umbral no se cierra sin
     * explicacion, y el dominio lo dice si falta.
     *
     * Si no hay caja para esa fecha de negocio, o si ya esta cerrada, el
     * dominio falla y la respuesta es un 422 con su mensaje.
     */
    public function cerrar(Request $request): JsonResponse
    {
        $datos = $request->all();

        $motivo = trim((string) ($datos['reason'] ?? ''));

        $conteo = $datos['counted_pieces'] ?? null;
        $importe = $datos['closing_balance'] ?? null;

        // UNA SOLA VERDAD: el importe o el conteo de piezas.
        if ($conteo !== null && $importe !== null) {
            return response()->json([
                'errorMsg' => 'El cierre se hace POR IMPORTE o POR CONTEO DE PIEZAS, pero no por los dos: '
                    .'son dos verdades y el cierre solo puede tener una.',
            ], 422);
        }

        if ($conteo === null) {
            $error = $this->validar($datos, 'closing_balance');
        } else {
            $error = $this->validarConteo($conteo);
        }

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
            if ($conteo === null) {
                $this->registers->close(
                    (int) $caja->id,
                    PosAmount::declare($importe, 'saldo contado'),
                    $request->user()?->id,
                    $motivo
                );
            } else {
                // El conteo se valida contra el MISMO catalogo que la
                // pantalla ensena: el de la MONEDA de la tienda. Y la moneda
                // se resuelve aqui, y no en el camino por importe: el cierre
                // por importe no depende de que la tienda tenga moneda.
                $this->registers->closeCounted(
                    (int) $caja->id,
                    $conteo,
                    $request->user()?->id,
                    $motivo,
                    (int) $this->connection->table('currencies')
                        ->where('code', $contexto->currency()['code'])
                        ->value('id')
                );
            }
        } catch (RuntimeException $fallo) {
            // La caja ya esta cerrada (el cierre es de UNA unidad), el Z no
            // se pudo sellar, o el descuadre exige un MOTIVO y no lo hay: en
            // los tres casos el cierre NO ha pasado, porque el Z se sella en
            // la misma transaccion.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $cerrada = CashRegister::query()->findOrFail((int) $caja->id);

        // EL Z SELLADO: el cierre HECHO, con su contenido y su firma. Las
        // cifras del cierre son las del Z, no un recalculo.
        $z = $this->day->zSellado((int) $store->id, $fecha);

        if ($z === null) {
            // El cierre sin Z no deberia pasar: el Z se sella en la misma
            // transaccion del cierre. Si no esta, se dice.
            return response()->json([
                'errorMsg' => 'La caja se cerro, pero su Z no se sello: el cierre de la tienda '
                    .$store->id.' para la fecha de negocio '.$fecha->format('Y-m-d').' no tiene Z.',
            ], 500);
        }

        return response()->json([
            'id' => (int) $cerrada->id,
            'status' => (string) $cerrada->status,
            'business_date' => $fecha->format('Y-m-d'),
            'closing_balance' => PosAmount::text($cerrada->closing_balance),
            'expected_balance' => PosAmount::text($z->expected_balance),
            'difference' => PosAmount::text($z->difference),
            'closed_by' => $this->responsable($cerrada->closedBy),
            'z' => $this->zProps($z),
        ], 201);
    }

    /**
     * REABRE la caja del dia, con un MOTIVO.
     *
     * REGLA que fija esta clase: *un cierre se puede deshacer, pero solo con
     * un motivo y queda en el historial*. El Z anterior NO se borra: se queda,
     * marcado, con quien lo reabrio y por que.
     *
     * Y CORREGIR UNA VENTA DE UN DIA CERRADO EXIGE REABRIR EL DIA: el flujo
     * de las ventas es de otra unidad, y esta pantalla no lo hace.
     */
    public function reabrir(Request $request): JsonResponse
    {
        $datos = $request->all();

        $motivo = trim((string) ($datos['reason'] ?? ''));

        if ($motivo === '') {
            return response()->json([
                'errorMsg' => 'Un cierre se puede deshacer, pero solo con un MOTIVO: '
                    .'un dia reabierto sin motivo es un dia que nadie sabe por que se toco.',
            ], 422);
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
                    .$fecha->format('Y-m-d').': no hay nada que reabrir.',
            ], 422);
        }

        try {
            $this->registers->reopen((int) $caja->id, $motivo, $request->user()?->id);
        } catch (RuntimeException $fallo) {
            // La caja no estaba cerrada, o no tiene un Z que reabrir.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        // LA AUDITORIA: quien deshizo un cierre, y por que. Un cierre que se
        // deshace sin dejar rastro es un cierre que nadie puede defender.
        (new AuditWriter($this->connection))->record(
            $request,
            'cash_register.reopened',
            'cash_register',
            (int) $caja->id,
            [
                'store_id' => (int) $store->id,
                'business_date' => $fecha->format('Y-m-d'),
                'reason' => $motivo,
            ],
        );

        return response()->json([
            'id' => (int) $caja->id,
            'status' => 'open',
            'business_date' => $fecha->format('Y-m-d'),
            'reopen_reason' => $motivo,
        ], 200);
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

        // EL Z SELLADO: si el dia esta cerrado, el cierre es un HECHO, con su
        // contenido y su firma. Y el HISTORIAL: lo que se cerro ese dia se
        // queda, aunque el dia se reabra.
        $z = $this->day->zSellado($storeId, $fecha);

        // EL CONteo por DENOMINACIONES: el catalogo de la tienda, el
        // desglose esperado del importe, y lo que el cajero conto.
        $conteo = $this->conteoDeLaCaja(
            $storeId,
            (int) $this->connection->table('currencies')->where('code', $moneda['code'])->value('id'),
            $cifras['expected_balance'],
            $z
        );

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
            'count' => $conteo,
            'z' => $z === null ? null : $this->zProps($z),
            'z_history' => $this->historialDeZ($storeId, $fecha),
            // Los DESTINOS de impresion de la tienda: donde sale el Z.
            'print_targets' => $this->destinosDeImpresion($storeId),
        ];
    }

    /**
     * Los DESTINOS de impresion FISICOS de la tienda: los que escriben
     * bytes en una impresora.
     *
     * El transporte `browser` no sale: el navegador es el destino del PAPEL
     * (el HTML del Z), no de los bytes.
     *
     * @return list<array{id: int, name: string, transport: string}>
     */
    private function destinosDeImpresion(int $storeId): array
    {
        $filas = $this->connection->table('print_targets')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->whereIn('transport', PrintTarget::TRANSPORTES_FISICOS)
            ->orderBy('name')
            ->get(['id', 'name', 'transport']);

        $destinos = [];

        foreach ($filas as $fila) {
            $destinos[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                'transport' => (string) $fila->transport,
            ];
        }

        return $destinos;
    }

    /**
     * Las IMPRESIONES del Z: cuantos trabajos de impresion ha salido de el.
     *
     * Es lo que hace que la pantalla pida un MOTIVO antes de volver a
     * imprimirlo: el Z es el documento del cierre.
     */
    private function impresionesDelZ(int $cashCloseId): int
    {
        return (int) $this->connection->table('print_jobs')
            ->where('cash_close_id', $cashCloseId)
            ->count();
    }

    /**
     * EL CONteo por DENOMINACIONES: el catalogo de la tienda, el desglose
     * esperado del importe y lo que el cajero conto.
     *
     * REGLA que fija este metodo: *el cajero cuenta PIEZAS*. La pantalla
     * ensena el catalogo de la tienda con el ESPERADO (cuantas piezas
     * deberia haber) y el CONTADO (las que cuenta), y el total contado frente
     * al esperado.
     *
     * Y CUANDO EL IMPORTE NO SE PUEDE FORMAR con el catalogo de la tienda, el
     * desglose lo dice (`expected_error`): un arqueo que no se puede
     * desglosar es un arqueo que hay que decir.
     *
     * @return array<string, mixed>
     */
    private function conteoDeLaCaja(
        int $storeId,
        int $currencyId,
        string $esperado,
        ?object $z
    ): array {
        $denominaciones = new CashDenominations($this->connection);

        $catalogo = $denominaciones->catalog($storeId, $currencyId);
        $desglose = $denominaciones->expected($esperado, $catalogo);

        $conteo = [];
        $diferencias = [];

        if ($z !== null) {
            $conteo = $this->piezasDelZ($z);
            $diferencias = $denominaciones->differences($desglose['pieces'], $conteo);
        }

        return [
            'denominations' => array_map(static fn (array $denominacion): array => [
                'id' => $denominacion['id'],
                'value' => $denominacion['value'],
                'kind' => $denominacion['kind'],
            ], $catalogo),
            'expected' => $desglose['pieces'],
            'expected_error' => $desglose['missing'],
            'expected_total' => $desglose['total'],
            'counted' => $conteo,
            'differences' => $diferencias,
            // El UMBRAL que APLICA a esta tienda: el que ella declara, o el
            // global si no declara ninguno. La pantalla ensena el mismo
            // numero que aplica el cierre.
            'threshold' => (new StoreThresholds($this->connection))->umbralDeDescuadre($storeId),
        ];
    }

    /**
     * Las PIEZAS contadas que guarda el Z: un mapa de id de denominacion a
     * cantidad.
     *
     * @return array<int, int>
     */
    private function piezasDelZ(object $z): array
    {
        $crudos = $z->counted_pieces ?? null;

        if (is_string($crudos)) {
            $crudos = json_decode($crudos, true);
        }

        if (! is_array($crudos)) {
            return [];
        }

        $piezas = [];

        foreach ($crudos as $clave => $valor) {
            if (is_array($valor)) {
                $piezas[(int) ($valor['id'] ?? 0)] = (int) ($valor['count'] ?? 0);
            } else {
                $piezas[(int) $clave] = (int) $valor;
            }
        }

        return $piezas;
    }

    /**
     * EL Z, tal y como lo ensena la pantalla.
     *
     * @return array<string, mixed>
     */
    private function zProps(object $z): array
    {
        return [
            'id' => (int) $z->id,
            'closed_at' => $z->closed_at === null
                ? null
                : Carbon::parse((string) $z->closed_at)->toIso8601String(),
            'closed_by' => $this->responsablePorId($z->closed_by),
            // LA FIRMA: si el hash no cuadra, alguien altero un Z sellado.
            'content_hash' => (string) $z->content_hash,
            'verified' => CashClose::verify($z),
            'opening_balance' => PosAmount::text($z->opening_balance),
            'expected_balance' => PosAmount::text($z->expected_balance),
            'counted_balance' => PosAmount::text($z->counted_balance),
            'difference' => PosAmount::text($z->difference),
            // EL MOTIVO: por que se cerro asi.
            'close_reason' => $z->close_reason ?? null,
            // El CONTEO: las piezas que el cajero conto.
            'counted_pieces' => $this->piezasDelZ($z),
            'subtotal' => PosAmount::text($z->subtotal),
            'tax_total' => PosAmount::text($z->tax_total),
            'grand_total' => PosAmount::text($z->grand_total),
            'sales_count' => (int) $z->sales_count,
            'first_document_number' => $z->first_document_number,
            'last_document_number' => $z->last_document_number,
            'first_control_number' => $z->first_control_number,
            'last_control_number' => $z->last_control_number,
            'payments' => $this->pagosDelZ($z),
            // SI SE REABRIO: cuando, quien y por que.
            'reopened_at' => $z->reopened_at === null
                ? null
                : Carbon::parse((string) $z->reopened_at)->toIso8601String(),
            'reopened_by' => $this->responsablePorId($z->reopened_by),
            'reopen_reason' => $z->reopen_reason,
            // Las IMPRESIONES del Z: cuantas veces ha salido. La pantalla lo
            // dice, porque reimprimir un Z pide un motivo.
            'print_count' => $this->impresionesDelZ((int) $z->id),
        ];
    }

    /**
     * EL HISTORIAL de Z del dia: cada cierre, con su firma y con la marca de
     * si se reabrió.
     *
     * @return list<array<string, mixed>>
     */
    private function historialDeZ(int $storeId, DateTimeImmutable $fecha): array
    {
        $historial = [];

        foreach ((new CashClose($this->connection))->history($storeId, $fecha->format('Y-m-d')) as $z) {
            $historial[] = [
                'id' => (int) $z->id,
                'closed_at' => Carbon::parse((string) $z->closed_at)->toIso8601String(),
                'closed_by' => $this->responsablePorId($z->closed_by),
                'content_hash' => (string) $z->content_hash,
                'verified' => CashClose::verify($z),
                'expected_balance' => PosAmount::text($z->expected_balance),
                'counted_balance' => PosAmount::text($z->counted_balance),
                'difference' => PosAmount::text($z->difference),
                'reopened_at' => $z->reopened_at === null
                    ? null
                    : Carbon::parse((string) $z->reopened_at)->toIso8601String(),
                'reopened_by' => $this->responsablePorId($z->reopened_by),
                'reopen_reason' => $z->reopen_reason,
            ];
        }

        return $historial;
    }

    /**
     * EL DESGLOSE del Z: lo que entro en la caja y por donde.
     *
     * @return list<array{id: int|null, name: string|null, type: string, amount: string, is_cash: bool}>
     */
    private function pagosDelZ(object $z): array
    {
        $crudos = $z->payments ?? null;

        if (is_string($crudos)) {
            $crudos = json_decode($crudos, true);
        }

        if (! is_array($crudos)) {
            return [];
        }

        $pagos = [];

        foreach ($crudos as $pago) {
            $pagos[] = [
                'id' => isset($pago['id']) && $pago['id'] !== null ? (int) $pago['id'] : null,
                'name' => $pago['name'] === null ? null : (string) $pago['name'],
                'type' => (string) ($pago['type'] ?? 'cash'),
                'amount' => PosAmount::text($pago['amount'] ?? '0'),
                'is_cash' => (bool) ($pago['is_cash'] ?? false),
            ];
        }

        return $pagos;
    }

    /**
     * Un RESPONSABLE por su id: quien cerro y quien reabrio.
     *
     * @return array{id: int, name: string}|null
     */
    private function responsablePorId(mixed $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $usuario = User::query()->find((int) $userId);

        return $usuario === null ? null : $this->responsable($usuario);
    }

    /**
     * LAS CIFRAS del dia: la partida, el en curso, el esperado, el contado y
     * el descuadre.
     *
     * REGLA que fija este metodo: *el esperado es el saldo en curso, y solo
     * el saldo en curso*. El saldo de partida ya esta en el libro, con su
     * asiento de apertura, asi que sumarlo otra vez contaria ese dinero dos
     * veces.
     *
     * Y UN DIA CERRADO NO SE RECALCULA: si hay un Z sellado, sus cifras son
     * las del dia, aunque despues se corrija una venta de ayer.
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

        // El Z SELLADO: si el dia esta cerrado, el cierre es un HECHO y se
        // LEE, no se recalcula.
        $z = $this->day->zSellado($storeId, $fecha);

        if ($z !== null) {
            return [
                'opening_balance' => $partida,
                'running_balance' => $enCurso,
                'expected_balance' => PosAmount::text($z->expected_balance),
                'counted_balance' => PosAmount::text($z->counted_balance),
                'difference' => PosAmount::text($z->difference),
            ];
        }

        // El ESPERADO: el saldo en curso.
        $esperado = $this->day->expected($enCurso);

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

    /**
     * VALIDA el CONTEO por piezas: cuantas piezas hay de cada denominacion.
     *
     * REGLA que fija este metodo: *un conteo es un mapa de cantidades
     * enteras y no negativas*. El id de la denominacion lo pone el
     * navegador, pero las piezas que no sean del catalogo de la tienda las
     * rechaza el DOMINIO: la validacion de forma no es la validacion de
     * fondo.
     *
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validarConteo(mixed $conteo): ?string
    {
        $validador = Validator::make(['counted_pieces' => $conteo], [
            'counted_pieces' => ['array'],
            'counted_pieces.*' => ['integer', 'min:0'],
        ]);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }
}
