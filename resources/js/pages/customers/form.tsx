import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { postJson, putJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { type PosCurrency, formatAmount } from '@/lib/pos';
import { ArrowLeft } from 'lucide-react';
import { useState, type FormEvent } from 'react';

/**
 * The customer of the form, as the backend hands it over.
 */
export interface CustomerFormCustomer {
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
 * A country of the catalogue. It is a ROW, not a literal of the interface:
 * the country of a customer is data.
 */
export interface CustomerCountry {
    id: number;
    name: string;
}

export interface CustomerFormProps {
    customer: CustomerFormCustomer | null;
    countries: CustomerCountry[];
    store: { id: number; name: string; code_name: string | null };
    currency: PosCurrency;
}

/**
 * The data of the form.
 *
 * Every amount is a DECIMAL STRING: the credit limit is written as text and
 * sent as text, because a `float` is not money.
 */
type CustomerFormData = {
    name: string;
    tax_id: string;
    email: string;
    phone: string;
    mobile: string;
    address: string;
    city: string;
    zip_code: string;
    customer_state: string;
    customer_country: string;
    credit_limit: string;
    is_active: boolean;
};

/**
 * The value that means "nothing selected". Radix does not allow an empty
 * string as the value of an item.
 */
const NONE = 'none';

/**
 * The screen of a customer: the ALTA and the EDICION are the same screen.
 *
 * THE WRITE GOES THROUGH THE API: the form does not POST to a page route,
 * it writes to `/api/clientes` and then REDIRECTS to the sheet with
 * `router.visit`. That is the rule of the unit: the pages are Inertia and
 * the writes are JSON.
 */
export default function CustomerForm({ customer, countries, store, currency }: CustomerFormProps) {
    const { can } = usePermissions();
    const [form, setForm] = useState<CustomerFormData>({
        name: customer?.name ?? '',
        tax_id: customer?.tax_id ?? '',
        email: customer?.email ?? '',
        phone: customer?.phone ?? '',
        mobile: customer?.mobile ?? '',
        address: customer?.address ?? '',
        city: customer?.city ?? '',
        zip_code: customer?.zip_code ?? '',
        customer_state: customer?.customer_state ?? '',
        customer_country: customer?.customer_country === null || customer?.customer_country === undefined ? NONE : String(customer.customer_country),
        credit_limit: customer?.credit_limit ?? '',
        is_active: customer?.is_active ?? true,
    });

    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    /**
     * Changes a field of the form.
     */
    const change = <K extends keyof CustomerFormData>(field: K, value: CustomerFormData[K]) => {
        setForm((current) => ({ ...current, [field]: value }));
    };

    /**
     * The PAYLOAD: the same shape for the alta and for la edicion.
     *
     * The empty texts travel as `null`: un texto vacio no es un dato, y el
     * maestro guarda `null` en vez de una cadena de espacios.
     */
    const payload = () => ({
        name: form.name,
        tax_id: form.tax_id === '' ? null : form.tax_id,
        email: form.email === '' ? null : form.email,
        phone: form.phone === '' ? null : form.phone,
        mobile: form.mobile === '' ? null : form.mobile,
        address: form.address === '' ? null : form.address,
        city: form.city === '' ? null : form.city,
        zip_code: form.zip_code === '' ? null : form.zip_code,
        customer_state: form.customer_state === '' ? null : form.customer_state,
        customer_country: form.customer_country === NONE ? null : Number(form.customer_country),
        credit_limit: form.credit_limit === '' ? null : form.credit_limit,
        is_active: form.is_active,
    });

    /**
     * The SAVE: it writes to the API and, when it succeeds, it goes to the
     * sheet of the customer.
     *
     * The BALANCE is not in the payload: it is DERIVED, and the edit does not
     * touch it.
     */
    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setSaving(true);
        setError(null);

        try {
            if (customer) {
                await putJson(`/api/clientes/${customer.id}`, payload());

                router.visit(`/clientes/${customer.id}`);

                return;
            }

            const creado = await postJson<{ id: number }>('/api/clientes', payload());

            router.visit(`/clientes/${creado.id}`);
        } catch (fallo) {
            setError((fallo as Error).message);
        } finally {
            setSaving(false);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Customers',
            href: '/clientes',
        },
        {
            title: customer ? customer.name : 'New customer',
            href: customer ? `/clientes/${customer.id}` : '/clientes/nuevo',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={customer ? customer.name : 'New customer'} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">{customer ? 'Customer' : 'New customer'}</CardTitle>
                        </CardHeader>

                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" value={form.name} onChange={(event) => change('name', event.target.value)} required />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="tax_id">Document</Label>
                                <Input
                                    id="tax_id"
                                    value={form.tax_id}
                                    onChange={(event) => change('tax_id', event.target.value)}
                                    maxLength={50}
                                />
                                <p className="text-muted-foreground text-xs">The document identifies the customer, and it is unique.</p>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="email">Email</Label>
                                <Input id="email" type="email" value={form.email} onChange={(event) => change('email', event.target.value)} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="phone">Phone</Label>
                                <Input id="phone" value={form.phone} onChange={(event) => change('phone', event.target.value)} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="mobile">Mobile</Label>
                                <Input id="mobile" value={form.mobile} onChange={(event) => change('mobile', event.target.value)} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="address">Address</Label>
                                <Input id="address" value={form.address} onChange={(event) => change('address', event.target.value)} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="city">City</Label>
                                <Input id="city" value={form.city} onChange={(event) => change('city', event.target.value)} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="zip_code">Zip code</Label>
                                <Input id="zip_code" value={form.zip_code} onChange={(event) => change('zip_code', event.target.value)} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="customer_state">State</Label>
                                <Input
                                    id="customer_state"
                                    value={form.customer_state}
                                    onChange={(event) => change('customer_state', event.target.value)}
                                />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="customer_country">Country</Label>
                                <Select
                                    value={form.customer_country}
                                    onValueChange={(value) => change('customer_country', value)}
                                >
                                    <SelectTrigger id="customer_country">
                                        <SelectValue placeholder="Without country" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Without country</SelectItem>
                                        {countries.map((country) => (
                                            <SelectItem key={country.id} value={String(country.id)}>
                                                {country.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="is_active">Active</Label>
                                <div className="flex items-center gap-2 pt-1.5">
                                    <Checkbox
                                        id="is_active"
                                        checked={form.is_active}
                                        onCheckedChange={(checked) => change('is_active', checked === true)}
                                    />
                                    <span className="text-sm">It shows up in the till of {store.name}</span>
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="credit_limit">Credit limit</Label>
                                <Input
                                    id="credit_limit"
                                    value={form.credit_limit}
                                    onChange={(event) => change('credit_limit', event.target.value)}
                                    inputMode="decimal"
                                    placeholder="0.0000"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {form.credit_limit === ''
                                        ? 'Without a limit. The sheet warns when the debt goes over it.'
                                        : `Limit: ${formatAmount(form.credit_limit, currency)}`}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {error !== null && (
                        <p className="bg-destructive/10 text-destructive rounded-md px-3 py-2 text-sm">{error}</p>
                    )}

                    <div className="flex items-center gap-3">
                        {can(customer === null ? 'customer.create' : 'customer.update') && (
                            <Button type="submit" disabled={saving}>
                                {saving ? 'Saving…' : customer ? 'Save' : 'Create'}
                            </Button>
                        )}

                        <Button variant="ghost" asChild>
                            <Link href="/clientes">
                                <ArrowLeft />
                                Back to the customers
                            </Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
