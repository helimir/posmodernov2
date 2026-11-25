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
 * An expense of the listing, as the backend hands it over.
 *
 * `amount` is a DECIMAL STRING: the columns are `numeric(25,4)` and a `float`
 * is not money. `payment_type` says WHERE the money came from, and that is
 * the difference between an expense that takes the cash of the till and one
 * that does not: an expense paid from the BANK never touched the drawer.
 */
export interface ExpenseRow {
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
    category: { code: string; name: string; account_code: string | null } | null;
}

/**
 * A category of expense, with the account it is charged to.
 */
export interface ExpenseCategory {
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

export interface ExpensesProps {
    expenses: ExpenseRow[];
    categories: ExpenseCategory[];
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
 * The draft of the ALTA: the amount, the category and where it is paid from.
 */
interface ExpenseDraft {
    amount: string;
    expense_category_id: string;
    payment_type: string;
    bank_account_id: string;
    description: string;
    reference: string;
}

/**
 * The EXPENSES of the store: what the business spends, and where each one was
 * paid from.
 */
export default function Expenses({ expenses, categories, bank_accounts, currency, business_date, store }: ExpensesProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [creating, setCreating] = useState(false);
    const [cancelling, setCancelling] = useState<ExpenseRow | null>(null);
    const [reason, setReason] = useState('');
    const [draft, setDraft] = useState<ExpenseDraft>({
        amount: '',
        expense_category_id: '',
        payment_type: CASH,
        bank_account_id: '',
        description: '',
        reference: '',
    });

    /**
     * The ALTA: the expense is born in the server and its ledger entry is
     * written there. The screen sends the DATA and the server decides which
     * accounts are charged and credited.
     */
    const createExpense = async () => {
        setError(null);
        setSaving(true);

        try {
            await postJson('/api/contabilidad/gastos', {
                amount: draft.amount,
                expense_category_id: draft.expense_category_id === '' ? null : Number(draft.expense_category_id),
                payment_type: draft.payment_type,
                bank_account_id:
                    draft.payment_type === 'bank' && draft.bank_account_id !== '' ? Number(draft.bank_account_id) : null,
                description: draft.description,
                reference: draft.reference,
            });

            setCreating(false);
            setDraft({
                amount: '',
                expense_category_id: '',
                payment_type: CASH,
                bank_account_id: '',
                description: '',
                reference: '',
            });
            router.reload({ only: ['expenses'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The expense could not be created.');
        } finally {
            setSaving(false);
        }
    };

    /**
     * The CANCEL: the expense is MARKED, never deleted, and it needs a reason.
     */
    const cancelExpense = async () => {
        if (cancelling === null) {
            return;
        }

        setError(null);
        setSaving(true);

        try {
            await postJson(`/api/contabilidad/gastos/${cancelling.id}/anular`, { reason });

            setCancelling(null);
            setReason('');
            router.reload({ only: ['expenses'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The expense could not be cancelled.');
        } finally {
            setSaving(false);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Expenses',
            href: '/contabilidad/gastos',
        },
    ];

    /**
     * The name of the bank account an expense was paid from.
     */
    const nombreDelBanco = (id: number | null): string => {
        if (id === null) {
            return '';
        }

        return bank_accounts.find((banco) => banco.id === id)?.name ?? '';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expenses" />

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
                                {expenses.length} {expenses.length === 1 ? 'expense' : 'expenses'}
                                <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                Business date {business_date}. An expense is born in the expense register and its ledger
                                entry is written from it. Paid in cash, it takes the money of the till; paid from the bank,
                                it does not: the money was never in the drawer. A cancelled expense is MARKED, never
                                deleted.
                            </CardDescription>
                        </div>

                        {can('expense.create') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setDraft({
                                        amount: '',
                                        expense_category_id: categories[0]?.id.toString() ?? '',
                                        payment_type: CASH,
                                        bank_account_id: '',
                                        description: '',
                                        reference: '',
                                    });
                                    setCreating(true);
                                }}
                            >
                                <Plus />
                                New expense
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {expenses.length === 0 ? (
                            <p className="text-muted-foreground text-sm">There is no expense yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Date</th>
                                            <th className="px-2 py-2 text-left font-medium">Category</th>
                                            <th className="px-2 py-2 text-left font-medium">Description</th>
                                            <th className="px-2 py-2 text-right font-medium">Amount</th>
                                            <th className="px-2 py-2 text-left font-medium">Paid from</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {expenses.map((gasto) => (
                                            <tr
                                                key={gasto.id}
                                                className={
                                                    gasto.status === 'recorded'
                                                        ? 'border-b last:border-0'
                                                        : 'border-b last:border-0 text-muted-foreground'
                                                }
                                            >
                                                <td className="px-2 py-2 tabular-nums">{gasto.business_date}</td>
                                                <td className="px-2 py-2">{gasto.category?.name ?? '—'}</td>
                                                <td className="px-2 py-2">{gasto.description ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">{formatAmount(gasto.amount, currency)}</td>
                                                <td className="px-2 py-2">
                                                    {gasto.payment_type === 'cash' ? 'Cash' : nombreDelBanco(gasto.bank_account_id)}
                                                </td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={gasto.status === 'recorded' ? 'default' : 'secondary'}>
                                                        {gasto.status === 'recorded' ? 'Recorded' : 'Cancelled'}
                                                    </Badge>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <div className="flex justify-end">
                                                        {can('expense.cancel') && gasto.status === 'recorded' && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() => {
                                                                    setError(null);
                                                                    setReason('');
                                                                    setCancelling(gasto);
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

            {/* El ALTA: el importe, la categoria y de donde se paga. */}
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
                        <DialogTitle>New expense</DialogTitle>
                        <DialogDescription>
                            The expense is born in the register of the store and its ledger entry is written from it. Paid
                            in cash, it takes the money of the till; paid from the bank, the money never touched the
                            drawer.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="expense-amount">Amount</Label>
                            <Input
                                id="expense-amount"
                                value={draft.amount}
                                onChange={(event) => setDraft({ ...draft, amount: event.target.value })}
                                placeholder="25.00"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="expense-category">Category</Label>
                            <Select
                                value={draft.expense_category_id}
                                onValueChange={(value) => setDraft({ ...draft, expense_category_id: value })}
                            >
                                <SelectTrigger id="expense-category" aria-label="Category of the expense">
                                    <SelectValue placeholder="Category" />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((categoria) => (
                                        <SelectItem key={categoria.id} value={categoria.id.toString()}>
                                            {categoria.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="expense-payment">Paid from</Label>
                            <Select
                                value={draft.payment_type}
                                onValueChange={(value) => setDraft({ ...draft, payment_type: value })}
                            >
                                <SelectTrigger id="expense-payment" aria-label="Where the expense is paid from">
                                    <SelectValue placeholder="Paid from" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={CASH}>Cash</SelectItem>
                                    <SelectItem value="bank">Bank account</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {draft.payment_type === 'bank' && (
                            <div className="grid gap-2">
                                <Label htmlFor="expense-bank">Bank account</Label>
                                <Select
                                    value={draft.bank_account_id}
                                    onValueChange={(value) => setDraft({ ...draft, bank_account_id: value })}
                                >
                                    <SelectTrigger id="expense-bank" aria-label="Bank account of the expense">
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
                            <Label htmlFor="expense-description">Description</Label>
                            <Input
                                id="expense-description"
                                value={draft.description}
                                onChange={(event) => setDraft({ ...draft, description: event.target.value })}
                                placeholder="Stationery of the store"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="expense-reference">Reference</Label>
                            <Input
                                id="expense-reference"
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
                        <Button disabled={saving} onClick={createExpense}>
                            Create expense
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
                        <DialogTitle>Cancel the expense?</DialogTitle>
                        <DialogDescription>
                            The expense will be MARKED, never deleted, and its ledger entry will be reversed. The reason is
                            mandatory: an expense cancelled without a reason is an expense nobody knows why it was
                            touched.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="expense-reason">Reason</Label>
                        <Input
                            id="expense-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder="Registered twice"
                        />
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelling(null)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={cancelExpense}>
                            Cancel the expense
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
