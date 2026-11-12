<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Enums\AccountRole;
use App\Models\Account;
use App\Models\Store;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * El PLAN DE CUENTAS de la tienda: el catalogo de cuentas con el rol que
 * cada una cumple, y la edicion de ese rol.
 *
 * Esta clase es la COSTEZA entre el navegador y el plan. No decide nada de
 * negocio: la invariante (el tipo de la cuenta es el que el rol exige) la
 * cumple `ChartOfAccounts`, y aqui solo se traduce su fallo al contrato de
 * la API.
 *
 * REGLAS que fija esta clase:
 *
 *  - EL PLAN ES DE LA TIENDA ACTIVA: la pantalla enseña el plan de la
 *    tienda del contexto (`PosContext`), la misma que usa la caja. El plan de
 *    una tienda es suyo, y editarlo no toca el de las demas.
 *  - EL LISTADO es el CATALOGO COMPLETO: todas las cuentas, con su codigo,
 *    su nombre, su tipo y el rol que cumplen en esta tienda (o `null` si no
 *    cumplen ninguno). El catalogo es el de todas las tiendas; el rol es de
 *    esta.
 *  - EL HUECO SE VE: si al plan le falta un rol, la pantalla lo AVISA. El
 *    sistema no podra cobrar, y ese aviso es el hueco a la vista, no un error
 *    en el momento de cobrar.
 *  - LA EDICION RESPETA LA INVARIANTE: una asignacion cuyo tipo no sea el
 *    que el rol exige se rechaza con un 422 y su mensaje.
 *
 * LOS IMPORTES no viajan por aqui: un plan de cuentas no tiene importes.
 */
class ChartOfAccountsController extends Controller
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * La PANTALLA del plan de cuentas.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/accounting/accounts.tsx`:
     *
     *  - `accounts`: lista de `{ id, code, name, type, is_active, role }`. El
     *    `role` es el que la cuenta cumple en la tienda activa, o `null`.
     *  - `roles`: los cuatro roles, con `{ value, label, required_type }`: el
     *    tipo que cada rol exige.
     *  - `missing_roles`: los roles que le faltan al plan de la tienda.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     */
    public function index(Request $request): Response
    {
        $escenario = $this->escenario($request);

        return Inertia::render('accounting/accounts', [
            'accounts' => $this->cuentas((int) $escenario['store']->id),
            'roles' => $this->roles(),
            'missing_roles' => $this->chart->missingRoles((int) $escenario['store']->id),
            'store' => $this->storeProps($escenario['store']),
        ]);
    }

    /**
     * La EDICION del rol de una cuenta en la tienda activa.
     *
     * Un rol `null` QUITA el rol de la cuenta; un rol con nombre se lo
     * asigna. Y si la asignacion viola la invariante (el tipo de la cuenta no
     * es el que el rol exige), o el rol ya lo cumple otra cuenta, la
     * respuesta es un 422 con su mensaje y NO se escribe nada.
     */
    public function updateRole(Request $request, Account $account): JsonResponse
    {
        $datos = $request->validate([
            'role' => ['nullable', Rule::enum(AccountRole::class)],
        ]);

        try {
            $store = $this->context($request)->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $rol = $datos['role'] ?? null;

        try {
            if ($rol === null) {
                $this->chart->detach((int) $store->id, (int) $account->id);
            } else {
                $this->chart->assign((int) $store->id, (int) $account->id, AccountRole::from($rol));
            }
        } catch (RuntimeException $fallo) {
            // La invariante (o la unicidad del plan) se cumple en el DOMINIO:
            // aqui solo se traduce su fallo al contrato de la API.
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        return response()->json([
            'account_id' => (int) $account->id,
            'code' => (string) $account->code,
            'role' => $this->chart->roleOf((int) $store->id, (int) $account->id),
            'roles' => $this->chart->roles((int) $store->id),
            'missing_roles' => $this->chart->missingRoles((int) $store->id),
        ]);
    }

    /**
     * El ESCENARIO de la peticion: la tienda activa.
     *
     * @return array{context: PosContext, store: Store}
     */
    private function escenario(Request $request): array
    {
        $context = $this->context($request);

        try {
            $store = $context->store();
        } catch (RuntimeException $fallo) {
            // Sin tienda activa la pantalla NO abre: no se sirve el plan de una
            // tienda que no existe.
            abort(409, $fallo->getMessage());
        }

        return ['context' => $context, 'store' => $store];
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
     * El CATALOGO de cuentas, con el rol que cumple cada una en la tienda.
     *
     * @return list<array<string, mixed>>
     */
    private function cuentas(int $storeId): array
    {
        $roles = $this->chart->roles($storeId);

        $porCuenta = [];

        foreach ($roles as $rol => $codigo) {
            $porCuenta[$codigo] = $rol;
        }

        $filas = $this->connection->table('accounts')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'is_active']);

        $cuentas = [];

        foreach ($filas as $fila) {
            $codigo = (string) $fila->code;

            $cuentas[] = [
                'id' => (int) $fila->id,
                'code' => $codigo,
                'name' => (string) $fila->name,
                'type' => (string) $fila->type,
                'is_active' => (bool) $fila->is_active,
                'role' => $porCuenta[$codigo] ?? null,
            ];
        }

        return $cuentas;
    }

    /**
     * Los CUATRO roles, con el tipo que cada uno exige.
     *
     * @return list<array{value: string, label: string, required_type: string}>
     */
    private function roles(): array
    {
        $etiquetas = [
            AccountRole::Cash->value => 'Cash',
            AccountRole::Receivable->value => 'Receivable',
            AccountRole::Revenue->value => 'Revenue',
            AccountRole::TaxPayable->value => 'Tax payable',
        ];

        $roles = [];

        foreach (AccountRole::cases() as $rol) {
            $roles[] = [
                'value' => $rol->value,
                'label' => $etiquetas[$rol->value],
                'required_type' => $rol->requiredType()->value,
            ];
        }

        return $roles;
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
