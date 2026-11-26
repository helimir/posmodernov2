import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/api';
import { type PosCurrency, formatAmount } from '@/lib/pos';
import { usePermissions } from '@/lib/permissions';
import { Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * A QUOTA of a plan, as the backend hands it over.
 *
 * The amounts are DECIMAL STRINGS: the columns are `numeric(25,4)` and a
 * `float` is not money.
 */
export interface InstallmentQuota {
    id: number;
    number: number;
    due_date: string;
    amount: string;
    paid_amount: string;
    status: 'pending' | 'paid' | 'overdue';
    /** Whether the quota is past its due date and still not collected. */
    overdue: boolean;
}

/**
 * A PLAN of the store: the calendar of a credit sale.
 */
export interface InstallmentPlan {
    id: number;
    sale_id: number | null;
    invoice_no: string | null;
    reference_no: string | null;
    customer: { id: number; name: string };
    principal: string;
    interest_percentage: string;
    installment_count: number;
    date: string;
    status: 'draft' | 'active' | 'paid' | 'cancelled';
    total: string;
    paid_total: string;
    pending_total: string;
    quotas: InstallmentQuota[];
}

/**
 * A credit sale of the store that has no plan yet: the one to finance.
 */
export interface CreditSaleOption {
    id: number;
    invoice_no: string | null;
    sale_date: string;
    customer_name: string;
    total: string;
    pending: string;
}

export interface InstallmentsProps {
    plans: InstallmentPlan[];
    sales: CreditSaleOption[];
    currency: PosCurrency;
    business_date: string;
    store: { id: number; name: string; code_name: string | null };
}

/**
 * The INSTALLMENT PLANS of the store: the calendars of the credit sales, with
 * the state of every quota and the button that collects it.
 */
export default function Installments({ plans, sales, currency, business_date, store }: InstallmentsProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [creating, setCreating] = useState(false);
    const [collecting, setCollecting] = useState<InstallmentQuota | null>(null);
    const [saleId, setSaleId] = useState('');
    const [interest, setInterest] = useState('0.0000');
    const [count, setCount] = useState('3');
    const [firstDueDate, setFirstDueDate] = useState('');
    const [referenceNo, setReferenceNo] = useState('');

    /**
     * The PLAN: the server calculates the interest and the calendar. The
     * screen sends the terms and does not repeat the arithmetic.
     */
    const createPlan = async () => {
        setError(null);
        setSaving(true);

        try {
            await postJson('/api/cuotas', {
                sale_id: Number(saleId),
                interest_percentage: interest,
                count: Number(count),
                first_due_date: firstDueDate,
                reference_no: referenceNo === '' ? null : referenceNo,
            });

            setCreating(false);
            setSaleId('');
            setInterest('0.0000');
            setCount('3');
            setFirstDueDate('');
            setReferenceNo('');
            router.reload({ only: ['plans', 'sales'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The plan could not be created.');
        } finally {
            setSaving(false);
        }
    };

    /**
     * The COLLECTION of one quota: the money enters the cash and the quota is
     * paid. The server writes the movement of the customer book and the
     * ledger entry in ONE transaction.
     */
    const collectQuota = async () => {
        if (collecting === null) {
            return;
        }

        setError(null);
        setSaving(true);

        try {
            await postJson(`/api/cuotas/${collecting.id}/cobro`, {});

            setCollecting(null);
            router.reload({ only: ['plans'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The quota could not be collected.');
        } finally {
            setSaving(false);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Installments',
            href: '/cuotas',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Installments" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {error !== null && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardDescription className="text-destructive text-sm">{error}</CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <Card>
                    <CardHeader className="flex-row items-start justify-between gap-3 space-y-0">
                        <div>
                            <CardTitle className="text-base">
                                {plans.length} {plans.length === 1 ? 'plan' : 'plans'}
                                <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                Business date {business_date}. A plan is the calendar of a credit sale: the interest is
                                calculated in the server and the last quota absorbs the rounding difference, so the
                                quotas add up to the total EXACTLY. Collecting a quota is a collection of the customer
                                book: the money enters the cash and the balance of the customer goes down.
                            </CardDescription>
                        </div>

                        {can('installment.create') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setSaleId('');
                                    setInterest('0.0000');
                                    setCount('3');
                                    setFirstDueDate('');
                                    setReferenceNo('');
                                    setCreating(true);
                                }}
                            >
                                <Plus />
                                New plan
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {plans.length === 0 ? (
                            <p className="text-muted-foreground text-sm">There is no installment plan yet.</p>
                        ) : (
                            <div className="flex flex-col gap-4">
                                {plans.map((plan) => (
                                    <div key={plan.id} className="rounded-lg border">
                                        <div className="flex flex-wrap items-center justify-between gap-2 border-b p-3">
                                            <div className="flex flex-col gap-1">
                                                <span className="text-sm font-medium">
                                                    {plan.customer.name} · {plan.invoice_no ?? `#${plan.sale_id ?? plan.id}`}
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    {plan.installment_count} quotas from {plan.date} · principal{' '}
                                                    {formatAmount(plan.principal, currency)} · interest{' '}
                                                    {plan.interest_percentage} % · total{' '}
                                                    {formatAmount(plan.total, currency)}
                                                </span>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <Badge variant="secondary">
                                                    {formatAmount(plan.paid_total, currency)} collected
                                                </Badge>
                                                <Badge variant={plan.status === 'paid' ? 'default' : 'outline'}>
                                                    {plan.status}
                                                </Badge>
                                            </div>
                                        </div>

                                        <div className="overflow-x-auto">
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="border-b">
                                                        <th className="px-2 py-2 text-left font-medium">Quota</th>
                                                        <th className="px-2 py-2 text-left font-medium">Due date</th>
                                                        <th className="px-2 py-2 text-right font-medium">Amount</th>
                                                        <th className="px-2 py-2 text-right font-medium">Collected</th>
                                                        <th className="px-2 py-2 text-left font-medium">State</th>
                                                        <th className="px-2 py-2" />
                                                    </tr>
                                                </thead>

                                                <tbody>
                                                    {plan.quotas.map((quota) => (
                                                        <tr key={quota.id} className="border-b last:border-0">
                                                            <td className="px-2 py-2 tabular-nums">{quota.number}</td>
                                                            <td className="px-2 py-2 tabular-nums">{quota.due_date}</td>
                                                            <td className="px-2 py-2 text-right tabular-nums">
                                                                {formatAmount(quota.amount, currency)}
                                                            </td>
                                                            <td className="px-2 py-2 text-right tabular-nums">
                                                                {formatAmount(quota.paid_amount, currency)}
                                                            </td>
                                                            <td className="px-2 py-2">
                                                                <Badge variant={quota.status === 'paid' ? 'default' : 'secondary'}>
                                                                    {quota.overdue ? 'overdue' : quota.status}
                                                                </Badge>
                                                            </td>
                                                            <td className="px-2 py-2 text-right">
                                                                {quota.status !== 'paid' && can('installment.collect') && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        onClick={() => {
                                                                            setError(null);
                                                                            setCollecting(quota);
                                                                        }}
                                                                    >
                                                                        Collect
                                                                    </Button>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* El PLAN: la venta a credito y las condiciones del acuerdo. */}
            <Dialog
                open={creating}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreating(false);
                    }
                }}
            >
                <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>New installment plan</DialogTitle>
                        <DialogDescription>
                            The plan is the calendar of a credit sale. The interest is simple, it is calculated in the
                            server, and the last quota absorbs the rounding difference. The interest is debt of the
                            customer and income of the store.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="plan-sale">Credit sale</Label>
                            <Select value={saleId} onValueChange={setSaleId}>
                                <SelectTrigger id="plan-sale" aria-label="Credit sale of the plan">
                                    <SelectValue placeholder="Sale" />
                                </SelectTrigger>
                                <SelectContent>
                                    {sales.map((venta) => (
                                        <SelectItem key={venta.id} value={venta.id.toString()}>
                                            {venta.invoice_no ?? `#${venta.id}`} · {venta.customer_name} ·{' '}
                                            {formatAmount(venta.pending, currency)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="plan-interest">Interest percentage</Label>
                            <Input
                                id="plan-interest"
                                value={interest}
                                onChange={(event) => setInterest(event.target.value)}
                                placeholder="0.0000"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="plan-count">Number of quotas</Label>
                            <Input
                                id="plan-count"
                                value={count}
                                onChange={(event) => setCount(event.target.value)}
                                placeholder="3"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="plan-due-date">First due date</Label>
                            <Input
                                id="plan-due-date"
                                value={firstDueDate}
                                onChange={(event) => setFirstDueDate(event.target.value)}
                                placeholder="2026-12-01"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="plan-reference">Reference</Label>
                            <Input
                                id="plan-reference"
                                value={referenceNo}
                                onChange={(event) => setReferenceNo(event.target.value)}
                                placeholder="Optional"
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={createPlan}>
                            Agree the plan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* El COBRO de una cuota: el dinero entra a la caja. */}
            <Dialog
                open={collecting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setCollecting(null);
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Collect the quota</DialogTitle>
                        <DialogDescription>
                            The quota is collected in cash: the money enters the cash of the day and the balance of the
                            customer goes down. The movement of the customer book and the ledger entry are written
                            together, or neither is written.
                        </DialogDescription>
                    </DialogHeader>

                    {collecting !== null && (
                        <div className="grid gap-1 text-sm">
                            <span className="font-medium">
                                Quota {collecting.number} · {formatAmount(collecting.amount, currency)}
                            </span>
                            <span className="text-muted-foreground">Due on {collecting.due_date}</span>
                        </div>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCollecting(null)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={collectQuota}>
                            Collect
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
