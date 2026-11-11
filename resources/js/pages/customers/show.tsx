import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/api';
import { type PosCurrency, formatAmount } from '@/lib/pos';
import { AlertTriangle, ArrowLeft, Pencil } from 'lucide-react';
import { useState, type FormEvent } from 'react';

/**
 * The customer of the sheet, as the backend hands it over.
 */
export interface CustomerSheet {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    mobile: string | null;
    address: string | null;
    city: string | null;
    zip_code: string | null;
    customer_state: string | null;
    customer_country: number | null;
    tax_id: string | null;
    is_active: boolean;
    credit_limit: string | null;
}

/**
 * A movement of the book of the customer.
 */
export interface CustomerEntry {
    id: number;
    type: string;
    reference_no: string | null;
    amount: string;
    balance_after: string | null;
    entry_date: string;
    description: string | null;
}

export interface CustomerPagination {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface CustomersShowProps {
    customer: CustomerSheet;
    balance: string;
    due: string;
    credit_limit: string | null;
    cache: { balance: string; due: string } | null;
    cache_matches: boolean;
    over_limit: boolean;
    entries: CustomerEntry[];
    pagination: CustomerPagination;
    store: { id: number; name: string; code_name: string | null };
    currency: PosCurrency;
}

/**
 * The TYPE of a movement of the book, in the language of the interface.
 *
 * The type is DATA (`sale`, `payment`, `return`, `adjustment`), y aqui se
 * traduce a la palabra que lee el cajero. Un movimiento desconocido sale tal
 * cual: la pantalla no inventa lo que no sabe.
 */
function labelDeMovimiento(type: string): string {
    switch (type) {
        case 'sale':
            return 'Sale';
        case 'payment':
            return 'Payment';
        case 'return':
            return 'Return';
        case 'adjustment':
            return 'Adjustment';
        default:
            return type;
    }
}

/**
 * The sheet of a customer: what they owe, what they may owe, the book of
 * their movements and the form of the payment.
 */
export default function CustomersShow({
    customer,
    balance,
    due,
    credit_limit,
    cache,
    cache_matches,
    over_limit,
    entries,
    pagination,
    store,
    currency,
}: CustomersShowProps) {
    const [amount, setAmount] = useState('');
    const [referenceNo, setReferenceNo] = useState('');
    const [entryDate, setEntryDate] = useState('');
    const [paying, setPaying] = useState(false);
    const [error, setError] = useState<string | null>(null);

    /**
     * The PAYMENT: it writes to the API and, when it succeeds, it RELOADS the
     * props, so the new balance and the new movement are on the screen.
     *
     * The date is optional: if it does not come, the server uses the BUSINESS
     * date of the store.
     */
    const pay = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setPaying(true);
        setError(null);

        try {
            await postJson(`/api/clientes/${customer.id}/cobros`, {
                amount,
                reference_no: referenceNo,
                entry_date: entryDate === '' ? null : entryDate,
            });

            setAmount('');
            setReferenceNo('');
            setEntryDate('');

            router.reload();
        } catch (fallo) {
            setError((fallo as Error).message);
        } finally {
            setPaying(false);
        }
    };

