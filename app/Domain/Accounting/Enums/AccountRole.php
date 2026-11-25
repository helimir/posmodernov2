<?php

namespace App\Domain\Accounting\Enums;

/**
 * El ROL que una cuenta cumple en el plan de una tienda.
 *
 * Los seis roles son los que el sistema necesita para operar: la caja, la
 * cartera de clientes, los ingresos, el impuesto por pagar, el pasivo y el
 * capital. Cada uno exige un TIPO de cuenta, y esa exigencia es la
 * INVARIANTE del plan: si a la cuenta de ingresos se le asigna el rol de la
 * caja, el balance no cuadra y nadie se entera hasta que cuadra mal.
 *
 * EL ROL DEL CAPITAL ('equity') es la CONTRAPARTIDA del saldo de partida: al
 * abrir la caja, el dinero que se cuenta a mano se carga a la cuenta de
 * efectivo y se abona a la de capital. Sin el, el saldo de partida no tiene
 * donde abonarse, y una caja sin cuenta donde asentar no es una caja.
 *
 * EL ROL DEL PASIVO ('liability') es la CONTRAPARTIDA de lo que la tienda
 * debe: el credito a favor del cliente (la reversa de una venta anulada deja
 * al cliente con un saldo a favor, y ese saldo es una deuda de la tienda) y
 * las deudas a proveedores. Es UN SOLO rol para las dos cosas, porque las
 * cuentas concretas son DATOS: cada tienda decide en que cuenta de pasivo
 * asienta lo que debe, y el rol es solo la etiqueta que la invariante exige.
 * COMO EL DEL CAPITAL, es OPCIONAL: su ausencia no impide cobrar.
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
    case Liability = 'liability';
    case Equity = 'equity';

    /**
     * El TIPO de cuenta que el rol exige.
     *
     * REGLA que fija este metodo: *el rol exige su tipo*. La caja y la
     * cartera son ACTIVO (son lo que la tienda tiene), el impuesto por pagar
     * y el pasivo son PASIVO (es lo que la tienda debe), los ingresos son
     * INGRESO (es lo que la tienda gana) y el capital es PATRIMONIO (es lo
     * que la tienda tiene de suyos).
     */
    public function requiredType(): AccountType
    {
        return match ($this) {
            self::Cash, self::Receivable => AccountType::Asset,
            self::TaxPayable, self::Liability => AccountType::Liability,
            self::Revenue => AccountType::Income,
            self::Equity => AccountType::Equity,
        };
    }

    /**
     * Los ROLES OBLIGATORIOS del plan COMPLETO: los cuatro que el cobro
     * exige.
     *
     * REGLA que fija este metodo: *el plan completo son CUATRO roles, y los
     * demas son OPCIONALES*. La caja, la cartera, los ingresos y el impuesto
     * por pagar los necesita toda tienda que cobre. El capital solo hace falta
     * cuando la caja se abre con una partida distinta de cero, porque ahi es
     * donde el asiento de apertura necesita la contrapartida: una partida CERO
     * no se asienta, y sin asiento no hace falta contrapartida. Y el pasivo
     * solo hace falta donde hay algo que la tienda debe asentar (el credito a
     * favor del cliente de una venta anulada, la deuda a un proveedor), que
     * es un hecho, no un requisito del cobro.
     *
     * Y POR QUE NO SE QUITA NINGUN ROL DEL CATALOGO: los roles `equity` y
     * `liability` siguen siendo roles VALIDOS y ASIGNABLES (una cuenta de
     * capital o de pasivo mas en el plan), lo que cambia es que su ausencia
     * ya no impide cobrar.
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
