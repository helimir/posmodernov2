<?php

namespace Tests\Fixtures;

use App\Domain\Accounting\CashClose;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * El ESCENARIO de los reportes: las filas que los reportes LEEN.
 *
 * REGLA que fija este fixture: *el reporte es una CAPA DE LECTURA, asi que
 * su escenario son las filas*. No hace falta pasar por el checkout para
 * escribir una venta: al reporte lo que le importa es la fila de `sales` y
 * sus totales, y escribirlas aqui deja el test en lo que tiene que probar
 * (la LECTURA) y no en como se escribieron.
 *
 * Y LAS FECHAS son DATO: cada fila nace con la fecha de negocio que se le
 * pasa, y el reporte no la calcula. Un reporte que cuenta dias con
 * `DAY()/MONTH()/YEAR()` parte el dia a la medianoche del servidor, que no
 * es el dia del negocio.
 */
final class ReportFixture
{
    /**
     * Una TIENDA del reporte.
     *
     * Y LA TIENDA TIENE MONEDA: una pantalla de reporte enseña los importes
     * con el simbolo y los separadores de la MONEDA de la tienda, y son DATO
     * (no hay ningun literal por pais en la pantalla). Asi que la tienda del
     * escenario declara su pais, y el pais su moneda.
     */
    public static function store(string $nombre = 'Tienda del reporte'): int
    {
        $countryId = self::pais();

        return (int) Store::query()->create([
            'name' => $nombre,
            'country_id' => $countryId,
            // La tienda DECLARA su perfil fiscal: sin perfil, ni la moneda ni
            // la serie se pueden resolver, y una pantalla de reportes no
            // puede ensenar importes sin la moneda de la tienda.
            'fiscal_profile_id' => self::perfilFiscal(),
        ])->id;
    }

