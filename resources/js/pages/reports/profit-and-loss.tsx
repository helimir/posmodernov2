import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { formatAmount } from '@/lib/pos';

/**
 * A row of the PROFIT AND LOSS report, as the backend hands it over.
 *
 * The report groups by ACCOUNT, not by the type of the entry: an income and
 * a transfer share the same entry type, and only the account tells them
 * apart.
 */
interface ProfitAndLossRow {
    account_code: string;
    account_name: string;
    kind: string;
    debit: string;
    credit: string;
    amount: string;
}

interface ProfitAndLossTotals {
    income: string;
    expense: string;
    result: string;
}

interface ProfitAndLossProps extends ReportSharedProps {
    rows: ProfitAndLossRow[];
    totals: ProfitAndLossTotals;
}

/**
 * The PROFIT AND LOSS report: the result of the period.
 */
export default function ProfitAndLossReport({ rows, totals, ...shared }: ProfitAndLossProps) {
    const columns: ReportColumn[] = [
        { key: 'account_code', label: 'Account' },
        { key: 'account_name', label: 'Name' },
        { key: 'kind', label: 'Kind' },
        { key: 'debit', label: 'Debit', align: 'right' },
        { key: 'credit', label: 'Credit', align: 'right' },
        { key: 'amount', label: 'Amount', align: 'right' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Profit and loss', href: '/reportes/resultados' }];

    return (
        <ReportShell title="Profit and loss" path="/reportes/resultados" exportKey="resultados" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    account_code: row.account_code,
                    account_name: row.account_name,
                    kind: row.kind === 'income' ? 'Income' : 'Expense',
                    debit: formatAmount(row.debit, shared.currency),
                    credit: formatAmount(row.credit, shared.currency),
                    amount: formatAmount(row.amount, shared.currency),
                }))}
                empty="There are no movements in this period."
            />

            <dl className="mt-4 grid gap-2 text-sm sm:grid-cols-3">
                <div>
                    <dt className="text-muted-foreground">Income</dt>
                    <dd className="tabular-nums">{formatAmount(totals.income, shared.currency)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Expense</dt>
                    <dd className="tabular-nums">{formatAmount(totals.expense, shared.currency)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Result</dt>
                    <dd className="tabular-nums font-medium">{formatAmount(totals.result, shared.currency)}</dd>
                </div>
            </dl>
        </ReportShell>
    );
}
