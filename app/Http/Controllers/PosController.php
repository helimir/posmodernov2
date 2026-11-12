<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\CashRegisterService;
use App\Domain\Checkout\CheckoutInput;
use App\Domain\Checkout\CheckoutLineInput;
use App\Domain\Checkout\CheckoutPaymentInput;
use App\Domain\Checkout\CheckoutResult;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Fiscal\FiscalIssuanceRunner;
use App\Domain\Fiscal\ValueObjects\FiscalIssuanceOutcome;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Support\Pos\PosAmount;
use App\Support\Pos\PosBudget;
use App\Support\Pos\PosCatalog;
use App\Support\Pos\PosContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * La CAJA: el punto de venta.
 *
 * Esta clase es la COSTEZA entre el navegador y el dominio. No cobra: ORQUESTA
 * al dominio, que es quien sabe cobrar. La venta entera (la reserva del
 * numero de documento, la del numero de control, el consumo FIFO del stock,
 * la cabecera, las lineas, los totales, el asiento contable y los pagos) la
 * hace `CheckoutService`, en UNA transaccion.
 *
 * LO QUE HACE ESTA CLASE, Y POR QUE:
 *
 *  - RESUELVE EL CONTEXTO (tienda, series, fechas, plan de cuentas) a traves
 *    de `App\Support\Pos\PosContext`, que lo cachea durante la peticion. Las
 *    tres acciones de la caja hablan de la MISMA tienda, las MISMAS series y
 *    la MISMA fecha de negocio.
 *  - VALIDA la entrada del cobro antes de tocar el dominio, y responde 422
 *    con `{ errorMsg }` cuando algo no vale: un cobro invalido no llega al
 *    dominio.
 *  - ORQUESTA la imputacion del credito del cliente, que el checkout del
 *    dominio NO hace (el cobro de una deuda es del credito, no de la venta).
 *
 * LO QUE NO HACE, Y POR QUE:
 *
 *  - NO reimprime la aritmetica del dominio: el total lo calcula el motor de
 *    impuestos, y aqui solo se LEE lo que el motor escribio. Una segunda
 *    copia de la aritmetica es una segunda verdad, y las dos divergen.
 *  - NO inventa un plan de cuentas: lo lee de la configuracion (o lo recibe
 *    de la tabla por tienda, cuando exista).
 *  - NO toca el dominio: `CheckoutService`, `TaxCalculator`, `LedgerWriter`,
 *    `CustomerBalanceService` y `SalePaymentStatus` se orquestan, no se
 *    modifican.
 */
class PosController extends Controller
{
    /**
     * El catalogo de la tienda.
     */
    private readonly PosCatalog $catalog;

    public function __construct(
        private readonly Connection $connection,
        private readonly CheckoutService $checkout,
        private readonly CustomerBalanceService $balances,
        private readonly PosBudget $budget,
        private readonly CashRegisterService $registers,
        private readonly FiscalIssuanceRunner $emision,
    ) {
        $this->catalog = new PosCatalog($connection);
    }

