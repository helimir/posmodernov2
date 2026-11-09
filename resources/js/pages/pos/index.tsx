import { Head } from '@inertiajs/react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

/**
 * The shape of the point-of-sale screen, as the backend hands it over.
 *
 * Every amount is a DECIMAL STRING: the columns are `numeric(25,4)` and a
 * `float` is not money.
 */
export interface PosStore {
    id: number;
    name: string;
    code_name: string | null;
    timezone: string;
}

export interface PosProduct {
    id: number;
    code: string;
    name: string;
    type: 'product' | 'service';
    sell_price: string;
    quantity_in_stock: string;
    unit_name: string | null;
}

export interface PosPaymentMethod {
    id: number;
    code: string;
    name: string;
}

export interface PosIndexProps {
    store: PosStore;
    catalog: PosProduct[];
    payment_methods: PosPaymentMethod[];
    document_series_id: number | null;
    control_series_id: number | null;
    business_date: string;
    currency_code: string;
    catalog_page_size: number;
}

/**
 * The PLACEHOLDER of the point-of-sale screen.
 *
 * The backend of the till (work unit 1-1) is done; the screen itself (work
 * unit 1-2) is not. This file exists because Inertia has to resolve a page
 * component for every route it renders: without it, the screen cannot open
 * at all.
 *
 * So this is NOT the till: it is the CONTRACT, written down in the type that
 * the screen will consume. It shows the scenario the backend resolved and
 * nothing else. The screen replaces it.
 */
export default function PosIndex({
    store,
    catalog,
    payment_methods,
    document_series_id,
    control_series_id,
    business_date,
    currency_code,
}: PosIndexProps) {
    return (
        <AppLayout>
            <Head title="Point of sale" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Point of sale</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            <dt className="text-muted-foreground">Store</dt>
                            <dd>{store.name}</dd>

                            <dt className="text-muted-foreground">Business date</dt>
                            <dd className="tabular-nums">{business_date}</dd>

                            <dt className="text-muted-foreground">Currency</dt>
                            <dd>{currency_code}</dd>

                            <dt className="text-muted-foreground">Document series</dt>
                            <dd>{document_series_id ?? '—'}</dd>

                            <dt className="text-muted-foreground">Control series</dt>
                            <dd>{control_series_id ?? '—'}</dd>

                            <dt className="text-muted-foreground">Payment methods</dt>
                            <dd>{payment_methods.map((method) => method.name).join(', ') || '—'}</dd>

                            <dt className="text-muted-foreground">Catalog</dt>
                            <dd className="tabular-nums">{catalog.length}</dd>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
