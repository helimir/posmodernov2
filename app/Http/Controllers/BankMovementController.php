<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\BankMovementService;
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
 * Los MOVIMIENTOS BANCARIOS de la tienda: la pagina y el alta.
 *
 * REGLA que fija esta clase: *la pagina es Inertia y las escrituras son
 * JSON*, como en el resto del repositorio. Y el movimiento es UN hecho del
 * libro: no hay tabla de contadores, porque el saldo de una cuenta se deriva
 * de sus movimientos (spec `contabilidad-caja`, "sin contadores derivados").
 *
 * Y LAS DOS CUENTAS del movimiento son de TESORERIA: la caja de la tienda (el
 * rol `cash` de su plan) y las cuentas bancarias (la cuenta que declara su
 * vinculo por tienda). El dominio lo resuelve; aqui no se escriben codigos
 * de cuenta a mano.
 *
 * Y LA REGLA DE LA CAJA se ensena: la pantalla dice DE donde sale el dinero y
 * DONDE entra, porque ahi esta la diferencia entre un movimiento que baja el
 * saldo del cajon y uno que no lo baja.
 */
class BankMovementController extends CashFlowController
{
    /**
     * Cuantos movimientos devuelve la lista.
     */
    private const LIMITE = 50;

    public function __construct(
        Connection $connection,
        private readonly BankMovementService $movements,
    ) {
        parent::__construct($connection);
    }

