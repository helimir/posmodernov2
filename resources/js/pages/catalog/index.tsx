import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { usePermissions } from '@/lib/permissions';
import { type PosCurrency, formatAmount, formatPlainAmount } from '@/lib/pos';
import { Plus, Search } from 'lucide-react';
import { useCallback, useState } from 'react';

/**
 * A product of the catalog, as the backend hands it over.
 *
 * Every amount is a DECIMAL STRING: the columns are `numeric(25,4)` and a
 * `float` is not money. The price is the one of the ACTIVE store.
 */
export interface CatalogProduct {
    id: number;
    code: string;
    name: string;
    category: string | null;
    sell_price: string;
    quantity_in_stock: string;
    type: 'product' | 'service';
    is_active: boolean;
}

export interface CatalogFilters {
    q: string;
    category_id: number | null;
    status: 'active' | 'inactive' | null;
}

export interface CatalogPagination {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface CatalogIndexProps {
    products: CatalogProduct[];
    pagination: CatalogPagination;
    filters: CatalogFilters;
    categories: { id: number; name: string }[];
    store: { id: number; name: string; code_name: string | null };
    currency: PosCurrency;
}

/**
 * The value that means "no filter" in the selects. Radix does not allow an
 * empty string as the value of an item.
 */
const NONE = 'none';

/**
 * The catalog: the master of products and the price of the active store.
 */
export default function CatalogIndex({ products, pagination, filters, categories, store, currency }: CatalogIndexProps) {
    const { can } = usePermissions();
    const [query, setQuery] = useState(filters.q);
    const [category, setCategory] = useState(filters.category_id === null ? NONE : String(filters.category_id));
    const [status, setStatus] = useState(filters.status ?? NONE);

    /**
     * The FILTERS go to the server: the list is paginated and the page that
     * comes back is the one that matches the filters.
     */
    const applyFilters = useCallback(() => {
        router.get(
            '/catalogo',
            {
                q: query.trim() === '' ? undefined : query,
                category_id: category === NONE ? undefined : category,
                status: status === NONE ? undefined : status,
            },
            { preserveState: true, preserveScroll: true },
        );
    }, [query, category, status]);

    /**
     * The PAGE: the server pagina, and the pagina keeps los filtros.
     */
    const goToPage = useCallback(
        (page: number) => {
            router.get(
                '/catalogo',
                {
                    q: filters.q === '' ? undefined : filters.q,
                    category_id: filters.category_id === null ? undefined : filters.category_id,
                    status: filters.status ?? undefined,
                    page: page > 1 ? page : undefined,
                },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Catalog',
            href: '/catalogo',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Catalog" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader className="flex-row items-center justify-between gap-3">
                        <CardTitle>Catalog</CardTitle>

                        {can('catalog.create') && (
                            <Button asChild size="sm">
                                <Link href="/catalogo/nuevo">
                                    <Plus />
                                    New product
                                </Link>
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                applyFilters();
                            }}
                            className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <div className="relative">
                                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                <Input
                                    value={query}
                                    onChange={(event) => setQuery(event.target.value)}
                                    placeholder="Search by code or name"
                                    aria-label="Search by code or name"
                                    className="pl-9"
                                />
                            </div>

                            <Select value={category} onValueChange={setCategory}>
                                <SelectTrigger aria-label="Category">
                                    <SelectValue placeholder="Every category" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Every category</SelectItem>
                                    {categories.map((item) => (
                                        <SelectItem key={item.id} value={String(item.id)}>
                                            {item.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger aria-label="Status">
                                    <SelectValue placeholder="Every status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Every status</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="inactive">Inactive</SelectItem>
                                </SelectContent>
                            </Select>

                            <Button type="submit" variant="outline">
                                Filter
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {pagination.total} {pagination.total === 1 ? 'product' : 'products'}
                            <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                        </CardTitle>
                    </CardHeader>

                    <CardContent>
                        {products.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no product here yet. Create one, or change the filters.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Code</th>
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-left font-medium">Category</th>
                                            <th className="px-2 py-2 text-right font-medium">Price</th>
                                            <th className="px-2 py-2 text-right font-medium">Stock</th>
                                            <th className="px-2 py-2 text-left font-medium">Type</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {products.map((product) => (
                                            <tr key={product.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">
                                                    <Link href={`/catalogo/${product.id}`} className="underline underline-offset-4">
                                                        {product.code}
                                                    </Link>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <Link href={`/catalogo/${product.id}`} className="underline underline-offset-4">
                                                        {product.name}
                                                    </Link>
                                                </td>
                                                <td className="px-2 py-2">{product.category ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {formatAmount(product.sell_price, currency)}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {product.type === 'service'
                                                        ? '—'
                                                        : formatPlainAmount(product.quantity_in_stock, currency)}
                                                </td>
                                                <td className="px-2 py-2">{product.type === 'service' ? 'Service' : 'Product'}</td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={product.is_active ? 'default' : 'secondary'}>
                                                        {product.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
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
