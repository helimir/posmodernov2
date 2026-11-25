import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { formatAmount } from '@/lib/pos';

/**
 * A row of the SALES BY PERIOD report, as the backend hands it over.
 *
 * Every amount is a DECIMAL STRING: the columns are `numeric(25,4)` and a
 * `float` is not money.
 */
interface SalesRow {
    date: string;
    store_id: number;
    store_name: string;
    sales_count: number;
    subtotal: string;
    discount: string;
    tax_total: string;
    grand_total: string;
}

interface SalesTotals {
    sales_count: number;
    subtotal: string;
    discount: string;
    tax_total: string;
    grand_total: string;
}

interface SalesReportProps extends ReportSharedProps {
    rows: SalesRow[];
    totals: SalesTotals;
}

/**
 * The SALES BY PERIOD report: one row per day and store.
 *
 * The totals are the SUM of the rows above them: the aggregate and the rows
 * count the same thing.
 */
export default function SalesReport({ rows, totals, ...shared }: SalesReportProps) {
    const columns: ReportColumn[] = [
        { key: 'date', label: 'Date' },
        { key: 'store_name', label: 'Store' },
        { key: 'sales_count', label: 'Sales', align: 'right' },
        { key: 'subtotal', label: 'Subtotal', align: 'right' },
        { key: 'discount', label: 'Discount', align: 'right' },
        { key: 'tax_total', label: 'Tax', align: 'right' },
        { key: 'grand_total', label: 'Total', align: 'right' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales by period', href: '/reportes/ventas' }];

    return (
        <ReportShell title="Sales by period" path="/reportes/ventas" exportKey="ventas" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    date: row.date,
                    store_name: row.store_name,
                    sales_count: row.sales_count,
                    subtotal: formatAmount(row.subtotal, shared.currency),
                    discount: formatAmount(row.discount, shared.currency),
                    tax_total: formatAmount(row.tax_total, shared.currency),
                    grand_total: formatAmount(row.grand_total, shared.currency),
                }))}
                footer={{
                    date: 'Total',
                    sales_count: totals.sales_count,
                    subtotal: formatAmount(totals.subtotal, shared.currency),
                    discount: formatAmount(totals.discount, shared.currency),
                    tax_total: formatAmount(totals.tax_total, shared.currency),
                    grand_total: formatAmount(totals.grand_total, shared.currency),
                }}
                empty="There are no sales in this period."
            />
        </ReportShell>
    );
}
