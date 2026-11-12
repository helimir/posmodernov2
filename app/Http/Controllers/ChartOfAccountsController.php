<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use App\Models\Account;
use App\Models\Store;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
 *  - EL CATALOGO SE MANTIENE DESDE AQUI: la cuenta entra con su codigo, su
 *    nombre y su tipo, y sale con su motivo. Una cuenta que tiene historia
 *    NO se borra: se apaga, y la pantalla avisa cuando el plan se queda
 *    incompleto.
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
     *  - `types`: los CINCO tipos de cuenta, con `{ value, label }`: el
     *    conjunto cerrado del dominio, que es lo que el ALTA ofrece.
     *  - `missing_roles`: los roles que le faltan al plan de la tienda.
     *  - `store`: `{ id, name, code_name }`, la tienda activa.
     */
    public function index(Request $request): Response
    {
        $escenario = $this->escenario($request);

        return Inertia::render('accounting/accounts', [
            'accounts' => $this->cuentas((int) $escenario['store']->id),
            'roles' => $this->roles(),
            'types' => $this->types(),
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
     * El ALTA de una cuenta del catalogo.
     *
     * El DOMINIO decide: el codigo es la clave natural y es UNICO, y se
     * NORMALIZA. Aqui solo se traduce su fallo al contrato de la API: un 422
     * con `{"errorMsg": "..."}`.
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->all();

        $error = $this->validarAlta($datos);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $id = $this->chart->create(
                (string) ($datos['code'] ?? ''),
                (string) ($datos['name'] ?? ''),
                AccountType::from((string) $datos['type']),
            );
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $cuenta = Account::query()->findOrFail($id);

        return response()->json([
            'id' => (int) $cuenta->id,
            'code' => (string) $cuenta->code,
            'name' => (string) $cuenta->name,
            'type' => (string) $cuenta->type,
            'is_active' => (bool) $cuenta->is_active,
        ], 201);
    }

    /**
     * APAGA una cuenta del catalogo.
     *
     * La cuenta deja de cumplir ningun rol, pero sus vinculos NO se tocan: el
     * rol sigue declarado y el plan de la tienda puede quedarse incompleto.
     * Por eso la respuesta AVISA de ese plan: el hueco se ve antes de que el
     * cobro falle.
     */
    public function deactivate(Request $request, Account $account): JsonResponse
    {
        try {
            $store = $this->context($request)->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        try {
            $dejados = $this->chart->deactivate((int) $account->id);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        return $this->respuestaDelEstado($account, $store, $dejados, true);
    }

    /**
     * ENCIENDE una cuenta del catalogo: vuelve a cumplir su rol, y el plan de
     * la tienda se completa.
     */
    public function activate(Request $request, Account $account): JsonResponse
    {
        try {
            $store = $this->context($request)->store();
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        try {
            $cumplidos = $this->chart->activate((int) $account->id);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        return $this->respuestaDelEstado($account, $store, $cumplidos, false);
    }

    /**
     * BORRA una cuenta del catalogo, si nunca se ha usado.
     *
     * Una cuenta con movimientos contables, con un rol o con un plan en
     * alguna tienda NO se borra: se apaga. Y el rechazo dice QUE hacer.
     */
    public function destroy(Request $request, Account $account): JsonResponse
    {
        try {
            $this->chart->delete((int) $account->id);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        return response()->json([
            'account_id' => (int) $account->id,
            'code' => (string) $account->code,
            'deleted' => true,
        ]);
    }

    /**
     * La RESPUESTA del estado de una cuenta: si esta activa, los roles de la
     * tienda activa, los que le faltan y los AVISOS.
     *
     * @param  array<int, string>  $roles  Id de tienda => rol.
     */
    private function respuestaDelEstado(Account $account, Store $store, array $roles, bool $apagada): JsonResponse
    {
        // La cuenta viene de antes del cambio: se recarga para que el estado
        // que se responde sea el de AHORA, no el que se Recordaba.
        $account->refresh();

        return response()->json([
            'account_id' => (int) $account->id,
            'code' => (string) $account->code,
            'is_active' => (bool) $account->is_active,
            'roles' => $this->chart->roles((int) $store->id),
            'missing_roles' => $this->chart->missingRoles((int) $store->id),
            'warnings' => $this->avisos((string) $account->code, $roles, $apagada),
        ]);
    }

    /**
     * Los AVISOS de un apagado o un encendido: que plan puede haber quedado
     * incompleto, y en que tienda.
     *
     * El texto es de PANTALLA, y por eso esta en ingles: lo que el dominio
     * decide es QUE roles cambian, y como se dice es de la costura.
     *
     * @param  array<int, string>  $roles  Id de tienda => rol.
     * @return list<string>
     */
    private function avisos(string $codigo, array $roles, bool $apagada): array
    {
        $avisos = [];

        foreach ($roles as $storeId => $rol) {
            $tienda = Store::query()->find($storeId);

            $nombre = $tienda === null
                ? "the store {$storeId}"
                : "the store {$tienda->name}";

            $etiqueta = $this->etiquetaDelRol($rol);

            $avisos[] = $apagada
                ? "The account {$codigo} no longer fulfils the role '{$etiqueta}' in {$nombre}. "
                    .'The plan of that store may now be incomplete: the till cannot charge without every role. '
                    .'The role stays declared until the account is removed from the plan.'
                : "The account {$codigo} fulfils the role '{$etiqueta}' in {$nombre} again.";
        }

        return $avisos;
    }

    /**
     * La ETIQUETA de un rol: la que ve la persona.
     */
    private function etiquetaDelRol(string $rol): string
    {
        foreach ($this->roles() as $opcion) {
            if ($opcion['value'] === $rol) {
                return $opcion['label'];
            }
        }

        return $rol;
    }

    /**
     * VALIDA la entrada del ALTA.
     *
     * REGLA que fija este metodo: *la FORMA la comprueba la costura y el
     * CONTENIDO el dominio*. El codigo y el nombre solo tienen que ser
     * cadenas: si estan vacios, o son demasiado largos, o el codigo ya
     * existe, lo dice `ChartOfAccounts` con su mensaje, que es el que la
     * persona necesita.
     *
     * @param  array<string, mixed>  $datos
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function validarAlta(array $datos): ?string
    {
        $reglas = [
            'code' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
            'type' => ['required', Rule::enum(AccountType::class)],
        ];

        $validador = Validator::make($datos, $reglas);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
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
     * Los CINCO tipos de cuenta, con su etiqueta.
     *
     * El conjunto es CERRADO y lo define el dominio (`AccountType`): la
     * pantalla lo recibe y no escribe una lista de tipos a mano, porque una
     * lista escrita a mano es una lista que se queda vieja.
     *
     * @return list<array{value: string, label: string}>
     */
    private function types(): array
    {
        $etiquetas = [
            AccountType::Asset->value => 'Asset',
            AccountType::Liability->value => 'Liability',
            AccountType::Equity->value => 'Equity',
            AccountType::Income->value => 'Income',
            AccountType::Expense->value => 'Expense',
        ];

        $types = [];

        foreach (AccountType::cases() as $tipo) {
            $types[] = [
                'value' => $tipo->value,
                'label' => $etiquetas[$tipo->value],
            ];
        }

        return $types;
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
