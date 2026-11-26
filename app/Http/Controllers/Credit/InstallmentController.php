<?php

namespace App\Http\Controllers\Credit;

use App\Domain\Credit\InstallmentCollectionService;
use App\Domain\Credit\InstallmentPlanService;
use App\Domain\Reports\CustomerAgingReport;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportScope;
use App\Domain\Sales\AppliedAmount;
use App\Http\Controllers\StoreContextController;
use App\Models\Customer;
use App\Support\Pos\PosAmount;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Las CUOTAS: los planes de la tienda, su calendario y el cobro.
 *
 * REGLA que fija esta clase: *las PAGINAS son Inertia y las ESCRITURAS son
 * JSON*, como en el resto del repositorio. Y NO decide nada de negocio: el
 * plan lo crea `InstallmentPlanService` y el cobro lo registra
 * `InstallmentCollectionService`; aqui solo se traduce su fallo al contrato
 * de la API (422 con `{"errorMsg"}`).
 *
 * Y LA TIENDA es la del CONTEXTO (la de la sesion), la misma que usa la caja:
 * el credito de un cliente es POR TIENDA, y un plan de otra tienda no se ve
 * ni se cobra desde esta.
 *
 * Y EL EXTRACTO DE UN CLIENTE es el EXTRACTO DE UN PLAN, no un reporte
 * nuevo: las cuotas son las de sus planes, y la deuda del cliente sale del
 * AGING que ya existe (el mismo que lee `customer_ledger_entries`), no de una
 * segunda cuenta.
 *
 * Los importes viajan como TEXTO decimal: las columnas son `numeric(25,4)` y
 * un `float` no es un dinero.
 */
class InstallmentController extends StoreContextController
{
    /**
     * Cuantos planes trae la pantalla.
     *
     * Es un tope, no una paginacion: la lista es el RECENTRO de la tienda, y
     * una pagina que trae cincuenta filas es la que se lee.
     */
    private const LIMITE = 50;

    public function __construct(
        Connection $connection,
        private readonly InstallmentPlanService $plans,
        private readonly InstallmentCollectionService $collections,
    ) {
        parent::__construct($connection);
    }

    /**
     * La PAGINA de los planes de cuotas de la tienda, con el estado de cada
     * cuota.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/credit/installments.tsx`:
     *
     *  - `plans`: lista de `{ id, sale_id, invoice_no, customer, principal,
     *    interest_percentage, installment_count, date, status, total,
     *    paid_total, pending_total, quotas }`. Los importes son TEXTO decimal.
     *  - `sales`: las ventas a credito SIN plan, para acordar uno.
     *  - `currency`: la moneda de la tienda.
     *  - `business_date`: la fecha de NEGOCIO de la tienda.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     */
    public function index(Request $request): Response
    {
        try {
            $tienda = $this->tienda($request);
            $contexto = $this->contexto($request);
            $moneda = $contexto->currency();
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }

        $storeId = (int) $tienda->id;

        return Inertia::render('credit/installments', [
            'plans' => $this->planes($storeId, null, $contexto->businessDate()->format('Y-m-d')),
            'sales' => $this->ventasParaFinanciar($storeId),
            'currency' => $moneda,
            'business_date' => $contexto->businessDate()->format('Y-m-d'),
            'store' => $this->propsDeTienda($tienda),
        ]);
    }

    /**
     * La PAGINA de las cuotas de un cliente: el EXTRACTO de sus planes.
     *
     * Y LA DEUDA del cliente sale del AGING que ya existe: no se repara la
     * cuenta del cliente para esta pantalla.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/credit/customer-installments.tsx`:
     *
     *  - `customer`: `{ id, name, tax_id }`.
     *  - `plans`: los planes del cliente, con sus cuotas.
     *  - `aging`: `{ balance, due, credit, oldest_date, age_days }` del
     *    cliente en la tienda, o `null` si no debe nada.
     *  - `currency`, `business_date` y `store`: como en el resto.
     */
    public function customer(Request $request, Customer $customer): Response
    {
        try {
            $tienda = $this->tienda($request);
            $contexto = $this->contexto($request);
            $moneda = $contexto->currency();
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }

        $storeId = (int) $tienda->id;
        $customerId = (int) $customer->id;

        return Inertia::render('credit/customer-installments', [
            'customer' => [
                'id' => $customerId,
                'name' => (string) $customer->name,
                'tax_id' => $customer->tax_id,
            ],
            'plans' => $this->planes($storeId, $customerId, $contexto->businessDate()->format('Y-m-d')),
            'aging' => $this->aging($storeId, $customerId, $contexto->businessDate()),
            'currency' => $moneda,
            'business_date' => $contexto->businessDate()->format('Y-m-d'),
            'store' => $this->propsDeTienda($tienda),
        ]);
    }

