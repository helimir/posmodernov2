import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { formatAmount } from '@/lib/pos';

/**
 * An open debt of a customer: the charge that is not covered yet, with the
 * days it has been open.
 */
interface AgingItem {
    date: string;
    amount: string;
    age_days: number;
}

/**
 * A row of the CUSTOMER AGING report, as the backend hands it over.
 *
 * `balance` carries its SIGN: a negative balance is a credit in favour, and
 * `due` is then zero.
 */
interface AgingRow {
    customer_id: number;
    name: string | null;
    store_id: number;
    store_name: string;
    balance: string;
    due: string;
    credit: string;
    oldest_date: string | null;
    age_days: number | null;
    items: AgingItem[];
}

interface AgingTotals {
    due: string;
    credit: string;
}

interface CustomerAgingProps extends ReportSharedProps {
    rows: AgingRow[];
    totals: AgingTotals;
}

/**
 * The CUSTOMER AGING report: the debt and how long it has been open.
 *
 * The age is in DAYS and the open charges are one by one: the report does
 * not invent ageing bands, because a band is a business rule and this
 * repository has none written down.
 */
export default function CustomerAgingReport({ rows, totals, ...shared }: CustomerAgingProps) {
    const columns: ReportColumn[] = [
        { key: 'name', label: 'Customer' },
        { key: 'store_name', label: 'Store' },
        { key: 'balance', label: 'Balance', align: 'right' },
        { key: 'due', label: 'Due', align: 'right' },
        { key: 'credit', label: 'Credit', align: 'right' },
        { key: 'oldest_date', label: 'Oldest charge' },
        { key: 'age_days', label: 'Age (days)', align: 'right' },
        { key: 'open_charges', label: 'Open charges' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Customer aging', href: '/reportes/clientes' }];

    return (
        <ReportShell title="Customer aging" path="/reportes/clientes" exportKey="clientes" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    name: row.name ?? 'Without name',
                    store_name: row.store_name,
                    balance: formatAmount(row.balance, shared.currency),
                    due: formatAmount(row.due, shared.currency),
                    credit: formatAmount(row.credit, shared.currency),
                    oldest_date: row.oldest_date ?? '-',
                    age_days: row.age_days ?? '-',
                    open_charges: row.items.map((item) => `${item.date} (${formatAmount(item.amount, shared.currency)}, ${item.age_days} days)`).join('; '),
                }))}
                footer={{
                    name: 'Total',
                    due: formatAmount(totals.due, shared.currency),
                    credit: formatAmount(totals.credit, shared.currency),
                }}
                empty="No customer owes anything in this scope."
            />
        </ReportShell>
    );
}
