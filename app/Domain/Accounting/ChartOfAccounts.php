<?php

namespace App\Domain\Accounting;

use App\Domain\Accounting\Enums\AccountRole;
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
 */
final readonly class ChartOfAccounts
{
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
     * @throws RuntimeException Cuando la cuenta no existe, cuando su tipo no
     *                          es el que el rol exige, o cuando el rol ya lo
     *                          cumple otra cuenta de la tienda.
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
        $otro = $this->connection->table('store_accounts')
            ->join('accounts', 'accounts.id', '=', 'store_accounts.account_id')
            ->where('store_accounts.store_id', $storeId)
            ->where('store_accounts.role', $role->value)
            ->where('store_accounts.account_id', '!=', $accountId)
            ->first();

        if ($otro !== null) {
            throw new RuntimeException(
                "El rol '{$role->value}' ya lo cumple la cuenta {$otro->code} en la tienda {$storeId}: "
                .'un rol tiene una sola cuenta por tienda.'
            );
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