    /**
     * La PANTALLA de la caja.
     *
     * Devuelve el escenario con el que la caja abre: la tienda, el catalogo
     * inicial, los medios de pago, las series fiscales, la fecha de negocio,
     * la moneda y el tamano de pagina del catalogo.
     *
     * El CONTRATO de los props es el de `resources/js/pages/pos/index.tsx`:
     *
     *  - `store`: `{ id, name, code_name, timezone }`.
     *  - `catalog`: lista de
     *    `{ id, code, name, type, sell_price, quantity_in_stock, unit_name }`.
     *    Los importes son TEXTO decimal.
     *  - `payment_methods`: lista de `{ id, code, name, type }`. El TIPO
     *    (`cash`, `card`, `transfer`, `credit`) es lo que dice si un pago es
     *    un COBRO o es DEUDA, y la caja lo necesita para cobrar a credito.
     *  - `document_series_id`: `int|null`.
     *  - `control_series_id`: `int|null`.
     *  - `business_date`: `'Y-m-d'`.
     *  - `currency_code`: `string`.
     *  - `currency`: `{ code, symbol, symbol_position, decimals,
     *    thousand_separator, decimal_separator }`. El codigo solo no alcanza:
     *    un importe sin simbolo ni separadores no se lee.
     *  - `catalog_page_size`: `int`.
     */
    public function index(Request $request): Response
    {
        try {
            $context = $this->context($request);
            $store = $context->store();
            $currencyCode = $context->currencyCode();
            $currency = $context->currency();
            $catalog = $this->catalog->products(
                (int) $store->id,
                null,
                1,
                (int) config('pos.catalog_page_size', 24)
            );
        } catch (RuntimeException $fallo) {
            // Sin tienda activa, sin moneda o sin series, la caja no abre: no
            // se sirve una pantalla de una caja que no puede cobrar.
            abort(409, $fallo->getMessage());
        }

        return Inertia::render('pos/index', [
            'store' => [
                'id' => (int) $store->id,
                'name' => (string) $store->name,
                'code_name' => $store->code_name,
                'timezone' => $context->timezone()->getName(),
            ],
            'catalog' => $catalog['products'],
            'payment_methods' => $this->paymentMethods((int) $store->id),
            'document_series_id' => $context->documentSeriesId(),
            'control_series_id' => $context->controlSeriesId(),
            'business_date' => $context->businessDate()->format('Y-m-d'),
            'currency_code' => $currencyCode,
            'currency' => $currency,
            'catalog_page_size' => (int) config('pos.catalog_page_size', 24),
        ]);
    }

