import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/api';
import { type PosCurrency, formatAmount } from '@/lib/pos';
import { usePermissions } from '@/lib/permissions';
import { Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * A supplier of the listing, as the backend hands it over.
 *
 * `balance` is a DECIMAL STRING: the columns are `numeric(25,4)` and a `float`
 * is not money. It is the DERIVED debt of the store with the supplier: it
 * comes from its purchases and its returns, never from a counter that is
 * written by hand.
 */
export interface SupplierRow {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    tax_id: string | null;
    balance: string;
}

export interface SuppliersProps {
    suppliers: SupplierRow[];
    currency: PosCurrency;
    business_date: string;
    store: { id: number; name: string; code_name: string | null };
}

/**
 * The SUPPLIERS of the store: who the store buys from, and what it owes.
 */
export default function Suppliers({ suppliers, currency, business_date, store }: SuppliersProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [creating, setCreating] = useState(false);
    const [draft, setDraft] = useState({ name: '', email: '', phone: '', tax_id: '' });

    /**
     * The ALTA: the supplier is born in the server, with its sheet in the
     * store. The balance is never written here: it is derived from the
     * purchases and the returns.
     */
    const createSupplier = async () => {
        setError(null);
        setSaving(true);

        try {
            await postJson('/api/proveedores', {
                name: draft.name,
                email: draft.email,
                phone: draft.phone,
                tax_id: draft.tax_id,
            });

            setCreating(false);
            setDraft({ name: '', email: '', phone: '', tax_id: '' });
            router.reload({ only: ['suppliers'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The supplier could not be created.');
        } finally {
            setSaving(false);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Suppliers',
            href: '/proveedores',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Suppliers" />

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
                                {suppliers.length} {suppliers.length === 1 ? 'supplier' : 'suppliers'}
                                <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                Business date {business_date}. The balance is the debt the store has with the supplier,
                                and it is DERIVED from its purchases and its returns: it is never a counter written by
                                hand.
                            </CardDescription>
                        </div>

                        {can('supplier.create') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setDraft({ name: '', email: '', phone: '', tax_id: '' });
                                    setCreating(true);
                                }}
                            >
                                <Plus />
                                New supplier
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {suppliers.length === 0 ? (
                            <p className="text-muted-foreground text-sm">There is no supplier yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-left font-medium">Tax id</th>
                                            <th className="px-2 py-2 text-left font-medium">Phone</th>
                                            <th className="px-2 py-2 text-right font-medium">Balance</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {suppliers.map((proveedor) => (
                                            <tr key={proveedor.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">{proveedor.name}</td>
                                                <td className="px-2 py-2">{proveedor.tax_id ?? '—'}</td>
                                                <td className="px-2 py-2">{proveedor.phone ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {formatAmount(proveedor.balance, currency)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* El ALTA: el nombre es lo unico obligatorio. */}
            <Dialog
                open={creating}
                onOpenChange={(open) => {
                    if (!open) {
                        setCreating(false);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New supplier</DialogTitle>
                        <DialogDescription>
                            The supplier is born in the catalog and gets its sheet in this store. Its balance is
                            derived from the purchases and the returns: nobody writes it by hand.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="supplier-name">Name</Label>
                            <Input
                                id="supplier-name"
                                value={draft.name}
                                onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                                placeholder="Distribuidora del centro"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="supplier-tax-id">Tax id</Label>
                            <Input
                                id="supplier-tax-id"
                                value={draft.tax_id}
                                onChange={(event) => setDraft({ ...draft, tax_id: event.target.value })}
                                placeholder="J-40123456-7"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="supplier-phone">Phone</Label>
                            <Input
                                id="supplier-phone"
                                value={draft.phone}
                                onChange={(event) => setDraft({ ...draft, phone: event.target.value })}
                                placeholder="0412-1234567"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="supplier-email">Email</Label>
                            <Input
                                id="supplier-email"
                                value={draft.email}
                                onChange={(event) => setDraft({ ...draft, email: event.target.value })}
                                placeholder="ventas@distribuidora.com"
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={createSupplier}>
                            Create supplier
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
