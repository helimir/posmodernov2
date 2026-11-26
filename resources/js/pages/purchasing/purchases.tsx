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
import { postJson } from '@/lib/api';
import { type PosCurrency, formatAmount } from '@/lib/pos';
import { usePermissions } from '@/lib/permissions';
import { Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * A purchase of the listing, as the backend hands it over.
 *
 * `total` is a DECIMAL STRING: the columns are `numeric(25,4)` and a `float`
 * is not money. `entry_written` says whether the purchase reached the ledger:
 * a purchase whose entry could not be written is a purchase the accounting
 * does not see, and the screen has to say so.
 */
export interface PurchaseRow {
    id: number;
    purchase_date: string;
    invoice_no: string | null;
    reference_no: string | null;
    status: 'draft' | 'received' | 'paid' | 'cancelled';
    total: string;
    cancellation_reason: string | null;
    cancelled_at: string | null;
    entry_written: boolean;
    supplier: { id: number; name: string } | null;
}

/**
 * A supplier of the store, with the balance the store owes.
 */
export interface SupplierOption {
    id: number;
    name: string;
    balance: string;
}

/**
 * A product of the catalog of the store, with its purchase price.
 */
export interface ProductOption {
    id: number;
    code: string;
    name: string;
    purchase_price: string;
}

export interface PurchasesProps {
    purchases: PurchaseRow[];
    suppliers: SupplierOption[];
    products: ProductOption[];
    currency: PosCurrency;
    business_date: string;
    store: { id: number; name: string; code_name: string | null };
}

/**
 * The draft of a LINE of the purchase.
 */
interface PurchaseLineDraft {
    product_id: string;
    quantity: string;
    unit_price: string;
    discount: string;
}

/**
 * The PURCHASES of the store: the merchandise that comes in, with its lots and
 * its debt.
 */
export default function Purchases({ purchases, suppliers, products, currency, business_date, store }: PurchasesProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [warning, setWarning] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [creating, setCreating] = useState(false);
    const [cancelling, setCancelling] = useState<PurchaseRow | null>(null);
    const [reason, setReason] = useState('');
    const [supplierId, setSupplierId] = useState('');
    const [invoiceNo, setInvoiceNo] = useState('');
    const [lines, setLines] = useState<PurchaseLineDraft[]>([]);

    /**
     * The ALTA: the purchase is born in the server, with its lots and its
     * ledger entry. The screen sends the DATA and the server decides what the
     * entry says.
     */
    const createPurchase = async () => {
        setError(null);
        setWarning(null);
        setSaving(true);

        try {
            const respuesta = await postJson<{ warning: string | null }>('/api/compras', {
                supplier_id: supplierId === '' ? null : Number(supplierId),
                invoice_no: invoiceNo,
                lines: lines.map((linea) => ({
                    product_id: Number(linea.product_id),
                    quantity: linea.quantity,
                    unit_price: linea.unit_price,
                    discount: linea.discount,
                })),
            });

            setCreating(false);
            setSupplierId('');
            setInvoiceNo('');
            setLines([]);
            setWarning(respuesta.warning);
            router.reload({ only: ['purchases', 'suppliers'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The purchase could not be created.');
        } finally {
            setSaving(false);
        }
    };

    /**
     * The CANCEL: the purchase is MARKED, never deleted, and it needs a
     * reason. Its stock goes back, all of it.
     */
    const cancelPurchase = async () => {
        if (cancelling === null) {
            return;
        }

        setError(null);
        setWarning(null);
        setSaving(true);

        try {
            const respuesta = await postJson<{ warning: string | null }>(`/api/compras/${cancelling.id}/anular`, {
                reason,
            });

            setCancelling(null);
            setReason('');
            setWarning(respuesta.warning);
            router.reload({ only: ['purchases', 'suppliers'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The purchase could not be cancelled.');
        } finally {
            setSaving(false);
        }
    };

    /**
     * The line of the purchase that is being written.
     */
    const nuevaLinea = (): PurchaseLineDraft => ({
        product_id: '',
        quantity: '1',
        unit_price: '',
        discount: '0',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Purchases',
            href: '/compras',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchases" />

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
                                {purchases.length} {purchases.length === 1 ? 'purchase' : 'purchases'}
                                <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                Business date {business_date}. A purchase is NOT an expense: it is stock that comes in
                                and a debt with the supplier. The stock enters through its lots, so the till never
                                writes it by hand. A cancelled purchase is MARKED, never deleted, and it needs a
                                reason.
                            </CardDescription>
                        </div>

                        {can('purchase.create') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setWarning(null);
                                    setSupplierId(suppliers[0]?.id.toString() ?? '');
                                    setInvoiceNo('');
                                    setLines([nuevaLinea()]);
                                    setCreating(true);
                                }}
                            >
                                <Plus />
                                New purchase
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {purchases.length === 0 ? (
                            <p className="text-muted-foreground text-sm">There is no purchase yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Date</th>
                                            <th className="px-2 py-2 text-left font-medium">Supplier</th>
                                            <th className="px-2 py-2 text-left font-medium">Invoice</th>
                                            <th className="px-2 py-2 text-right font-medium">Total</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-left font-medium">Ledger</th>
                                            <th className="px-2 py-2 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {purchases.map((compra) => (
                                            <tr
                                                key={compra.id}
                                                className={
                                                    compra.status === 'cancelled'
                                                        ? 'border-b last:border-0 text-muted-foreground'
                                                        : 'border-b last:border-0'
                                                }
                                            >
                                                <td className="px-2 py-2 tabular-nums">{compra.purchase_date}</td>
                                                <td className="px-2 py-2">{compra.supplier?.name ?? '—'}</td>
                                                <td className="px-2 py-2">{compra.invoice_no ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {formatAmount(compra.total, currency)}
                                                </td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={compra.status === 'cancelled' ? 'secondary' : 'default'}>
                                                        {compra.status === 'cancelled' ? 'Cancelled' : 'Received'}
                                                    </Badge>
                                                </td>
                                                <td className="px-2 py-2">
                                                    {compra.entry_written ? 'Written' : 'Not written'}
                                                </td>
                                                <td className="px-2 py-2">
                                                    <div className="flex justify-end">
                                                        {can('purchase.cancel') && compra.status !== 'cancelled' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setError(null);
                                                                    setWarning(null);
                                                                    setReason('');
                                                                    setCancelling(compra);
                                                                }}
                                                            >
                                                                Cancel
                                                            </Button>
                                                        )}
                                                    </div>
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

            {/* El ALTA: el proveedor, el comprobante y las lineas. */}
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
                        <DialogTitle>New purchase</DialogTitle>
                        <DialogDescription>
                            The purchase is born in the server: its lines, its lots, the stock that comes in and the
                            debt with the supplier. The stock enters through the lots, so the till never writes it by
                            hand.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="purchase-supplier">Supplier</Label>
                            <Select value={supplierId} onValueChange={(value) => setSupplierId(value)}>
                                <SelectTrigger id="purchase-supplier" aria-label="Supplier of the purchase">
                                    <SelectValue placeholder="Supplier" />
                                </SelectTrigger>
                                <SelectContent>
                                    {suppliers.map((proveedor) => (
                                        <SelectItem key={proveedor.id} value={proveedor.id.toString()}>
                                            {proveedor.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="purchase-invoice">Invoice of the supplier</Label>
                            <Input
                                id="purchase-invoice"
                                value={invoiceNo}
                                onChange={(event) => setInvoiceNo(event.target.value)}
                                placeholder="FAC-0001"
                            />
                        </div>

                        {lines.map((linea, indice) => (
                            <div key={indice} className="grid gap-2 rounded-lg border p-3">
                                <div className="grid gap-2">
                                    <Label htmlFor={`purchase-product-${indice}`}>Product</Label>
                                    <Select
                                        value={linea.product_id}
                                        onValueChange={(value) => {
                                            const producto = products.find((p) => p.id.toString() === value);
                                            const copia = [...lines];

                                            copia[indice] = {
                                                ...linea,
                                                product_id: value,
                                                unit_price:
                                                    linea.unit_price !== ''
                                                        ? linea.unit_price
                                                        : (producto?.purchase_price ?? ''),
                                            };

                                            setLines(copia);
                                        }}
                                    >
                                        <SelectTrigger
                                            id={`purchase-product-${indice}`}
                                            aria-label="Product of the line"
                                        >
                                            <SelectValue placeholder="Product" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {products.map((producto) => (
                                                <SelectItem key={producto.id} value={producto.id.toString()}>
                                                    {producto.code} · {producto.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid grid-cols-3 gap-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor={`purchase-quantity-${indice}`}>Quantity</Label>
                                        <Input
                                            id={`purchase-quantity-${indice}`}
                                            value={linea.quantity}
                                            onChange={(event) => {
                                                const copia = [...lines];

                                                copia[indice] = { ...linea, quantity: event.target.value };

                                                setLines(copia);
                                            }}
                                            placeholder="10"
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={`purchase-price-${indice}`}>Unit price</Label>
                                        <Input
                                            id={`purchase-price-${indice}`}
                                            value={linea.unit_price}
                                            onChange={(event) => {
                                                const copia = [...lines];

                                                copia[indice] = { ...linea, unit_price: event.target.value };

                                                setLines(copia);
                                            }}
                                            placeholder="10.00"
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor={`purchase-discount-${indice}`}>Discount</Label>
                                        <Input
                                            id={`purchase-discount-${indice}`}
                                            value={linea.discount}
                                            onChange={(event) => {
                                                const copia = [...lines];

                                                copia[indice] = { ...linea, discount: event.target.value };

                                                setLines(copia);
                                            }}
                                            placeholder="0.00"
                                        />
                                    </div>
                                </div>

                                <div className="flex justify-end">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setLines(lines.filter((_, i) => i !== indice))}
                                    >
                                        Remove the line
                                    </Button>
                                </div>
                            </div>
                        ))}

                        <Button variant="outline" size="sm" onClick={() => setLines([...lines, nuevaLinea()])}>
                            <Plus />
                            Add a line
                        </Button>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={createPurchase}>
                            Create purchase
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* La ANULACION: el motivo es obligatorio. */}
            <Dialog
                open={cancelling !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setCancelling(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cancel the purchase?</DialogTitle>
                        <DialogDescription>
                            The purchase will be MARKED, never deleted, and the stock of ALL its lines will go back to
                            its own lot. The reason is mandatory: a purchase cancelled without a reason is a purchase
                            nobody knows why it was touched.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="purchase-reason">Reason</Label>
                        <Input
                            id="purchase-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder="Registered twice"
                        />
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelling(null)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={cancelPurchase}>
                            Cancel the purchase
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