    /**
     * La BUSQUEDA de productos de la tienda.
     *
     * Es el equivalente del `PRODUCTLIST` del legacy, con dos reglas de
     * busqueda: por NOMBRE como prefijo y por CODIGO exacto. Sin `q`
     * devuelve la primera pagina del catalogo.
     *
     * Responde `{ products, page, page_size, total }`: los productos con su
     * precio y su stock real, la pagina, el tamano de pagina y cuantos hay en
     * total.
     */
    public function products(Request $request): JsonResponse
    {
        try {
            $store = $this->context($request)->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $query = $request->query('q');
        $page = $request->query('page');

        $resultado = $this->catalog->products(
            (int) $store->id,
            is_string($query) ? $query : null,
            is_numeric($page) ? (int) $page : 1,
            (int) config('pos.search_page_size', 24)
        );

        return response()->json($resultado);
    }

    /**
     * El PRESUPUESTO: lo que se va a cobrar, SIN cobrarlo.
     *
     * Es el mismo pedido que el cobro (`{ customer_id?, lines, payments }`),
     * pero NO cobra: no reserva numero, no consume stock y no escribe
     * asiento. Devuelve el total CALCULADO POR EL MOTOR, con su desglose:
     * `{ subtotal, tax_total, discount, rounding_adjustment, grand_total }`.
     *
     * POR QUE EXISTE: el precio con impuesto NO se puede calcular en el
     * navegador sin replicar la resolucion de categoria y de tasa del
     * dominio, y una segunda verdad diverge (el legacy multiplicaba el
     * impuesto por el precio de catalogo: defecto P0 #13). La pantalla pide
     * el total al servidor y lo ENSENA; si la peticion falla, la pantalla no
     * deja cobrar, porque cobrar sin total es cobrar a ciegas.
     *
     * Los importes son TEXTO decimal, como los del cobro.
     */
    public function budget(Request $request): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validar($datos, false);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $context = $this->context($request);
            $store = $context->store();
            $documentSeriesId = $context->documentSeriesId();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        if ($documentSeriesId === null) {
            return response()->json([
                'errorMsg' => 'La tienda '.$store->id.' no tiene serie de documento: '
                    .'sin serie no hay moneda ni tasa con que calcular el total.',
            ], 422);
        }

        try {
            $total = $this->budget->quote(
                (int) $store->id,
                $documentSeriesId,
                $context->issuedAt(),
                $this->lineas($datos),
            );
        } catch (RuntimeException $fallo) {
            // Sin perfil, sin moneda, sin categoria o sin tasa vigente no hay
            // total: es un fallo de la OPERACION, no del servidor, y la
            // pantalla lo enseña en vez de cobrar a ciegas.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        return response()->json($total);
    }

    /**
     * El COBRO de la venta.
     *
     * Valida la entrada, resuelve el contexto y cobra DENTRO de UNA
     * transaccion: la venta que escribe el dominio y la imputacion del
     * credito del cliente son un solo hecho, y si la imputacion falla, la
     * venta tampoco.
     *
     * Responde 201 con `{ sale_id, invoice_no, control_number, grand_total,
     * status, change, fiscal }`. Los importes son TEXTO decimal.
     *
     * El campo `fiscal` es el ESTADO FISCAL de la venta: `{ status,
     * document_id, control_number, message }`, con `status` en `issued`
     * (emitido), `failed` (fallido, con su motivo) o `not_emitted` (el
     * interruptor de emision esta apagado).
     *
     * NO SE COBRA SIN CAJA DEL DIA ABIERTA.
     *
     * REGLA que fija este metodo: *no se cobra sin la caja del dia ABIERTA*.
     * Una venta sin caja es una venta sin contra que contrastar, y el
     * descuadre del dia se pierde antes de empezar. El dominio lo dice
     * (`CashRegisterService::registerFor()`): si la caja no existe para esa
     * fecha de negocio, o si ya esta cerrada, el cobro falla con 422 y la
     * transaccion se revierte entera.
     *
     * LIMITACION CONOCIDA — EL PAGO PARCIAL SIN CLIENTE: sin cliente, los pagos
     * tienen que cubrir el total ENTERO. Lo que no cubren no es deuda: no hay
     * a quien imputarsela, y una venta a medias no es una venta. Con cliente,
     * en cambio, esa parte es DEUDA y la venta se cobra.
     *
     * Y EL SOBREPAGO NO ES UNA LIMITACION: el cliente entrega 100 por una
     * venta de 58, sobran 42, y esos 42 son el CAMBIO. La venta queda
     * `paid`, el efectivo recibido y el cambio quedan en el `details` (jsonb)
     * del pago, y el cambio se devuelve en la respuesta. El libro contable no
     * se resiente: el asiento debita el TOTAL de la venta, no lo que se
     * entrego en el mostrador.
     */
    public function checkout(Request $request): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validar($datos);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $context = $this->context($request);
            $store = $context->store();
            $documentSeriesId = $context->documentSeriesId();
            $lineas = $this->lineas($datos);
            $pagos = $this->pagos($datos);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        if ($documentSeriesId === null) {
            return response()->json([
                'errorMsg' => 'La tienda '.$store->id.' no tiene serie de documento: '
                    .'sin serie no hay numero de documento que reservar, y sin numero no hay venta.',
            ], 422);
        }

        $customerId = isset($datos['customer_id']) ? (int) $datos['customer_id'] : null;
        $actorId = $request->user()?->id;

        // UN PAGO A CREDITO SIN CLIENTE ES UN ERROR.
        //
        // REGLA que fija este metodo: *cobrar a credito sin cliente no se
        // cobra*. Un pago de tipo `credit` es DEUDA, y una deuda sin destino
        // no se registra: el movimiento del libro necesita un cliente al que
        // imputarse. Y la pantalla NO lo bloquea en el navegador: lo manda al
        // servidor y el servidor lo dice, porque el navegador no es la
        // verdad de que un medio de pago sea de credito.
        $fallo = $this->creditoSinCliente($pagos, $customerId);

        if ($fallo !== null) {
            return response()->json(['errorMsg' => $fallo], 422);
        }

        try {
            $resultado = $this->connection->transaction(function () use (
                $context,
                $store,
                $documentSeriesId,
                $lineas,
                $pagos,
                $customerId,
                $actorId
            ): array {
                // LA CAJA DEL DIA, antes de cobrar. Va DENTRO de la
                // transaccion: si la caja no esta abierta, la venta no se
                // escribe, y si se escribe, la caja estaba abierta.
                $this->exigeLaCajaAbierta((int) $store->id, $context);

                $resultado = $this->checkout->checkout(new CheckoutInput(
                    storeId: (int) $store->id,
                    customerId: $customerId,
                    documentSeriesId: $documentSeriesId,
                    controlSeriesId: $context->controlSeriesId(),
                    lines: $lineas,
                    payments: $pagos,
                    accounts: $context->accounts(),
                    issuedAt: $context->issuedAt(),
                    businessDate: $context->businessDate(),
                    actorId: $actorId,
                ));

                return $this->trasLaVenta($resultado, $context, $store, $customerId);
            });
        } catch (RuntimeException $fallo) {
            // Un fallo del dominio (no hay stock, falta una cuenta, la serie
            // esta agotada, la caja no esta abierta) es un fallo de la
            // OPERACION, no del servidor: la caja lo enseña y la transaccion
            // se revierte entera.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        // LA EMISION FISCAL (T2), FUERA de la transaccion de T1.
        //
        // REGLA que fija este metodo: *un fallo fiscal NO puede hacer perder
        // una venta*. La venta ya esta confirmada: si el comprobante no sale,
        // el cobro responde 201 IGUAL, con el aviso, y la venta queda
        // pendiente de reintento. Si el cobro devolviera un error, el cajero
        // creeria que no se vendio, y cobraria otra vez: eso es PEOR que un
        // comprobante pendiente.
        $fiscal = $this->estadoFiscal((int) $resultado['sale_id'], $actorId);

        return response()->json(array_merge($resultado, ['fiscal' => $fiscal]), 201);
    }

    /**
     * El ESTADO FISCAL de la venta, para la respuesta del cobro.
     *
     * REGLA que fija este metodo: *el interruptor apagado es una emision que
     * NO ocurre, no una emision que falla*. Con el interruptor apagado no se
     * entra en T2: no se resuelve ningun canal y no se toca nada mas. Es el
     * modo de puesta en marcha.
     *
     * Y CON EL INTERRUPTOR ENCENDIDO, el estado es el que dice la emision:
     * emitido (con su numero de control), fallido (con su motivo) o no
     * emitido.
     *
     * @return array{status: string, document_id: int|null, control_number: string|null, message: string|null}
     */
    private function estadoFiscal(int $saleId, ?int $actorId): array
    {
        if (! config('fiscal.emission_enabled')) {
            return FiscalIssuanceOutcome::noEmitido()->aArray();
        }

        return $this->emision->issue($saleId, $actorId)->aArray();
    }

    /**
     * EXIGE la caja del dia ABIERTA.
     *
     * REGLA que fija este metodo: *no se cobra sin la caja del dia ABIERTA*.
     *
     * La llamada es `CashRegisterService::registerFor()`: el DOMINIO sabe si
     * la caja existe para esa fecha de negocio y si sigue abierta, y su
     * mensaje dice por que falla. Aqui no se reescribe la comprobacion: se
     * llama al dominio y se traduce su fallo al mensaje que el cajero
     * entiende ("abrala antes de cobrar"), con el detalle del dominio
     * detras.
     *
     * @throws RuntimeException Cuando no hay caja para esa fecha de negocio,
     *                          o cuando ya esta cerrada.
     */
    private function exigeLaCajaAbierta(int $storeId, PosContext $context): void
    {
        try {
            $this->registers->registerFor($storeId, $context->businessDate());
        } catch (RuntimeException $fallo) {
            throw new RuntimeException(
                'La caja del dia no esta abierta: abrala antes de cobrar. '.$fallo->getMessage(),
                0,
                $fallo
            );
        }
    }

    /**
     * LO QUE PASA DESPUES de que el dominio cobro: el detalle del pago y la
     * imputacion del credito del cliente.
     *
     * Va DENTRO de la misma transaccion de la venta: si la imputacion del
     * credito falla, la venta tampoco. Y si la venta falla, no queda ningun
     * movimiento de credito.
     *
     * EL REPARTO DE LOS PAGOS NO SE RECALCULA AQUI: lo calcula el DOMINIO
     * (`CheckoutService`) y lo trae el resultado. Este metodo lo CONSUME: el
     * detalle del pago y la imputacion del credito salen de ese reparto. Una
     * segunda aritmetica de la misma verdad es una segunda verdad, y las dos
     * divergen.
     *
     * @return array<string, mixed>
     */
    private function trasLaVenta(
        CheckoutResult $resultado,
        PosContext $context,
        Store $store,
        ?int $customerId
    ): array {
        $pagosEscritos = $this->connection->table('sale_payments')
            ->where('sale_id', $resultado->saleId)
            ->orderBy('id')
            ->get();

        $cambioTotal = BigDecimal::zero()->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);

        foreach ($resultado->payments as $reparto) {
            $pago = $pagosEscritos->get($reparto->index);

            if ($pago === null) {
                continue;
            }

            if (! BigDecimal::of($reparto->change)->isZero()) {
                $cambioTotal = $cambioTotal->plus($reparto->change);
            }

            // El detalle del pago: el efectivo recibido y el cambio. El
            // backend no puede saber que medio de pago es el de efectivo sin
            // una regla de datos, asi que el detalle va en TODOS los pagos y
            // el cambio solo es distinto de cero en el que lo produce.
            $this->connection->table('sale_payments')
                ->where('id', $pago->id)
                ->update([
                    'details' => [
                        'cash_received' => $reparto->received,
                        'change' => $reparto->change,
                    ],
                    'updated_at' => now(),
                ]);
        }

        // LA IMPUTACION DEL CREDITO DEL CLIENTE.
        //
        // EL LIBRO DEL CLIENTE ES UN MINI-LIBRO: LA VENTA SUMA y el COBRO
        // RESTA.
        //
        //  1. LA VENTA: un movimiento de tipo `sale` con el TOTAL de la
        //     venta, POSITIVO, porque lo que el cliente DEBE. Es el total, no
        //     solo la parte que no se pagó: la venta es un hecho entero.
        //  2. LOS COBROS: un movimiento de tipo `payment` por cada pago que
        //     NO sea de tipo `credit`, con lo APLICADO a la venta, NEGATIVO.
        //     El importe es el APLICADO, no el recibido: el cambio se
        //     devolvio al cliente y no le reduce una deuda.
        //
        // Un pago de tipo `credit` NO genera movimiento de pago: es deuda, no
        // cobro, y su importe se queda como saldo a favor de la tienda.
        //
        // EL LIMITE DE CREDITO NO SE COMPRUEBA AQUI: la regla la cumple el
        // DOMINIO (`CustomerBalanceService::assertCreditLimit()`), que es quien
        // sabe cuanto debe el cliente y cual es su limite. Aqui no se
        // reescribe la comprobacion: el fallo del dominio sube como un
        // RuntimeException y el cobro se revierte entero.
        if ($customerId !== null) {
            $this->balances->registerSale(
                customerId: $customerId,
                storeId: (int) $store->id,
                amount: $resultado->grandTotal,
                referenceNo: $resultado->documentNumber,
                entryDate: $context->businessDate(),
                saleId: $resultado->saleId,
            );

            foreach ($resultado->payments as $reparto) {
                if ($reparto->type === PaymentMethod::TYPE_CREDIT) {
                    continue;
                }

                $aplicado = BigDecimal::of($reparto->applied);

                if ($aplicado->isZero() || $aplicado->isNegative()) {
                    continue;
                }

                $this->balances->registerPayment(
                    customerId: $customerId,
                    storeId: (int) $store->id,
                    amount: $reparto->applied,
                    referenceNo: $resultado->documentNumber,
                    entryDate: $context->businessDate(),
                    saleId: $resultado->saleId,
                );
            }
        }

        return [
            'sale_id' => $resultado->saleId,
            'invoice_no' => $resultado->documentNumber,
            'control_number' => $resultado->controlNumber,
            'grand_total' => $resultado->grandTotal,
            'status' => $resultado->status,
            'change' => (string) $cambioTotal,
        ];
    }

