<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\ExpenseService;
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
 * Los GASTOS de la tienda: la pagina, el alta y la anulacion.
 *
 * REGLA que fija esta clase: *la pagina es Inertia y las escrituras son
 * JSON*, como en el resto del repositorio. Y el gasto NACE en `expenses` y su
 * asiento se deriva de el: el dominio decide que cuenta se carga y que cuenta
 * se acredita, y aqui no se replica esa aritmetica.
 *
 * Y LA REGLA DE LA CAJA se ensena, no se esconde: la pantalla dice DE DONDE se
 * pago el gasto, porque ahi esta la diferencia entre un gasto que baja el
 * saldo del cajon y uno que no lo baja (el que se pago desde el banco).
 *
 * Y LA ANULACION marca el gasto, no lo borra, y exige un MOTIVO: el gasto
 * se queda en el historial con su marca, su motivo y su responsable.
 */
class ExpenseController extends CashFlowController
{
    /**
     * Cuantos gastos devuelve la lista.
     *
     * Es un tope, no una paginacion: la lista es el RECENTRO de la tienda, y
     * una pagina que trae cincuenta filas es la que se lee.
     */
    private const LIMITE = 50;

    public function __construct(
        Connection $connection,
        private readonly ExpenseService $expenses,
    ) {
        parent::__construct($connection);
    }

