import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Sales',
        href: '/ventas',
    },
];

interface SaleSummary {
    id: number;
    invoice_no: string | null;
    sale_date: string | null;
    customer: string | null;
    status: string;
    grand_total: string | null;
    items_count: number;
}

/**
 * El color del estado de la venta.
 *
 * Los estados son los del dominio (`paid`, `confirmed`, `cancelled`,
 * `fiscal_failed`, `draft`): la pagina los enseña como son, sin traducirlos
 * a un estado propio.
 */
function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'paid':
            return 'default';
        case 'confirmed':
            return 'secondary';
        case 'cancelled':
        case 'fiscal_failed':
            return 'destructive';
        default:
            return 'outline';
    }
}

export default function SalesIndex({ sales }: { sales: SaleSummary[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Sales</CardTitle>
                    </CardHeader>

                    <CardContent>
                        {sales.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There are no sales yet. Run <code>php artisan ops:smoke-sale</code> to make one.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Number</th>
                                            <th className="px-2 py-2 text-left font-medium">Business date</th>
                                            <th className="px-2 py-2 text-left font-medium">Customer</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-right font-medium">Lines</th>
                                            <th className="px-2 py-2 text-right font-medium">Total</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {sales.map((sale) => (
                                            <tr key={sale.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">
                                                    <Link
                                                        href={`/ventas/${sale.id}`}
                                                        className="underline underline-offset-4"
                                                    >
                                                        {sale.invoice_no ?? `#${sale.id}`}
                                                    </Link>
                                                </td>
                                                <td className="px-2 py-2 tabular-nums">{sale.sale_date ?? '—'}</td>
                                                <td className="px-2 py-2">{sale.customer ?? 'Walk-in customer'}</td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={statusVariant(sale.status)}>{sale.status}</Badge>
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">{sale.items_count}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">{sale.grand_total ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