    /**
     * El TIPO decide si un pago es un COBRO o es DEUDA, y una DEUDA sin
     * CLIENTE no tiene destino: no se registra.
     *
     * El TIPO viene en el propio pago: lo resuelve `pagos()`, en UNA
     * consulta, antes de construir la entrada del dominio.
     *
     * @param  list<CheckoutPaymentInput>  $pagos
     * @return string|null El mensaje del error, o `null` si el cobro es valido.
     */
    private function creditoSinCliente(array $pagos, ?int $customerId): ?string
    {
        if ($customerId !== null) {
            return null;
        }

        foreach ($pagos as $pago) {
            if ($pago->paymentMethodType === PaymentMethod::TYPE_CREDIT) {
                return 'Un pago a credito necesita un cliente: una deuda sin destino no se registra.';
            }
        }

        return null;
    }

    /**
     * Los TIPOS de los medios de pago de una venta, de UNA consulta.
     *
     * El TIPO decide si un pago es un COBRO o es DEUDA: uno de tipo `credit`
     * no genera movimiento de pago en el libro del cliente. Es DATO de la
     * fila (`payment_methods.type`), no un literal del codigo.
     *
     * @param  list<int|null>  $ids
     * @return array<int, string|null> Medio de pago => su tipo.
     */
    private function tiposDeMedioDePago(array $ids): array
    {
        $unicos = [];

        foreach ($ids as $id) {
            if ($id !== null) {
                $unicos[(int) $id] = (int) $id;
            }
        }

        if ($unicos === []) {
            return [];
        }

        $filas = $this->connection->table('payment_methods')
            ->whereIn('id', array_values($unicos))
            ->pluck('type', 'id');

        $tipos = [];

        foreach ($filas as $id => $tipo) {
            // Un medio de pago SIN tipo declarado cobra en efectivo: el tipo
            // es DATO de la fila, y su DEFECTO es el efectivo.
            $tipos[(int) $id] = $tipo === null ? null : (string) $tipo;
        }

        return $tipos;
    }