    /**
     * La MONEDA del escenario, con su simbolo y sus separadores.
     */
    public static function moneda(): int
    {
        $id = DB::table('currencies')->where('code', 'VES')->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('currencies')->insertGetId([
            'code' => 'VES',
            'name' => 'Bolivar',
            'symbol' => 'Bs.',
            'symbol_position' => 'before',
            'decimals' => 2,
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * El PAIS del escenario, con su moneda.
     */
    public static function pais(): int
    {
        $id = DB::table('countries')->where('iso_code', 'RP')->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('countries')->insertGetId([
            'iso_code' => 'RP',
            'name' => 'Pais del reporte',
            'locale' => 'es',
            'timezone' => 'America/Caracas',
            'default_currency_id' => self::moneda(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Una VENTA, con sus totales y su linea.
     *
     * @param  array{
     *     store_id: int,
     *     date: string,
     *     status?: string,
     *     user_id?: int|null,
     *     product_id?: int|null,
     *     subtotal?: string,
     *     tax_total?: string,
     *     discount?: string,
     *     grand_total?: string,
     *     quantity?: string,
     *     net_amount?: string,
     *     line_tax?: string,
     *     line_total?: string,
     *     fiscal_document_id?: int|null
     * }  $atributos
     * @return int El id de la venta.
     */
    public static function sale(array $atributos): int
    {
        $storeId = (int) $atributos['store_id'];

        $saleId = (int) DB::table('sales')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $atributos['customer_id'] ?? null,
            'fiscal_document_id' => $atributos['fiscal_document_id'] ?? null,
            'invoice_no' => $atributos['invoice_no'] ?? ('REP-'.DB::table('sales')->count()),
            'sale_date' => $atributos['date'],
            'status' => $atributos['status'] ?? 'paid',
            'created_by' => $atributos['user_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_totals')->insert([
            'sale_id' => $saleId,
            'sub_total' => $atributos['subtotal'] ?? '50.0000',
            'discount' => $atributos['discount'] ?? '0.0000',
            'tax_total' => $atributos['tax_total'] ?? '8.0000',
            'rounding_adjustment' => '0.0000',
            'grand_total' => $atributos['grand_total'] ?? '58.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (isset($atributos['product_id']) && $atributos['product_id'] !== null) {
            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'product_id' => (int) $atributos['product_id'],
                'store_id' => $storeId,
                'quantity' => $atributos['quantity'] ?? '2.0000',
                'unit_price' => $atributos['unit_price'] ?? '25.0000',
                'discount' => '0.0000',
                'tax_rate_snapshot' => $atributos['tax_rate_snapshot'] ?? '16.0000',
                'tax_amount' => $atributos['line_tax'] ?? '8.0000',
                'base_amount' => $atributos['net_amount'] ?? '50.0000',
                'line_total' => $atributos['line_total'] ?? '58.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $saleId;
    }

    /**
     * Un PRODUCTO del catalogo maestro.
     */
    public static function producto(string $codigo = 'REP-001', string $nombre = 'Producto del reporte'): int
    {
        return (int) DB::table('products')->insertGetId([
            'code' => $codigo,
            'name' => $nombre,
            'type' => 'product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un ASIENTO del libro, con sus lineas.
     *
     * @param  list<array{account: string, debit?: string, credit?: string}>  $lineas
     * @return int El id del asiento.
     */
    public static function asiento(
        int $storeId,
        string $date,
        array $lineas,
        string $tipo = 'sale',
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): int {
        $entryTypeId = (int) DB::table('ledger_entry_types')->where('code', $tipo)->value('id');

        $debe = '0.0000';
        $haber = '0.0000';

        foreach ($lineas as $linea) {
            $debe = bcadd($debe, $linea['debit'] ?? '0.0000', 4);
            $haber = bcadd($haber, $linea['credit'] ?? '0.0000', 4);
        }

        $entryId = (int) DB::table('ledger_entries')->insertGetId([
            'entry_type_id' => $entryTypeId,
            'store_id' => $storeId,
            'business_date' => $date,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'amount' => $debe,
            'occurred_at' => $date.' 12:00:00+00:00',
            'description' => 'Asiento del reporte',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lineas as $linea) {
            DB::table('ledger_lines')->insert([
                'ledger_entry_id' => $entryId,
                'account_code' => $linea['account'],
                'debit' => $linea['debit'] ?? '0.0000',
                'credit' => $linea['credit'] ?? '0.0000',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $entryId;
    }

    /**
     * Un MOVIMIENTO del libro del cliente.
     */
    public static function movimientoCliente(
        int $customerId,
        int $storeId,
        string $date,
        string $type,
        string $amount,
        string $referenceNo = 'REF-001',
    ): int {
        return (int) DB::table('customer_ledger_entries')->insertGetId([
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'type' => $type,
            'reference_no' => $referenceNo,
            'amount' => $amount,
            'entry_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un LOTE de inventario, con su coste.
     */
    public static function lote(
        int $productId,
        int $storeId,
        string $cantidad,
        string $coste,
        string $recibido = '2026-01-01 08:00:00+00:00',
    ): int {
        $purchaseId = (int) DB::table('purchases')->insertGetId([
            'store_id' => $storeId,
            'purchase_date' => substr($recibido, 0, 10),
            'status' => 'received',
            'invoice_no' => 'REP-COMPRA-'.DB::table('purchases')->count(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = (int) DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'product_id' => $productId,
            'store_id' => $storeId,
            'quantity' => $cantidad,
            'unit_price' => $coste,
            'discount' => '0.0000',
            'tax_amount' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('inventory_lots')->insertGetId([
            'product_id' => $productId,
            'store_id' => $storeId,
            'purchase_item_id' => $purchaseItemId,
            'quantity_received' => $cantidad,
            'quantity_consumed' => '0.0000',
            'quantity_returned' => '0.0000',
            'unit_cost' => $coste,
            'received_at' => $recibido,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un PAIS y un PERFIL FISCAL minimos: los necesita un comprobante.
     */
    public static function perfilFiscal(): int
    {
        $countryId = self::pais();

        $id = DB::table('fiscal_profiles')->where('code', 'RP_REPORTE')->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('fiscal_profiles')->insertGetId([
            'country_id' => $countryId,
            'code' => 'RP_REPORTE',
            'name' => 'Perfil fiscal del reporte',
            'requires_control_number' => false,
            'authorized_range_required' => false,
            'holes_allowed' => true,
            'signer_model' => 'none',
            'tax_inclusive_default' => false,
            'default_currency_id' => self::moneda(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un COMPROBANTE fiscal emitido, con su nodo de impuesto.
     *
     * @param  array{
     *     store_id: int,
     *     date: string,
     *     status?: string,
     *     tax_type?: string,
     *     tax_rate?: string|null,
     *     base?: string,
     *     tax?: string,
     *     subtotal?: string,
     *     tax_total?: string,
     *     total?: string
     * }  $atributos
     * @return int El id del comprobante.
     */
    public static function comprobante(array $atributos): int
    {
        $perfilId = self::perfilFiscal();

        $documentId = (int) DB::table('fiscal_documents')->insertGetId([
            'fiscal_profile_id' => $perfilId,
            'store_id' => (int) $atributos['store_id'],
            'document_type' => $atributos['document_type'] ?? 'invoice',
            'status' => $atributos['status'] ?? 'issued',
            'number' => $atributos['number'] ?? (9000 + DB::table('fiscal_documents')->count()),
            'issue_date' => $atributos['date'],
            'issued_at' => $atributos['date'].' 10:00:00+00:00',
            'voided_at' => $atributos['voided_at'] ?? null,
            'subtotal' => $atributos['subtotal'] ?? '100.0000',
            'tax_total' => $atributos['tax_total'] ?? '16.0000',
            'total_amount' => $atributos['total'] ?? '116.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (isset($atributos['tax_type'])) {
            DB::table('fiscal_document_taxes')->insert([
                'fiscal_document_id' => $documentId,
                'line_id' => null,
                'tax_type' => $atributos['tax_type'],
                'tax_category_id' => null,
                'tax_rate_id' => null,
                'tax_rate_snapshot' => $atributos['tax_rate'] ?? null,
                'evidence_status' => null,
                'base_amount' => $atributos['base'] ?? '100.0000',
                'tax_amount' => $atributos['tax'] ?? '16.0000',
                'rounding_adjustment' => '0.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $documentId;
    }

    /**
     * Un CIERRE de caja sellado.
     *
     * @param  array{
     *     store_id: int,
     *     date: string,
     *     opening_balance?: string,
     *     expected_balance?: string,
     *     counted_balance?: string,
     *     difference?: string,
     *     subtotal?: string,
     *     tax_total?: string,
     *     grand_total?: string,
     *     sales_count?: int,
     *     reopened_at?: string|null
     * }  $atributos
     * @return int El id del cierre.
     */
    public static function cierre(array $atributos): int
    {
        $storeId = (int) $atributos['store_id'];

        $registerId = $atributos['cash_register_id'] ?? null;

        if ($registerId === null) {
            $registerId = self::cajaAbierta($storeId, $atributos['date']);
        }

        $contenido = [
            'business_date' => $atributos['date'],
            'opening_balance' => $atributos['opening_balance'] ?? '0.0000',
            'expected_balance' => $atributos['expected_balance'] ?? '100.0000',
            'counted_balance' => $atributos['counted_balance'] ?? '100.0000',
            'difference' => $atributos['difference'] ?? '0.0000',
            'subtotal' => $atributos['subtotal'] ?? '80.0000',
            'tax_total' => $atributos['tax_total'] ?? '12.8000',
            'grand_total' => $atributos['grand_total'] ?? '92.8000',
            'sales_count' => $atributos['sales_count'] ?? 3,
            'first_document_number' => $atributos['first_document_number'] ?? '0001',
            'last_document_number' => $atributos['last_document_number'] ?? '0003',
            'first_control_number' => null,
            'last_control_number' => null,
            'payments' => [],
        ];

        $canonico = CashClose::canonico($contenido);

        return (int) DB::table('cash_closes')->insertGetId([
            'store_id' => $storeId,
            'business_date' => $atributos['date'],
            'cash_register_id' => (int) $registerId,
            'opening_balance' => $canonico['opening_balance'],
            'expected_balance' => $canonico['expected_balance'],
            'counted_balance' => $canonico['counted_balance'],
            'difference' => $canonico['difference'],
            'close_reason' => $atributos['close_reason'] ?? null,
            'counted_pieces' => null,
            'subtotal' => $canonico['subtotal'],
            'tax_total' => $canonico['tax_total'],
            'grand_total' => $canonico['grand_total'],
            'sales_count' => $canonico['sales_count'],
            'first_document_number' => $canonico['first_document_number'],
            'last_document_number' => $canonico['last_document_number'],
            'first_control_number' => null,
            'last_control_number' => null,
            'payments' => json_encode($canonico['payments']),
            'content_hash' => CashClose::hash($canonico),
            'closed_by' => $atributos['closed_by'] ?? null,
            'closed_at' => $atributos['date'].' 20:00:00+00:00',
            'reopened_at' => $atributos['reopened_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Una CAJA ABIERTA de una tienda en una fecha.
     */
    public static function cajaAbierta(int $storeId, string $date): int
    {
        return (int) DB::table('cash_registers')->insertGetId([
            'store_id' => $storeId,
            'business_date' => $date,
            'bank_account_id' => null,
            'opened_at' => $date.' 08:00:00+00:00',
            'closed_at' => null,
            'opening_balance' => '0.0000',
            'closing_balance' => null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un PLAN DE CUENTAS minimo para una tienda: la caja, los ingresos, los
     * gastos y el impuesto por pagar.
     */
    public static function plan(int $storeId): void
    {
        $cuentas = [
            ['1100000', 'Efectivo y caja', 'asset', 'cash'],
            ['4010000', 'Ventas', 'income', 'revenue'],
            ['4020000', 'Otros ingresos', 'income', null],
            ['5010000', 'Costo de ventas', 'expense', null],
            ['5110000', 'Gastos de administracion', 'expense', null],
            ['2110000', 'Impuesto por pagar', 'liability', 'tax_payable'],
            ['3100000', 'Capital social', 'equity', 'equity'],
        ];

        foreach ($cuentas as [$codigo, $nombre, $tipo, $rol]) {
            $accountId = DB::table('accounts')->where('code', $codigo)->value('id');

            if ($accountId === null) {
                $accountId = DB::table('accounts')->insertGetId([
                    'code' => $codigo,
                    'name' => $nombre,
                    'type' => $tipo,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($rol === null) {
                continue;
            }

            $vinculo = DB::table('store_accounts')
                ->where('store_id', $storeId)
                ->where('role', $rol)
                ->value('id');

            if ($vinculo !== null) {
                continue;
            }

            DB::table('store_accounts')->insert([
                'account_id' => (int) $accountId,
                'store_id' => $storeId,
                'role' => $rol,
                'status' => 'active',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