    /**
     * The PAGE of the book: the server paginates, and the page keeps the
     * customer.
     */
    const goToPage = (page: number) => {
        router.get(
            `/clientes/${customer.id}`,
            { page: page > 1 ? page : undefined },
            { preserveState: true, preserveScroll: true },
        );
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Customers',
            href: '/clientes',
        },
        {
            title: customer.name,
            href: `/clientes/${customer.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={customer.name} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {cache === null && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>No sheet in this store</AlertTitle>
                        <AlertDescription>
                            This customer has no sheet in {store.name}, so their credit here does not exist. A payment would be
                            rejected.
                        </AlertDescription>
                    </Alert>
                )}

                {cache !== null && !cache_matches && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>The cached balance does not match</AlertTitle>
                        <AlertDescription>
                            The cached balance ({formatAmount(cache.balance, currency)}) does not match the derived balance (
                            {formatAmount(balance, currency)}). The cache can go stale; the derived balance is the truth.
                        </AlertDescription>
                    </Alert>
                )}

                {over_limit && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>Over the credit limit</AlertTitle>
                        <AlertDescription>
                            The debt of this customer ({formatAmount(due, currency)}) is over their credit limit (
                            {credit_limit === null ? '—' : formatAmount(credit_limit, currency)}).
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader className="flex-row items-center justify-between gap-3">
                        <CardTitle className="text-base">
                            {customer.name}
                            <span className="text-muted-foreground ml-2 text-sm font-normal">
                                {customer.tax_id ?? 'Without document'}
                            </span>
                        </CardTitle>

                        <Button asChild variant="outline" size="sm">
                            <Link href={`/clientes/${customer.id}/editar`}>
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                    </CardHeader>

                    <CardContent>
                        <dl className="grid gap-4 sm:grid-cols-3">
                            <div className="space-y-1">
                                <dt className="text-muted-foreground text-xs uppercase">Balance</dt>
                                <dd className="text-2xl font-semibold tabular-nums">{formatAmount(balance, currency)}</dd>
                            </div>

                            <div className="space-y-1">
                                <dt className="text-muted-foreground text-xs uppercase">Due</dt>
                                <dd className="text-2xl font-semibold tabular-nums">{formatAmount(due, currency)}</dd>
                            </div>

                            <div className="space-y-1">
                                <dt className="text-muted-foreground text-xs uppercase">Credit limit</dt>
                                <dd className="text-2xl font-semibold tabular-nums">
                                    {credit_limit === null ? '—' : formatAmount(credit_limit, currency)}
                                </dd>
                            </div>
                        </dl>

                        <dl className="text-muted-foreground mt-4 grid gap-1 text-sm sm:grid-cols-2">
                            {customer.email && (
                                <div className="flex gap-2">
                                    <dt>Email:</dt>
                                    <dd className="text-foreground">{customer.email}</dd>
                                </div>
                            )}

                            {customer.phone && (
                                <div className="flex gap-2">
                                    <dt>Phone:</dt>
                                    <dd className="text-foreground">{customer.phone}</dd>
                                </div>
                            )}

                            {customer.mobile && (
                                <div className="flex gap-2">
                                    <dt>Mobile:</dt>
                                    <dd className="text-foreground">{customer.mobile}</dd>
                                </div>
                            )}

                            {customer.address && (
                                <div className="flex gap-2">
                                    <dt>Address:</dt>
                                    <dd className="text-foreground">{customer.address}</dd>
                                </div>
                            )}

                            {customer.city && (
                                <div className="flex gap-2">
                                    <dt>City:</dt>
                                    <dd className="text-foreground">{customer.city}</dd>
                                </div>
                            )}

                            <div className="flex gap-2">
                                <dt>Status:</dt>
                                <dd>
                                    <Badge variant={customer.is_active ? 'default' : 'secondary'}>
                                        {customer.is_active ? 'Active' : 'Inactive'}
                                    </Badge>
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-base">Book of the customer</CardTitle>
                        </CardHeader>

                        <CardContent>
                            {entries.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    There is no movement yet: this customer does not owe anything and has not paid anything.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b">
                                                <th className="px-2 py-2 text-left font-medium">Date</th>
                                                <th className="px-2 py-2 text-left font-medium">Type</th>
                                                <th className="px-2 py-2 text-left font-medium">Reference</th>
                                                <th className="px-2 py-2 text-right font-medium">Amount</th>
                                                <th className="px-2 py-2 text-right font-medium">Balance after</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {entries.map((entry) => (
                                                <tr key={entry.id} className="border-b last:border-0">
                                                    <td className="px-2 py-2">{entry.entry_date}</td>
                                                    <td className="px-2 py-2">{labelDeMovimiento(entry.type)}</td>
                                                    <td className="px-2 py-2">{entry.reference_no ?? '—'}</td>
                                                    <td className="px-2 py-2 text-right tabular-nums">{formatAmount(entry.amount, currency)}</td>
                                                    <td className="px-2 py-2 text-right tabular-nums">
                                                        {entry.balance_after === null ? '—' : formatAmount(entry.balance_after, currency)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {pagination.last_page > 1 && (
                                <div className="mt-4 flex items-center justify-between gap-3">
                                    <span className="text-muted-foreground text-sm">
                                        Page {pagination.page} of {pagination.last_page}
                                    </span>

                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={pagination.page <= 1}
                                            onClick={() => goToPage(pagination.page - 1)}
                                        >
                                            Previous
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={pagination.page >= pagination.last_page}
                                            onClick={() => goToPage(pagination.page + 1)}
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Payment</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <form onSubmit={pay} className="flex flex-col gap-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="amount">Amount</Label>
                                    <Input
                                        id="amount"
                                        value={amount}
                                        onChange={(event) => setAmount(event.target.value)}
                                        inputMode="decimal"
                                        placeholder="0.0000"
                                        required
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="reference_no">Reference</Label>
                                    <Input
                                        id="reference_no"
                                        value={referenceNo}
                                        onChange={(event) => setReferenceNo(event.target.value)}
                                        required
                                    />
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="entry_date">Date</Label>
                                    <Input
                                        id="entry_date"
                                        type="date"
                                        value={entryDate}
                                        onChange={(event) => setEntryDate(event.target.value)}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        Without a date, the business date of {store.name} is used.
                                    </p>
                                </div>

                                {error !== null && (
                                    <p className="bg-destructive/10 text-destructive rounded-md px-2 py-1 text-sm">{error}</p>
                                )}

                                <Button type="submit" disabled={paying}>
                                    {paying ? 'Registering…' : 'Register the payment'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>

                <div>
                    <Button variant="ghost" asChild>
                        <Link href="/clientes">
                            <ArrowLeft />
                            Back to the customers
                        </Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
