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
 * ES OBLIGATORIO DEL PLAN COMPLETO: el plan lo declara, y su ausencia se ve
 * en el plan, NO en el cobro (ver `delCobro()`).
 *
 * Y EL ROL DEL CAPITAL ES OPCIONAL: el plan COMPLETO son CINCO roles (los de
 * `obligatorios()`), y el de capital solo hace falta cuando la caja se abre
 * con una PARTIDA DISTINTA DE CERO, porque ahi es donde el asiento de apertura
 * necesita la contrapartida. Una partida CERO no se asienta (el libro no admite
 * lineas a cero), asi que sin partida no hace falta capital. El rol sigue
 * existiendo y siendo asignable: es un rol VALIDO, no un rol prohibido.
 *
 * Y "OBLIGATORIO" NO QUIERE DECIR "SIN EL ROL LA TIENDA NO COBRA": el plan
 * completo declara lo que el negocio DECLARA, y el cobro exige su propio
 * conjunto (`delCobro()`). La decision de la F2-13b sigue en pie: NINGUNA
 * TIENDA DEJA DE COBRAR.
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
     * Los ROLES OBLIGATORIOS del plan COMPLETO: los cinco que el plan
     * declara.
     *
     * REGLA que fija este metodo: *el plan completo son CINCO roles, y el
     * capital es OPCIONAL*. La caja, la cartera, los ingresos, el impuesto por
     * pagar y el PASIVO los declara toda tienda. El capital solo hace falta
     * cuando la caja se abre con una partida distinta de cero, porque ahi es
     * donde el asiento de apertura necesita la contrapartida: una partida CERO
     * no se asienta, y sin asiento no hace falta contrapartida.
     *
     * Y EL PASIVO es obligatorio del plan COMPLETO porque es la cuenta donde
     * se asienta lo que la tienda DEBE: el credito a favor del cliente de una
     * venta anulada y la deuda a un proveedor. Sin el, el plan declara una
     * deuda que no tiene donde asentar, y la anulacion de una venta cobrada
     * no se puede escribir.
     *
     * Y "OBLIGATORIO" NO ES "SIN EL ROL NO SE COBRA": el cobro exige su
     * propio conjunto (`delCobro()`), y la decision de la F2-13b sigue en
     * pie: NINGUNA TIENDA DEJA DE COBRAR. Lo que el pasivo falta es para
     * DESHACER una venta cobrada por error, y eso es peor que no poder
     * cobrar.
     *
     * Y POR QUE NO SE QUITA NINGUN ROL DEL CATALOGO: el rol `equity` sigue
     * siendo un rol VALIDO y ASIGNABLE (una cuenta de capital mas en el
     * plan), lo que cambia es que su ausencia ya no impide cobrar.
     *
     * @return list<self>
     */
    public static function obligatorios(): array
    {
        return [
            self::Cash,
            self::Receivable,
            self::Revenue,
            self::TaxPayable,
            self::Liability,
        ];
    }

    /**
     * Los ROLES que el COBRO exige: los cuatro que toda tienda que cobra
     * tiene que declarar.
     *
     * REGLA que fija este metodo: *el cobro exige CUATRO roles, y el resto no
     * lo detiene*. La caja (donde entra el dinero), la cartera (la parte que
     * no se cobra), los ingresos y el impuesto por pagar: sin ninguno de
     * ellos no hay asiento de venta, y sin asiento no hay cobro.
     *
     * Y EL PASIVO NO ESTA EN ESTA LISTA, y esa es la decision: una tienda sin
     * pasivo COBRA PERFECTAMENTE, y lo que no puede es deshacer una venta
     * cobrada por error. Un 422 en el cobro dejaria al cajero bloqueado con
     * un error que nadie puede corregir, y eso es PEOR que no poder cobrar.
     *
     * @return list<self>
     */
    public static function delCobro(): array
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

    /**
     * ¿Este rol es del COBRO?
     *
     * Es la diferencia entre "el plan lo declara" y "sin el rol no se cobra":
     * los dos conjuntos no son el mismo, y confundirlos deja a una tienda
     * sin poder cobrar.
     */
    public function esDelCobro(): bool
    {
        return in_array($this, self::delCobro(), true);
    }
}