    /**
     * La PAGINA de los gastos.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/accounting/expenses.tsx`:
     *
     *  - `expenses`: lista de `{ id, amount, description, reference,
     *    business_date, status, payment_type, bank_account_id, category,
     *    account_code, cancelled_at, cancellation_reason }`. Los importes son
     *    TEXTO decimal.
     *  - `categories`: las categorias de gasto con su cuenta contable:
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

        return Inertia::render('accounting/expenses', [
            'expenses' => $this->gastos((int) $tienda->id),
            'categories' => $this->categorias(),
            'bank_accounts' => (new TreasuryAccounts($this->connection))->bancos((int) $tienda->id),
            'currency' => $contexto->currency(),
            'business_date' => $contexto->businessDate()->format('Y-m-d'),
            'store' => $this->propsDeTienda($tienda),
        ]);
    }

    /**
     * El ALTA de un gasto.
     *
     * Responde 201 con el gasto y su asiento: `{ id, amount, status,
     * business_date, payment_type }`.
     *
     * Y SI EL GASTO NO SE PUEDE REGISTRAR, no se registra: el fallo del
     * dominio (una categoria sin cuenta, una caja cerrada, un importe de
     * cero) es un 422 con su mensaje, y no queda ni el gasto ni su asiento.
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
            $gastoId = $this->expenses->register(
                storeId: (int) $tienda->id,
                amount: PosAmount::declare($datos['amount'], 'importe del gasto'),
                businessDate: $negocio,
                paymentType: (string) $datos['payment_type'],
                bankAccountId: $this->cuentaBancaria($datos),
                expenseCategoryId: isset($datos['expense_category_id']) && $datos['expense_category_id'] !== null
                    ? (int) $datos['expense_category_id']
                    : null,
                description: $this->texto($datos['description'] ?? null),
                reference: $this->texto($datos['reference'] ?? null),
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        $gasto = $this->connection->table('expenses')->where('id', $gastoId)->first();

        // La AUDITORIA: quien registro el gasto, cuanto, cuando y de donde se
        // pago. Un gasto sin rastro es un gasto que nadie puede defender.
        $this->auditar(
            $request,
            'expense.created',
            'expense',
            $gastoId,
            [
                'amount' => PosAmount::text($gasto->amount),
                'business_date' => (string) $gasto->business_date,
                'payment_type' => (string) $gasto->payment_type,
                'bank_account_id' => $gasto->bank_account_id === null ? null : (int) $gasto->bank_account_id,
                'description' => $gasto->description,
                'reference' => $gasto->reference,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $gastoId,
            'amount' => PosAmount::text($gasto->amount),
            'status' => (string) $gasto->status,
            'business_date' => (string) $gasto->business_date,
            'payment_type' => (string) $gasto->payment_type,
        ], 201);
    }

    /**
     * La ANULACION de un gasto: lo marca y escribe el ajuste.
     *
     * Y EL MOTIVO es OBLIGATORIO: un gasto anulado sin motivo es un gasto que
     * nadie sabe por que se toco.
     */
    public function cancel(Request $request, int $expense): JsonResponse
    {
        $motivo = trim((string) ($request->all()['reason'] ?? ''));

        if ($motivo === '') {
            return response()->json([
                'errorMsg' => 'Un gasto se anula con un MOTIVO: un gasto anulado sin motivo es un gasto '
                    .'que nadie sabe por que se toco.',
            ], 422);
        }

        try {
            $tienda = $this->tienda($request);
            $this->expenses->cancel($expense, $motivo, $request->user()?->id);
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        $gasto = $this->connection->table('expenses')->where('id', $expense)->first();

        $this->auditar(
            $request,
            'expense.cancelled',
            'expense',
            $expense,
            [
                'reason' => $motivo,
                'amount' => $gasto === null ? null : PosAmount::text($gasto->amount),
                'business_date' => $gasto === null ? null : (string) $gasto->business_date,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $expense,
            'status' => 'cancelled',
            'cancellation_reason' => $motivo,
        ]);
    }

    /**
     * Los GASTOS de la tienda, los mas recientes primero.
     *
     * @return list<array<string, mixed>>
     */
    private function gastos(int $storeId): array
    {
        $filas = $this->connection->table('expenses')
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->where('expenses.store_id', $storeId)
            ->orderByDesc('expenses.business_date')
            ->orderByDesc('expenses.id')
            ->limit(self::LIMITE)
            ->get([
                'expenses.id as id',
                'expenses.amount as amount',
                'expenses.description as description',
                'expenses.reference as reference',
                'expenses.business_date as business_date',
                'expenses.status as status',
                'expenses.payment_type as payment_type',
                'expenses.bank_account_id as bank_account_id',
                'expenses.cancelled_at as cancelled_at',
                'expenses.cancellation_reason as cancellation_reason',
                'expense_categories.code as category_code',
                'expense_categories.name as category_name',
                'expense_categories.account_code as account_code',
            ]);

        $gastos = [];

        foreach ($filas as $fila) {
            $gastos[] = [
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
                'category' => $fila->category_code === null ? null : [
                    'code' => (string) $fila->category_code,
                    'name' => (string) $fila->category_name,
                    'account_code' => $fila->account_code === null ? null : (string) $fila->account_code,
                ],
            ];
        }

        return $gastos;
    }

    /**
     * Las CATEGORIAS DE GASTO del catalogo, con su cuenta contable.
     *
     * @return list<array{id: int, code: string, name: string, account_code: string|null}>
     */
    private function categorias(): array
    {
        $filas = $this->connection->table('expense_categories')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'account_code']);

        $categorias = [];

        foreach ($filas as $fila) {
            $categorias[] = [
                'id' => (int) $fila->id,
                'code' => (string) $fila->code,
                'name' => (string) $fila->name,
                'account_code' => $fila->account_code === null ? null : (string) $fila->account_code,
            ];
        }

        return $categorias;
    }

    /**
     * La cuenta bancaria que PAGA, si el pago es desde el banco.
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
     * REGLA que fija este metodo: *la FORMA la comprueba la costura y el
     * CONTENIDO el dominio*. El importe tiene que ser un numero decimal
     * positivo y el tipo de pago uno de los dos; si el gasto no tiene donde
     * cargarse, lo dice el dominio, con su mensaje.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validar(array $datos): ?string
    {
        $validador = Validator::make($datos, [
            'amount' => ['required', 'string', 'decimal:0,4', 'min:0'],
            'payment_type' => ['required', 'string', 'in:cash,bank'],
            'expense_category_id' => ['nullable', 'integer'],
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
