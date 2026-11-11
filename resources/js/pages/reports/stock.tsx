import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type PosCurrency, formatAmount, formatPlainAmount } from '@/lib/pos';
import { useCallback } from 'react';

/**
 * A product of the report, as the backend hands it over.
 *
 * `stock` is the REAL stock: the sum of the available lots of the product in
 * the active store, not the cache of the store. Every amount is a DECIMAL
 * STRING: the columns are `numeric(25,4)` and a `float` is not money.
 */
export interface StockReportProduct {
    id: number;
    code: string;
    name: string;
    sell_price: string;
    stock: string;
}

export interface StockReportPagination {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface StockReportProps {
    products: StockReportProduct[];
    pagination: StockReportPagination;
    threshold: number;
    store: { id: number; name: string; code_name: string | null };
    currency: PosCurrency;
}

/**
 * The report of the LOW STOCK: the products of the active store that are
 * running out, the most urgent first.
 *
 * A product without lots has a stock of ZERO, and it shows up: what cannot
 * be sold cannot be hidden.
 */
export default function StockReport({ products, pagination, threshold, store, currency }: StockReportProps) {
    /**
     * The PAGE: the server paginates, and the page keeps the store.
     */
    const goToPage = useCallback(
        (page: number) => {
            router.get('/reportes/stock', { page: page > 1 ? page : undefined }, { preserveState: true, preserveScroll: true });
        },
        [],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Low stock',
            href: '/reportes/stock',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Low stock" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {pagination.total} {pagination.total === 1 ? 'product' : 'products'} below the threshold
                            <span className="text-muted-foreground ml-2 text-sm font-normal">
                                under {threshold} units in {store.name}
                            </span>
                        </CardTitle>
                    </CardHeader>

                    <CardContent>
                        {products.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Nothing is running out: every product of this store is above the threshold.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Code</th>
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-right font-medium">Stock</th>
                                            <th className="px-2 py-2 text-right font-medium">Price</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {products.map((product) => (
                                            <tr key={product.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">{product.code}</td>
                                                <td className="px-2 py-2">{product.name}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    <Badge variant="destructive">{formatPlainAmount(product.stock, currency)}</Badge>
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">{formatAmount(product.sell_price, currency)}</td>
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
