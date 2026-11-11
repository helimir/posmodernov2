<?php

namespace App\Http\Controllers;

use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Credit\CustomerPaymentService;
use App\Models\Customer;
use App\Models\Store;
use App\Support\Pos\PosAmount;
use App\Support\Pos\PosContext;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * LOS CLIENTES y su CREDITO: las PAGINAS, la API y el libro del cliente.
 *
 * Esta clase es la COSTEZA entre el navegador y el credito del cliente. No
 * decide nada de negocio: el saldo lo DERIVA `CustomerBalanceService` de los
 * movimientos, y el cobro lo REGISTRA el mismo servicio.
 *
 * LA REGLA que separa las dos mitades de esta clase: *las PAGINAS son
 * Inertia y las ESCRITURAS son JSON*.
 *
 *  - LAS PAGINAS (`listado`, `alta`, `edicion`, `ficha`) se piden con un GET
 *    y se dibujan en el navegador. Viven en `/clientes`.
 *  - LA API (`index`, `show`, `store`, `update`, `cobro`) responde JSON, con
 *    el mismo contrato de siempre: los mismos campos y los mismos 422 con
 *    `{"errorMsg": "..."}`. Vive en `/api/clientes`.
 *
 * LAS DOS MITADES COMPARTEN los datos: la lista y la ficha se construyen UNA
 * vez, con las mismas consultas, y cada mitad los viste como su. Dos copias
 * de la misma consulta divergen, y la lista de la pagina y la lista de la API
 * tienen que decir lo mismo.
 *
 * REGLAS que fija esta clase:
 *
 *  - LA TIENDA ACTIVA: el credito de un cliente es POR TIENDA. La lista, la
 *    ficha y el cobro hablan de la tienda del contexto (`PosContext`), la
 *    misma que usa la caja: el saldo de un cliente en una tienda no es el de
 *    otra.
 *  - EL SALDO QUE SE ENSEÑA ES EL DERIVADO, nunca el cache. El cache
 *    (`customer_stores.balance`/`due`) puede desfasarse; la verdad son los
 *    movimientos. Y cuando el cache NO coincide con el derivado, la ficha lo
 *    DICE: no lo arregla a mano, porque el cache lo refresca el servicio.
 *  - LA BUSQUEDA: por NOMBRE como prefijo y por `tax_id` (el documento)
 *    exacto, como el codigo de un producto. Un termino de busqueda es DATO,
 *    no patron.
 *  - EL LIMITE DE CREDITO SE MUESTRA Y SE AVISA si la deuda lo supera, pero
 *    NO BLOQUEA LA VENTA: bloquear es una decision de negocio que todavia no
 *    esta tomada, y tomarla aqui seria inventarla.
 *  - LA EDICION NO TOCA EL SALDO: el saldo es DERIVADO, no un campo. La
 *    edicion cambia los datos del cliente y nada mas.
 *
 * Los importes viajan como TEXTO decimal: las columnas son `numeric(25,4)` y
 * un `float` no es un dinero.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CustomerBalanceService $balances,
        private readonly CustomerPaymentService $payments,
    ) {}

    /**
     * La LISTA de clientes: busqueda por nombre o por documento, y paginacion.
     *
     * El CONTRATO de la respuesta:
     *
     *  - `customers`: lista de `{ id, name, tax_id, balance, due }`. El saldo
     *    y la deuda son el DERIVADO de los movimientos del cliente en la
     *    tienda activa, como texto decimal.
     *  - `pagination`: `{ page, per_page, total, last_page }`.
     *  - `filters`: `{ q }`, tal y como se aplica.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $store = $this->context($request)->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $listado = $this->listadoDeClientes($request, $store);

        // La API conserva su contrato: la lista no lleva el limite ni la
        // respuesta a si el cliente esta sobre el. Eso es de la PANTALLA.
        $clientes = array_map(static fn (array $cliente): array => [
            'id' => $cliente['id'],
            'name' => $cliente['name'],
            'tax_id' => $cliente['tax_id'],
            'balance' => $cliente['balance'],
            'due' => $cliente['due'],
        ], $listado['customers']);

        return response()->json([
            'customers' => $clientes,
            'pagination' => $listado['pagination'],
            'filters' => $listado['filters'],
            'store' => $this->storeProps($store),
        ]);
    }

    /**
     * La FICHA de un cliente: sus datos, su saldo, su deuda, su limite de
     * credito, su cache y su extracto.
     *
     * El CONTRATO de la respuesta:
     *
     *  - `customer`: `{ id, name, email, phone, mobile, address, city,
     *    zip_code, customer_state, customer_country, tax_id, is_active,
     *    credit_limit }`.
     *  - `balance` y `due`: el saldo DERIVADO y la deuda, como texto decimal.
     *  - `credit_limit`: el limite del cliente, o `null` si no lo tiene.
     *  - `cache`: `{ balance, due }` del bridge, o `null` si el cliente no
     *    tiene ficha en la tienda.
     *  - `cache_matches`: si el cache coincide con el derivado.
     *  - `over_limit`: si la deuda supera el limite de credito.
     *  - `warnings`: los avisos de la ficha (cache desfasado, limite
     *    superado, cliente sin ficha).
     *  - `entries`: el extracto, del mas nuevo al mas viejo.
     *  - `pagination`: `{ page, per_page, total, last_page }`.
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        try {
            $store = $this->context($request)->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $datos = $this->fichaDeCliente($request, $customer, $store);

        return response()->json([
            'customer' => $datos['customer'],
            'balance' => $datos['balance'],
            'due' => $datos['due'],
            'credit_limit' => $datos['credit_limit'],
            'cache' => $datos['cache'],
            'cache_matches' => $datos['cache_matches'],
            'over_limit' => $datos['over_limit'],
            'warnings' => $this->avisos($datos),
            'entries' => $datos['entries'],
            'pagination' => $datos['pagination'],
            'store' => $datos['store'],
        ]);
    }

    /**
     * El ALTA de un cliente: su ficha en la tienda activa nace con el saldo a
     * cero, porque lo que no esta movido no se debe.
     *
     * Sin ficha, el cliente no tiene credito en la tienda, y un cobro suyo
     * seria un cobro sin destino.
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validarAlta($datos);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $store = $this->context($request)->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $documento = isset($datos['tax_id']) ? trim((string) $datos['tax_id']) : '';

        // El DOCUMENTO es unico: sin el, la busqueda por documento devolveria
        // mas de un cliente, y el documento identifica a una persona.
        if ($documento !== '') {
            $existe = $this->connection->table('customers')
                ->whereRaw('UPPER(tax_id) = ?', [mb_strtoupper($documento)])
                ->exists();

            if ($existe) {
                return response()->json([
                    'errorMsg' => 'Ya existe un cliente con el documento '.$documento.'.',
                ], 422);
            }
        }

        $limite = isset($datos['credit_limit']) && $datos['credit_limit'] !== null
            ? PosAmount::declare($datos['credit_limit'], 'limite de credito')
            : null;

        $activo = array_key_exists('is_active', $datos) ? (bool) $datos['is_active'] : true;

        $cliente = $this->connection->transaction(function () use ($datos, $documento, $limite, $activo, $store): Customer {
            $cliente = Customer::create([
                'name' => trim((string) $datos['name']),
                'email' => $this->texto($datos['email'] ?? null),
                'phone' => $this->texto($datos['phone'] ?? null),
                'mobile' => $this->texto($datos['mobile'] ?? null),
                'address' => $this->texto($datos['address'] ?? null),
                'city' => $this->texto($datos['city'] ?? null),
                'zip_code' => $this->texto($datos['zip_code'] ?? null),
                'customer_state' => $this->texto($datos['customer_state'] ?? null),
                'customer_country' => isset($datos['customer_country']) ? (int) $datos['customer_country'] : null,
                'tax_id' => $documento === '' ? null : $documento,
                'credit_limit' => $limite,
                'is_active' => $activo,
            ]);

            DB::table('customer_stores')->insert([
                'customer_id' => $cliente->id,
                'store_id' => (int) $store->id,
                'status' => 'active',
                'sort_order' => 0,
                // El cache arranca en cero: lo que no esta movido, no se debe.
                'balance' => '0.0000',
                'due' => '0.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $cliente;
        });

        return response()->json([
            'id' => (int) $cliente->id,
            'name' => (string) $cliente->name,
            'tax_id' => $cliente->tax_id,
            'credit_limit' => $limite,
            'balance' => '0.0000',
            'due' => '0.0000',
            'store_id' => (int) $store->id,
        ], 201);
    }

    /**
     * La EDICION de un cliente: sus DATOS.
     *
     * REGLA que fija este metodo: *la edicion NO toca el saldo*. El saldo es
     * DERIVADO de los movimientos, no un campo que se escribe: un saldo
     * editable es un saldo que se puede desincronizar de la verdad. Por eso
     * aqui no se escribe ni el saldo ni el cache de la tienda, y el unico
     * movimiento que puede cambiar la deuda es un COBRO.
     *
     * La ficha por tienda tampoco se toca: la edicion es del maestro del
     * cliente, y abrirle credito en una tienda es otra cosa.
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validarEdicion($datos);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $store = $this->context($request)->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $documento = isset($datos['tax_id']) ? trim((string) $datos['tax_id']) : '';

        // El DOCUMENTO sigue siendo unico: el del propio cliente no se
        // cuenta como choque.
        if ($documento !== '') {
            $existe = $this->connection->table('customers')
                ->whereRaw('UPPER(tax_id) = ?', [mb_strtoupper($documento)])
                ->where('id', '!=', (int) $customer->id)
                ->exists();

            if ($existe) {
                return response()->json([
                    'errorMsg' => 'Ya existe un cliente con el documento '.$documento.'.',
                ], 422);
            }
        }

        // El LIMITE: si no viene, se queda el que habia; si viene vacio, se
        // quita. Un limite que se puede poner y quitar desde la pantalla es
        // un limite, no un saldo.
        if (! array_key_exists('credit_limit', $datos)) {
            $limite = $customer->credit_limit === null ? null : PosAmount::text($customer->credit_limit);
        } elseif ($datos['credit_limit'] === null || $datos['credit_limit'] === '') {
            $limite = null;
        } else {
            $limite = PosAmount::declare($datos['credit_limit'], 'limite de credito');
        }

        $customer->fill([
            'name' => trim((string) $datos['name']),
            'email' => $this->texto($datos['email'] ?? null),
            'phone' => $this->texto($datos['phone'] ?? null),
            'mobile' => $this->texto($datos['mobile'] ?? null),
            'address' => $this->texto($datos['address'] ?? null),
            'city' => $this->texto($datos['city'] ?? null),
            'zip_code' => $this->texto($datos['zip_code'] ?? null),
            'customer_state' => $this->texto($datos['customer_state'] ?? null),
            'customer_country' => isset($datos['customer_country']) ? (int) $datos['customer_country'] : null,
            'tax_id' => $documento === '' ? null : $documento,
            'credit_limit' => $limite,
            'is_active' => array_key_exists('is_active', $datos) ? (bool) $datos['is_active'] : $customer->is_active,
        ]);

        $customer->save();

        $storeId = (int) $store->id;
        $customerId = (int) $customer->id;

        return response()->json([
            'id' => $customerId,
            'name' => (string) $customer->name,
            'tax_id' => $customer->tax_id,
            'credit_limit' => $limite,
            // El saldo DERIVADO: lo que dice la verdad, no lo que se ha
            // escrito.
            'balance' => $this->balances->balance($customerId, $storeId),
            'due' => $this->balances->due($customerId, $storeId),
            'store_id' => $storeId,
        ]);
    }

    /**
     * El COBRO de una deuda: lo que el cliente paga reduce su deuda, y el
     * saldo derivado baja con ella.
     *
     * El importe es TEXTO decimal y tiene que ser MAYOR QUE CERO: un cobro
     * de cero no es un cobro, y uno negativo es un reintegro, que se
     * registra con su propio tipo.
     *
     * Un cliente SIN FICHA en la tienda no tiene credito en ella, y su cobro
     * se rechaza con un 422 que lo dice.
     *
     * EL COBRO SE CONTABILIZA: el movimiento del libro del cliente y el
     * asiento contable se escriben en la MISMA transaccion, y el efectivo
     * cobrado llega a la cuenta de caja de la tienda. El detalle es del
     * dominio (`CustomerPaymentService`): aqui solo se orchestra.
     */
    public function cobro(Request $request, Customer $customer): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validarCobro($datos);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $context = $this->context($request);
            $store = $context->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $storeId = (int) $store->id;
        $customerId = (int) $customer->id;

        // La FECHA del movimiento: la que se manda, y si no viene, la fecha
        // de NEGOCIO de la tienda.
        $entryDate = isset($datos['entry_date']) && is_string($datos['entry_date']) && trim($datos['entry_date']) !== ''
            ? new DateTimeImmutable(trim($datos['entry_date']))
            : $context->businessDate();

        try {
            $entryId = $this->payments->collect(
                customerId: $customerId,
                storeId: $storeId,
                amount: (string) $datos['amount'],
                referenceNo: (string) $datos['reference_no'],
                entryDate: $entryDate,
                accounts: $context->accounts(),
                saleId: null,
                actorId: $request->user()?->id,
                occurredAt: $context->issuedAt(),
            );
        } catch (RuntimeException $fallo) {
            // El cliente no tiene ficha en la tienda, o el plan de cuentas no
            // declara la cuenta que el cobro necesita: su credito en ella no
            // existe, y un cobro sin destino no se registra.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        return response()->json([
            'entry_id' => $entryId,
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'amount' => PosAmount::text($datos['amount']),
            'reference_no' => (string) $datos['reference_no'],
            'entry_date' => $entryDate->format('Y-m-d'),
            'balance' => $this->balances->balance($customerId, $storeId),
            'due' => $this->balances->due($customerId, $storeId),
        ], 201);
    }

    /**
     * La PAGINA del LISTADO: los clientes de la tienda activa, con su saldo,
     * su deuda, su limite y si estan sobre el limite.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/customers/index.tsx`:
     *
     *  - `customers`: lista de `{ id, name, tax_id, balance, due,
     *    credit_limit, over_limit }`. Los importes son TEXTO decimal.
     *  - `pagination`: `{ page, per_page, total, last_page }`.
     *  - `filters`: `{ q }`, tal y como se aplica.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     *  - `currency`: la moneda de la tienda, para formatear los importes.
     */
    public function listado(Request $request): Response
    {
        $escenario = $this->escenario($request);
        $listado = $this->listadoDeClientes($request, $escenario['store']);

        return Inertia::render('customers/index', [
            'customers' => $listado['customers'],
            'pagination' => $listado['pagination'],
            'filters' => $listado['filters'],
            'store' => $this->storeProps($escenario['store']),
            'currency' => $escenario['currency'],
        ]);
    }

    /**
     * La PAGINA del ALTA: el MISMO formulario que la edicion, sin cliente.
     */
    public function alta(Request $request): Response
    {
        $escenario = $this->escenario($request);

        return Inertia::render('customers/form', $this->formularioDeCliente(null, $escenario));
    }

    /**
     * La PAGINA de la EDICION: el MISMO formulario que el alta, con el
     * cliente.
     */
    public function edicion(Request $request, Customer $customer): Response
    {
        $escenario = $this->escenario($request);

        return Inertia::render('customers/form', $this->formularioDeCliente($customer, $escenario));
    }

    /**
     * La PAGINA de la FICHA: el cliente, sus tres cifras, los avisos y el
     * extracto de su libro.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/customers/show.tsx`:
     *
     *  - `customer`: `{ id, name, email, phone, mobile, address, city,
     *    zip_code, customer_state, customer_country, tax_id, is_active,
     *    credit_limit }`.
     *  - `balance`, `due` y `credit_limit`: las tres cifras, como texto
     *    decimal.
     *  - `cache`: `{ balance, due }` del bridge, o `null` si el cliente no
     *    tiene ficha en la tienda.
     *  - `cache_matches`: si el cache coincide con el derivado.
     *  - `over_limit`: si la deuda supera el limite.
     *  - `entries`: el extracto, del mas nuevo al mas viejo.
     *  - `pagination`: `{ page, per_page, total, last_page }`.
     *  - `store` y `currency`: la tienda activa y su moneda.
     */
    public function ficha(Request $request, Customer $customer): Response
    {
        $escenario = $this->escenario($request);
        $datos = $this->fichaDeCliente($request, $customer, $escenario['store']);

        return Inertia::render('customers/show', [
            ...$datos,
            'currency' => $escenario['currency'],
        ]);
    }

    /**
     * El ESCENARIO de una pagina: la tienda activa y su moneda.
     *
     * Sin tienda activa o sin moneda las PAGINAS no abren: no se sirve una
     * pantalla de una tienda que no existe.
     *
     * @return array{store: Store, currency: array<string, mixed>}
     */
    private function escenario(Request $request): array
    {
        $context = $this->context($request);

        try {
            $store = $context->store();
            $currency = $context->currency();
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }

        return ['store' => $store, 'currency' => $currency];
    }

    /**
     * La CONSULTA de los clientes: los que tienen ficha en la tienda activa.
     */
    private function consultaDeClientes(int $storeId)
    {
        return $this->connection->table('customers')
            ->join('customer_stores', function (JoinClause $join) use ($storeId): void {
                $join->on('customer_stores.customer_id', '=', 'customers.id')
                    ->where('customer_stores.store_id', '=', $storeId);
            })
            ->where('customers.is_active', true)
            ->where('customer_stores.status', 'active');
    }

    /**
     * El LISTADO: los clientes de la tienda con su saldo derivado, su deuda,
     * su limite de credito y si estan sobre el limite.
     *
     * La construccion es UNA, y las dos mitades (la API y la pagina) la
     * visten: la API quita el limite y la respuesta al limite, que son de la
     * pantalla, y la pagina los enseña.
     *
     * @return array{customers: list<array<string, mixed>>, pagination: array<string, int>, filters: array<string, string>}
     */
    private function listadoDeClientes(Request $request, Store $store): array
    {
        $storeId = (int) $store->id;
        $termino = $this->termino($request);
        $page = $this->pagina($request);
        $perPage = $this->porPagina();

        $consulta = $this->consultaDeClientes($storeId);

        if ($termino !== '') {
            $consulta->where(function ($filtro) use ($termino): void {
                $filtro->whereRaw('UPPER(customers.name) LIKE ?', [$this->patronPrefijo($termino)])
                    ->orWhereRaw('UPPER(customers.tax_id) = ?', [mb_strtoupper($termino)]);
            });
        }

        $total = $consulta->count('customers.id');

        $rows = $consulta
            ->orderBy('customers.name')
            ->orderBy('customers.id')
            ->forPage($page, $perPage)
            ->get([
                'customers.id as id',
                'customers.name as name',
                'customers.tax_id as tax_id',
                'customers.credit_limit as credit_limit',
            ]);

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }

        $saldos = $this->balances->balances($storeId, $ids);

        $clientes = [];

        foreach ($rows as $row) {
            $saldo = $saldos[(int) $row->id] ?? ['balance' => '0.0000', 'due' => '0.0000'];

            $limite = $row->credit_limit === null ? null : PosAmount::text($row->credit_limit);

            // SOBRE EL LIMITE: lo que DEBE el cliente supera lo que se le
            // permite deber. Se avisa; no se bloquea.
            $excede = $limite !== null && bccomp($saldo['due'], $limite, PosAmount::SCALE) > 0;

            $clientes[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'tax_id' => $row->tax_id === null ? null : (string) $row->tax_id,
                'balance' => $saldo['balance'],
                'due' => $saldo['due'],
                'credit_limit' => $limite,
                'over_limit' => $excede,
            ];
        }

        return [
            'customers' => $clientes,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'filters' => ['q' => $termino],
        ];
    }

    /**
     * La FICHA de un cliente: sus datos, su saldo derivado, su deuda, su
     * limite, su cache y su extracto.
     *
     * @return array<string, mixed>
     */
    private function fichaDeCliente(Request $request, Customer $customer, Store $store): array
    {
        $storeId = (int) $store->id;
        $customerId = (int) $customer->id;

        // 1. EL SALDO DERIVADO: la verdad, no el cache.
        $saldo = $this->balances->balance($customerId, $storeId);
        $deuda = $this->balances->due($customerId, $storeId);

        // 2. EL CACHE, y si coincide con el derivado.
        $ficha = $this->connection->table('customer_stores')
            ->where('customer_id', $customerId)
            ->where('store_id', $storeId)
            ->first();

        $cache = $ficha === null ? null : [
            'balance' => PosAmount::text($ficha->balance),
            'due' => PosAmount::text($ficha->due),
        ];

        $coincide = $this->balances->holds($customerId, $storeId);

        // 3. EL LIMITE DE CREDITO: se muestra y se avisa, pero NO bloquea.
        $limite = $customer->credit_limit === null ? null : PosAmount::text($customer->credit_limit);

        $excede = $limite !== null && bccomp($deuda, $limite, PosAmount::SCALE) > 0;

        // 4. EL EXTRACTO: los movimientos de esa tienda, del mas nuevo al mas
        //    viejo.
        $page = $this->pagina($request);
        $perPage = $this->porPagina();

        $total = $this->connection->table('customer_ledger_entries')
            ->where('customer_id', $customerId)
            ->where('store_id', $storeId)
            ->count();

        $movimientos = $this->connection->table('customer_ledger_entries')
            ->where('customer_id', $customerId)
            ->where('store_id', $storeId)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $extracto = [];

        foreach ($movimientos as $movimiento) {
            $extracto[] = [
                'id' => (int) $movimiento->id,
                'type' => (string) $movimiento->type,
                'reference_no' => $movimiento->reference_no === null ? null : (string) $movimiento->reference_no,
                'amount' => PosAmount::text($movimiento->amount),
                'balance_after' => $movimiento->balance_after === null ? null : PosAmount::text($movimiento->balance_after),
                'entry_date' => (string) $movimiento->entry_date,
                'description' => $movimiento->description === null ? null : (string) $movimiento->description,
            ];
        }

        return [
            'customer' => $this->clienteProps($customer),
            'balance' => $saldo,
            'due' => $deuda,
            'credit_limit' => $limite,
            'cache' => $cache,
            'cache_matches' => $coincide,
            'over_limit' => $excede,
            'entries' => $extracto,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'store' => $this->storeProps($store),
        ];
    }

    /**
     * El FORMULARIO de un cliente: el alta y la edicion son la MISMA
     * pantalla, con el cliente o sin el.
     *
     * @param  array{store: Store, currency: array<string, mixed>}  $escenario
     * @return array<string, mixed>
     */
    private function formularioDeCliente(?Customer $customer, array $escenario): array
    {
        return [
            'customer' => $customer === null ? null : $this->clienteProps($customer),
            // Los PAISES son FILAS: el pais de un cliente es un dato, no un
            // literal de la interfaz.
            'countries' => $this->paises(),
            'store' => $this->storeProps($escenario['store']),
            'currency' => $escenario['currency'],
        ];
    }

    /**
     * Los DATOS de un cliente, tal y como los enseña la pantalla.
     *
     * @return array<string, mixed>
     */
    private function clienteProps(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'name' => (string) $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'mobile' => $customer->mobile,
            'address' => $customer->address,
            'city' => $customer->city,
            'zip_code' => $customer->zip_code,
            'customer_state' => $customer->customer_state,
            'customer_country' => $customer->customer_country === null ? null : (int) $customer->customer_country,
            'tax_id' => $customer->tax_id,
            'is_active' => (bool) $customer->is_active,
            'credit_limit' => $customer->credit_limit === null ? null : PosAmount::text($customer->credit_limit),
        ];
    }

    /**
     * Los PAISES del catalogo, para el selector del formulario.
     *
     * @return list<array{id: int, name: string}>
     */
    private function paises(): array
    {
        return $this->connection->table('countries')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($pais): array => [
                'id' => (int) $pais->id,
                'name' => (string) $pais->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Los AVISOS de la ficha: el cache desfasado, el limite superado y el
     * cliente sin ficha en la tienda.
     *
     * Son el aviso de la API, en castellano: el contrato de la API. La
     * PANTALLA escribe los suyos en el idioma de la interfaz, a partir de las
     * MISMAS banderas (`cache`, `cache_matches` y `over_limit`), porque un
     * texto del servidor en otro idioma no se puede pintar en la pantalla.
     *
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    private function avisos(array $datos): array
    {
        $avisos = [];

        if ($datos['cache'] === null) {
            $avisos[] = 'El cliente no tiene ficha en la tienda activa: su credito en esta tienda no existe.';
        } elseif (! $datos['cache_matches']) {
            $avisos[] = 'El cache del saldo no coincide con el saldo derivado: el saldo derivado es la verdad.';
        }

        if ($datos['over_limit']) {
            $avisos[] = 'La deuda del cliente supera su limite de credito.';
        }

        return $avisos;
    }

    /**
     * El CONTEXTO de la peticion.
     *
     * Se crea aqui y no en el constructor: el controlador puede sobrevivir a
     * una peticion, y un contexto guardado en la instancia seria el contexto
     * de la peticion ANTERIOR.
     */
    private function context(Request $request): PosContext
    {
        return new PosContext($this->connection, $request->user()?->id);
    }

    /**
     * VALIDA la entrada del ALTA.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validarAlta(array $datos): ?string
    {
        $reglas = [
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'customer_state' => ['nullable', 'string', 'max:100'],
            'customer_country' => ['nullable', 'integer', 'exists:countries,id'],
            'credit_limit' => ['nullable', 'string', 'decimal:0,4', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];

        $validador = Validator::make($datos, $reglas);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }

    /**
     * VALIDA la entrada de la EDICION.
     *
     * Las reglas son las del ALTA, y el SALDO no aparece: no es un campo, es
     * un DERIVADO, y un derivado que se puede escribir es un derivado que se
     * puede desincronizar.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validarEdicion(array $datos): ?string
    {
        return $this->validarAlta($datos);
    }

    /**
     * VALIDA la entrada del COBRO.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validarCobro(array $datos): ?string
    {
        $reglas = [
            'amount' => ['required', 'string', 'decimal:0,4'],
            'reference_no' => ['required', 'string', 'max:100'],
            'entry_date' => ['nullable', 'date_format:Y-m-d'],
        ];

        $validador = Validator::make($datos, $reglas);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        // El importe tiene que ser MAYOR QUE CERO. Un cobro de cero no es un
        // cobro, y uno negativo es un reintegro, que se registra con su
        // propio tipo.
        try {
            $importe = BigDecimal::of(trim((string) $datos['amount']));
        } catch (Throwable) {
            return 'El importe del cobro no es un numero decimal.';
        }

        if (! $importe->isGreaterThan(BigDecimal::zero())) {
            return 'El importe del cobro tiene que ser mayor que cero.';
        }

        return null;
    }

    /**
     * El TERMINO de busqueda de la peticion.
     */
    private function termino(Request $request): string
    {
        $query = $request->query('q');

        return is_string($query) ? trim($query) : '';
    }

    /**
     * La PAGINA de la peticion.
     */
    private function pagina(Request $request): int
    {
        $page = $request->query('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }

    /**
     * Cuantos clientes trae cada pagina.
     */
    private function porPagina(): int
    {
        return max(1, (int) config('pos.customer_page_size', 25));
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
     * Un TEXTO opcional: vacio es `null`, no una cadena de espacios.
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * La TIENDA del contexto, para la pantalla.
     *
     * @return array{id: int, name: string, code_name: string|null}
     */
    private function storeProps(Store $tienda): array
    {
        return [
            'id' => (int) $tienda->id,
            'name' => (string) $tienda->name,
            'code_name' => $tienda->code_name,
        ];
    }
}
