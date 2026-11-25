import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/api';
import { type FiscalStatus, fiscalLabel, fiscalVariant } from '@/lib/fiscal';
import { usePermissions } from '@/lib/permissions';
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

/**
 * The VOID of a sale: what left it out of the books.
 *
 * It is `null` in a sale that was never voided. When it is there, it says
 * WHEN it happened, WHY, which invoice lost its effect and which CREDIT NOTE
 * carries the correction.
 */
interface SaleVoid {
    reason: string | null;
    voided_at: string | null;
    document: { id: number; number: string; control_number: string | null } | null;
    note: { id: number; number: string; issue_date: string | null } | null;
}

/**
 * The REQUEST to void a sale: what the cashier asked for, and why.
 *
 * It is `null` when nobody asked for it. When it is there, its `status` says
 * where the request stands:
 *
 *  - `pending`: it waits for whoever may void the sales;
 *  - `rejected`: whoever may void said NO, and the rejection carries the
 *    reason, who said it and when;
 *  - `cancelled`: whoever asked for it took it back, with the reason;
 *  - `done`: the sale is voided, so there is nothing left to wait for.
 */
interface SaleVoidRequest {
    id: number;
    status: string;
    reason: string | null;
    requested_at: string | null;
    requested_by: { id: number; name: string } | null;
    rejected_at: string | null;
    rejected_by: { id: number; name: string } | null;
    rejection_reason: string | null;
    cancelled_at: string | null;
    cancelled_by: { id: number; name: string } | null;
    cancellation_reason: string | null;
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
    void: SaleVoid | null;
    void_request: SaleVoidRequest | null;
    /** EVERY request of this sale, from the newest to the oldest. */
    void_request_history: SaleVoidRequest[];
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
 * The LABEL of the state of a void request.
 *
 * The states are the ones of the domain (`pending`, `rejected`, `cancelled`
 * and `done`, which is not a state of the request but the voided sale).
 */
function requestStatusLabel(status: string): string {
    switch (status) {
        case 'pending':
            return 'Pending';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        case 'done':
            return 'Voided';
        default:
            return status;
    }
}

/**
 * The COLOR of the state of a void request.
 */
function requestStatusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'pending':
            return 'secondary';
        case 'rejected':
            return 'destructive';
        case 'done':
            return 'default';
        default:
            return 'outline';
    }
}

/**
 * The OUTCOME of a void request: what closed it.
 *
 * A pending request has no outcome yet: it is waiting.
 */
