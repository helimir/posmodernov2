import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { formatAmount, formatPlainAmount } from '@/lib/pos';

/**
 * A row of the SALES BY PRODUCT report, as the backend hands it over.
 */
interface SalesByProductRow {
    product_id: number;
    code: string;
    name: string;
    store_id: number;
    store_name: string;
    quantity: string;
    net_amount: string;
    tax_amount: string;
    total_amount: string;
}

interface SalesByProductTotals {
    quantity: string;
    net_amount: string;
    tax_amount: string;
    total_amount: string;
}

interface SalesByProductProps extends ReportSharedProps {
    rows: SalesByProductRow[];
    totals: SalesByProductTotals;
}

/**
 * The SALES BY PRODUCT report: one row per product and store.
 */
export default function SalesByProductReport({ rows, totals, ...shared }: SalesByProductProps) {
    const columns: ReportColumn[] = [
        { key: 'code', label: 'Code' },
        { key: 'name', label: 'Name' },
        { key: 'store_name', label: 'Store' },
        { key: 'quantity', label: 'Quantity', align: 'right' },
        { key: 'net_amount', label: 'Net', align: 'right' },
        { key: 'tax_amount', label: 'Tax', align: 'right' },
        { key: 'total_amount', label: 'Total', align: 'right' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales by product', href: '/reportes/ventas/productos' }];

    return (
        <ReportShell title="Sales by product" path="/reportes/ventas/productos" exportKey="ventas-productos" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    code: row.code,
                    name: row.name,
                    store_name: row.store_name,
                    quantity: formatPlainAmount(row.quantity, shared.currency),
                    net_amount: formatAmount(row.net_amount, shared.currency),
                    tax_amount: formatAmount(row.tax_amount, shared.currency),
                    total_amount: formatAmount(row.total_amount, shared.currency),
                }))}
                footer={{
                    name: 'Total',
                    quantity: formatPlainAmount(totals.quantity, shared.currency),
                    net_amount: formatAmount(totals.net_amount, shared.currency),
                    tax_amount: formatAmount(totals.tax_amount, shared.currency),
                    total_amount: formatAmount(totals.total_amount, shared.currency),
                }}
                empty="Nothing was sold in this period."
            />
        </ReportShell>
    );
}
