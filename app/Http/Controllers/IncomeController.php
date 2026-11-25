<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\IncomeService;
use App\Domain\Accounting\TreasuryAccounts;
use App\Support\Pos\PosAmount;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Los INGRESOS de la tienda: la pagina, el alta y la anulacion.
 *
 * REGLA que fija esta clase: *la pagina es Inertia y las escrituras son
 * JSON*, como en el resto del repositorio. Y el ingreso NACE en `incomes` y su
 * asiento se deriva de el: el dominio decide que cuenta se debita (la que
 * recibe el dinero) y que cuenta se abona (la de ingreso del origen).
 *
 * Y LA REGLA DE LA CAJA se ensena: la pantalla dice DONDE entra el dinero,
 * porque ahi esta la diferencia entre un ingreso que sube el saldo del cajon
 * y uno que no lo sube (el que entra en el banco).
 */
class IncomeController extends CashFlowController
{
    /**
     * Cuantos ingresos devuelve la lista.
     */
    private const LIMITE = 50;

    public function __construct(
        Connection $connection,
        private readonly IncomeService $incomes,
    ) {
        parent::__construct($connection);
    }

    /**
     * La PAGINA de los ingresos.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/accounting/incomes.tsx`:
     *
     *  - `incomes`: lista de `{ id, amount, description, reference,
     *    business_date, status, payment_type, bank_account_id, source,
     *    account_code, cancelled_at, cancellation_reason }`. Los importes son
     *    TEXTO decimal.
     *  - `sources`: los origenes de ingreso con su cuenta contable:
     *    `{ id, code, name, account_code }`.
     *  - `bank_accounts`: las cuentas bancarias de la tienda con su cuenta
     *    contable: `{ id, name, account_code }`.
     *  - `business_date`: la fecha de NEGOCIO de la tienda.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     */
    public function index(Request $request): Response
    {
        try {
            $tienda = $this->tienda($request);
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }

        $contexto = $this->contexto($request);

        return Inertia::render('accounting/incomes', [
            'incomes' => $this->ingresos((int) $tienda->id),
            'sources' => $this->origenes(),
            'bank_accounts' => (new TreasuryAccounts($this->connection))->bancos((int) $tienda->id),
            'currency' => $contexto->currency(),
            'business_date' => $contexto->businessDate()->format('Y-m-d'),
            'store' => $this->propsDeTienda($tienda),
        ]);
    }

