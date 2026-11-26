import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type PosCurrency, formatAmount } from '@/lib/pos';

import type { InstallmentPlan } from './installments';

/**
 * The AGING of the customer in the store, as the report that already exists
 * hands it over.
 *
 * The screen does NOT rebuild the aging: it asks for the row of the customer
 * and shows it. An aging per screen is two truths.
 */
export interface CustomerAging {
    balance: string;
    due: string;
    credit: string;
    oldest_date: string | null;
    age_days: number | null;
}

export interface CustomerInstallmentsProps {
    customer: { id: number; name: string; tax_id: string | null };
    plans: InstallmentPlan[];
    aging: CustomerAging | null;
    currency: PosCurrency;
    business_date: string;
    store: { id: number; name: string; code_name: string | null };
}

/**
 * The QUOTAS of a customer: the extract of their plans.
 */
export default function CustomerInstallments({ customer, plans, aging, currency, business_date, store }: CustomerInstallmentsProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Customers',
            href: '/clientes',
        },
        {
            title: 'Installments',
            href: `/clientes/${customer.id}/cuotas`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Installments" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {customer.name}
                            <span className="text-muted-foreground ml-2 text-sm font-normal">
                                {customer.tax_id ?? '—'} · {store.name}
                            </span>
                        </CardTitle>
                        <CardDescription className="mt-1.5">
                            Business date {business_date}. This is the extract of the plans of the customer: the quotas
                            and their state. The debt comes from the customer aging that already exists, and the quota
                            is collected from the plans screen.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-4">
                            <div className="rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">Balance</span>
                                <p className="text-sm font-medium tabular-nums">
                                    {aging ? formatAmount(aging.balance, currency) : formatAmount('0', currency)}
                                </p>
                            </div>

                            <div className="rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">Due</span>
                                <p className="text-sm font-medium tabular-nums">
                                    {aging ? formatAmount(aging.due, currency) : formatAmount('0', currency)}
                                </p>
                            </div>

                            <div className="rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">Credit in favour</span>
                                <p className="text-sm font-medium tabular-nums">
                                    {aging ? formatAmount(aging.credit, currency) : formatAmount('0', currency)}
                                </p>
                            </div>

                            <div className="rounded-lg border p-3">
                                <span className="text-muted-foreground text-xs">Oldest debt</span>
                                <p className="text-sm font-medium tabular-nums">
                                    {aging?.oldest_date ?? '—'}
                                    {aging?.age_days !== null && aging?.age_days !== undefined
                                        ? ` · ${aging.age_days} days`
                                        : ''}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {plans.length === 0 ? (
                    <Card>
                        <CardContent>
                            <p className="text-muted-foreground text-sm">This customer has no installment plan.</p>
                        </CardContent>
                    </Card>
                ) : (
                    plans.map((plan) => (
                        <Card key={plan.id}>
                            <CardHeader className="flex-row items-start justify-between gap-3 space-y-0">
                                <div>
                                    <CardTitle className="text-base">
                                        {plan.invoice_no ?? `#${plan.sale_id ?? plan.id}`}
                                        <span className="text-muted-foreground ml-2 text-sm font-normal">
                                            {plan.installment_count} quotas from {plan.date}
                                        </span>
                                    </CardTitle>
                                    <CardDescription className="mt-1.5">
                                        Principal {formatAmount(plan.principal, currency)} · interest{' '}
                                        {plan.interest_percentage} % · total {formatAmount(plan.total, currency)} ·{' '}
                                        {formatAmount(plan.paid_total, currency)} collected
                                    </CardDescription>
                                </div>

                                <Badge variant={plan.status === 'paid' ? 'default' : 'outline'}>{plan.status}</Badge>
                            </CardHeader>

                            <CardContent>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b">
                                                <th className="px-2 py-2 text-left font-medium">Quota</th>
                                                <th className="px-2 py-2 text-left font-medium">Due date</th>
                                                <th className="px-2 py-2 text-right font-medium">Amount</th>
                                                <th className="px-2 py-2 text-right font-medium">Collected</th>
                                                <th className="px-2 py-2 text-left font-medium">State</th>
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
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>
        </AppLayout>
    );
}