function requestOutcome(request: SaleVoidRequest): string {
    if (request.status === 'rejected') {
        return request.rejection_reason ?? 'Rejected';
    }

    if (request.status === 'cancelled') {
        return request.cancellation_reason ?? 'Cancelled';
    }

    if (request.status === 'done') {
        return 'The sale was voided';
    }

    return 'Waiting';
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
    const { can } = usePermissions();
    const [retrying, setRetrying] = useState(false);
    const [printing, setPrinting] = useState(false);
    const [fiscalError, setFiscalError] = useState<string | null>(null);
    const [voidOpen, setVoidOpen] = useState(false);
    const [voidReason, setVoidReason] = useState('');
    const [voiding, setVoiding] = useState(false);
    const [voidError, setVoidError] = useState<string | null>(null);
    const [requestOpen, setRequestOpen] = useState(false);
    const [requestReason, setRequestReason] = useState('');
    const [requesting, setRequesting] = useState(false);
    const [requestError, setRequestError] = useState<string | null>(null);
    const [rejectOpen, setRejectOpen] = useState(false);
    const [rejectReason, setRejectReason] = useState('');
    const [rejecting, setRejecting] = useState(false);
    const [rejectError, setRejectError] = useState<string | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const [cancelling, setCancelling] = useState(false);
    const [cancelError, setCancelError] = useState<string | null>(null);

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
     * The FISCAL STATUS of a VOIDED sale.
     *
     * The document of a voided sale is VOID: it was issued and it was
     * annulled, and saying "not issued" would say that it never existed.
     * The screen composes the two facts it has: the sale is cancelled and
     * the void block tells the rest.
     */
    const anulada = sale.void !== null;
    const etiquetaFiscal = anulada ? 'Voided' : fiscalLabel(sale.fiscal.status);
    const colorFiscal = anulada ? 'destructive' : fiscalVariant(sale.fiscal.status);

    /**
     * The PENDING request to void this sale.
     *
     * When it is there, the reason of the request is the reason of the void:
     * the cashier wrote why, and that is the explanation the void carries.
     */
    const solicitudPendiente = sale.void_request !== null && sale.void_request.status === 'pending' ? sale.void_request : null;

    /**
     * The REJECTED request: whoever may void said NO.
     *
     * It is the notification of the rejection: the cashier sees it here,
     * with the reason, who rejected it and when. A request that is closed in
     * silence is a request the cashier never learns about.
     */
    const solicitudRechazada = sale.void_request !== null && sale.void_request.status === 'rejected' ? sale.void_request : null;

    /**
     * The CANCELLED request: whoever asked for it took it back.
     */
    const solicitudCancelada = sale.void_request !== null && sale.void_request.status === 'cancelled' ? sale.void_request : null;

    /**
     * The USER of the session: the request is MINE only when I asked for it.
     *
     * Only the one who asked can take the request back: the permission opens
     * the door, the row says whose it is.
     */
    const { auth } = usePage<SharedData>().props;

    const usuarioActual = auth?.user?.id ?? null;

    const peticionEsMia =
        solicitudPendiente !== null &&
        solicitudPendiente.requested_by !== null &&
        solicitudPendiente.requested_by.id === usuarioActual;

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
     * The VOID of the sale.
     *
     * It needs a REASON: the server rejects the void without one, and the
     * reason is written in the audit trail.
     *
     * AND IT DOES NOT RETURN THE GOODS: the void is fiscal and accounting
     * only. The screen says it, because a void that looks like a return is
     * how the stock ends up wrong.
     */
    const voidSale = async () => {
        setVoiding(true);
        setVoidError(null);

        try {
            await postJson(`/ventas/${sale.id}/anulacion`, {
                reason: solicitudPendiente !== null ? solicitudPendiente.reason : voidReason,
            });

            router.reload();
        } catch (fallo) {
            setVoidError((fallo as Error).message);
        } finally {
            setVoiding(false);
        }
    };

    /**
     * The REQUEST to void this sale.
     *
     * It needs a REASON: the server rejects a request without one, and the
     * reason written is the one the void will carry.
     *
     * AND IT DOES NOT VOID ANYTHING: the sale stays in place until whoever
     * may void picks the request up.
     */
    const requestVoid = async () => {
        setRequesting(true);
        setRequestError(null);

        try {
            await postJson(`/ventas/${sale.id}/solicitud-anulacion`, { reason: requestReason });

            router.reload();
        } catch (fallo) {
            setRequestError((fallo as Error).message);
        } finally {
            setRequesting(false);
        }
    };

    /**
     * The REJECTION of the request: whoever may void says NO.
     *
     * It needs a REASON: the server rejects the rejection without one, and
     * the reason is what the cashier reads in his sale, with the name of
     * whoever wrote it.
     *
     * AND IT DOES NOT VOID ANYTHING: the sale stays in place. A rejection is
     * "I will not do it", and the void is "I did it": they are different
     * facts.
     */
    const rejectRequest = async () => {
        setRejecting(true);
        setRejectError(null);

        try {
            await postJson(`/ventas/${sale.id}/solicitud-anulacion/rechazo`, { reason: rejectReason });

            router.reload();
        } catch (fallo) {
            setRejectError((fallo as Error).message);
        } finally {
            setRejecting(false);
        }
    };

    /**
     * The CANCELLATION of the request: the one who asked takes it back.
     *
     * It needs a REASON: a request that is taken back without an explanation
     * does not say why it was taken back.
     *
     * AND IT DOES NOT VOID ANYTHING either: the sale stays in place.
     */
    const cancelRequest = async () => {
        setCancelling(true);
        setCancelError(null);

        try {
            await postJson(`/ventas/${sale.id}/solicitud-anulacion/cancelacion`, { reason: cancelReason });

            router.reload();
        } catch (fallo) {
            setCancelError((fallo as Error).message);
        } finally {
            setCancelling(false);
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

                        {/*
                          The VOID: it is a SUPERVISION action, so the button
                          only appears with its permission, and never in a
                          sale that is already voided.

                          AND THE REQUEST: the cashier that made the sale does
                          not undo it, they ASK for it with their reason, and
                          whoever may void picks the request up. That is why
                          the request has its own permission, and why the two
                          buttons are not the same thing.

                          AND THE THREE WAYS OUT OF A REQUEST: the person who
                          may void can REJECT it (with a reason), and the one
                          who asked can TAKE IT BACK (with a reason). A request
                          that could not be closed would wait forever.
                        */}
                        {!anulada && (
                            <div className="mt-4 flex flex-wrap items-center justify-end gap-2">
                                {can('sales.void') && (
                                    <Link
                                        href="/ventas/solicitudes-anulacion"
                                        className="text-sm underline underline-offset-4"
                                    >
                                        Pending void requests
                                    </Link>
                                )}

                                {can('sales.void_request') && solicitudPendiente === null && (
                                    <Button variant="outline" onClick={() => setRequestOpen(true)}>
                                        Request the void
                                    </Button>
                                )}

                                {can('sales.void') && solicitudPendiente !== null && (
                                    <Button variant="outline" onClick={() => setRejectOpen(true)}>
                                        Reject the request
                                    </Button>
                                )}

                                {can('sales.void_request') && peticionEsMia && (
                                    <Button variant="outline" onClick={() => setCancelOpen(true)}>
                                        Cancel the request
                                    </Button>
                                )}

                                {can('sales.void') && (
                                    <Button variant="destructive" onClick={() => setVoidOpen(true)}>
                                        {solicitudPendiente !== null ? 'Void the requested sale' : 'Void this sale'}
                                    </Button>
                                )}
                            </div>
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
                                    <Badge variant={colorFiscal}>{etiquetaFiscal}</Badge>
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

                            {sale.fiscal.can_retry && can('sales.fiscal_retry') && (
                                <Button onClick={retryIssuance} disabled={retrying}>
                                    {retrying ? 'Retrying…' : 'Retry issuance'}
                                </Button>
                            )}

                            {sale.fiscal.receipt_url &&
                                can('receipt.print') &&
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

                {/*
                  The VOID: the block that says this sale was annulled, WHY,
                  and which credit note carries the correction.

                  AND IT SAYS WHAT IT DID NOT DO: the goods are NOT back in
                  the warehouse. A void that looks like a return is how the
                  stock ends up wrong.
                */}
                {sale.void !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Voided sale</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <Field label="Voided at" value={sale.void.voided_at ?? '—'} />
                                <Field label="Reason" value={sale.void.reason ?? '—'} />
                                <Field label="Invoice" value={sale.void.document?.number ?? '—'} />
                                <Field
                                    label="Control number"
                                    value={sale.void.document?.control_number ?? '—'}
                                />
                                <Field label="Credit note" value={sale.void.note?.number ?? 'None'} />
                                <Field
                                    label="Credit note date"
                                    value={sale.void.note?.issue_date ?? '—'}
                                />
                            </dl>

                            <Alert variant="destructive" className="mt-4">
                                <AlertTitle>The goods were not returned to the stock</AlertTitle>
                                <AlertDescription>
                                    This void is fiscal and accounting only: the merchandise stays out of the
                                    warehouse and the money stays in the drawer. Returning the merchandise to the
                                    exact lot it came from is a separate operation.
                                </AlertDescription>
                            </Alert>

                            {/*
                              NO CREDIT NOTE: the sale had no issued document, so there was nothing to
                              annul before the authority. The screen says it, because a void without
                              a note looks like a void that forgot to issue it.
                            */}
                            {sale.void.note === null && (
                                <p className="text-muted-foreground mt-3 text-sm">
                                    No credit note: this sale had no issued document, so there was nothing to
                                    annul before the authority.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/*
                  The PENDING REQUEST: what the cashier asked for, and why.

                  AND IT SAYS WHO HAS TO ACT: the request is not an approval,
                  it is a queue entry. Whoever may void picks it up from the
                  sale and voids it there.
                */}
                {solicitudPendiente !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Void requested</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <Field label="Requested at" value={solicitudPendiente.requested_at ?? '—'} />
                                <Field
                                    label="Requested by"
                                    value={solicitudPendiente.requested_by?.name ?? '—'}
                                />
                                <Field label="Status" value="Pending" />
                            </dl>

                            <div className="mt-4">
                                <dt className="text-muted-foreground text-xs">Reason</dt>
                                <dd className="text-sm">{solicitudPendiente.reason ?? '—'}</dd>
                            </div>

                            <Alert className="mt-4">
                                <AlertTitle>This sale is not voided yet</AlertTitle>
                                <AlertDescription>
                                    The request is waiting for whoever may void the sales. Its reason is the reason
                                    the void will carry.
                                </AlertDescription>
                            </Alert>
                        </CardContent>
                    </Card>
                )}

                {/*
                  The REJECTED REQUEST: the notification of the rejection.

                  It is what the cashier sees when whoever may void said NO:
                  the reason of the request, the reason of the rejection, who
                  rejected it and when. A rejection that is not on the screen
                  is a rejection the cashier never learns about.
                */}
                {solicitudRechazada !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Void request rejected</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <Field label="Requested at" value={solicitudRechazada.requested_at ?? '—'} />
                                <Field
                                    label="Requested by"
                                    value={solicitudRechazada.requested_by?.name ?? '—'}
                                />
                                <Field label="Rejected at" value={solicitudRechazada.rejected_at ?? '—'} />
                                <Field
                                    label="Rejected by"
                                    value={solicitudRechazada.rejected_by?.name ?? '—'}
                                />
                            </dl>

                            <div className="mt-4">
                                <dt className="text-muted-foreground text-xs">Reason of the request</dt>
                                <dd className="text-sm">{solicitudRechazada.reason ?? '—'}</dd>
                            </div>

                            <div className="mt-4">
                                <dt className="text-muted-foreground text-xs">Reason of the rejection</dt>
                                <dd className="text-sm">{solicitudRechazada.rejection_reason ?? '—'}</dd>
                            </div>

                            <Alert className="mt-4">
                                <AlertTitle>This sale is not voided</AlertTitle>
                                <AlertDescription>
                                    The request was rejected, so the sale stays in place. A new request can be made
                                    with a new reason, and this one stays in the history of the sale.
                                </AlertDescription>
                            </Alert>
                        </CardContent>
                    </Card>
                )}

                {/*
                  The CANCELLED REQUEST: whoever asked for it took it back.

                  It says who and when, and why: a request that is taken back
                  without an explanation does not say why it was taken back.
                */}
                {solicitudCancelada !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Void request cancelled</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <Field label="Requested at" value={solicitudCancelada.requested_at ?? '—'} />
                                <Field
                                    label="Requested by"
                                    value={solicitudCancelada.requested_by?.name ?? '—'}
                                />
                                <Field label="Cancelled at" value={solicitudCancelada.cancelled_at ?? '—'} />
                                <Field
                                    label="Cancelled by"
                                    value={solicitudCancelada.cancelled_by?.name ?? '—'}
                                />
                            </dl>

                            <div className="mt-4">
                                <dt className="text-muted-foreground text-xs">Reason of the request</dt>
                                <dd className="text-sm">{solicitudCancelada.reason ?? '—'}</dd>
                            </div>

                            <div className="mt-4">
                                <dt className="text-muted-foreground text-xs">Reason of the cancellation</dt>
                                <dd className="text-sm">{solicitudCancelada.cancellation_reason ?? '—'}</dd>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/*
                  The HISTORY: EVERY request of this sale, from the newest to
                  the oldest.

                  A request that is replaced by another one does not
                  disappear: the sale keeps the story of what was asked, who
                  asked it and how it ended.
                */}
                {sale.void_request_history.length > 1 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Void request history</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-left font-medium">Requested at</th>
                                            <th className="px-2 py-2 text-left font-medium">Requested by</th>
                                            <th className="px-2 py-2 text-left font-medium">Reason</th>
                                            <th className="px-2 py-2 text-left font-medium">Outcome</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {sale.void_request_history.map((request) => (
                                            <tr key={request.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">
                                                    <Badge variant={requestStatusVariant(request.status)}>
                                                        {requestStatusLabel(request.status)}
                                                    </Badge>
                                                </td>
                                                <td className="px-2 py-2 whitespace-nowrap">
                                                    {request.requested_at ?? '—'}
                                                </td>
                                                <td className="px-2 py-2">{request.requested_by?.name ?? '—'}</td>
                                                <td className="px-2 py-2">{request.reason ?? '—'}</td>
                                                <td className="px-2 py-2">{requestOutcome(request)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

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

                {/*
                  The REQUEST DIALOG: the reason is REQUIRED, because the
                  reason written here is the reason the void will carry.
                */}
                <Dialog
                    open={requestOpen}
                    onOpenChange={(value) => {
                        setRequestOpen(value);

                        if (!value) {
                            setRequestReason('');
                            setRequestError(null);
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Request the void of this sale</DialogTitle>
                            <DialogDescription>
                                You are asking whoever may void the sales to undo this one. The reason is required,
                                and it is written in the audit trail with your name: it is the reason the void will
                                carry.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-2">
                            <Label htmlFor="void-request-reason">Reason</Label>
                            <textarea
                                id="void-request-reason"
                                rows={3}
                                value={requestReason}
                                onChange={(event) => setRequestReason(event.target.value)}
                                placeholder="Why is this sale being asked to be voided?"
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />

                            {requestError && <p className="text-destructive text-sm">{requestError}</p>}
                        </div>

                        <DialogFooter>
                            <Button variant="outline" onClick={() => setRequestOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                variant="outline"
                                onClick={requestVoid}
                                disabled={requesting || requestReason.trim() === ''}
                            >
                                {requesting ? 'Requesting…' : 'Request the void'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/*
                  The REJECTION DIALOG: the reason is REQUIRED, because the
                  cashier reads it in his sale, with the name of whoever wrote
                  it.

                  And it says what a rejection is NOT: the sale is not
                  voided.
                */}
                <Dialog
                    open={rejectOpen}
                    onOpenChange={(value) => {
                        setRejectOpen(value);

                        if (!value) {
                            setRejectReason('');
                            setRejectError(null);
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Reject the void request</DialogTitle>
                            <DialogDescription>
                                The request is closed with your reason, and the cashier sees it in the sale with your
                                name and the date. The sale is NOT voided: a rejection is "I will not do it", and the
                                void is a different operation.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-2">
                            <Label htmlFor="void-reject-reason">Reason of the rejection</Label>
                            <textarea
                                id="void-reject-reason"
                                rows={3}
                                value={rejectReason}
                                onChange={(event) => setRejectReason(event.target.value)}
                                placeholder="Why is this void request rejected?"
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />

                            {rejectError && <p className="text-destructive text-sm">{rejectError}</p>}
                        </div>

                        <DialogFooter>
                            <Button variant="outline" onClick={() => setRejectOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                variant="outline"
                                onClick={rejectRequest}
                                disabled={rejecting || rejectReason.trim() === ''}
                            >
                                {rejecting ? 'Rejecting…' : 'Reject the request'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/*
                  The CANCELLATION DIALOG: the reason is REQUIRED, because a
                  request that is taken back without an explanation does not
                  say why it was taken back.
                */}
                <Dialog
                    open={cancelOpen}
                    onOpenChange={(value) => {
                        setCancelOpen(value);

                        if (!value) {
                            setCancelReason('');
                            setCancelError(null);
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Cancel the void request</DialogTitle>
                            <DialogDescription>
                                You take your own request back, with your reason. The sale is NOT voided, and the
                                request stays in the history of the sale.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-2">
                            <Label htmlFor="void-cancel-reason">Reason of the cancellation</Label>
                            <textarea
                                id="void-cancel-reason"
                                rows={3}
                                value={cancelReason}
                                onChange={(event) => setCancelReason(event.target.value)}
                                placeholder="Why are you taking the request back?"
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />

                            {cancelError && <p className="text-destructive text-sm">{cancelError}</p>}
                        </div>

                        <DialogFooter>
                            <Button variant="outline" onClick={() => setCancelOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                variant="outline"
                                onClick={cancelRequest}
                                disabled={cancelling || cancelReason.trim() === ''}
                            >
                                {cancelling ? 'Cancelling…' : 'Cancel the request'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/*
                  The VOID DIALOG: the reason is REQUIRED, and the dialog says
                  what the void does NOT do.

                  The credit note takes a number of its own in the series of
                  the invoice, and the invoice is voided: the number that was
                  published is never published again.
                */}
                <Dialog
                    open={voidOpen}
                    onOpenChange={(value) => {
                        setVoidOpen(value);

                        if (!value) {
                            setVoidReason('');
                            setVoidError(null);
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Void this sale</DialogTitle>
                            <DialogDescription>
                                The invoice is voided and a credit note is issued with its own number in the same
                                series. The reason is required, and it is written in the audit trail with your name.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-2">
                            <Label htmlFor="void-reason">Reason</Label>
                            <textarea
                                id="void-reason"
                                rows={3}
                                value={solicitudPendiente !== null ? (solicitudPendiente.reason ?? '') : voidReason}
                                onChange={(event) => setVoidReason(event.target.value)}
                                disabled={solicitudPendiente !== null}
                                placeholder="Why is this sale being voided?"
                                className="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />

                            {/*
                              The REASON of a pending request is not rewritten:
                              it is the reason the cashier wrote, and it is the
                              reason the void carries.
                            */}
                            {solicitudPendiente !== null && (
                                <p className="text-muted-foreground text-sm">
                                    This is the reason of the pending request, written by{' '}
                                    {solicitudPendiente.requested_by?.name ?? 'the cashier'}. It is the reason the void
                                    carries.
                                </p>
                            )}

                            {voidError && <p className="text-destructive text-sm">{voidError}</p>}

                            <Alert variant="destructive">
                                <AlertTitle>The goods are not returned to the stock</AlertTitle>
                                <AlertDescription>
                                    This void is fiscal and accounting only. The merchandise stays out of the
                                    warehouse and the money stays in the drawer.
                                </AlertDescription>
                            </Alert>
                        </div>

                        <DialogFooter>
                            <Button variant="outline" onClick={() => setVoidOpen(false)}>
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={voidSale}
                                disabled={voiding || (solicitudPendiente === null && voidReason.trim() === '')}
                            >
                                {voiding ? 'Voiding…' : 'Void the sale'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
