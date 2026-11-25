import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { formatAmount, formatPlainAmount } from '@/lib/pos';

/**
 * A row of the VAT report, as the backend hands it over.
 *
 * `tax_type` says WHICH tax it is: `transfer` is the one the buyer pays and
 * `withholding` is the buyer's credit, and they are never mixed.
 */
interface VatRow {
    tax_type: string;
    tax_rate: string | null;
    base_amount: string;
    tax_amount: string;
    documents_count: number;
}

interface VatTotals {
    base_amount: string;
    tax_amount: string;
    documents_count: number;
}

interface VatProps extends ReportSharedProps {
    rows: VatRow[];
    totals: VatTotals;
    /** The sales of the period with NO issued document: the hole of the fiscal report. */
    without_document: { sales_count: number; amount: string };
}

/**
 * The VAT BY RATE report: the fiscal report.
 *
 * It comes from the RECEIPTS, not from the sales: a sale without a receipt
 * has no tax node, and the report SAYS how many sales are in that hole
 * instead of hiding them.
 */
export default function VatReport({ rows, totals, without_document, ...shared }: VatProps) {
    const columns: ReportColumn[] = [
        { key: 'tax_type', label: 'Tax' },
        { key: 'tax_rate', label: 'Rate', align: 'right' },
        { key: 'base_amount', label: 'Base', align: 'right' },
        { key: 'tax_amount', label: 'Tax amount', align: 'right' },
        { key: 'documents_count', label: 'Documents', align: 'right' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'VAT by rate', href: '/reportes/iva' }];

    return (
        <ReportShell title="VAT by rate" path="/reportes/iva" exportKey="iva" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    tax_type: row.tax_type === 'withholding' ? 'Withholding' : 'Transfer',
                    tax_rate: row.tax_rate === null ? '-' : formatPlainAmount(row.tax_rate, shared.currency),
                    base_amount: formatAmount(row.base_amount, shared.currency),
                    tax_amount: formatAmount(row.tax_amount, shared.currency),
                    documents_count: row.documents_count,
                }))}
                footer={{
                    tax_type: 'Total',
                    base_amount: formatAmount(totals.base_amount, shared.currency),
                    tax_amount: formatAmount(totals.tax_amount, shared.currency),
                    documents_count: totals.documents_count,
                }}
                empty="No receipt was issued in this period."
            />

            <p className="text-muted-foreground mt-4 text-sm">
                {without_document.sales_count} sale{without_document.sales_count === 1 ? '' : 's'} of this period
                {without_document.sales_count === 1 ? ' has' : ' have'} no issued receipt, and {without_document.sales_count === 1 ? 'is' : 'are'} not in this
                report: {formatAmount(without_document.amount, shared.currency)}.
            </p>
        </ReportShell>
    );
}
