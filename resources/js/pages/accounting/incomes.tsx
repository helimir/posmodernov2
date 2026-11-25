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
 * An income of the listing, as the backend hands it over.
 *
 * An income is money that COMES IN and that does not come from a sale. The
 * `amount` is a DECIMAL STRING, and `payment_type` says where the money
 * entered: the drawer of the till or a bank account.
 */
export interface IncomeRow {
    id: number;
    amount: string;
    description: string | null;
    reference: string | null;
    business_date: string;
    status: 'recorded' | 'cancelled';
    payment_type: 'cash' | 'bank';
    bank_account_id: number | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    source: { code: string; name: string; account_code: string | null } | null;
}

/**
 * An origin of income, with the account it is credited to.
 */
export interface IncomeSource {
    id: number;
    code: string;
    name: string;
    account_code: string | null;
}

/**
 * A bank account of the store, with its ledger account.
 */
export interface BankAccountOption {
    id: number;
    name: string;
    account_code: string;
}

export interface IncomesProps {
    incomes: IncomeRow[];
    sources: IncomeSource[];
    bank_accounts: BankAccountOption[];
    currency: PosCurrency;
    business_date: string;
    store: { id: number; name: string; code_name: string | null };
}

/**
 * The value that means "the cash of the till".
 */
const CASH = 'cash';

/**
 * The draft of the ALTA: the amount, the origin and where the money enters.
 */
interface IncomeDraft {
    amount: string;
    income_source_id: string;
    payment_type: string;
    bank_account_id: string;
    description: string;
    reference: string;
}

/**
 * The INCOMES of the store: the money that comes in and does not come from a
 * sale.
 */
