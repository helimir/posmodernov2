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
}