    /**
     * El ALTA del plan de cuotas de una venta a credito.
     *
     * Responde 201 con `{ plan_id, principal, interest, total, count,
     * first_due_date, balance, due }`: las tres cifras del plan y como queda
     * la deuda del cliente.
     *
     * Y SI EL PLAN NO SE PUEDE ACORDAR, no se acuerda: el fallo del dominio
     * (una venta de otra tienda, una venta sin deuda, una venta que ya tiene
     * plan) es un 422 con su mensaje, y no queda ni plan ni calendario ni
     * interes.
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validar($datos);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $tienda = $this->tienda($request);
            $contexto = $this->contexto($request);
            $negocio = $contexto->businessDate();
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        try {
            $resultado = $this->plans->create(
                saleId: (int) $datos['sale_id'],
                storeId: (int) $tienda->id,
                interestPercentage: (string) $datos['interest_percentage'],
                count: (int) $datos['count'],
                firstDueDate: new DateTimeImmutable((string) $datos['first_due_date']),
                businessDate: $negocio,
                occurredAt: $contexto->issuedAt(),
                accounts: $contexto->accounts(),
                referenceNo: $this->texto($datos['reference_no'] ?? null),
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        // La AUDITORIA: quien acordo el plan, sobre que venta, con que interes
        // y cuantas cuotas.
        $this->auditar(
            $request,
            'installment.plan_created',
            'installment_plan',
            $resultado->planId,
            [
                'sale_id' => $resultado->saleId,
                'principal' => $resultado->principal,
                'interest' => $resultado->interest,
                'total' => $resultado->total,
                'count' => $resultado->count,
                'first_due_date' => $resultado->firstDueDate,
                'business_date' => $negocio->format('Y-m-d'),
            ],
            (int) $tienda->id
        );

        return response()->json([
            'plan_id' => $resultado->planId,
            'sale_id' => $resultado->saleId,
            'principal' => $resultado->principal,
            'interest' => $resultado->interest,
            'total' => $resultado->total,
            'count' => $resultado->count,
            'first_due_date' => $resultado->firstDueDate,
            'entry_id' => $resultado->entryId,
            'balance' => $resultado->balance,
            'due' => $resultado->due,
        ], 201);
    }

    /**
     * El COBRO de una cuota.
     *
     * Responde 201 con `{ schedule_id, plan_id, amount, plan_status,
     * entry_id, balance, due }`.
     *
     * Y EL DINERO ENTRA A LA CAJA: el cobro exige la caja del dia ABIERTA, y
     * si no esta, el dominio lo dice y no se cobra nada.
     */
    public function collect(Request $request, int $schedule): JsonResponse
    {
        try {
            $tienda = $this->tienda($request);
            $contexto = $this->contexto($request);
            $negocio = $contexto->businessDate();
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        try {
            $resultado = $this->collections->collect(
                scheduleId: $schedule,
                storeId: (int) $tienda->id,
                businessDate: $negocio,
                occurredAt: $contexto->issuedAt(),
                accounts: $contexto->accounts(),
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        // La AUDITORIA: quien cobro, cuanto y que cuota. Un cobro sin rastro
        // es un cobro que el cliente podria disputar.
        $this->auditar(
            $request,
            'installment.collected',
            'installment_schedule',
            $resultado->scheduleId,
            [
                'plan_id' => $resultado->planId,
                'amount' => $resultado->amount,
                'plan_status' => $resultado->planStatus,
                'entry_id' => $resultado->entryId,
                'business_date' => $negocio->format('Y-m-d'),
            ],
            (int) $tienda->id
        );

        return response()->json([
            'schedule_id' => $resultado->scheduleId,
            'plan_id' => $resultado->planId,
            'amount' => $resultado->amount,
            'plan_status' => $resultado->planStatus,
            'entry_id' => $resultado->entryId,
            'balance' => $resultado->balance,
            'due' => $resultado->due,
        ], 201);
    }

    /**
     * LOS PLANES de la tienda (o de un cliente), con el estado de cada cuota.
     *
     * Y LAS CUOTAS se leen de UNA consulta para todos los planes: la pantalla
     * no pregunta cuota a cuota.
     *
     * @return list<array<string, mixed>>
     */
    private function planes(int $storeId, ?int $customerId, string $businessDate): array
    {
        $consulta = $this->connection->table('installment_plans')
            ->join('customers', 'customers.id', '=', 'installment_plans.customer_id')
            ->leftJoin('sales', 'sales.id', '=', 'installment_plans.sale_id')
            ->where('installment_plans.store_id', $storeId)
            ->orderByDesc('installment_plans.id')
            ->limit(self::LIMITE);

        if ($customerId !== null) {
            $consulta->where('installment_plans.customer_id', $customerId);
        }

        $filas = $consulta->get([
            'installment_plans.id as id',
            'installment_plans.sale_id as sale_id',
            'installment_plans.customer_id as customer_id',
            'installment_plans.reference_no as reference_no',
            'installment_plans.principal as principal',
            'installment_plans.interest_percentage as interest_percentage',
            'installment_plans.installment_count as installment_count',
            'installment_plans.date as date',
            'installment_plans.status as status',
            'customers.name as customer_name',
            'sales.invoice_no as invoice_no',
        ]);

        if ($filas === []) {
            return [];
        }

        $ids = [];

        foreach ($filas as $fila) {
            $ids[] = (int) $fila->id;
        }

        $cuotasPorPlan = $this->cuotasDeLosPlanes($ids, $businessDate);

        $planes = [];

        foreach ($filas as $fila) {
            $cuotas = $cuotasPorPlan[(int) $fila->id] ?? [];

            $total = '0.0000';
            $cobrado = '0.0000';

            foreach ($cuotas as $cuota) {
                $total = PosAmount::decimal($total)
                    ->plus(PosAmount::decimal($cuota['amount']))
                    ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);

                $cobrado = PosAmount::decimal($cobrado)
                    ->plus(PosAmount::decimal($cuota['paid_amount']))
                    ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP);
            }

            $planes[] = [
                'id' => (int) $fila->id,
                'sale_id' => $fila->sale_id === null ? null : (int) $fila->sale_id,
                'invoice_no' => $fila->invoice_no === null ? null : (string) $fila->invoice_no,
                'reference_no' => $fila->reference_no === null ? null : (string) $fila->reference_no,
                'customer' => [
                    'id' => (int) $fila->customer_id,
                    'name' => (string) ($fila->customer_name ?? ''),
                ],
                'principal' => PosAmount::text($fila->principal),
                'interest_percentage' => PosAmount::text($fila->interest_percentage),
                'installment_count' => (int) $fila->installment_count,
                'date' => (string) $fila->date,
                'status' => (string) $fila->status,
                'total' => PosAmount::text($total),
                'paid_total' => PosAmount::text($cobrado),
                'pending_total' => PosAmount::text(PosAmount::decimal($total)->minus(PosAmount::decimal($cobrado))),
                'quotas' => $cuotas,
            ];
        }

        return $planes;
    }

    /**
     * LAS CUOTAS de una lista de planes, agrupadas por plan.
     *
     * Y LA VENCIDA se DERIVA: una cuota que no esta cobrada y cuyo vencimiento
     * ya paso esta VENCIDA, y eso es un HECHO (una fecha), no una regla. La
     * columna `status` de la cuota no se escribe: el estado de la cuota lo
     * dice la columna, y la vencida se calcula al leerla.
     *
     * @param  list<int>  $planIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function cuotasDeLosPlanes(array $planIds, string $businessDate): array
    {
        if ($planIds === []) {
            return [];
        }

        $filas = $this->connection->table('installment_schedules')
            ->whereIn('installment_plan_id', $planIds)
            ->orderBy('installment_plan_id')
            ->orderBy('number')
            ->get();

        $porPlan = [];

        foreach ($filas as $fila) {
            $estado = (string) $fila->status;

            $porPlan[(int) $fila->installment_plan_id][] = [
                'id' => (int) $fila->id,
                'number' => (int) $fila->number,
                'due_date' => (string) $fila->due_date,
                'amount' => PosAmount::text($fila->amount),
                'paid_amount' => PosAmount::text($fila->paid_amount),
                'status' => $estado,
                'overdue' => $estado !== 'paid' && (string) $fila->due_date < $businessDate,
            ];
        }

        return $porPlan;
    }

    /**
     * LAS VENTAS A CREDITO de la tienda que NO tienen plan: las que se pueden
     * financiar.
     *
     * Y SOLO LAS QUE TIENEN DEUDA: un plan sobre una venta cobrada seria un
     * plan de nada.
     *
     * @return list<array{id: int, invoice_no: string|null, sale_date: string, customer_name: string, total: string, pending: string}>
     */
    private function ventasParaFinanciar(int $storeId): array
    {
        $filas = $this->connection->table('sales')
            ->join('sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('installment_plans', 'installment_plans.sale_id', '=', 'sales.id')
            ->where('sales.store_id', $storeId)
            ->where('sales.status', '!=', 'cancelled')
            ->whereNull('installment_plans.id')
            ->orderByDesc('sales.id')
            ->limit(self::LIMITE)
            ->get([
                'sales.id as id',
                'sales.invoice_no as invoice_no',
                'sales.sale_date as sale_date',
                'customers.name as customer_name',
                'sale_totals.grand_total as total',
            ]);

        $ventas = [];

        foreach ($filas as $fila) {
            $ventas[] = [
                'id' => (int) $fila->id,
                'invoice_no' => $fila->invoice_no === null ? null : (string) $fila->invoice_no,
                'sale_date' => (string) $fila->sale_date,
                'customer_name' => (string) ($fila->customer_name ?? ''),
                'total' => PosAmount::text($fila->total),
                'pending' => $this->pendienteDeLaVenta((int) $fila->id, PosAmount::text($fila->total)),
            ];
        }

        return $ventas;
    }

    /**
     * LO QUE LA VENTA DEJO SIN COBRAR.
     *
     * Y LO APLICADO se lee con el CRITERIO de `AppliedAmount` (lo aplicado de
     * una fila sin `applied` es su bruto): el mismo criterio que usan el
     * estado de pago y el desglose de la caja.
     */
    private function pendienteDeLaVenta(int $saleId, string $total): string
    {
        $cobrado = $this->connection->table('sale_payments')
            ->where('sale_id', $saleId)
            ->selectRaw('COALESCE(SUM('.AppliedAmount::sql('sale_payments').'), 0) as aplicado')
            ->value('aplicado');

        return PosAmount::text(PosAmount::decimal($total)->minus(PosAmount::decimal($cobrado ?? '0')));
    }

    /**
     * LA DEUDA DEL CLIENTE, segun el AGING que ya existe.
     *
     * Y NO SE REHACE EL AGING: esta pantalla pide el aging del cliente y
     * enseña su fila. Un aging distinto en cada pantalla son dos verdades.
     *
     * @return array{balance: string, due: string, credit: string, oldest_date: string|null, age_days: int|null}|null
     */
    private function aging(int $storeId, int $customerId, DateTimeImmutable $negocio): ?array
    {
        $periodo = ReportPeriod::between(
            $negocio->modify('first day of this month')->format('Y-m-d'),
            $negocio->format('Y-m-d')
        );

        $reporte = (new CustomerAgingReport($this->connection))->build(
            ReportScope::of([$storeId]),
            $periodo
        );

        foreach ($reporte['rows'] as $fila) {
            if ((int) $fila['customer_id'] !== $customerId) {
                continue;
            }

            return [
                'balance' => (string) $fila['balance'],
                'due' => (string) $fila['due'],
                'credit' => (string) $fila['credit'],
                'oldest_date' => $fila['oldest_date'],
                'age_days' => $fila['age_days'],
            ];
        }

        return null;
    }

    /**
     * Un TEXTO de la peticion, o `null` si no hay.
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
     * VALIDA la FORMA de la entrada del alta.
     *
     * REGLA que fija este metodo: *la FORMA la comprueba la costura y el
     * CONTENIDO el dominio*. La venta tiene que estar, el interes tiene que
     * ser un porcentaje y las cuotas tienen que ser un numero de cuotas.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validar(array $datos): ?string
    {
        $validador = Validator::make($datos, [
            'sale_id' => ['required', 'integer', 'min:1'],
            'interest_percentage' => ['required', 'string', 'decimal:0,4', 'min:0'],
            // El `installment_count` de la columna es un `unsignedSmallInteger`:
            // un numero de cuotas que no cabe es un error de la FORMA, no un
            // error de PostgreSQL.
            'count' => ['required', 'integer', 'min:1', 'max:65535'],
            'first_due_date' => ['required', 'date_format:Y-m-d'],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }
}