export default function Incomes({ incomes, sources, bank_accounts, currency, business_date, store }: IncomesProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [creating, setCreating] = useState(false);
    const [cancelling, setCancelling] = useState<IncomeRow | null>(null);
    const [reason, setReason] = useState('');
    const [draft, setDraft] = useState<IncomeDraft>({
        amount: '',
        income_source_id: '',
        payment_type: CASH,
        bank_account_id: '',
        description: '',
        reference: '',
    });

    /**
     * The ALTA: the income is born in the server and its ledger entry is
     * written there.
     */
    const createIncome = async () => {
        setError(null);
        setSaving(true);

        try {
            await postJson('/api/contabilidad/ingresos', {
                amount: draft.amount,
                income_source_id: draft.income_source_id === '' ? null : Number(draft.income_source_id),
                payment_type: draft.payment_type,
                bank_account_id:
                    draft.payment_type === 'bank' && draft.bank_account_id !== '' ? Number(draft.bank_account_id) : null,
                description: draft.description,
                reference: draft.reference,
            });

            setCreating(false);
            setDraft({
                amount: '',
                income_source_id: '',
                payment_type: CASH,
                bank_account_id: '',
                description: '',
                reference: '',
            });
            router.reload({ only: ['incomes'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The income could not be created.');
        } finally {
            setSaving(false);
        }
    };

    /**
     * The CANCEL: the income is MARKED, never deleted, and it needs a reason.
     */
    const cancelIncome = async () => {
        if (cancelling === null) {
            return;
        }

        setError(null);
        setSaving(true);

        try {
            await postJson(`/api/contabilidad/ingresos/${cancelling.id}/anular`, { reason });

            setCancelling(null);
            setReason('');
            router.reload({ only: ['incomes'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The income could not be cancelled.');
        } finally {
            setSaving(false);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Incomes',
            href: '/contabilidad/ingresos',
        },
    ];

    /**
     * The name of the bank account an income entered.
     */
    const nombreDelBanco = (id: number | null): string => {
        if (id === null) {
            return '';
        }

        return bank_accounts.find((banco) => banco.id === id)?.name ?? '';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Incomes" />

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
                                {incomes.length} {incomes.length === 1 ? 'income' : 'incomes'}
                                <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                Business date {business_date}. An income is money that comes in and does not come from a
                                sale. It is born in the register of the store and its ledger entry is written from it.
                                Entered in the till, it raises the cash of the day; entered in the bank, it does not.
                            </CardDescription>
                        </div>

                        {can('income.create') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setDraft({
                                        amount: '',
                                        income_source_id: sources[0]?.id.toString() ?? '',
                                        payment_type: CASH,
                                        bank_account_id: '',
                                        description: '',
                                        reference: '',
                                    });
                                    setCreating(true);
                                }}
                            >
                                <Plus />
                                New income
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {incomes.length === 0 ? (
                            <p className="text-muted-foreground text-sm">There is no income yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Date</th>
                                            <th className="px-2 py-2 text-left font-medium">Origin</th>
                                            <th className="px-2 py-2 text-left font-medium">Description</th>
                                            <th className="px-2 py-2 text-right font-medium">Amount</th>
                                            <th className="px-2 py-2 text-left font-medium">Entered in</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {incomes.map((ingreso) => (
                                            <tr
                                                key={ingreso.id}
                                                className={
                                                    ingreso.status === 'recorded'
                                                        ? 'border-b last:border-0'
                                                        : 'border-b last:border-0 text-muted-foreground'
                                                }
                                            >
                                                <td className="px-2 py-2 tabular-nums">{ingreso.business_date}</td>
                                                <td className="px-2 py-2">{ingreso.source?.name ?? '—'}</td>
                                                <td className="px-2 py-2">{ingreso.description ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {formatAmount(ingreso.amount, currency)}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {ingreso.payment_type === 'cash'
                                                        ? 'Cash'
                                                        : nombreDelBanco(ingreso.bank_account_id)}
                                                </td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={ingreso.status === 'recorded' ? 'default' : 'secondary'}>
                                                        {ingreso.status === 'recorded' ? 'Recorded' : 'Cancelled'}
                                                    </Badge>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <div className="flex justify-end">
                                                        {can('income.cancel') && ingreso.status === 'recorded' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setError(null);
                                                                    setReason('');
                                                                    setCancelling(ingreso);
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

            {/* El ALTA: el importe, el origen y donde entra el dinero. */}
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
                        <DialogTitle>New income</DialogTitle>
                        <DialogDescription>
                            The income is born in the register of the store and its ledger entry is written from it.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="income-amount">Amount</Label>
                            <Input
                                id="income-amount"
                                value={draft.amount}
                                onChange={(event) => setDraft({ ...draft, amount: event.target.value })}
                                placeholder="80.00"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="income-source">Origin</Label>
                            <Select
                                value={draft.income_source_id}
                                onValueChange={(value) => setDraft({ ...draft, income_source_id: value })}
                            >
                                <SelectTrigger id="income-source" aria-label="Origin of the income">
                                    <SelectValue placeholder="Origin" />
                                </SelectTrigger>
                                <SelectContent>
                                    {sources.map((origen) => (
                                        <SelectItem key={origen.id} value={origen.id.toString()}>
                                            {origen.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="income-payment">Entered in</Label>
                            <Select
                                value={draft.payment_type}
                                onValueChange={(value) => setDraft({ ...draft, payment_type: value })}
                            >
                                <SelectTrigger id="income-payment" aria-label="Where the money enters">
                                    <SelectValue placeholder="Entered in" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={CASH}>Cash</SelectItem>
                                    <SelectItem value="bank">Bank account</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {draft.payment_type === 'bank' && (
                            <div className="grid gap-2">
                                <Label htmlFor="income-bank">Bank account</Label>
                                <Select
                                    value={draft.bank_account_id}
                                    onValueChange={(value) => setDraft({ ...draft, bank_account_id: value })}
                                >
                                    <SelectTrigger id="income-bank" aria-label="Bank account of the income">
                                        <SelectValue placeholder="Bank account" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {bank_accounts.map((banco) => (
                                            <SelectItem key={banco.id} value={banco.id.toString()}>
                                                {banco.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="income-description">Description</Label>
                            <Input
                                id="income-description"
                                value={draft.description}
                                onChange={(event) => setDraft({ ...draft, description: event.target.value })}
                                placeholder="Rent collected"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="income-reference">Reference</Label>
                            <Input
                                id="income-reference"
                                value={draft.reference}
                                onChange={(event) => setDraft({ ...draft, reference: event.target.value })}
                                placeholder="INV-0001"
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={createIncome}>
                            Create income
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
                        <DialogTitle>Cancel the income?</DialogTitle>
                        <DialogDescription>
                            The income will be MARKED, never deleted, and its ledger entry will be reversed. The reason is
                            mandatory.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="income-reason">Reason</Label>
                        <Input
                            id="income-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder="Registered twice"
                        />
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelling(null)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={cancelIncome}>
                            Cancel the income
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