    /**
     * El SELECTOR DE CLIENTES de la caja.
     *
     * Devuelve los clientes que tienen FICHA en la TIENDA ACTIVA: el credito
     * de un cliente es por tienda, y un cliente que no esta en la tienda no
     * se le vende. Los clientes INACTIVOS tampoco se ofrecen.
     *
     * La busqueda es la del catalogo: por NOMBRE como prefijo y por
     * `tax_id` (el documento) exacto. Un termino de busqueda es DATO, no
     * patron: escribir `50%` no devuelve medio catalogo, porque el comodin
     * va escapado en el VALOR del parametro y la sentencia NO lleva `ESCAPE`
     * explicito (el analizador de PDO cuenta los parametros de mas).
     *
     * Responde `{ customers }`: cada cliente con su nombre, su documento, su
     * saldo DERIVADO y su deuda, como texto decimal.
     */
    public function clientes(Request $request): JsonResponse
    {
        try {
            $store = $this->context($request)->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $storeId = (int) $store->id;

        $query = $request->query('q');
        $termino = is_string($query) ? trim($query) : '';

        $consulta = $this->connection->table('customers')
            ->join('customer_stores', function (JoinClause $join) use ($storeId): void {
                $join->on('customer_stores.customer_id', '=', 'customers.id')
                    ->where('customer_stores.store_id', '=', $storeId);
            })
            ->where('customers.is_active', true)
            ->where('customer_stores.status', 'active');

        if ($termino !== '') {
            $consulta->where(function ($filtro) use ($termino): void {
                $filtro->whereRaw('UPPER(customers.name) LIKE ?', [$this->patronPrefijo($termino)])
                    ->orWhereRaw('UPPER(customers.tax_id) = ?', [mb_strtoupper($termino)]);
            });
        }

        $filas = $consulta
            ->orderBy('customers.name')
            ->orderBy('customers.id')
            ->limit(max(1, (int) config('pos.customer_page_size', 25)))
            ->get(['customers.id as id', 'customers.name as name', 'customers.tax_id as tax_id']);

        $ids = [];

        foreach ($filas as $fila) {
            $ids[] = (int) $fila->id;
        }

        $saldos = $this->balances->balances($storeId, $ids);

        $clientes = [];

        foreach ($filas as $fila) {
            $saldo = $saldos[(int) $fila->id] ?? ['balance' => '0.0000', 'due' => '0.0000'];

            $clientes[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                'tax_id' => $fila->tax_id === null ? null : (string) $fila->tax_id,
                'balance' => $saldo['balance'],
                'due' => $saldo['due'],
            ];
        }

        return response()->json(['customers' => $clientes]);
    }

    /**
     * El PATRON del `LIKE`: el termino en mayusculas y con los comodines
     * escapados.
     *
     * REGLA que fija este metodo: *el escape es el de POR DEFECTO de
     * PostgreSQL, la barra invertida, y no un `ESCAPE` explicito*.
     *
     * El motivo es TECNICO y verificado: el analizador de PDO cuenta los
     * parametros de la sentencia, y `ESCAPE '\'` le engaña (la barra
     * invertida parece escapar el cierre de la cadena). Una sentencia con
     * DOS `LIKE ... ESCAPE '\'` se prepara con menos parametros de los que
     * lleva y el error es "Invalid parameter number". Con el escape por
     * defecto, la barra invertida viaja en el VALOR del parametro y no en el
     * texto de la sentencia, y el patron se comporta igual.
     *
     * Un termino de busqueda es DATO del usuario, no un patron: sin escapar,
     * escribir `50%` devolveria medio catalogo.
     */
    private function patronPrefijo(string $termino): string
    {
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            mb_strtoupper($termino)
        );

        return $escaped.'%';
    }

