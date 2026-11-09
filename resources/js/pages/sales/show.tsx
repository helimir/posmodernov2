import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

interface SaleLine {
    id: number;
    product_name: string;
    product_code: string | null;
    quantity: string;
    unit_price: string;
    discount: string;
    base_amount: string;
    tax_rate_snapshot: string | null;
    evidence_status: string | null;
    tax_amount: string;
    line_total: string;
}

interface SaleTotals {
    sub_total: string;
    discount: string;
    tax_total: string;
    rounding_adjustment: string;
    grand_total: string;
    evidence_status: string | null;
}

interface SalePayment {
    id: number;
    payment_method: string | null;
    amount: string;
}

interface LedgerLine {
    account_code: string;
    debit: string;
    credit: string;
}

interface LedgerEntry {
    id: number;
    entry_type: string;
    business_date: string | null;
    amount: string;
    description: string | null;
    lines: LedgerLine[];
}

interface SaleDetail {
    id: number;
    invoice_no: string | null;
    sale_date: string | null;
    status: string;
    customer: string | null;
    store: string | null;
    created_at: string | null;
    lines: SaleLine[];
    totals: SaleTotals | null;
    payments: SalePayment[];
    ledger: LedgerEntry | null;
}

/**
 * El color del estado de la venta.
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

/**
 * Una fila de la tabla: la etiqueta y su valor.
 */
function Field({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm tabular-nums">{value}</dd>
        </div>
    );
}

export default function SaleShow({ sale }: { sale: SaleDetail }) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Sales',
            href: '/ventas',
        },
        {
            title: sale.invoice_no ?? `Sale #${sale.id}`,
            href: `/ventas/${sale.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={sale.invoice_no ?? `Sale #${sale.id}`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-3">
                            {sale.invoice_no ?? `Sale #${sale.id}`}
                            <Badge variant={statusVariant(sale.status)}>{sale.status}</Badge>
                        </CardTitle>
                    </CardHeader>

                    <CardContent>
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Field label="Business date" value={sale.sale_date ?? '—'} />
                            <Field label="Customer" value={sale.customer ?? 'Walk-in customer'} />
                            <Field label="Store" value={sale.store ?? '—'} />
                            <Field label="Created at" value={sale.created_at ?? '—'} />
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Lines</CardTitle>
                    </CardHeader>

                    <CardContent>
                        {sale.lines.length === 0 ? (
                            <p className="text-muted-foreground text-sm">This sale has no lines.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Product</th>
                                            <th className="px-2 py-2 text-right font-medium">Quantity</th>
                                            <th className="px-2 py-2 text-right font-medium">Unit price</th>
                                            <th className="px-2 py-2 text-right font-medium">Taxable base</th>
                                            <th className="px-2 py-2 text-right font-medium">Tax rate</th>
                                            <th className="px-2 py-2 text-right font-medium">Tax</th>
                                            <th className="px-2 py-2 text-right font-medium">Line total</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {sale.lines.map((line) => (
                                            <tr key={line.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">
                                                    <span className="block">{line.product_name}</span>
                                                    {line.product_code && (
                                                        <span className="text-muted-foreground text-xs">{line.product_code}</span>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">{line.quantity}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">{line.unit_price}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">{line.base_amount}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {line.tax_rate_snapshot === null ? '—' : `${line.tax_rate_snapshot}%`}
                                                    {line.evidence_status && (
                                                        <span className="text-muted-foreground block text-xs">{line.evidence_status}</span>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">{line.tax_amount}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">{line.line_total}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Totals</CardTitle>
                        </CardHeader>

                        <CardContent>
                            {sale.totals === null ? (
                                <p className="text-muted-foreground text-sm">This sale has no totals.</p>
                            ) : (
                                <dl className="grid gap-3 text-sm">
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">Subtotal</dt>
                                        <dd className="tabular-nums">{sale.totals.sub_total}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">Discount</dt>
                                        <dd className="tabular-nums">{sale.totals.discount}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">Tax</dt>
                                        <dd className="tabular-nums">{sale.totals.tax_total}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">Rounding adjustment</dt>
                                        <dd className="tabular-nums">{sale.totals.rounding_adjustment}</dd>
                                    </div>
                                    <div className="flex justify-between border-t pt-3 font-medium">
                                        <dt>Total</dt>
                                        <dd className="tabular-nums">{sale.totals.grand_total}</dd>
                                    </div>
                                    {sale.totals.evidence_status && (
                                        <div className="flex justify-between">
                                            <dt className="text-muted-foreground">Evidence</dt>
                                            <dd>{sale.totals.evidence_status}</dd>
                                        </div>
                                    )}
                                </dl>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Payments</CardTitle>
                        </CardHeader>

                        <CardContent>
                            {sale.payments.length === 0 ? (
                                <p className="text-muted-foreground text-sm">This sale has no payments.</p>
                            ) : (
                                <dl className="grid gap-3 text-sm">
                                    {sale.payments.map((payment) => (
                                        <div key={payment.id} className="flex justify-between">
                                            <dt className="text-muted-foreground">{payment.payment_method ?? 'Payment'}</dt>
                                            <dd className="tabular-nums">{payment.amount}</dd>
                                        </div>
                                    ))}
                                </dl>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Ledger entry</CardTitle>
                    </CardHeader>

                    <CardContent>
                        {sale.ledger === null ? (
                            <p className="text-muted-foreground text-sm">This sale has no ledger entry.</p>
                        ) : (
                            <div className="space-y-3">
                                <dl className="grid gap-4 sm:grid-cols-3">
                                    <Field label="Type" value={sale.ledger.entry_type} />
                                    <Field label="Business date" value={sale.ledger.business_date ?? '—'} />
                                    <Field label="Amount" value={sale.ledger.amount} />
                                </dl>

                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b">
                                                <th className="px-2 py-2 text-left font-medium">Account</th>
                                                <th className="px-2 py-2 text-right font-medium">Debit</th>
                                                <th className="px-2 py-2 text-right font-medium">Credit</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {sale.ledger.lines.map((line, index) => (
                                                <tr key={`${line.account_code}-${index}`} className="border-b last:border-0">
                                                    <td className="px-2 py-2">{line.account_code}</td>
                                                    <td className="px-2 py-2 text-right tabular-nums">{line.debit}</td>
                                                    <td className="px-2 py-2 text-right tabular-nums">{line.credit}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <p className="text-muted-foreground text-sm">
                    <Link href="/ventas" className="underline underline-offset-4">
                        Back to sales
                    </Link>
                </p>
            </div>
        </AppLayout>
    );
}
