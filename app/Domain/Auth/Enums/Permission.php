<?php

namespace App\Domain\Auth\Enums;

/**
 * El CATALOGO de permisos: el conjunto CERRADO de lo que la aplicacion
 * protege.
 *
 * REGLA que fija este enum: *un permiso que no esta aqui no existe, y un
 * permiso que esta aqui existe para todos*. El catalogo es la UNICA fuente:
 * el sembrador siembra desde aqui, la UI pregunta aqui y el middleware exige
 * aqui. Ningun slug se escribe a mano en dos sitios.
 *
 * DE DONDE SALE EL CATALOGO: de las RUTAS QUE EXISTEN. Cada pantalla y cada
 * escritura real del v2 tiene su permiso, y nada mas. No es la matriz del
 * legacy (unos 190 slugs, con typos y con huerfanos): es la de este sistema,
 * que tiene menos pantallas y mas exactas.
 *
 * LA FORMA del slug es `{modulo}.{accion}`: el modulo dice DE QUE va el
 * permiso y la accion dice QUE se puede hacer con el. Las dos columnas de la
 * tabla `permissions` (`module` y `action`) salen de aqui, y no se escriben a
 * mano en el sembrador.
 *
 * Y LOS NOMBRES SIGUEN LA CONVENCION DEL LEGACY donde se puede, para que el
 * negocio los reconozca: se leen, se crean, se actualizan y se borran. Los
 * typos del legacy (`crate_category`, `updadte_pmethod_status`) y sus
 * huerfanos (`read_banner`, `sms_setting`, `read_holding_order`) NO se
 * copian: un permiso que el sistema no tiene no se inventa.
 */
enum Permission: string
{
    /** Ver el panel de inicio. */
    case DashboardRead = 'dashboard.read';

    /** Abrir la pantalla de la caja y buscar en ella. */
    case PosAccess = 'pos.access';

    /** Pedir el presupuesto de una venta sin cobrarla. */
    case PosBudget = 'pos.budget';

    /** Ver las ventas y el detalle de una venta. */
    case SalesRead = 'sales.read';

    /** Cobrar: crear una venta. */
    case SalesCreate = 'sales.create';

    /** Reintentar la emision fiscal de una venta. */
    case SalesFiscalRetry = 'sales.fiscal_retry';

    /** Ver el comprobante fiscal de una venta. */
    case ReceiptRead = 'receipt.read';

    /** Encolar el comprobante para imprimirlo. */
    case ReceiptPrint = 'receipt.print';

    /** Ver la caja del dia. */
    case CashDayRead = 'cash_day.read';

    /** Abrir la caja del dia. */
    case CashDayOpen = 'cash_day.open';

    /** Cerrar la caja del dia. */
    case CashDayClose = 'cash_day.close';

    /** Ver los clientes y su credito. */
    case CustomerRead = 'customer.read';

    /** Dar de alta un cliente. */
    case CustomerCreate = 'customer.create';

    /** Editar un cliente. */
    case CustomerUpdate = 'customer.update';

    /** Cobrar el credito de un cliente. */
    case CustomerPayment = 'customer.payment';

    /** Ver el catalogo de productos y el precio de la tienda. */
    case CatalogRead = 'catalog.read';

    /** Dar de alta un producto. */
    case CatalogCreate = 'catalog.create';

    /** Editar un producto. */
    case CatalogUpdate = 'catalog.update';

    /** Poner el precio de un producto en una tienda. */
    case CatalogPrice = 'catalog.price';

    /** Ver el plan de cuentas de la tienda. */
    case AccountingRead = 'accounting.read';

    /** Dar de alta una cuenta del catalogo. */
    case AccountingCreate = 'accounting.create';

    /** Cambiar el estado o el rol de una cuenta. */
    case AccountingUpdate = 'accounting.update';

    /** Borrar una cuenta del catalogo. */
    case AccountingDelete = 'accounting.delete';

    /** Ver los reportes. */
    case ReportRead = 'report.read';

    /** Ver los usuarios y sus roles. */
    case UserRead = 'user.read';

    /** Cambiar el rol de un usuario. */
    case UserUpdate = 'user.update';

    /** Ver los roles y sus permisos. */
    case RoleRead = 'role.read';

    /** Cambiar los permisos o el ambito de tiendas de un rol. */
    case RoleUpdate = 'role.update';

    /**
     * El MODULO del permiso: la parte del slug antes del punto.
     *
     * Es la columna `permissions.module`, y sale del propio slug: el modulo
     * no se escribe en dos sitios.
     */
    public function module(): string
    {
        $partes = explode('.', $this->value, 2);

        return $partes[0];
    }

    /**
     * La ACCION del permiso: la parte del slug despues del punto.
     *
     * Es la columna `permissions.action`, y sale del propio slug.
     */
    public function action(): string
    {
        $partes = explode('.', $this->value, 2);

        return $partes[1] ?? '';
    }

    /**
     * La ETIQUETA del permiso: el texto que ve la persona.
     *
     * Es el `permissions.label`, y esta en ingles porque es texto de la
     * interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::DashboardRead => 'View the dashboard',
            self::PosAccess => 'Use the till',
            self::PosBudget => 'Quote a sale',
            self::SalesRead => 'View sales',
            self::SalesCreate => 'Charge a sale',
            self::SalesFiscalRetry => 'Retry the fiscal issuance',
            self::ReceiptRead => 'View receipts',
            self::ReceiptPrint => 'Print receipts',
            self::CashDayRead => 'View the cash day',
            self::CashDayOpen => 'Open the cash day',
            self::CashDayClose => 'Close the cash day',
            self::CustomerRead => 'View customers',
            self::CustomerCreate => 'Create customers',
            self::CustomerUpdate => 'Update customers',
            self::CustomerPayment => 'Collect customer credit',
            self::CatalogRead => 'View the catalog',
            self::CatalogCreate => 'Create products',
            self::CatalogUpdate => 'Update products',
            self::CatalogPrice => 'Set product prices',
            self::AccountingRead => 'View the chart of accounts',
            self::AccountingCreate => 'Create accounts',
            self::AccountingUpdate => 'Update accounts',
            self::AccountingDelete => 'Delete accounts',
            self::ReportRead => 'View reports',
            self::UserRead => 'View users',
            self::UserUpdate => 'Assign user roles',
            self::RoleRead => 'View roles',
            self::RoleUpdate => 'Update role permissions',
        };
    }

    /**
     * TODOS los slugs del catalogo, en orden.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_map(
            static fn (self $permiso): string => $permiso->value,
            self::cases(),
        );
    }

    /**
     * Los permisos de un MODULO: los que el modulo agrupa.
     *
     * La pantalla de un rol agrupa los permisos por modulo, y el modulo sale
     * del slug: no hay una lista de modulos escrita a mano.
     *
     * @return list<self>
     */
    public static function delModulo(string $modulo): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $permiso): bool => $permiso->module() === $modulo,
        ));
    }

    /**
     * Los MODULOS del catalogo, en orden y sin repetir.
     *
     * @return list<string>
     */
    public static function modulos(): array
    {
        $modulos = [];

        foreach (self::cases() as $permiso) {
            $modulos[$permiso->module()] = true;
        }

        return array_keys($modulos);
    }
}