    /**
     * Los MEDIOS DE PAGO de la tienda: los que su ficha declara activos.
     *
     * Cada uno lleva su TIPO (`cash`, `card`, `transfer`, `credit`), porque
     * el TIPO es lo que decide si un pago es un COBRO o es DEUDA: la caja
     * necesita saber cual es el de credito para poder cobrar a credito.
     *
     * @return list<array{id: int, code: string, name: string, type: string}>
     */
    private function paymentMethods(int $storeId): array
    {
        $rows = $this->connection->table('payment_method_stores')
            ->join('payment_methods', 'payment_methods.id', '=', 'payment_method_stores.payment_method_id')
            ->where('payment_method_stores.store_id', $storeId)
            ->where('payment_method_stores.status', 'active')
            ->orderBy('payment_method_stores.sort_order')
            ->orderBy('payment_methods.id')
            ->get([
                'payment_methods.id as id',
                'payment_methods.code as code',
                'payment_methods.name as name',
                'payment_methods.type as type',
            ]);

        $methods = [];

        foreach ($rows as $row) {
            $methods[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                // El tipo es DATO de la fila, y su DEFECTO es el efectivo: un
                // medio de pago sin tipo declarado cobra en efectivo.
                'type' => $row->type === null ? 'cash' : (string) $row->type,
            ];
        }

        return $methods;
    }

