import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { formatAmount } from '@/lib/pos';

/**
 * A row of the CASH BOOK report, as the backend hands it over.
 */
interface CashBookRow {
    date: string;
    store_id: number;
    store_name: string;
    opening_balance: string;
    debit: string;
    credit: string;
    closing_balance: string;
}

interface CashBookTotals {
    debit: string;
    credit: string;
    closing_balance: string;
}

/**
 * A day of the period with NO movement in the cash, and whether the till was
 * open: a day that did not operate is not a day of zero sales.
 */
interface DayWithoutMovement {
    date: string;
    till_open: boolean;
}

interface CashBookProps extends ReportSharedProps {
    rows: CashBookRow[];
    totals: CashBookTotals;
    without_movement: { store_id: number; store_name: string; days: DayWithoutMovement[] }[];
}

/**
 * The CASH BOOK report: the money of the drawer, day by day.
 */
export default function CashBookReport({ rows, totals, without_movement, ...shared }: CashBookProps) {
    const columns: ReportColumn[] = [
        { key: 'date', label: 'Date' },
        { key: 'store_name', label: 'Store' },
        { key: 'opening_balance', label: 'Opening', align: 'right' },
        { key: 'debit', label: 'Debit', align: 'right' },
        { key: 'credit', label: 'Credit', align: 'right' },
        { key: 'closing_balance', label: 'Closing', align: 'right' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cash book', href: '/reportes/libro-caja' }];

    return (
        <ReportShell title="Cash book" path="/reportes/libro-caja" exportKey="libro-caja" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    date: row.date,
                    store_name: row.store_name,
                    opening_balance: formatAmount(row.opening_balance, shared.currency),
                    debit: formatAmount(row.debit, shared.currency),
                    credit: formatAmount(row.credit, shared.currency),
                    closing_balance: formatAmount(row.closing_balance, shared.currency),
                }))}
                footer={{
                    date: 'Total',
                    debit: formatAmount(totals.debit, shared.currency),
                    credit: formatAmount(totals.credit, shared.currency),
                    closing_balance: formatAmount(totals.closing_balance, shared.currency),
                }}
                empty="The cash did not move in this period."
            />

            {without_movement.length > 0 && (
                <div className="mt-4">
                    <p className="text-muted-foreground text-sm">
                        The days of this period with no movement in the cash are not days of zero sales: they are days that did not move.
                    </p>

                    {without_movement.map((tienda) => (
                        <p key={tienda.store_id} className="mt-1 text-sm">
                            <span className="font-medium">{tienda.store_name}:</span>{' '}
                            {tienda.days.map((dia) => `${dia.date}${dia.till_open ? ' (till open)' : ''}`).join(', ')}
                        </p>
                    ))}
                </div>
            )}
        </ReportShell>
    );
}
