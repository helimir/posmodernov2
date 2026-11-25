import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { Badge } from '@/components/ui/badge';
import { formatAmount } from '@/lib/pos';

/**
 * A row of the PERIOD Z report, as the backend hands it over.
 *
 * It is a SEALED close: the figures are the ones the close was signed with,
 * and `verified` says whether the signature still matches.
 */
interface PeriodZRow {
    date: string;
    store_id: number;
    store_name: string;
    sales_count: number;
    subtotal: string;
    tax_total: string;
    grand_total: string;
    first_document_number: string | null;
    last_document_number: string | null;
    verified: boolean;
}

interface PeriodZTotals {
    sales_count: number;
    subtotal: string;
    tax_total: string;
    grand_total: string;
}

interface DayWithoutClose {
    date: string;
    till_open: boolean;
}

interface PeriodZProps extends ReportSharedProps {
    rows: PeriodZRow[];
    totals: PeriodZTotals;
    without_close: { store_id: number; store_name: string; days: DayWithoutClose[] }[];
}

/**
 * The PERIOD Z report: the fiscal summary of the closed days.
 *
 * The period is assembled with the CLOSES: a sealed close is a fact that is
 * read, never recalculated.
 */
export default function PeriodZReport({ rows, totals, without_close, ...shared }: PeriodZProps) {
    const columns: ReportColumn[] = [
        { key: 'date', label: 'Date' },
        { key: 'store_name', label: 'Store' },
        { key: 'sales_count', label: 'Sales', align: 'right' },
        { key: 'subtotal', label: 'Subtotal', align: 'right' },
        { key: 'tax_total', label: 'Tax', align: 'right' },
        { key: 'grand_total', label: 'Total', align: 'right' },
        { key: 'documents', label: 'Documents' },
        { key: 'verified', label: 'Signature' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Z by period', href: '/reportes/z' }];

    return (
        <ReportShell title="Z by period" path="/reportes/z" exportKey="z" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    date: row.date,
                    store_name: row.store_name,
                    sales_count: row.sales_count,
                    subtotal: formatAmount(row.subtotal, shared.currency),
                    tax_total: formatAmount(row.tax_total, shared.currency),
                    grand_total: formatAmount(row.grand_total, shared.currency),
                    documents: row.first_document_number === null ? '-' : `${row.first_document_number} - ${row.last_document_number ?? ''}`,
                    verified: row.verified ? <Badge>Verified</Badge> : <Badge variant="destructive">Altered</Badge>,
                }))}
                footer={{
                    date: 'Total',
                    sales_count: totals.sales_count,
                    subtotal: formatAmount(totals.subtotal, shared.currency),
                    tax_total: formatAmount(totals.tax_total, shared.currency),
                    grand_total: formatAmount(totals.grand_total, shared.currency),
                }}
                empty="No day was closed in this period."
            />

            {without_close.length > 0 && (
                <div className="mt-4">
                    <p className="text-muted-foreground text-sm">
                        These days had the till open and were never closed: they are not in this report.
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