    /**
     * La PAGINA de los movimientos bancarios.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/accounting/banking.tsx`:
     *
     *  - `movements`: lista de `{ id, business_date, amount, description,
     *    reference, from, to, occurred_at }`. Los importes son TEXTO decimal,
     *    y `from` y `to` son `{ account_code, name }`.
     *  - `bank_accounts`: las cuentas bancarias de la tienda con su cuenta
     *    contable: `{ id, name, account_code }`.
     *  - `cash_account`: la cuenta de efectivo de la tienda (el rol `cash` de
     *    su plan): `{ code, name }`.
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

        $tesoreria = new TreasuryAccounts($this->connection);

        return Inertia::render('accounting/banking', [
            'movements' => $this->movimientos((int) $tienda->id),
            'bank_accounts' => $tesoreria->bancos((int) $tienda->id),
            'cash_account' => $this->cuentaDeEfectivo((int) $tienda->id),
            'currency' => $contexto->currency(),
            'business_date' => $contexto->businessDate()->format('Y-m-d'),
            'store' => $this->propsDeTienda($tienda),
        ]);
    }

    /**
     * El ALTA de un movimiento bancario.
     *
     * Responde 201 con el movimiento: `{ id, amount, business_date, from,
     * to }`.
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
            $asientoId = $this->movements->move(
                storeId: (int) $tienda->id,
                amount: PosAmount::declare($datos['amount'], 'importe del movimiento'),
                businessDate: $negocio,
                fromType: (string) $datos['from_type'],
                fromBankAccountId: $this->cuentaBancaria($datos['from_bank_account_id'] ?? null),
                toType: (string) $datos['to_type'],
                toBankAccountId: $this->cuentaBancaria($datos['to_bank_account_id'] ?? null),
                description: $this->texto($datos['description'] ?? null),
                reference: $this->texto($datos['reference'] ?? null),
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $fallo) {
            return $this->fallo($fallo);
        }

        $asiento = $this->connection->table('ledger_entries')->where('id', $asientoId)->first();

        $this->auditar(
            $request,
            'bank_movement.created',
            'bank_movement',
            $asientoId,
            [
                'amount' => PosAmount::text($asiento->amount),
                'business_date' => (string) $asiento->business_date,
                'from_type' => (string) $datos['from_type'],
                'to_type' => (string) $datos['to_type'],
                'from_bank_account_id' => $datos['from_bank_account_id'] ?? null,
                'to_bank_account_id' => $datos['to_bank_account_id'] ?? null,
                'description' => $datos['description'] ?? null,
            ],
            (int) $tienda->id
        );

        return response()->json([
            'id' => $asientoId,
            'amount' => PosAmount::text($asiento->amount),
            'business_date' => (string) $asiento->business_date,
        ], 201);
    }

    /**
     * Los MOVIMIENTOS de la tienda, los mas recientes primero.
     *
     * Son los asientos de tipo `bank_movement` cuyo hecho es un movimiento
     * bancario: los ingresos son movimientos de dinero ENTRANTES, pero tienen
     * su propia fuente de verdad (`incomes`) y su propia pantalla.
     *
     * @return list<array<string, mixed>>
     */
    private function movimientos(int $storeId): array
    {
        $filas = $this->connection->table('ledger_entries')
            ->join('ledger_entry_types', 'ledger_entry_types.id', '=', 'ledger_entries.entry_type_id')
            ->where('ledger_entries.store_id', $storeId)
            ->where('ledger_entry_types.code', 'bank_movement')
            ->where('ledger_entries.reference_type', 'bank_movement')
            ->orderByDesc('ledger_entries.business_date')
            ->orderByDesc('ledger_entries.id')
            ->limit(self::LIMITE)
            ->get([
                'ledger_entries.id as id',
                'ledger_entries.amount as amount',
                'ledger_entries.business_date as business_date',
                'ledger_entries.description as description',
                'ledger_entries.occurred_at as occurred_at',
            ]);

        $movimientos = [];

        foreach ($filas as $fila) {
            $lineas = $this->connection->table('ledger_lines')
                ->where('ledger_entry_id', (int) $fila->id)
                ->get(['account_code', 'debit', 'credit']);

            $debe = null;
            $haber = null;

            foreach ($lineas as $linea) {
                if (bccomp((string) $linea->debit, '0', 4) === 1) {
                    $debe = (string) $linea->account_code;
                }

                if (bccomp((string) $linea->credit, '0', 4) === 1) {
                    $haber = (string) $linea->account_code;
                }
            }

            $movimientos[] = [
                'id' => (int) $fila->id,
                'amount' => PosAmount::text($fila->amount),
                'business_date' => (string) $fila->business_date,
                'description' => $fila->description === null ? null : (string) $fila->description,
                'occurred_at' => Carbon::parse((string) $fila->occurred_at)->toIso8601String(),
                // DE DONDE sale y DONDE entra, con su cuenta contable.
                'from' => ['account_code' => $haber, 'name' => $this->nombreDeLaCuenta($haber)],
                'to' => ['account_code' => $debe, 'name' => $this->nombreDeLaCuenta($debe)],
            ];
        }

        return $movimientos;
    }

    /**
     * El NOMBRE de una cuenta contable, si el catalogo la conoce.
     */
    private function nombreDeLaCuenta(?string $codigo): ?string
    {
        if ($codigo === null || trim($codigo) === '') {
            return null;
        }

        $nombre = $this->connection->table('accounts')->where('code', $codigo)->value('name');

        return $nombre === null ? null : (string) $nombre;
    }

    /**
     * La cuenta de EFECTIVO de la tienda: la del rol `cash` de su plan.
     *
     * @return array{code: string, name: string|null}
     */
    private function cuentaDeEfectivo(int $storeId): array
    {
        $codigo = (new TreasuryAccounts($this->connection))->efectivo($storeId);

        $nombre = $this->connection->table('accounts')->where('code', $codigo)->value('name');

        return [
            'code' => $codigo,
            'name' => $nombre === null ? null : (string) $nombre,
        ];
    }

    /**
     * La cuenta bancaria de un lado del movimiento.
     */
    private function cuentaBancaria(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return (int) $valor;
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
            'from_type' => ['required', 'string', 'in:cash,bank'],
            'to_type' => ['required', 'string', 'in:cash,bank'],
            'from_bank_account_id' => ['nullable', 'integer'],
            'to_bank_account_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }
}
