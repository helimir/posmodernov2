<?php

namespace App\Domain\Accounting;

use App\Domain\Accounting\Enums\AccountRole;
use App\Domain\Accounting\Enums\AccountType;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El PLAN DE CUENTAS de una tienda: que cuenta cumple cada rol.
 *
 * REGLA que fija esta clase: *el plan de cuentas de una tienda se lee de sus
 * vinculos, y si le falta un rol, el cobro falla con un mensaje util*. NO hay
 * un plan por defecto: un plan incompleto es un plan que hay que ver.
 *
 * POR QUE el plan es DATO y no configuracion: el negocio decide que cuenta
 * es su caja, cual su cartera y cual su ingreso, y esa decision cambia con el
 * tiempo y es distinta en cada tienda. En la configuracion no se puede
 * cambiar sin desplegar, y no puede haber un plan por tienda.
 *
 * Y LA INVARIANTE que fija la asignacion: *el tipo de la cuenta debe ser el
 * que el rol exige*. Si a la cuenta de ingresos se le asigna el rol de la
 * caja, el balance no cuadra y nadie se entera hasta que cuadra mal. La
 * invariante se comprueba AL ASIGNAR, no despues, y el error dice que rol,
 * que cuenta y que tipo esperaba.
 *
 * LAS DOS REGLAS DEL VINCULO:
 *
 *  - UN ROL POR TIENDA: si el rol ya lo cumple otra cuenta, la asignacion se
 *    rechaza con un mensaje que dice quien lo cumple. Quitarle el rol a otra
 *    cuenta sin querer es una sorpresa destructiva, y el que la quiere
 *    quitar lo hace con la operacion de quitar;
 *  - UN ROL POR CUENTA: la cuenta que ya cumple un rol lo cambia por el
 *    nuevo, en vez de acumular roles.
 *
 * Y LAS REGLAS del CATALOGO (el alta y la baja de una cuenta):
 *
 *  - EL CODIGO ES LA CLAVE NATURAL: es UNICO en todo el catalogo, y se
 *    NORMALIZA (sin espacios en blanco alrededor, en mayusculas). Un codigo
 *    contable con espacios es un codigo que se teclea mal;
 *  - UNA CUENTA CON HISTORIA NO SE BORRA: se APAGA. Solo se borra la cuenta
 *    que NUNCA se ha usado: la que no tiene movimientos contables, no
 *    cumple ningun rol y no tiene plan en ninguna tienda;
 *  - UNA CUENTA APAGADA NO CUMPLE NINGUN ROL: SALE DEL PLAN. Apagarla quita
 *    sus vinculos, y encenderla NO le devuelve el rol: el rol se vuelve a
 *    asignar si se quiere. Y el plan NUNCA se contradice: no puede decir a
 *    la vez que le falta un rol y que ya lo cumple una cuenta apagada.
 */
