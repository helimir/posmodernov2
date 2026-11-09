<?php

namespace App\Http\Controllers;

use App\Domain\Checkout\CheckoutInput;
use App\Domain\Checkout\CheckoutLineInput;
use App\Domain\Checkout\CheckoutPaymentInput;
use App\Domain\Checkout\CheckoutResult;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Credit\CustomerBalanceService;
use App\Models\Store;
use App\Support\Pos\PosAmount;
use App\Support\Pos\PosCatalog;
use App\Support\Pos\PosContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
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
     *  - `payment_methods`: lista de `{ id, code, name }`.
     *  - `document_series_id`: `int|null`.
     *  - `control_series_id`: `int|null`.
     *  - `business_date`: `'Y-m-d'`.
     *  - `currency_code`: `string`.
     *  - `catalog_page_size`: `int`.
     */
    public function index(Request $request): Response
    {
        try {
            $context = $this->context($request);
            $store = $context->store();
            $currencyCode = $context->currencyCode();
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
     * El COBRO de la venta.
     *
     * Valida la entrada, resuelve el contexto y cobra DENTRO de UNA
     * transaccion: la venta que escribe el dominio y la imputacion del
     * credito del cliente son un solo hecho, y si la imputacion falla, la
     * venta tampoco.
     *
     * Responde 201 con `{ sale_id, invoice_no, control_number, grand_total,
     * status, change }`. Los importes son TEXTO decimal.
     *
     * LIMITACION CONOCIDA — EL SOBREPAGO: `SalePaymentStatus` exige que los
     * pagos cubran el total EXACTAMENTE. Si el cliente entrega de mas, la
     * suma de lo recibido NO iguala al total y la venta queda `confirmed`, no
     * `paid`: el dominio no modela el sobrepago y aqui no se cambia. Lo que
     * se hace es NO perder el dato: el efectivo recibido y el cambio quedan
     * en el `details` (jsonb) del pago, y el cambio se devuelve en la
     * respuesta. El libro contable no se resiente: el asiento debita el
     * TOTAL de la venta, no lo que se entrego en el mostrador.
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

                return $this->trasLaVenta($resultado, $pagos, $context, $store, $customerId);
            });
        } catch (RuntimeException $fallo) {
            // Un fallo del dominio (no hay stock, falta una cuenta, la serie
            // esta agotada) es un fallo de la OPERACION, no del servidor: la
            // caja lo enseña y la transaccion se revierte entera.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        return response()->json($resultado, 201);
    }

    /**
     * LO QUE PASA DESPUES de que el dominio cobro: el cambio, el detalle del
     * pago y la imputacion del credito del cliente.
     *
     * Va DENTRO de la misma transaccion de la venta: si la imputacion del
     * credito falla, la venta tampoco. Y si la venta falla, no queda ningun
     * movimiento de credito.
     *
     * @param  list<CheckoutPaymentInput>  $pagos  Los pagos de la entrada.
     * @return array<string, mixed>
     */
    private function trasLaVenta(
        CheckoutResult $resultado,
        array $pagos,
        PosContext $context,
        Store $store,
        ?int $customerId
    ): array {
        // El TOTAL: lo que calculo el motor de impuestos. Se LEE de lo que
        // el dominio escribio, no se recalcula: una segunda aritmetica es una
        // segunda verdad.
        $total = PosAmount::decimal(
            $this->connection->table('sale_totals')
                ->where('sale_id', $resultado->saleId)
                ->value('grand_total')
        );

        $pagosEscritos = $this->connection->table('sale_payments')
            ->where('sale_id', $resultado->saleId)
            ->orderBy('id')
            ->get();

        $restante = $total;
        $cambioTotal = BigDecimal::zero()->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
        $aplicados = [];

        foreach ($pagosEscritos as $indice => $pago) {
            $recibido = PosAmount::decimal($pagos[$indice]->amount);

            // Lo APLICADO a la venta es lo recibido, hasta donde alcanza el
            // total: lo que sobra es el CAMBIO, que se devuelve al cliente.
            $aplicado = $recibido->isGreaterThan($restante) ? $restante : $recibido;
            $cambio = $recibido->minus($aplicado);

            $restante = $restante->minus($aplicado);
            $aplicados[] = $aplicado;

            if (! $cambio->isZero()) {
                $cambioTotal = $cambioTotal->plus($cambio);
            }

            // El detalle del pago: el efectivo recibido y el cambio. El
            // backend no puede saber que medio de pago es el de efectivo sin
            // una regla de datos, asi que el detalle va en TODOS los pagos y
            // el cambio solo es distinto de cero en el que lo produce.
            $this->connection->table('sale_payments')
                ->where('id', $pago->id)
                ->update([
                    'details' => [
                        'cash_received' => (string) $recibido,
                        'change' => (string) $cambio,
                    ],
                    'updated_at' => now(),
                ]);
        }

        // La IMPUTACION del credito del cliente: un cobro reduce la deuda, y
        // el importe es el APLICADO a la venta (el cambio se devolvio al
        // cliente, no le reduce una deuda). El servicio se encarga de poner
        // el importe en negativo: lo que el cliente DEBE es positivo, lo que
        // paga es negativo.
        if ($customerId !== null) {
            foreach ($aplicados as $aplicado) {
                if ($aplicado->isZero() || $aplicado->isNegative()) {
                    continue;
                }

                $this->balances->registerPayment(
                    customerId: $customerId,
                    storeId: (int) $store->id,
                    amount: (string) $aplicado,
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
     * Los MEDIOS DE PAGO de la tienda: los que su ficha declara activos.
     *
     * @return list<array{id: int, code: string, name: string}>
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
            ]);

        $methods = [];

        foreach ($rows as $row) {
            $methods[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
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
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validar(array $datos): ?string
    {
        $validador = Validator::make($datos, [
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required'],
            'lines.*.unit_price' => ['required'],
            'lines.*.discount' => ['nullable'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['nullable', 'integer', 'min:1'],
            'payments.*.amount' => ['required'],
        ]);

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
     * El importe del pago es lo que ENTREGO el cliente: el cambio se calcula
     * despues, cuando el dominio ya ha dicho cuanto es el total.
     *
     * @param  array<string, mixed>  $datos
     * @return list<CheckoutPaymentInput>
     */
    private function pagos(array $datos): array
    {
        $pagos = [];

        foreach ($datos['payments'] as $pago) {
            $pagos[] = new CheckoutPaymentInput(
                paymentMethodId: isset($pago['payment_method_id']) ? (int) $pago['payment_method_id'] : null,
                amount: PosAmount::declare($pago['amount'], 'importe del pago'),
            );
        }

        return $pagos;
    }
}
