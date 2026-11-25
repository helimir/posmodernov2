import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { Badge } from '@/components/ui/badge';
import { formatAmount } from '@/lib/pos';

/**
 * A row of the CASH COUNT report, as the backend hands it over.
 *
 * `verified` says whether the SIGNATURE of the closed day still matches its
 * content: a close that was tampered with is not a close.
 */
interface CashCountRow {
    date: string;
    store_id: number;
    store_name: string;
    opening_balance: string;
    expected_balance: string;
    counted_balance: string;
    difference: string;
    verified: boolean;
    closed_by: string | null;
    closed_at: string | null;
    close_reason: string | null;
}

interface CashCountTotals {
    difference: string;
}

interface DayWithoutClose {
    date: string;
    till_open: boolean;
}

interface CashCountProps extends ReportSharedProps {
    rows: CashCountRow[];
    totals: CashCountTotals;
    without_close: { store_id: number; store_name: string; days: DayWithoutClose[] }[];
}

/**
 * The CASH COUNT report: what was counted against what should have been
 * there, day by day.
 */
export default function CashCountReport({ rows, totals, without_close, ...shared }: CashCountProps) {
    const columns: ReportColumn[] = [
        { key: 'date', label: 'Date' },
        { key: 'store_name', label: 'Store' },
        { key: 'opening_balance', label: 'Opening', align: 'right' },
        { key: 'expected_balance', label: 'Expected', align: 'right' },
        { key: 'counted_balance', label: 'Counted', align: 'right' },
        { key: 'difference', label: 'Difference', align: 'right' },
        { key: 'verified', label: 'Signature' },
        { key: 'closed_by', label: 'Closed by' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cash count', href: '/reportes/arqueo' }];

    return (
        <ReportShell title="Cash count" path="/reportes/arqueo" exportKey="arqueo" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    date: row.date,
                    store_name: row.store_name,
                    opening_balance: formatAmount(row.opening_balance, shared.currency),
                    expected_balance: formatAmount(row.expected_balance, shared.currency),
                    counted_balance: formatAmount(row.counted_balance, shared.currency),
                    difference: formatAmount(row.difference, shared.currency),
                    verified: row.verified ? <Badge>Verified</Badge> : <Badge variant="destructive">Altered</Badge>,
                    closed_by: row.closed_by ?? '-',
                }))}
                footer={{
                    date: 'Total',
                    difference: formatAmount(totals.difference, shared.currency),
                }}
                empty="No day was closed in this period."
            />

            {without_close.length > 0 && (
                <div className="mt-4">
                    <p className="text-muted-foreground text-sm">
                        These days had the till open and were never closed: they are not days of zero sales.
                    </p>

                    {without_close.map((tienda) => (
                        <p key={tienda.store_id} className="mt-1 text-sm">
                            <span className="font-medium">{tienda.store_name}:</span> {tienda.days.map((dia) => dia.date).join(', ')}
                        </p>
                    ))}
                </div>
            )}
        </ReportShell>
    );
}