    /**
     * El CONTEXTO de la peticion.
     *
     * Se crea aqui, DENTRO de la accion, y no en el constructor: el
     * controlador es un objeto que puede sobrevivir a una peticion (en las
     * pruebas el router reutiliza la instancia, y en Octane el proceso
     * sobrevive a miles). Un contexto guardado en la instancia del
     * controlador seria el contexto de la peticion ANTERIOR: la caja abriria
     * en la tienda del cliente anterior.
     *
     * Cada accion pide el contexto UNA vez y lo usa para todo lo que hace:
     * dentro de una peticion, el contexto es UNO.
     */
    private function context(Request $request): PosContext
    {
        return new PosContext($this->connection, $request->user()?->id);
    }

    /**
     * VALIDA la entrada del cobro.
     *
     * REGLA que fija este metodo: *una venta sin lineas, con una cantidad
     * que no es positiva o con un pago que no es positivo no es una venta*.
     * Se responde 422 y el dominio no se toca: un cobro invalido no llega al
     * motor de impuestos ni al libro contable.
     *
     * @param  array<string, mixed>  $datos
     * @param  bool  $conPagos  Si los pagos tienen que venir. El PRESUPUESTO
     *                          no los necesita: el total no depende de como
     *                          se paga, y exigir un pago para preguntar el
     *                          total haria fallar la pantalla cuando el
     *                          cliente aun no ha dado nada.
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validar(array $datos, bool $conPagos = true): ?string
    {
        $reglas = [
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required'],
            'lines.*.unit_price' => ['required'],
            'lines.*.discount' => ['nullable'],
        ];

        if ($conPagos) {
            $reglas['payments'] = ['required', 'array', 'min:1'];
            $reglas['payments.*.payment_method_id'] = ['nullable', 'integer', 'min:1'];
            $reglas['payments.*.amount'] = ['required'];
        }

        $validador = Validator::make($datos, $reglas);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        foreach ($datos['lines'] as $indice => $linea) {
            try {
                $cantidad = PosAmount::decimal($linea['quantity'] ?? null);
            } catch (RuntimeException) {
                return 'La cantidad de la linea '.($indice + 1).' no es un numero decimal.';
            }

            if ($cantidad->isZero() || $cantidad->isNegative()) {
                return 'La cantidad de la linea '.($indice + 1).' tiene que ser mayor que cero.';
            }

            try {
                $precio = PosAmount::decimal($linea['unit_price'] ?? null);
            } catch (RuntimeException) {
                return 'El precio de la linea '.($indice + 1).' no es un numero decimal.';
            }

            if ($precio->isNegative()) {
                return 'El precio de la linea '.($indice + 1).' no puede ser negativo.';
            }
        }

        if (! $conPagos) {
            return null;
        }

        foreach ($datos['payments'] as $indice => $pago) {
            try {
                $importe = PosAmount::decimal($pago['amount'] ?? null);
            } catch (RuntimeException) {
                return 'El importe del pago '.($indice + 1).' no es un numero decimal.';
            }

            if ($importe->isZero() || $importe->isNegative()) {
                return 'El importe del pago '.($indice + 1).' tiene que ser mayor que cero.';
            }
        }

        return null;
    }

    /**
     * Las LINEAS de la venta, tal como las cobra el dominio.
     *
     * @param  array<string, mixed>  $datos
     * @return list<CheckoutLineInput>
     */
    private function lineas(array $datos): array
    {
        $lineas = [];

        foreach ($datos['lines'] as $linea) {
            $lineas[] = new CheckoutLineInput(
                productId: (int) $linea['product_id'],
                quantity: PosAmount::declare($linea['quantity'], 'cantidad'),
                unitPrice: PosAmount::declare($linea['unit_price'], 'precio'),
                discount: PosAmount::declare($linea['discount'] ?? '0', 'descuento'),
            );
        }

        return $lineas;
    }