    /**
     * El ALTA de un ingreso.
     *
     * Responde 201 con el ingreso: `{ id, amount, status, business_date,
     * payment_type }`.
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
            $negocio = $this->contexto($request)->businessDate();
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        try {
            $ingresoId = $this->incomes->register(
                storeId: (int) $tienda->id,
                amount: PosAmount::declare($datos['amount'], 'importe del ingreso'),
                businessDate: $negocio,
                paymentType: (string) $datos['payment_type'],
                bankAccountId: $this->cuentaBancaria($datos),
                incomeSourceId: isset($datos['income_source_id']) && $datos['income_source_id'] !== null
                    ? (int) $datos['income_source_id']
                    : null,
                description: $this->texto($datos['description'] ?? null),
                reference: $this->texto($datos['reference'] ?? null),
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        $ingreso = $this->connection->table('incomes')->where('id', $ingresoId)->first();

        $this->auditar(
            $request,
            'income.created',
            'income',
            $ingresoId,
            [
                'amount' => PosAmount::text($ingreso->amount),
                'business_date' => (string) $ingreso->business_date,
                'payment_type' => (string) $ingreso->payment_type,
                'bank_account_id' => $ingreso->bank_account_id === null ? null : (int) $ingreso->bank_account_id,
                'description' => $ingreso->description,
                'reference' => $ingreso->reference,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $ingresoId,
            'amount' => PosAmount::text($ingreso->amount),
            'status' => (string) $ingreso->status,
            'business_date' => (string) $ingreso->business_date,
            'payment_type' => (string) $ingreso->payment_type,
        ], 201);
    }

    /**
     * La ANULACION de un ingreso: lo marca y escribe el ajuste.
     */
    public function cancel(Request $request, int $income): JsonResponse
    {
        $motivo = trim((string) ($request->all()['reason'] ?? ''));

        if ($motivo === '') {
            return response()->json([
                'errorMsg' => 'Un ingreso se anula con un MOTIVO: un ingreso anulado sin motivo es un ingreso '
                    .'que nadie sabe por que se toco.',
            ], 422);
        }

        try {
            $tienda = $this->tienda($request);
            $this->incomes->cancel($income, $motivo, $request->user()?->id);
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        $ingreso = $this->connection->table('incomes')->where('id', $income)->first();

        $this->auditar(
            $request,
            'income.cancelled',
            'income',
            $income,
            [
                'reason' => $motivo,
                'amount' => $ingreso === null ? null : PosAmount::text($ingreso->amount),
                'business_date' => $ingreso === null ? null : (string) $ingreso->business_date,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $income,
            'status' => 'cancelled',
            'cancellation_reason' => $motivo,
        ]);
    }

    /**
     * Los INGRESOS de la tienda, los mas recientes primero.
     *
     * @return list<array<string, mixed>>
     */
    private function ingresos(int $storeId): array
    {
        $filas = $this->connection->table('incomes')
            ->leftJoin('income_sources', 'income_sources.id', '=', 'incomes.income_source_id')
            ->where('incomes.store_id', $storeId)
            ->orderByDesc('incomes.business_date')
            ->orderByDesc('incomes.id')
            ->limit(self::LIMITE)
            ->get([
                'incomes.id as id',
                'incomes.amount as amount',
                'incomes.description as description',
                'incomes.reference as reference',
                'incomes.business_date as business_date',
                'incomes.status as status',
                'incomes.payment_type as payment_type',
                'incomes.bank_account_id as bank_account_id',
                'incomes.cancelled_at as cancelled_at',
                'incomes.cancellation_reason as cancellation_reason',
                'income_sources.code as source_code',
                'income_sources.name as source_name',
                'income_sources.account_code as account_code',
            ]);

        $ingresos = [];

        foreach ($filas as $fila) {
            $ingresos[] = [
                'id' => (int) $fila->id,
                'amount' => PosAmount::text($fila->amount),
                'description' => $fila->description === null ? null : (string) $fila->description,
                'reference' => $fila->reference === null ? null : (string) $fila->reference,
                'business_date' => (string) $fila->business_date,
                'status' => (string) $fila->status,
                'payment_type' => (string) $fila->payment_type,
                'bank_account_id' => $fila->bank_account_id === null ? null : (int) $fila->bank_account_id,
                'cancelled_at' => $fila->cancelled_at === null
                    ? null
                    : Carbon::parse((string) $fila->cancelled_at)->toIso8601String(),
                'cancellation_reason' => $fila->cancellation_reason === null
                    ? null
                    : (string) $fila->cancellation_reason,
                'source' => $fila->source_code === null ? null : [
                    'code' => (string) $fila->source_code,
                    'name' => (string) $fila->source_name,
                    'account_code' => $fila->account_code === null ? null : (string) $fila->account_code,
                ],
            ];
        }

        return $ingresos;
    }

    /**
     * Los ORIGENES DE INGRESO del catalogo, con su cuenta contable.
     *
     * @return list<array{id: int, code: string, name: string, account_code: string|null}>
     */
    private function origenes(): array
    {
        $filas = $this->connection->table('income_sources')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'account_code']);

        $origenes = [];

        foreach ($filas as $fila) {
            $origenes[] = [
                'id' => (int) $fila->id,
                'code' => (string) $fila->code,
                'name' => (string) $fila->name,
                'account_code' => $fila->account_code === null ? null : (string) $fila->account_code,
            ];
        }

        return $origenes;
    }

    /**
     * La cuenta bancaria que RECIBE, si el dinero entra en el banco.
     */
    private function cuentaBancaria(array $datos): ?int
    {
        $id = $datos['bank_account_id'] ?? null;

        return $id === null || $id === '' ? null : (int) $id;
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
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validar(array $datos): ?string
    {
        $validador = Validator::make($datos, [
            'amount' => ['required', 'string', 'decimal:0,4', 'min:0'],
            'payment_type' => ['required', 'string', 'in:cash,bank'],
            'income_source_id' => ['nullable', 'integer'],
            'bank_account_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }
}
