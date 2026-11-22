<?php

namespace App\Domain\Accounting\Enums;

/**
 * El ROL que una cuenta cumple en el plan de una tienda.
 *
 * Los cinco roles son los que el sistema necesita para operar: la caja, la
 * cartera de clientes, los ingresos, el impuesto por pagar y el capital. Cada
 * uno exige un TIPO de cuenta, y esa exigencia es la INVARIANTE del plan: si
 * a la cuenta de ingresos se le asigna el rol de la caja, el balance no
 * cuadra y nadie se entera hasta que cuadra mal.
 *
 * EL ROL DEL CAPITAL ('equity') es la CONTRAPARTIDA del saldo de partida: al
 * abrir la caja, el dinero que se cuenta a mano se carga a la cuenta de
 * efectivo y se abona a la de capital. Sin el, el saldo de partida no tiene
 * donde abonarse, y una caja sin cuenta donde asentar no es una caja.
 *
 * Y EL ROL DEL CAPITAL ES OPCIONAL: el plan COMPLETO son CUATRO roles (los de
 * `obligatorios()`), y el de capital solo hace falta cuando la caja se abre
 * con una PARTIDA DISTINTA DE CERO, porque ahi es donde el asiento de apertura
 * necesita la contrapartida. Una partida CERO no se asienta (el libro no admite
 * lineas a cero), asi que sin partida no hace falta capital. El rol sigue
 * existiendo y siendo asignable: es un rol VALIDO, no un rol prohibido.
 *
 * Los valores son los del enum de Postgres `account_role`, y no se anade
 * ninguno mas sin justificarlo.
 */
enum AccountRole: string
{
    case Cash = 'cash';
    case Receivable = 'receivable';
    case Revenue = 'revenue';
    case TaxPayable = 'tax_payable';
    case Equity = 'equity';

    /**
     * El TIPO de cuenta que el rol exige.
     *
     * REGLA que fija este metodo: *el rol exige su tipo*. La caja y la
     * cartera son ACTIVO (son lo que la tienda tiene), el impuesto por pagar
     * es PASIVO (es lo que la tienda debe), los ingresos son INGRESO (es lo
     * que la tienda gana) y el capital es PATRIMONIO (es lo que la tienda
     * tiene de suyos).
     */
    public function requiredType(): AccountType
    {
        return match ($this) {
            self::Cash, self::Receivable => AccountType::Asset,
            self::TaxPayable => AccountType::Liability,
            self::Revenue => AccountType::Income,
            self::Equity => AccountType::Equity,
        };
    }

    /**
     * Los ROLES OBLIGATORIOS del plan COMPLETO: los cuatro que el cobro
     * exige.
     *
     * REGLA que fija este metodo: *el plan completo son CUATRO roles, y el del
     * capital es OPCIONAL*. La caja, la cartera, los ingresos y el impuesto
     * por pagar los necesita toda tienda que cobre. El capital solo hace falta
     * cuando la caja se abre con una partida distinta de cero, porque ahi es
     * donde el asiento de apertura necesita la contrapartida: una partida CERO
     * no se asienta, y sin asiento no hace falta contrapartida.
     *
     * Y POR QUE NO ES EL ROL DEL CAPITAL EL QUE SE QUITA DEL CASO: el rol
     * `equity` sigue siendo un rol VALIDO y ASIGNABLE (una cuenta de capital
     * mas en el plan), lo que cambia es que su ausencia ya no impide cobrar.
     *
     * @return list<self>
     */
    public static function obligatorios(): array
    {
        return [self::Cash, self::Receivable, self::Revenue, self::TaxPayable];
    }

    /**
     * ¿Este rol es OBLIGATORIO del plan completo?
     */
    public function esObligatorio(): bool
    {
        return in_array($this, self::obligatorios(), true);
    }
}