final readonly class ChartOfAccounts
{
    /**
     * El codigo de la cuenta es su clave natural, y la columna admite treinta
     * caracteres: es el tope que dice el ESQUEMA, no una medida inventada.
     */
    private const CODIGO_MAXIMO = 30;

    /**
     * El nombre de la cuenta, con el mismo tope que su columna.
     */
    private const NOMBRE_MAXIMO = 120;

    public function __construct(private Connection $connection) {}

    /**
     * El PLAN de la tienda: rol => codigo de cuenta.
     *
     * REGLA que fija este metodo: *el plan sale de los vinculos ACTIVOS de la
     * tienda, y si le falta un rol, este metodo FALLA*. No hay un plan por
     * defecto y no hay un plan de otra tienda: el plan de una tienda es suyo.
     *
     * @return array<string, string>
     *
     * @throws RuntimeException Cuando al plan de la tienda le falta un rol.
     */
    public function plan(int $storeId): array
    {
        $roles = $this->roles($storeId);

        $faltan = $this->missingRoles($storeId);

        if ($faltan !== []) {
            throw new RuntimeException($this->mensajeIncompleto($storeId, $faltan, $roles));
        }

        return $roles;
    }

    /**
     * Los ROLES que la tienda tiene, con el codigo de su cuenta.
     *
     * A diferencia de `plan()`, aqui NO se exige que esten todos: el hueco es
     * informacion, y la pantalla la enseña.
     *
     * CUENTAN los vinculos ACTIVOS de la tienda y las cuentas ACTIVAS del
     * catalogo: una cuenta apagada no admite movimientos nuevos, asi que no
     * cumple ningun rol mientras lo este.
     *
     * @return array<string, string>
     */
    public function roles(int $storeId): array
    {
        $filas = $this->connection->table('store_accounts')
            ->join('accounts', 'accounts.id', '=', 'store_accounts.account_id')
            ->where('store_accounts.store_id', $storeId)
            ->where('store_accounts.status', 'active')
            ->where('accounts.is_active', true)
            ->orderBy('store_accounts.id')
            ->get([
                'store_accounts.role as role',
                'accounts.code as code',
            ]);

        $roles = [];

        foreach ($filas as $fila) {
            $roles[(string) $fila->role] = (string) $fila->code;
        }

        return $roles;
    }

    /**
     * Los ROLES que le faltan al plan de la tienda.
     *
     * @return list<string>
     */
    public function missingRoles(int $storeId): array
    {
        $presentes = array_keys($this->roles($storeId));

        $faltan = [];

        foreach (AccountRole::cases() as $rol) {
            if (! in_array($rol->value, $presentes, true)) {
                $faltan[] = $rol->value;
            }
        }

        return $faltan;
    }

    /**
     * El ROL que cumple una cuenta en la tienda, o `null` si no cumple
     * ninguno.
     */
    public function roleOf(int $storeId, int $accountId): ?string
    {
        $rol = $this->connection->table('store_accounts')
            ->join('accounts', 'accounts.id', '=', 'store_accounts.account_id')
            ->where('store_accounts.store_id', $storeId)
            ->where('store_accounts.account_id', $accountId)
            ->where('store_accounts.status', 'active')
            ->where('accounts.is_active', true)
            ->value('store_accounts.role');

        return $rol === null ? null : (string) $rol;
    }

    /**
     * ASIGNA un rol a una cuenta del plan de la tienda.
     *
     * REGLA que fija este metodo: *el tipo de la cuenta debe ser el que el
     * rol exige*, y la comprobacion es AL ASIGNAR.
     *
     * Y LA REGLA DE "un rol por tienda" se PREGUNTA COMO `roles()`: una
     * cuenta APAGADA no cumple ningun rol, asi que su vinculo no ocupa el
     * rol y no bloquea a nadie. Si aparece uno, es un RESIDUO y se LIBERA:
     * el dominio se repara solo de los datos que quedaron de antes, en vez
     * de quedarse bloqueado.
     *
     * @throws RuntimeException Cuando la cuenta no existe, cuando su tipo no
     *                          es el que el rol exige, o cuando el rol ya lo
     *                          cumple otra cuenta ACTIVA de la tienda.
     */
    public function assign(int $storeId, int $accountId, AccountRole $role): void
    {
        $cuenta = $this->connection->table('accounts')->where('id', $accountId)->first();

        if ($cuenta === null) {
            throw new RuntimeException(
                "La cuenta {$accountId} no existe en el catalogo de cuentas."
            );
        }

        // Una cuenta APAGADA no admite movimientos nuevos: no puede cumplir
        // un rol.
        if (! (bool) $cuenta->is_active) {
            throw new RuntimeException(
                "La cuenta {$cuenta->code} esta apagada: una cuenta apagada no puede cumplir un rol."
            );
        }

        // LA INVARIANTE: el tipo de la cuenta es el que el rol exige.
        $tipo = (string) $cuenta->type;
        $esperado = $role->requiredType()->value;

        if ($tipo !== $esperado) {
            throw new RuntimeException(
                "El rol '{$role->value}' exige una cuenta de tipo '{$esperado}' y la cuenta "
                ."{$cuenta->code} es de tipo '{$tipo}': no se puede asignar."
            );
        }

        // UN ROL POR TIENDA: si lo cumple otra cuenta, no se puede.
        //
        // Y SE PREGUNTA COMO `roles()`: una cuenta APAGADA no cumple ningun
        // rol, asi que su vinculo no cuenta como ocupante del rol. Y si lo
        // encuentra, no bloquea: lo LIBERA. El vinculo de una cuenta apagada
        // es un RESIDUO (de unos datos viejos o de una migracion a mano), y
        // el dominio se repara solo en vez de quedarse bloqueado.
        $otro = $this->connection->table('store_accounts')
            ->join('accounts', 'accounts.id', '=', 'store_accounts.account_id')
            ->where('store_accounts.store_id', $storeId)
            ->where('store_accounts.role', $role->value)
            ->where('store_accounts.account_id', '!=', $accountId)
            ->first([
                'store_accounts.id as id',
                'store_accounts.account_id as account_id',
                'accounts.code as code',
                'accounts.is_active as is_active',
            ]);

        if ($otro !== null) {
            if (! (bool) $otro->is_active) {
                $this->connection->table('store_accounts')
                    ->where('store_id', $storeId)
                    ->where('account_id', $otro->account_id)
                    ->delete();
            } else {
                throw new RuntimeException(
                    "El rol '{$role->value}' ya lo cumple la cuenta {$otro->code} en la tienda {$storeId}: "
                    .'un rol tiene una sola cuenta por tienda.'
                );
            }
        }

        // UN ROL POR CUENTA: el suyo se cambia, no se acumula.
        $this->connection->table('store_accounts')
            ->where('store_id', $storeId)
            ->where('account_id', $accountId)
            ->delete();

        $this->connection->table('store_accounts')->insert([
            'account_id' => $accountId,
            'store_id' => $storeId,
            'role' => $role->value,
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * QUITA el rol de una cuenta del plan de la tienda.
     *
     * Quitar un rol es una decision explicita del negocio: si el plan se
     * queda sin el, la pantalla lo AVISA antes de que el cobro falle.
     */
    public function detach(int $storeId, int $accountId): void
    {
        $this->connection->table('store_accounts')
            ->where('store_id', $storeId)
            ->where('account_id', $accountId)
            ->delete();
    }

    /**
     * El ALTA de una cuenta del catalogo.
     *
     * REGLA que fija este metodo: *el codigo de una cuenta es su clave
     * natural y es UNICO en todo el catalogo*.
     *
     * El codigo se NORMALIZA (sin espacios en blanco alrededor, en
     * mayusculas): un codigo contable con espacios es un codigo que se
     * teclea mal, y dos cuentas tecleadas distinto son la MISMA cuenta con
     * dos nombres. El nombre se recorta, y los dos no pueden estar vacios.
     *
     * La cuenta nace ACTIVA: una cuenta nueva cumple lo que se le asigne, y
     * apagarla es una decision posterior y explicita.
     *
     * @throws RuntimeException Cuando el codigo esta vacio, no cabe, el
     *                          nombre esta vacio, o el codigo ya existe.
     */
    public function create(string $code, string $name, AccountType $type): int
    {
        $codigo = mb_strtoupper(trim($code));
        $nombre = trim($name);

        if ($codigo === '') {
            throw new RuntimeException(
                'El código de la cuenta no puede estar vacío: es la clave natural de la cuenta.'
            );
        }

        if (mb_strlen($codigo) > self::CODIGO_MAXIMO) {
            throw new RuntimeException(
                'El código de la cuenta no puede tener más de '.self::CODIGO_MAXIMO
                .' caracteres: es lo que admite el catálogo.'
            );
        }

        if ($nombre === '') {
            throw new RuntimeException('El nombre de la cuenta no puede estar vacío.');
        }

        if (mb_strlen($nombre) > self::NOMBRE_MAXIMO) {
            throw new RuntimeException(
                'El nombre de la cuenta no puede tener más de '.self::NOMBRE_MAXIMO
                .' caracteres: es lo que admite el catálogo.'
            );
        }

        // La clave natural es UNICA: el error lo dice el DOMINIO, con sus
        // palabras, y no PostgreSQL con las suyas.
        $existe = $this->connection->table('accounts')
            ->where('code', $codigo)
            ->exists();

        if ($existe) {
            throw new RuntimeException(
                "Ya existe una cuenta con el código {$codigo}: el código es la clave natural de la cuenta."
            );
        }

        return (int) $this->connection->table('accounts')->insertGetId([
            'code' => $codigo,
            'name' => $nombre,
            'type' => $type->value,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * APAGA una cuenta del catalogo.
     *
     * REGLA que fija esta unidad: *una cuenta que tiene movimientos
     * contables NO se borra: se APAGA*. La apaga no la destruye: su
     * historia se queda, y deja de admitir movimientos nuevos.
     *
     * Y LA REGLA DE LA UNIDAD: *una cuenta APAGADA no cumple NINGUN rol: SALE
     * DEL PLAN*. Por eso apagarla quita ademas sus VINCULOS con las tiendas:
     * un vinculo que su cuenta no cumple es un residuo, y un residuo bloquea
     * el plan. El plan de la tienda puede quedarse incompleto, y eso hay que
     * verlo ANTES de que el cobro falle: por eso este metodo devuelve los
     * roles que la cuenta deja de cumplir.
     *
     * @return array<int, string> Los roles que la cuenta DEJA de cumplir: id
     *                            de tienda => rol.
     *
     * @throws RuntimeException Cuando la cuenta no existe.
     */
    public function deactivate(int $accountId): array
    {
        $this->cuenta($accountId);

        // Los roles que deja de cumplir se leen ANTES de apagarla: apagada,
        // ya no cumpliria ninguno y el aviso se perderia.
        $roles = $this->rolesByStore($accountId);

        // Las DOS escrituras van juntas: una cuenta apagada con su vinculo
        // puesto es el estado que hace que el plan se contradiga.
        $this->connection->transaction(function () use ($accountId): void {
            $this->connection->table('accounts')
                ->where('id', $accountId)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            // Y sale del PLAN: sus vinculos se quitan.
            $this->connection->table('store_accounts')
                ->where('account_id', $accountId)
                ->delete();
        });

        return $roles;
    }

    /**
     * ENCIENDE una cuenta del catalogo.
     *
     * Es lo inverso de apagarla en el ESTADO, y NO en el plan: *encender NO
     * devuelve el rol*. La cuenta salio del plan cuando se apago, y volver a
     * cumplir un rol es una ASIGNACION nueva, una decision del negocio.
     *
     * @return array<int, string> Siempre vacio: encender no devuelve ningun
     *                            rol.
     *
     * @throws RuntimeException Cuando la cuenta no existe.
     */
    public function activate(int $accountId): array
    {
        $this->cuenta($accountId);

        $this->connection->table('accounts')
            ->where('id', $accountId)
            ->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);

        return [];
    }

    /**
     * BORRA una cuenta del catalogo, si nunca se ha usado.
     *
     * REGLA que fija este metodo: *una cuenta se borra SOLO si no se ha
     * usado nunca; si tiene historia, se apaga*.
     *
     * Se mira TRES cosas, y cada una tiene su mensaje:
     *
     *  - los MOVIMIENTOS contables: `ledger_lines.account_code` es un TEXTO
     *    y no una clave foranea, asi que la cuenta se busca por su CODIGO, y
     *    el historico manda: una cuenta que tiene movimientos no se borra,
     *    se apaga;
     *  - el ROL que cumple: una cuenta que cumple un rol no se borra, porque
     *    el plan de la tienda depende de ella, y hay que quitarle el rol
     *    primero;
     *  - los VINCULOS: aunque la cuenta no cumpla un rol activo, su vinculo
     *    es el plan de la tienda, y tambien hay que quitarlo.
     *
     * @throws RuntimeException Cuando la cuenta no existe, cuando tiene
     *                          movimientos contables, cuando cumple un rol o
     *                          cuando tiene un plan en alguna tienda.
     */
    public function delete(int $accountId): void
    {
        $cuenta = $this->cuenta($accountId);

        $codigo = (string) $cuenta->code;

        // 1. El HISTORICO manda: la cuenta tiene movimientos contables.
        if ($this->tieneMovimientos($codigo)) {
            throw new RuntimeException(
                "La cuenta {$codigo} tiene movimientos contables: no se puede eliminar, solo se puede desactivar."
            );
        }

        $vinculos = $this->connection->table('store_accounts')
            ->where('account_id', $accountId)
            ->orderBy('id')
            ->get(['store_id', 'role', 'status']);

        // 2. El ROL que cumple: el vinculo activo de una cuenta activa.
        $cumple = null;
        $tiene = null;

        foreach ($vinculos as $vinculo) {
            $tiene ??= $vinculo;

            if ($vinculo->status === 'active' && (bool) $cuenta->is_active) {
                $cumple ??= $vinculo;
            }
        }

        if ($cumple !== null) {
            throw new RuntimeException(
                "La cuenta {$codigo} cumple el rol '{$cumple->role}' en la tienda {$cumple->store_id}: "
                .'quítale el rol antes de eliminarla.'
            );
        }

        // 3. Los VINCULOS: aunque no cumpla un rol, tiene un plan.
        if ($tiene !== null) {
            throw new RuntimeException(
                "La cuenta {$codigo} tiene un plan en la tienda {$tiene->store_id}: "
                .'quítale el rol antes de eliminarla.'
            );
        }

        $this->connection->table('accounts')
            ->where('id', $accountId)
            ->delete();
    }

    /**
     * Los ROLES que una cuenta cumple en las tiendas, con el id de la tienda.
     *
     * CUENTAN los vinculos activos y la cuenta ACTIVA: una cuenta apagada no
     * cumple ningun rol mientras lo este.
     *
     * @return array<int, string> Id de tienda => rol.
     */
    public function rolesByStore(int $accountId): array
    {
        $filas = $this->connection->table('store_accounts')
            ->join('accounts', 'accounts.id', '=', 'store_accounts.account_id')
            ->where('store_accounts.account_id', $accountId)
            ->where('store_accounts.status', 'active')
            ->where('accounts.is_active', true)
            ->orderBy('store_accounts.id')
            ->get([
                'store_accounts.store_id as store_id',
                'store_accounts.role as role',
            ]);

        $roles = [];

        foreach ($filas as $fila) {
            $roles[(int) $fila->store_id] = (string) $fila->role;
        }

        return $roles;
    }

    /**
     * La CUENTA del catalogo, o un fallo si no existe.
     *
     * @throws RuntimeException Cuando la cuenta no existe.
     */
    private function cuenta(int $accountId): object
    {
        $cuenta = $this->connection->table('accounts')
            ->where('id', $accountId)
            ->first();

        if ($cuenta === null) {
            throw new RuntimeException(
                "La cuenta {$accountId} no existe en el catálogo de cuentas."
            );
        }

        return $cuenta;
    }

    /**
     * Si la cuenta TIENE MOVIMIENTOS contables.
     *
     * `ledger_lines.account_code` es un TEXTO y no una clave foranea: la
     * cuenta se busca por su codigo, y el historico manda.
     */
    private function tieneMovimientos(string $codigo): bool
    {
        return $this->connection->table('ledger_lines')
            ->where('account_code', $codigo)
            ->exists();
    }

    /**
     * El MENSAJE de un plan incompleto: que tienda, que rol falta y que roles
     * tiene.
     *
     * @param  list<string>  $faltan
     * @param  array<string, string>  $roles
     */
    private function mensajeIncompleto(int $storeId, array $faltan, array $roles): string
    {
        $losQueFaltan = count($faltan) === 1
            ? "le falta el rol '{$faltan[0]}'"
            : 'le faltan los roles '.implode(', ', array_map(
                static fn (string $rol): string => "'{$rol}'",
                $faltan
            ));

        $losQueTiene = $roles === []
            ? 'no cumple ninguno'
            : 'cumple: '.implode(', ', array_keys($roles));

        return "La tienda {$storeId} no tiene un plan de cuentas completo: {$losQueFaltan} ({$losQueTiene}). "
            .'Sin esas cuentas no se puede cobrar.';
    }
}
