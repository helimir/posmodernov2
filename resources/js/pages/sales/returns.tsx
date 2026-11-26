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
import { getJson, postJson } from '@/lib/api';
import { type PosCurrency, formatAmount } from '@/lib/pos';
import { usePermissions } from '@/lib/permissions';
import { Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * A merchandise return of the listing, as the backend hands it over.
 */
export interface SaleReturnRow {
    id: number;
    sale_id: number;
    invoice_no: string | null;
    reference_no: string | null;
    return_date: string;
    total: string;
    product_name: string | null;
    quantity: string | null;
}

/**
 * A sale of the store, to pick the one the merchandise comes back from.
 */
export interface SaleOption {
    id: number;
    invoice_no: string | null;
    sale_date: string;
    total: string;
}

/**
 * A line of a sale that can be returned, with what is left to return.
 */
export interface ReturnableLine {
    id: number;
    product_name: string;
    product_code: string;
    quantity: string;
    returned: string;
    available: string;
    unit_price: string;
    base_amount: string;
    tax_amount: string;
    line_total: string;
}

export interface SaleReturnsProps {
    returns: SaleReturnRow[];
    sales: SaleOption[];
    currency: PosCurrency;
    business_date: string;
    store: { id: number; name: string; code_name: string | null };
}

/**
 * The MERCHANDISE RETURNS of the store: the goods that come back from the
 * customers.
 */
export default function SaleReturns({ returns, sales, currency, business_date, store }: SaleReturnsProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [warning, setWarning] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [creating, setCreating] = useState(false);
    const [saleId, setSaleId] = useState('');
    const [lines, setLines] = useState<ReturnableLine[]>([]);
    const [quantities, setQuantities] = useState<Record<number, string>>({});

    /**
     * The LINES of the chosen sale: the server says how much of each one can
     * still be returned.
     */
    const loadLines = async (venta: string) => {
        setSaleId(venta);
        setError(null);
        setLines([]);

        if (venta === '') {
            return;
        }

        try {
            const respuesta = await getJson<{ lines: ReturnableLine[] }>(`/api/ventas/${venta}/devolucion`);

            setLines(respuesta.lines);
            setQuantities({});
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The sale could not be read.');
        }
    };

    /**
     * The ALTA: the merchandise goes back to the lot it came from, and the
     * customer book is compensated with a return of its own type.
     */
    const createReturn = async () => {
        setError(null);
        setWarning(null);
        setSaving(true);

        try {
            const respuesta = await postJson<{ warning: string | null }>(`/api/ventas/${saleId}/devolucion`, {
                lines: Object.entries(quantities)
                    .filter(([, cantidad]) => cantidad !== '' && cantidad !== '0')
                    .map(([itemId, cantidad]) => ({
                        sale_item_id: Number(itemId),
                        quantity: cantidad,
                    })),
            });

            setCreating(false);
            setSaleId('');
            setLines([]);
            setQuantities({});
            setWarning(respuesta.warning);
            router.reload({ only: ['returns'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The return could not be created.');
        } finally {
            setSaving(false);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Returns',
            href: '/ventas/devoluciones',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Returns" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {error !== null && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardDescription className="text-destructive text-sm">{error}</CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {warning !== null && (
                    <Card className="border-amber-500">
                        <CardHeader>
                            <CardDescription className="text-sm">{warning}</CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <Card>
                    <CardHeader className="flex-row items-start justify-between gap-3 space-y-0">
                        <div>
                            <CardTitle className="text-base">
                                {returns.length} {returns.length === 1 ? 'return' : 'returns'}
                                <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                Business date {business_date}. This is NOT the void of a sale: the void leaves the
                                goods where they are, and here the goods come back, to the very lot they came from.
                                The customer book is compensated with a return of its own type.
                            </CardDescription>
                        </div>

                        {can('sale_return.create') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setWarning(null);
                                    setSaleId('');
                                    setLines([]);
                                    setQuantities({});
                                    setCreating(true);
                                }}
                            >
                                <Plus />
                                New return
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {returns.length === 0 ? (
                            <p className="text-muted-foreground text-sm">There is no return yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Date</th>
                                            <th className="px-2 py-2 text-left font-medium">Sale</th>
                                            <th className="px-2 py-2 text-left font-medium">Product</th>
                                            <th className="px-2 py-2 text-right font-medium">Quantity</th>
                                            <th className="px-2 py-2 text-right font-medium">Total</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {returns.map((devolucion) => (
                                            <tr key={devolucion.id} className="border-b last:border-0">
                                                <td className="px-2 py-2 tabular-nums">{devolucion.return_date}</td>
                                                <td className="px-2 py-2">
                                                    {devolucion.invoice_no ?? `#${devolucion.sale_id}`}
                                                </td>
                                                <td className="px-2 py-2">{devolucion.product_name ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {devolucion.quantity ?? '—'}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {formatAmount(devolucion.total, currency)}
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

            {/* El ALTA: la venta y las lineas que se devuelven. */}
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
                        <DialogTitle>New return</DialogTitle>
                        <DialogDescription>
                            The merchandise goes back to the lot it came from, and the customer book is compensated
                            with a return of its own type. If the customer ends up with a credit, the store owes it:
                            that credit is a liability.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="return-sale">Sale</Label>
                            <Select value={saleId} onValueChange={(value) => loadLines(value)}>
                                <SelectTrigger id="return-sale" aria-label="Sale of the return">
                                    <SelectValue placeholder="Sale" />
                                </SelectTrigger>
                                <SelectContent>
                                    {sales.map((venta) => (
                                        <SelectItem key={venta.id} value={venta.id.toString()}>
                                            {venta.invoice_no ?? `#${venta.id}`} · {venta.sale_date}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {lines.length > 0 && (
                            <div className="grid gap-2">
                                {lines.map((linea) => (
                                    <div key={linea.id} className="grid gap-2 rounded-lg border p-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-sm font-medium">
                                                {linea.product_code} · {linea.product_name}
                                            </span>
                                            <Badge variant="secondary">{linea.available} left</Badge>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor={`return-quantity-${linea.id}`}>Quantity to return</Label>
                                            <Input
                                                id={`return-quantity-${linea.id}`}
                                                value={quantities[linea.id] ?? ''}
                                                onChange={(event) =>
                                                    setQuantities({ ...quantities, [linea.id]: event.target.value })
                                                }
                                                placeholder={linea.available}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={createReturn}>
                            Register the return
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
