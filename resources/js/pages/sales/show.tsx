import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/api';
import { type FiscalStatus, fiscalLabel, fiscalVariant } from '@/lib/fiscal';
import { useState } from 'react';

/**
 * A print target of the store: a printer that writes the bytes.
 */
interface PrintTarget {
    id: number;
    name: string;
    transport: string;
}

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

/**
 * A payment of the sale, with its THREE figures.
 *
 * `received` is what the customer handed over, `applied` is what the payment
 * COVERED of the sale (the truth) and `change` is what went back.
 */
interface SalePayment {
    id: number;
    payment_method_id: number | null;
    payment_method: string | null;
    type: string;
    received: string;
    applied: string;
    change: string;
}

/**
 * The SUMMARY of the payments of the sale: the sums and the GAP.
 *
 * `difference` is `applied_total` minus the total of the sale, WITH its sign.
 */
interface SalePaymentSummary {
    received_total: string;
    applied_total: string;
    change_total: string;
    total: string | null;
    difference: string | null;
    matches_total: boolean | null;
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
    fiscal: FiscalStatus;
    print_targets: PrintTarget[];
    lines: SaleLine[];
    totals: SaleTotals | null;
    payments: SalePayment[];
    payment_summary: SalePaymentSummary;
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
    const [retrying, setRetrying] = useState(false);
    const [printing, setPrinting] = useState(false);
    const [fiscalError, setFiscalError] = useState<string | null>(null);

    /**
     * The GAP between what the payments APPLIED and the total of the sale.
     *
     * It carries its sign: a sale that adds up has no gap, and a number
     * without a sign would hide which side the hole is on.
     */
    const descuadre = sale.payment_summary.difference;
    const sobra = descuadre !== null && !descuadre.startsWith('-');
    const absoluto = descuadre === null ? null : descuadre.replace('-', '');

    /**
     * The RETRY of the fiscal issuance.
     *
     * It REUSES the reservation and does not burn numbers: a retry that
     * fails leaves the sale alone, and the screen says why.
     */
    const retryIssuance = async () => {
        setRetrying(true);
        setFiscalError(null);

        try {
            await postJson(`/ventas/${sale.id}/emision`, {});

            router.reload();
        } catch (fallo) {
            setFiscalError((fallo as Error).message);
        } finally {
            setRetrying(false);
        }
    };

    /**
     * The PRINT of the receipt: the ticket goes to the queue of the target,
     * and the agent LAN writes the bytes.
     */
    const printReceipt = async (targetId: number) => {
        setPrinting(true);
        setFiscalError(null);

        try {
            await postJson(`/comprobantes/${sale.fiscal.document_id}/imprimir`, {
                print_target_id: targetId,
            });
        } catch (fallo) {
            setFiscalError((fallo as Error).message);
        } finally {
            setPrinting(false);
        }
    };

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

                        {/*
                          The WARNING: the applied payments do not add up to the
                          total of the sale. The hole is a fact, and the screen
                          says it WITH the figures.
                        */}
                        {sale.payment_summary.matches_total === false && (
                            <Alert variant="destructive" className="mt-4">
                                <AlertTitle>Payments do not match the sale total</AlertTitle>
                                <AlertDescription>
                                    Applied {sale.payment_summary.applied_total} against a sale total of{' '}
                                    {sale.payment_summary.total}
                                    {absoluto !== null && (
                                        <> — {sobra ? 'over by' : 'short by'} {absoluto}.</>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Fiscal document</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground text-xs">Fiscal status</dt>
                                <dd className="text-sm">
                                    <Badge variant={fiscalVariant(sale.fiscal.status)}>
                                        {fiscalLabel(sale.fiscal.status)}
                                    </Badge>
                                </dd>
                            </div>

                            <Field label="Control number" value={sale.fiscal.control_number ?? '—'} />

                            <Field
                                label="Document"
                                value={sale.fiscal.document_id !== null ? String(sale.fiscal.document_id) : '—'}
                            />
                        </dl>

                        {sale.fiscal.message && (
                            <p className="text-destructive mt-3 text-sm">{sale.fiscal.message}</p>
                        )}

                        {fiscalError && <p className="text-destructive mt-3 text-sm">{fiscalError}</p>}

                        <div className="mt-4 flex flex-wrap gap-2">
                            {sale.fiscal.receipt_url && (
                                <Button asChild variant="outline">
                                    <a href={sale.fiscal.receipt_url} target="_blank" rel="noreferrer">
                                        View receipt
                                    </a>
                                </Button>
                            )}

                            {sale.fiscal.can_retry && (
                                <Button onClick={retryIssuance} disabled={retrying}>
                                    {retrying ? 'Retrying…' : 'Retry issuance'}
                                </Button>
                            )}

                            {sale.fiscal.receipt_url &&
                                sale.print_targets.map((target) => (
                                    <Button
                                        key={target.id}
                                        variant="outline"
                                        onClick={() => printReceipt(target.id)}
                                        disabled={printing}
                                    >
                                        {printing ? 'Queueing…' : `Print to ${target.name}`}
                                    </Button>
                                ))}
                        </div>
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
                                    <div className="flex justify-between">
                                        <dt>Applied to the sale</dt>
                                        <dd className="tabular-nums">{sale.payment_summary.applied_total}</dd>
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
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b">
                                                <th className="px-2 py-2 text-left font-medium">Payment method</th>
                                                <th className="px-2 py-2 text-left font-medium">Type</th>
                                                <th className="px-2 py-2 text-right font-medium">Received</th>
                                                <th className="px-2 py-2 text-right font-medium">Applied</th>
                                                <th className="px-2 py-2 text-right font-medium">Change</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {sale.payments.map((payment) => (
                                                <tr key={payment.id} className="border-b last:border-0">
                                                    <td className="px-2 py-2">
                                                        <span className="block">{payment.payment_method ?? 'Payment'}</span>
                                                        {payment.type === 'credit' && (
                                                            <span className="text-muted-foreground block text-xs">
                                                                Credit is not money in the drawer
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-2 py-2">{payment.type}</td>
                                                    <td className="px-2 py-2 text-right tabular-nums">
                                                        {payment.received}
                                                    </td>
                                                    <td className="px-2 py-2 text-right font-medium tabular-nums">
                                                        {payment.applied}
                                                    </td>
                                                    <td className="px-2 py-2 text-right tabular-nums">
                                                        {payment.change === '0.0000' ? '—' : payment.change}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>

                                        <tfoot>
                                            <tr className="border-t">
                                                <td className="px-2 py-2 font-medium" colSpan={2}>
                                                    Totals
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {sale.payment_summary.received_total}
                                                </td>
                                                <td className="px-2 py-2 text-right font-semibold tabular-nums">
                                                    {sale.payment_summary.applied_total}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {sale.payment_summary.change_total}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
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
