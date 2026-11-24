import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

/**
 * A PENDING request to void a sale, as the backend hands it over.
 *
 * It carries the reason the CASHIER wrote, who wrote it and when. The reason
 * is the one the void will carry: it is the explanation of why the sale has
 * to go, and it does not get rewritten.
 */
export interface VoidRequestRow {
    sale_id: number;
    invoice_no: string | null;
    sale_date: string | null;
    customer: string | null;
    grand_total: string | null;
    reason: string | null;
    requested_at: string | null;
    requested_by: { id: number; name: string } | null;
}

export interface VoidRequestsProps {
    requests: VoidRequestRow[];
    store: {
        id: number;
        name: string;
        code_name: string | null;
    };
}

/**
 * The PENDING requests to void a sale: the work queue of whoever may void.
 *
 * The cashier that made the sale did NOT make it: they left the request with
 * their reason, and this is where it is picked up.
 *
 * AND THE SCREEN DOES NOT VOID ANYTHING: voiding is a fiscal operation (it
 * issues a credit note and consumes a number of the series), and it lives in
 * the sale, with the sale. What this screen does is to say WHICH sales are
 * waiting and WHY.
 *
 * AND ONLY THE PENDING ONES: a sale that is already voided is not waiting
 * for anything.
 */
export default function VoidRequests({ requests, store }: VoidRequestsProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Void requests',
            href: '/ventas/solicitudes-anulacion',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Void requests" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Pending void requests of {store.name}</CardTitle>
                        <CardDescription className="mt-1.5">
                            The sales that the cashier asked to void, with the reason they wrote. The void itself is
                            done from the sale, with its credit note and its number in the series.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        {requests.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no pending void request in this store.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Sale</th>
                                            <th className="px-2 py-2 text-left font-medium">Date</th>
                                            <th className="px-2 py-2 text-left font-medium">Customer</th>
                                            <th className="px-2 py-2 text-right font-medium">Total</th>
                                            <th className="px-2 py-2 text-left font-medium">Requested by</th>
                                            <th className="px-2 py-2 text-left font-medium">Requested at</th>
                                            <th className="px-2 py-2 text-left font-medium">Reason</th>
                                            <th className="px-2 py-2 text-right font-medium">Sale</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {requests.map((request) => (
                                            <tr key={request.sale_id} className="border-b last:border-0">
                                                <td className="px-2 py-2 font-mono">
                                                    {request.invoice_no ?? `#${request.sale_id}`}
                                                </td>
                                                <td className="px-2 py-2 whitespace-nowrap">{request.sale_date ?? '—'}</td>
                                                <td className="px-2 py-2">{request.customer ?? 'Walk-in customer'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {request.grand_total ?? '—'}
                                                </td>
                                                <td className="px-2 py-2">{request.requested_by?.name ?? '—'}</td>
                                                <td className="px-2 py-2 whitespace-nowrap">{request.requested_at ?? '—'}</td>
                                                <td className="px-2 py-2">{request.reason ?? '—'}</td>
                                                <td className="px-2 py-2 text-right">
                                                    <Button asChild variant="outline" size="sm">
                                                        <Link href={`/ventas/${request.sale_id}`}>Open</Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">What a void does</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <ul className="text-muted-foreground list-disc space-y-1 pl-5 text-sm">
                            <li>
                                The invoice is voided and a credit note is issued with its own number in the same series.
                            </li>
                            <li>The merchandise is NOT returned to the stock: that is another operation.</li>
                            <li>The money stays in the drawer: a voided sale is still cash of the day.</li>
                            <li>
                                A sale of a CLOSED business day cannot be voided: the day has to be reopened first, with its
                                own reason.
                            </li>
                        </ul>

                        <Badge variant="outline" className="mt-3">
                            The reason of the request is the reason of the void
                        </Badge>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
