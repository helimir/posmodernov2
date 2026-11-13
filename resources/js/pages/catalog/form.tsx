import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { usePermissions } from '@/lib/permissions';
import { type PosCurrency, formatPlainAmount } from '@/lib/pos';
import { AlertTriangle, ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';

/**
 * An option of a selector of the master catalog.
 */
export interface CatalogOption {
    id: number;
    name: string;
}

/**
 * A fiscal category. They live PER FISCAL PROFILE, so the option says which
 * profile it belongs to.
 */
export interface CatalogTaxCategory {
    id: number;
    code: string;
    name: string;
    profile_code: string | null;
}

/**
 * A store of the prices table.
 */
export interface CatalogStoreOption {
    id: number;
    name: string;
    code_name: string | null;
    is_active_store: boolean;
}

/**
 * The price of a product in ONE store.
 */
export interface CatalogStorePrice {
    store_id: number;
    store_name: string;
    store_code_name: string | null;
    is_active_store: boolean;
    sell_price: string;
    purchase_price: string;
    quantity_in_stock: string;
    tax_category_id: number | null;
    tax_category_name: string | null;
}

/**
 * The product of the sheet, as the backend hands it over.
 */
export interface CatalogProductDetail {
    id: number;
    code: string;
    name: string;
    description: string | null;
    type: 'product' | 'service';
    is_active: boolean;
    category_id: number | null;
    brand_id: number | null;
    unit_id: number | null;
    box_id: number | null;
    tax_category_id: number | null;
    tax_category_name: string | null;
    store_tax_category_id: number | null;
    store_tax_category_name: string | null;
    effective_tax_category_id: number | null;
    effective_tax_category_name: string | null;
    missing_tax_category: boolean;
    quantity_in_stock: string;
    prices: CatalogStorePrice[];
}

export interface CatalogFormProps {
    product: CatalogProductDetail | null;
    categories: CatalogOption[];
    brands: CatalogOption[];
    units: CatalogOption[];
    boxes: CatalogOption[];
    tax_categories: CatalogTaxCategory[];
    stores: CatalogStoreOption[];
    store: { id: number; name: string; code_name: string | null };
    currency: PosCurrency;
}

/**
 * The data of the form.
 *
 * The ids travel as `string | number | null`: the selector works with
 * strings and the payload carries the number.
 */
type CatalogFormData = {
    code: string;
    name: string;
    description: string;
    type: string;
    is_active: boolean;
    category_id: string | number | null;
    brand_id: string | number | null;
    unit_id: string | number | null;
    box_id: string | number | null;
    tax_category_id: string | number | null;
    sell_price: string;
    purchase_price: string;
};

/**
 * The value that means "nothing selected". Radix does not allow an empty
 * string as the value of an item.
 */
const NONE = 'none';

/**
 * The screen of a product: the ALTA and the EDICION are the same screen.
 */
export default function CatalogForm({
    product,
    categories,
    brands,
    units,
    boxes,
    tax_categories,
    stores,
    store,
    currency,
}: CatalogFormProps) {
    const { can } = usePermissions();
    const activePrice = product?.prices.find((price) => price.is_active_store);

    /**
     * The ROWS of the prices table: the price of every active store.
     *
     * In the ALTA the product does not exist yet: the rows are the active
     * stores with a price of zero, because that is the price with which the
     * sheet of the product is opened.
     */
    const rows: CatalogStorePrice[] = product
        ? product.prices
        : stores.map((option) => ({
              store_id: option.id,
              store_name: option.name,
              store_code_name: option.code_name,
              is_active_store: option.is_active_store,
              sell_price: '0.0000',
              purchase_price: '0.0000',
              quantity_in_stock: '0.0000',
              tax_category_id: null,
              tax_category_name: null,
          }));

    const form = useForm<CatalogFormData>({
        code: product?.code ?? '',
        name: product?.name ?? '',
        description: product?.description ?? '',
        type: product?.type ?? 'product',
        is_active: product?.is_active ?? true,
        category_id: product?.category_id ?? null,
        brand_id: product?.brand_id ?? null,
        unit_id: product?.unit_id ?? null,
        box_id: product?.box_id ?? null,
        // La categoria que MANDA en esta tienda: el override de la tienda si
        // lo hay, si no la del producto.
        tax_category_id: product?.effective_tax_category_id ?? null,
        sell_price: activePrice?.sell_price ?? '0.0000',
        purchase_price: activePrice?.purchase_price ?? '0.0000',
    });

    // El payload lleva los ids como numeros y los "nada" como `null`.
    form.transform((data) => ({
        ...data,
        category_id: data.category_id === NONE || data.category_id === null ? null : Number(data.category_id),
        brand_id: data.brand_id === NONE || data.brand_id === null ? null : Number(data.brand_id),
        unit_id: data.unit_id === NONE || data.unit_id === null ? null : Number(data.unit_id),
        box_id: data.box_id === NONE || data.box_id === null ? null : Number(data.box_id),
        tax_category_id: data.tax_category_id === NONE || data.tax_category_id === null ? null : Number(data.tax_category_id),
    }));

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (product) {
            form.put(`/catalogo/${product.id}`);

            return;
        }

        form.post('/catalogo');
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Catalog',
            href: '/catalogo',
        },
        {
            title: product ? product.code : 'New product',
            href: product ? `/catalogo/${product.id}` : '/catalogo/nuevo',
        },
    ];

    /**
     * The value of a selector: the id, or the "nothing" sentinel.
     */
    const selectValue = (value: string | number | null): string => (value === null || value === undefined ? NONE : String(value));

    /**
     * The message of an error of the form.
     */
    const errorOf = (field: keyof CatalogFormData): string | undefined => form.errors[field];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={product ? product.code : 'New product'} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {product?.missing_tax_category && (
                    <div className="bg-destructive/10 text-destructive flex items-start gap-3 rounded-lg border p-3 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <p>
                            This product has no fiscal treatment. The till will not charge a line without one: pick a tax
                            category before selling it.
                        </p>
                    </div>
                )}

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">{product ? 'Product' : 'New product'}</CardTitle>
                        </CardHeader>

                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="code">Code</Label>
                                <Input
                                    id="code"
                                    value={form.data.code}
                                    onChange={(event) => form.setData('code', event.target.value)}
                                    required
                                    maxLength={100}
                                />
                                {errorOf('code') && <p className="text-destructive text-sm">{errorOf('code')}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required />
                                {errorOf('name') && <p className="text-destructive text-sm">{errorOf('name')}</p>}
                            </div>

                            <div className="space-y-1.5 sm:col-span-2">
                                <Label htmlFor="description">Description</Label>
                                <Input
                                    id="description"
                                    value={form.data.description}
                                    onChange={(event) => form.setData('description', event.target.value)}
                                />
                                {errorOf('description') && <p className="text-destructive text-sm">{errorOf('description')}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="type">Type</Label>
                                <Select value={form.data.type} onValueChange={(value) => form.setData('type', value)}>
                                    <SelectTrigger id="type">
                                        <SelectValue placeholder="Type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="product">Product</SelectItem>
                                        <SelectItem value="service">Service</SelectItem>
                                    </SelectContent>
                                </Select>
                                {errorOf('type') && <p className="text-destructive text-sm">{errorOf('type')}</p>}
                                <p className="text-muted-foreground text-xs">
                                    A service does not create a lot and does not consume stock.
                                </p>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="is_active">Active</Label>
                                <div className="flex items-center gap-2 pt-1.5">
                                    <Checkbox
                                        id="is_active"
                                        checked={form.data.is_active}
                                        onCheckedChange={(checked) => form.setData('is_active', checked === true)}
                                    />
                                    <span className="text-sm">It shows up in the till of {store.name}</span>
                                </div>
                                {errorOf('is_active') && <p className="text-destructive text-sm">{errorOf('is_active')}</p>}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="category_id">Category</Label>
                                <Select
                                    value={selectValue(form.data.category_id)}
                                    onValueChange={(value) => form.setData('category_id', value === NONE ? null : value)}
                                >
                                    <SelectTrigger id="category_id">
                                        <SelectValue placeholder="Without category" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Without category</SelectItem>
                                        {categories.map((option) => (
                                            <SelectItem key={option.id} value={String(option.id)}>
                                                {option.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="brand_id">Brand</Label>
                                <Select
                                    value={selectValue(form.data.brand_id)}
                                    onValueChange={(value) => form.setData('brand_id', value === NONE ? null : value)}
                                >
                                    <SelectTrigger id="brand_id">
                                        <SelectValue placeholder="Without brand" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Without brand</SelectItem>
                                        {brands.map((option) => (
                                            <SelectItem key={option.id} value={String(option.id)}>
                                                {option.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="unit_id">Unit</Label>
                                <Select
                                    value={selectValue(form.data.unit_id)}
                                    onValueChange={(value) => form.setData('unit_id', value === NONE ? null : value)}
                                >
                                    <SelectTrigger id="unit_id">
                                        <SelectValue placeholder="Without unit" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Without unit</SelectItem>
                                        {units.map((option) => (
                                            <SelectItem key={option.id} value={String(option.id)}>
                                                {option.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="box_id">Box</Label>
                                <Select
                                    value={selectValue(form.data.box_id)}
                                    onValueChange={(value) => form.setData('box_id', value === NONE ? null : value)}
                                >
                                    <SelectTrigger id="box_id">
                                        <SelectValue placeholder="Without box" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Without box</SelectItem>
                                        {boxes.map((option) => (
                                            <SelectItem key={option.id} value={String(option.id)}>
                                                {option.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Tax category</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="tax_category_id">Tax category</Label>
                                <Select
                                    value={selectValue(form.data.tax_category_id)}
                                    onValueChange={(value) => form.setData('tax_category_id', value === NONE ? null : value)}
                                >
                                    <SelectTrigger id="tax_category_id">
                                        <SelectValue placeholder="Without tax category" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Without tax category</SelectItem>
                                        {tax_categories.map((option) => (
                                            <SelectItem key={option.id} value={String(option.id)}>
                                                {option.name}
                                                {option.profile_code ? ` (${option.profile_code})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errorOf('tax_category_id') && (
                                    <p className="text-destructive text-sm">{errorOf('tax_category_id')}</p>
                                )}
                            </div>

                            <dl className="text-muted-foreground grid gap-1 text-sm">
                                <div className="flex gap-2">
                                    <dt>Product:</dt>
                                    <dd className="text-foreground">{product?.tax_category_name ?? 'None'}</dd>
                                </div>
                                <div className="flex gap-2">
                                    <dt>{store.name}:</dt>
                                    <dd className="text-foreground">{product?.store_tax_category_name ?? 'None'}</dd>
                                </div>
                            </dl>

                            <p className="text-muted-foreground text-xs">
                                {product?.store_tax_category_id !== null && product?.store_tax_category_id !== undefined
                                    ? `This store overrides the category of the product: the override of ${store.name} is the one that applies here.`
                                    : 'The category of the product applies in this store. The price endpoint can set an override for a store.'}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Prices</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="sell_price">Sell price in {store.name}</Label>
                                    <Input
                                        id="sell_price"
                                        value={form.data.sell_price}
                                        onChange={(event) => form.setData('sell_price', event.target.value)}
                                        inputMode="decimal"
                                        required
                                    />
                                    {errorOf('sell_price') && <p className="text-destructive text-sm">{errorOf('sell_price')}</p>}
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="purchase_price">Purchase price in {store.name}</Label>
                                    <Input
                                        id="purchase_price"
                                        value={form.data.purchase_price}
                                        onChange={(event) => form.setData('purchase_price', event.target.value)}
                                        inputMode="decimal"
                                    />
                                    {errorOf('purchase_price') && (
                                        <p className="text-destructive text-sm">{errorOf('purchase_price')}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="quantity_in_stock">Stock in {store.name}</Label>
                                <Input id="quantity_in_stock" value={formatPlainAmount(product?.quantity_in_stock ?? '0.0000', currency)} disabled readOnly />
                                <p className="text-muted-foreground text-xs">
                                    The stock is derived from the lots (purchases and adjustments). It cannot be edited here.
                                </p>
                            </div>

                            <div className="space-y-2">
                                <h3 className="text-sm font-medium">Prices by store</h3>

                                {product === null && (
                                    <p className="text-muted-foreground text-xs">
                                        The sheet of the product is opened in every active store, with price zero. The price
                                        above is the one of {store.name}.
                                    </p>
                                )}

                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b">
                                                <th className="px-2 py-2 text-left font-medium">Store</th>
                                                <th className="px-2 py-2 text-right font-medium">Sell price</th>
                                                <th className="px-2 py-2 text-right font-medium">Purchase price</th>
                                                <th className="px-2 py-2 text-right font-medium">Stock</th>
                                                <th className="px-2 py-2 text-left font-medium">Tax category</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {rows.map((price) => (
                                                <tr key={price.store_id} className="border-b last:border-0">
                                                    <td className="px-2 py-2">
                                                        <span className="block">{price.store_name}</span>
                                                        {price.is_active_store && (
                                                            <Badge variant="secondary" className="mt-1">
                                                                This store
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="px-2 py-2 text-right tabular-nums">
                                                        {formatPlainAmount(price.sell_price, currency)}
                                                    </td>
                                                    <td className="px-2 py-2 text-right tabular-nums">
                                                        {formatPlainAmount(price.purchase_price, currency)}
                                                    </td>
                                                    <td className="px-2 py-2 text-right tabular-nums">
                                                        {formatPlainAmount(price.quantity_in_stock, currency)}
                                                    </td>
                                                    <td className="px-2 py-2">{price.tax_category_name ?? '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <p className="text-muted-foreground text-xs">
                                    The price of a store is set in that store: this sheet edits the price of {store.name}.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-3">
                        {can(product === null ? 'catalog.create' : 'catalog.update') && (
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Saving…' : product ? 'Save changes' : 'Create product'}
                            </Button>
                        )}

                        <Button variant="ghost" asChild>
                            <Link href="/catalogo">
                                <ArrowLeft />
                                Back to the catalog
                            </Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
