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

    /** Anular una venta: la deja sin efecto con una nota de credito. */
    case SalesVoid = 'sales.void';

    /**
     * PEDIR la anulacion de una venta.
     *
     * Y ESTE PERMISO ES DISTINTO DE `sales.void` A PROPOSITO: quien pide la
     * anulacion NO la ejecuta. El cajero hizo la venta y no la deshace:
     * deja la peticion con su motivo, y quien tiene `sales.void` la lleva a
     * cabo. Anular sin motivo de quien pidio seria una anulacion que nadie
     * puede defender despues.
     */
    case SalesVoidRequest = 'sales.void_request';

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

    /** Reabrir un dia cerrado: deshacer un cierre sellado. */
    case CashDayReopen = 'cash_day.reopen';

    /** Imprimir el Z: el cierre de caja, en papel y en la impresora. */
    case CashDayPrint = 'cash_day.print';

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

    /** Ver los gastos de la tienda y sus asientos. */
    case ExpenseRead = 'expense.read';

    /** Registrar un gasto: el gasto nace en `expenses` y su asiento se deriva. */
    case ExpenseCreate = 'expense.create';

    /**
     * Anular un gasto.
     *
     * Y ESTE PERMISO ES DISTINTO DE `expense.create` A PROPOSITO: quien
     * registra el gasto NO lo anula. El gasto se marca, no se borra, y anular
     * sin motivo de quien lo registro seria una anulacion que nadie puede
     * defender despues. Anula quien puede deshacer: el contador.
     */
    case ExpenseCancel = 'expense.cancel';

    /** Ver los ingresos de la tienda y sus asientos. */
    case IncomeRead = 'income.read';

    /** Registrar un ingreso que no viene de una venta. */
    case IncomeCreate = 'income.create';

    /** Anular un ingreso: marcarlo, con su motivo. */
    case IncomeCancel = 'income.cancel';

    /** Ver los movimientos bancarios de la tienda. */
    case BankingRead = 'banking.read';

    /** Registrar un movimiento bancario: el dinero que se reparte la treasury. */
    case BankingCreate = 'banking.create';

    /** Ver los reportes. */
    case ReportRead = 'report.read';

    /**
     * EXPORTAR los reportes: sacar los datos del sistema en un fichero.
     *
     * Y ESTE PERMISO ES DISTINTO DE `report.read` A PROPOSITO: *leer un
     * reporte es mirar una pantalla; exportarlo es SACAR los datos*. El
     * archivo se queda en el equipo de quien lo pide, y ahi un nombre de
     * cliente o un importe travelling no vuelve. Quien puede ver el reporte
     * puede mirar la pantalla; quien puede sacarlo es quien responde de
     * ese fichero.
     */
    case ReportExport = 'report.export';

    /** Ver la auditoria de negocio: quien hizo que, y desde donde. */
    case AuditRead = 'audit.read';

    /** Ver los usuarios y sus roles. */
    case UserRead = 'user.read';

    /** Cambiar el rol de un usuario. */
    case UserUpdate = 'user.update';

    /** Ver los roles y sus permisos. */
    case RoleRead = 'role.read';

    /** Ver el catalogo de impresoras y los destinos de impresion. */
    case PrintingRead = 'printing.read';

    /** Dar de alta una impresora o un destino de impresion. */
    case PrintingCreate = 'printing.create';

    /** Editar una impresora o un destino de impresion. */
    case PrintingUpdate = 'printing.update';

    /** Probar la conexion de un destino de impresion. */
    case PrintingTest = 'printing.test';

    /** Reimprimir un comprobante que ya salio. */
    case PrintingReprint = 'printing.reprint';

    /** Reintentar un trabajo de impresion que fallo. */
    case PrintingRetry = 'printing.retry';

    /**
     * Ver SUS PROPIOS trabajos de impresion.
     *
     * Y ESTE PERMISO ES DISTINTO DE `printing.read` A PROPOSITO: el cajero
     * tiene que poder mirar SI SU PAPEL SALIO, y eso no le da la configuracion
     * de las impresoras ni la cola de sus compañeros. Es el permiso de SU
     * pantalla, y solo de SU pantalla.
     */
    case PrintingOwn = 'printing.own';

    /** Cambiar los permisos o el ambito de tiendas de un rol. */
    case RoleUpdate = 'role.update';

    /** Ver la configuracion de la tienda. */
    case StoreRead = 'store.read';

    /** Cambiar la configuracion de la tienda. */
    case StoreUpdate = 'store.update';

    /** Ver la administracion fiscal: las series, los perfiles y los proveedores. */
    case FiscalRead = 'fiscal.read';

    /** Dar de alta una serie, un perfil o un proveedor fiscal. */
    case FiscalCreate = 'fiscal.create';

    /** Editar una serie, un perfil o un proveedor fiscal. */
    case FiscalUpdate = 'fiscal.update';

    /** Ver las compras de la tienda, sus lotes y su asiento. */
    case PurchaseRead = 'purchase.read';

    /**
     * Registrar una compra: la mercaderia entra con sus lotes.
     *
     * Y ESTE PERMISO ES DISTINTO DE `purchase.cancel` A PROPOSITO: quien
     * registra la compra NO la anula. La compra se marca, no se borra, y
     * anular sin motivo de quien la registro seria una anulacion que nadie
     * puede defender despues.
     */
    case PurchaseCreate = 'purchase.create';

    /** Anular una compra: marcarla, con su motivo. */
    case PurchaseCancel = 'purchase.cancel';

    /** Devolver mercaderia al proveedor: la compra que vuelve. */
    case PurchaseReturn = 'purchase.return';

    /** Ver los proveedores de la tienda y su saldo. */
    case SupplierRead = 'supplier.read';

    /** Dar de alta un proveedor. */
    case SupplierCreate = 'supplier.create';

    /** Ver las devoluciones de mercaderia a los clientes. */
    case SaleReturnRead = 'sale_return.read';

    /** Registrar una devolucion de mercaderia: la que repone el lote exacto. */
    case SaleReturnCreate = 'sale_return.create';

    /** Ver los planes de cuotas y el estado de cada cuota. */
    case InstallmentRead = 'installment.read';

    /**
     * Acordar el plan de cuotas de una venta a credito.
     *
     * Y ESTE PERMISO ES DISTINTO DE `installment.collect` A PROPOSITO: acordar
     * el plan es DECIDIR como se paga, y cobrar la cuota es RECOGER el dinero.
     */
    case InstallmentCreate = 'installment.create';

    /**
     * Cobrar una cuota: el dinero entra a la caja y la cuota se marca.
     *
     * Y ESTE PERMISO ES DISTINTO DE `customer.payment` A PROPOSITO: el cobro de
     * una cuota es un cobro MAS (ademas del cobro suelto del cliente), y el
     * que cobra una cuota toca el PLAN y el CALENDARIO, que son cosas que el
     * cobro suelto no toca.
     */
    case InstallmentCollect = 'installment.collect';

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
            self::SalesVoid => 'Void a sale',
            self::SalesVoidRequest => 'Request the void of a sale',
            self::ReceiptRead => 'View receipts',
            self::ReceiptPrint => 'Print receipts',
            self::CashDayRead => 'View the cash day',
            self::CashDayOpen => 'Open the cash day',
            self::CashDayClose => 'Close the cash day',
            self::CashDayReopen => 'Reopen a closed cash day',
            self::CashDayPrint => 'Print the closing report (Z)',
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
            self::ExpenseRead => 'View expenses',
            self::ExpenseCreate => 'Create expenses',
            self::ExpenseCancel => 'Cancel expenses',
            self::IncomeRead => 'View incomes',
            self::IncomeCreate => 'Create incomes',
            self::IncomeCancel => 'Cancel incomes',
            self::BankingRead => 'View the bank movements',
            self::BankingCreate => 'Register bank movements',
            self::ReportRead => 'View reports',
            self::ReportExport => 'Export the reports',
            self::AuditRead => 'View the business audit trail',
            self::UserRead => 'View users',
            self::UserUpdate => 'Assign user roles',
            self::RoleRead => 'View roles',
            self::RoleUpdate => 'Update role permissions',
            self::PrintingRead => 'View the printers and the print destinations',
            self::PrintingCreate => 'Create printers and print destinations',
            self::PrintingUpdate => 'Update printers and print destinations',
            self::PrintingTest => 'Test the connection of a print destination',
            self::PrintingReprint => 'Reprint a ticket that already went out',
            self::PrintingRetry => 'Retry a print job that failed',
            self::PrintingOwn => 'View your own print jobs',
            self::StoreRead => 'View the store settings',
            self::StoreUpdate => 'Change the store settings',
            self::FiscalRead => 'View the fiscal administration',
            self::FiscalCreate => 'Create fiscal series, profiles and providers',
            self::FiscalUpdate => 'Update fiscal series, profiles and providers',
            self::PurchaseRead => 'View the purchases',
            self::PurchaseCreate => 'Register purchases',
            self::PurchaseCancel => 'Cancel purchases',
            self::PurchaseReturn => 'Return merchandise to the supplier',
            self::SupplierRead => 'View the suppliers and their balance',
            self::SupplierCreate => 'Create suppliers',
            self::SaleReturnRead => 'View the merchandise returns',
            self::SaleReturnCreate => 'Register a merchandise return',
            self::InstallmentRead => 'View the installment plans and their quotas',
            self::InstallmentCreate => 'Agree the installment plan of a credit sale',
            self::InstallmentCollect => 'Collect an installment',
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
