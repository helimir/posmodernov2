import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { formatAmount } from '@/lib/pos';

/**
 * A row of the SALES BY CASHIER report, as the backend hands it over.
 *
 * A sale with no cashier has a `null` name: a cashier that is not known is a
 * cashier that nobody can audit, and the hole has to be seen.
 */
interface SalesByCashierRow {
    user_id: number | null;
    name: string | null;
    sales_count: number;
    subtotal: string;
    tax_total: string;
    grand_total: string;
}

interface SalesByCashierTotals {
    sales_count: number;
    subtotal: string;
    tax_total: string;
    grand_total: string;
}

interface SalesByCashierProps extends ReportSharedProps {
    rows: SalesByCashierRow[];
    totals: SalesByCashierTotals;
}

/**
 * The SALES BY CASHIER report: one row per cashier.
 */
export default function SalesByCashierReport({ rows, totals, ...shared }: SalesByCashierProps) {
    const columns: ReportColumn[] = [
        { key: 'name', label: 'Cashier' },
        { key: 'sales_count', label: 'Sales', align: 'right' },
        { key: 'subtotal', label: 'Subtotal', align: 'right' },
        { key: 'tax_total', label: 'Tax', align: 'right' },
        { key: 'grand_total', label: 'Total', align: 'right' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales by cashier', href: '/reportes/ventas/cajeros' }];

    return (
        <ReportShell title="Sales by cashier" path="/reportes/ventas/cajeros" exportKey="ventas-cajeros" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    name: row.name ?? 'Without cashier',
                    sales_count: row.sales_count,
                    subtotal: formatAmount(row.subtotal, shared.currency),
                    tax_total: formatAmount(row.tax_total, shared.currency),
                    grand_total: formatAmount(row.grand_total, shared.currency),
                }))}
                footer={{
                    name: 'Total',
                    sales_count: totals.sales_count,
                    subtotal: formatAmount(totals.subtotal, shared.currency),
                    tax_total: formatAmount(totals.tax_total, shared.currency),
                    grand_total: formatAmount(totals.grand_total, shared.currency),
                }}
                empty="There are no sales in this period."
            />
        </ReportShell>
    );
}
