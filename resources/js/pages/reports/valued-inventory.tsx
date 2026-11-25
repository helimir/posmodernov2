import { type BreadcrumbItem } from '@/types';

import ReportShell, { type ReportSharedProps } from '@/components/reports/report-shell';
import ReportTable, { type ReportColumn } from '@/components/reports/report-table';
import { formatAmount, formatPlainAmount } from '@/lib/pos';

/**
 * A LAYER of stock: a lot and what is left of it, at its own cost.
 */
interface InventoryLayer {
    lot_id: number;
    received_at: string;
    available: string;
    unit_cost: string;
    value: string;
}

/**
 * A row of the VALUED INVENTORY report, as the backend hands it over.
 */
interface InventoryRow {
    product_id: number;
    code: string;
    name: string;
    store_id: number;
    store_name: string;
    quantity: string;
    value: string;
    layers: InventoryLayer[];
}

interface InventoryTotals {
    quantity: string;
    value: string;
}

interface ValuedInventoryProps extends ReportSharedProps {
    rows: InventoryRow[];
    totals: InventoryTotals;
}

/**
 * The VALUED INVENTORY report: what is in the warehouse and what it is worth.
 *
 * It is a PHOTOGRAPH: the lots are the current ones, and there is no history
 * of valuations to look at.
 */
export default function ValuedInventoryReport({ rows, totals, ...shared }: ValuedInventoryProps) {
    const columns: ReportColumn[] = [
        { key: 'code', label: 'Code' },
        { key: 'name', label: 'Name' },
        { key: 'store_name', label: 'Store' },
        { key: 'quantity', label: 'Quantity', align: 'right' },
        { key: 'value', label: 'Value', align: 'right' },
        { key: 'layers', label: 'Layers (FIFO)' },
    ];

    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Valued inventory', href: '/reportes/inventario' }];

    return (
        <ReportShell title="Valued inventory" path="/reportes/inventario" exportKey="inventario" breadcrumbs={breadcrumbs} {...shared}>
            <ReportTable
                columns={columns}
                rows={rows.map((row) => ({
                    code: row.code,
                    name: row.name,
                    store_name: row.store_name,
                    quantity: formatPlainAmount(row.quantity, shared.currency),
                    value: formatAmount(row.value, shared.currency),
                    layers: row.layers
                        .map((layer) => `${layer.received_at}: ${formatPlainAmount(layer.available, shared.currency)} x ${formatAmount(layer.unit_cost, shared.currency)}`)
                        .join('; '),
                }))}
                footer={{
                    name: 'Total',
                    quantity: formatPlainAmount(totals.quantity, shared.currency),
                    value: formatAmount(totals.value, shared.currency),
                }}
                empty="There is no stock in this scope."
            />
        </ReportShell>
    );
}