    /**
     * Los PAGOS de la venta, tal como los cobra el dominio.
     *
     * El importe del pago es lo que ENTREGO el cliente: el cambio lo calcula
     * el DOMINIO, cuando ya sabe cuanto es el total.
     *
     * El TIPO del medio de pago se resuelve ANTES, en UNA consulta, y viaja
     * en el pago: el dominio no consulta la base de datos para saber si un
     * pago es un cobro o es deuda. El tipo decide el reparto (que se aplica
     * a la venta) y el asiento (que entra a la caja y que no).
     *
     * @param  array<string, mixed>  $datos
     * @return list<CheckoutPaymentInput>
     */
    private function pagos(array $datos): array
    {
        $ids = [];

        foreach ($datos['payments'] as $pago) {
            $ids[] = isset($pago['payment_method_id']) ? (int) $pago['payment_method_id'] : null;
        }

        $tipos = $this->tiposDeMedioDePago($ids);

        $pagos = [];

        foreach ($datos['payments'] as $indice => $pago) {
            $medioId = $ids[$indice];

            $pagos[] = new CheckoutPaymentInput(
                paymentMethodId: $medioId,
                amount: PosAmount::declare($pago['amount'], 'importe del pago'),
                paymentMethodType: $medioId === null ? null : ($tipos[$medioId] ?? null),
            );
        }

        return $pagos;
    }
}
