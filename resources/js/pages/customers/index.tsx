import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type PosCurrency, formatAmount } from '@/lib/pos';
import { Plus, Search } from 'lucide-react';
import { useCallback, useState } from 'react';

/**
 * A customer of the listing, as the backend hands it over.
 *
 * Every amount is a DECIMAL STRING: the columns are `numeric(25,4)` and a
 * `float` is not money. The balance and the due are DERIVED from the
 * movements of the customer in the active store.
 */
export interface CustomerRow {
    id: number;
    name: string;
    tax_id: string | null;
    balance: string;
    due: string;
    credit_limit: string | null;
    over_limit: boolean;
}

export interface CustomerPagination {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface CustomerFilters {
    q: string;
}

export interface CustomersIndexProps {
    customers: CustomerRow[];
    pagination: CustomerPagination;
    filters: CustomerFilters;
    store: { id: number; name: string; code_name: string | null };
    currency: PosCurrency;
}

/**
 * The catalog of customers: who they are, what they owe and how much they
 * are allowed to owe.
 */
export default function CustomersIndex({ customers, pagination, filters, store, currency }: CustomersIndexProps) {
    const [query, setQuery] = useState(filters.q);

    /**
     * The SEARCH goes to the server: the list is paginated, and the page that
     * comes back is the one that matches the search.
     *
     * The search is by NAME (as a prefix) or by DOCUMENT (exact): it is the
     * search of the till, not a filter of the browser.
     */
    const applyFilters = useCallback(() => {
        router.get(
            '/clientes',
            { q: query.trim() === '' ? undefined : query },
            { preserveState: true, preserveScroll: true },
        );
    }, [query]);

    /**
     * The PAGE: the server paginates, and the page keeps the search.
     */
    const goToPage = useCallback(
        (page: number) => {
            router.get(
                '/clientes',
                {
                    q: filters.q === '' ? undefined : filters.q,
                    page: page > 1 ? page : undefined,
                },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Customers',
            href: '/clientes',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Customers" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader className="flex-row items-center justify-between gap-3">
                        <CardTitle>Customers</CardTitle>

                        <Button asChild size="sm">
                            <Link href="/clientes/nuevo">
                                <Plus />
                                New customer
                            </Link>
                        </Button>
                    </CardHeader>

                    <CardContent>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                applyFilters();
                            }}
                            className="flex items-end gap-3"
                        >
                            <div className="relative flex-1">
                                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                <Input
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Search by name or document"
                                    aria-label="Search by name or document"
                                    className="pl-9"
                                />
                            </div>

                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {pagination.total} {pagination.total === 1 ? 'customer' : 'customers'}
                            <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                        </CardTitle>
                    </CardHeader>

                    <CardContent>
                        {customers.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no customer here yet. Create one, or change the search.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-left font-medium">Document</th>
                                            <th className="px-2 py-2 text-right font-medium">Balance</th>
                                            <th className="px-2 py-2 text-right font-medium">Due</th>
                                            <th className="px-2 py-2 text-right font-medium">Credit limit</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {customers.map((customer) => (
                                            <tr key={customer.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">
                                                    <Link href={`/clientes/${customer.id}`} className="underline underline-offset-4">
                                                        {customer.name}
                                                    </Link>
                                                </td>
                                                <td className="px-2 py-2">{customer.tax_id ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {formatAmount(customer.balance, currency)}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {formatAmount(customer.due, currency)}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {customer.credit_limit === null ? '—' : formatAmount(customer.credit_limit, currency)}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {customer.over_limit ? (
                                                        <Badge variant="destructive">Over the limit</Badge>
                                                    ) : (
                                                        <Badge variant="secondary">Within the limit</Badge>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2 text-right">
                                                    <Button asChild variant="outline" size="sm">
                                                        <Link href={`/clientes/${customer.id}/editar`}>Edit</Link>
                                                    </Button>
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
            </div>
        </AppLayout>
    );
}
