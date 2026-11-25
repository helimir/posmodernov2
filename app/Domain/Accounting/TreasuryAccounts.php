<?php

namespace App\Domain\Accounting;

use App\Domain\Accounting\Enums\AccountRole;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * La TESORERIA de una tienda: las cuentas por donde entra y sale el dinero.
 *
 * REGLA que fija esta clase: *el dinero de la tienda vive en dos sitios, la
 * CAJA y las CUENTAS BANCARIAS, y cada uno tiene su cuenta contable*. La caja
 * es el rol `cash` del plan de cuentas de la tienda (su cuenta la declara el
 * negocio, como todos los roles); la cuenta bancaria es la que tiene el
 * vinculo por tienda (`bank_account_stores.account_code`), porque el saldo de
 * una cuenta bancaria es POR CUENTA Y TIENDA.
 *
 * Y LA REGLA DE LA CAJA: *un gasto que se paga en efectivo mueve el saldo en
 * curso de la caja del dia, y uno que se paga desde el banco no*. No es una
 * convencion: sale de donde se acredita el asiento. La cuenta de la caja es la
 * que `CashDay` lee para derivar el saldo del dia, asi que un asiento que
 * acredita la cuenta del banco NO aparece en el saldo del cajon: no hay que
 * recordar la regla, la cumple la cuenta.
 *
 * Y NINGUNA CUENTA SE INVENTA: si la tienda no tiene cuenta de caja, o la
 * cuenta bancaria no tiene cuenta contable en esa tienda, la operacion falla
 * con un mensaje util. Un gasto sin cuenta donde asentar no es un gasto.
 */
final readonly class TreasuryAccounts
{
    /**
     * El TIPO de pago: el efectivo de la caja.
     */
    public const PAGO_CAJA = 'cash';

    /**
     * El TIPO de pago: una cuenta bancaria.
     */
    public const PAGO_BANCO = 'bank';

    public function __construct(private Connection $connection) {}

    /**
     * La cuenta de EFECTIVO de la tienda: la del rol `cash` de su plan.
     *
     * @throws RuntimeException Cuando la tienda no tiene plan de cuentas.
     */
    public function efectivo(int $storeId): string
    {
        $roles = (new ChartOfAccounts($this->connection))->roles($storeId);

        $codigo = trim((string) ($roles[AccountRole::Cash->value] ?? ''));

        if ($codigo === '') {
            throw new RuntimeException(
                "La tienda {$storeId} no tiene cuenta de efectivo en su plan de cuentas: "
                .'una caja sin cuenta donde asentar no es una caja.'
            );
        }

        return $codigo;
    }

    /**
     * La cuenta contable de una CUENTA BANCARIA en una tienda.
     *
     * @throws RuntimeException Cuando la cuenta no es de esa tienda, cuando
     *                          esta apagada, o cuando no tiene cuenta
     *                          contable.
     */
    public function banco(int $storeId, int $bankAccountId): string
    {
        $vinculo = $this->connection->table('bank_account_stores')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'bank_account_stores.bank_account_id')
            ->where('bank_account_stores.store_id', $storeId)
            ->where('bank_account_stores.bank_account_id', $bankAccountId)
            ->first([
                'bank_account_stores.account_code as account_code',
                'bank_account_stores.status as status',
                'bank_accounts.name as name',
                'bank_accounts.is_active as is_active',
            ]);

        if ($vinculo === null) {
            throw new RuntimeException(
                "La cuenta bancaria {$bankAccountId} no es de la tienda {$storeId}."
            );
        }

        if ((string) $vinculo->status !== 'active' || ! (bool) $vinculo->is_active) {
            throw new RuntimeException(
                "La cuenta bancaria '{$vinculo->name}' esta apagada en la tienda {$storeId}: "
                .'una cuenta apagada no admite movimientos nuevos.'
            );
        }

        $codigo = trim((string) ($vinculo->account_code ?? ''));

        if ($codigo === '') {
            throw new RuntimeException(
                "La cuenta bancaria '{$vinculo->name}' no tiene cuenta contable en la tienda {$storeId}: "
                .'sin ella el movimiento no tiene donde asentar.'
            );
        }

        return $codigo;
    }

    /**
     * Las CUENTAS BANCARIAS de una tienda, con su cuenta contable.
     *
     * Son las que la pantalla ofrece: una cuenta bancaria SIN cuenta contable
     * no puede recibir ni ceder dinero en el libro, asi que no se ofrece. Y la
     * CAJA no esta en esta lista: la caja es el rol `cash` del plan, no una
     * cuenta bancaria mas.
     *
     * @return list<array{id: int, name: string, account_code: string}>
     */
    public function bancos(int $storeId): array
    {
        $filas = $this->connection->table('bank_account_stores')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'bank_account_stores.bank_account_id')
            ->where('bank_account_stores.store_id', $storeId)
            ->where('bank_account_stores.status', 'active')
            ->where('bank_accounts.is_active', true)
            ->whereNotNull('bank_account_stores.account_code')
            ->orderBy('bank_accounts.name')
            ->get([
                'bank_accounts.id as id',
                'bank_accounts.name as name',
                'bank_account_stores.account_code as account_code',
            ]);

        $bancos = [];

        foreach ($filas as $fila) {
            $bancos[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                'account_code' => (string) $fila->account_code,
            ];
        }

        return $bancos;
    }

    /**
     * La cuenta que FINANCLA un movimiento: la caja o la cuenta bancaria.
     *
     * REGLA que fija este metodo: *el tipo de pago decide la cuenta*, y el
     * tipo de pago es un DATO del movimiento, no una conjetura del sistema.
     *
     * @param  string  $paymentType  `cash` o `bank`.
     *
     * @throws RuntimeException Cuando el tipo de pago no es uno de los dos, o
     *                          cuando el pago es desde el banco y no se dice
     *                          cual.
     */
    public function financia(int $storeId, string $paymentType, ?int $bankAccountId): string
    {
        if ($paymentType === self::PAGO_CAJA) {
            if ($bankAccountId !== null) {
                throw new RuntimeException(
                    'Un pago en efectivo no viene de una cuenta bancaria: '
                    .'el dinero que se paga en efectivo sale de la caja.'
                );
            }

            return $this->efectivo($storeId);
        }

        if ($paymentType === self::PAGO_BANCO) {
            if ($bankAccountId === null) {
                throw new RuntimeException(
                    'Un pago desde el banco dice QUE cuenta bancaria lo paga: '
                    .'un pago sin cuenta de origen no tiene donde descontarse.'
                );
            }

            return $this->banco($storeId, $bankAccountId);
        }

        throw new RuntimeException(
            "El tipo de pago '{$paymentType}' no existe: un pago sale de la caja o de una cuenta bancaria."
        );
    }
}
