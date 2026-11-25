import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

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
 * A bank movement of the listing, as the backend hands it over.
 *
 * A movement takes the money from ONE treasury account of the store (the
 * till or a bank account) and puts it in ANOTHER. The `amount` is a DECIMAL
 * STRING, and `from` and `to` are the two sides with their ledger account.
 */
export interface BankMovementRow {
    id: number;
    amount: string;
    business_date: string;
    description: string | null;
    occurred_at: string;
    from: { account_code: string | null; name: string | null };
    to: { account_code: string | null; name: string | null };
}

/**
 * A bank account of the store, with its ledger account.
 */
export interface BankAccountOption {
    id: number;
    name: string;
    account_code: string;
}

export interface BankingProps {
    movements: BankMovementRow[];
    bank_accounts: BankAccountOption[];
    cash_account: { code: string; name: string | null };
    currency: PosCurrency;
    business_date: string;
    store: { id: number; name: string; code_name: string | null };
}

/**
 * The value that means "the cash of the till".
 */
const CASH = 'cash';

/**
 * The draft of the ALTA: the amount, the two sides of the movement.
 */
interface MovementDraft {
    amount: string;
    from_type: string;
    from_bank_account_id: string;
    to_type: string;
    to_bank_account_id: string;
    description: string;
    reference: string;
}

/**
 * The BANK MOVEMENTS of the store: the money that is shared out between the
 * till and the bank accounts.
 */
export default function Banking({
    movements,
    bank_accounts,
    cash_account,
    currency,
    business_date,
    store,
}: BankingProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [creating, setCreating] = useState(false);
    const [draft, setDraft] = useState<MovementDraft>({
        amount: '',
        from_type: CASH,
        from_bank_account_id: '',
        to_type: 'bank',
        to_bank_account_id: '',
        description: '',
        reference: '',
    });

    /**
     * The ALTA: the movement is written in the ledger by the server, which
     * resolves the two accounts of the treasury.
     */
    const createMovement = async () => {
        setError(null);
        setSaving(true);

        try {
            await postJson('/api/contabilidad/banca', {
                amount: draft.amount,
                from_type: draft.from_type,
                from_bank_account_id:
                    draft.from_type === 'bank' && draft.from_bank_account_id !== '' ? Number(draft.from_bank_account_id) : null,
                to_type: draft.to_type,
                to_bank_account_id:
                    draft.to_type === 'bank' && draft.to_bank_account_id !== '' ? Number(draft.to_bank_account_id) : null,
                description: draft.description,
                reference: draft.reference,
            });

            setCreating(false);
            setDraft({
                amount: '',
                from_type: CASH,
                from_bank_account_id: '',
                to_type: 'bank',
                to_bank_account_id: '',
                description: '',
                reference: '',
            });
            router.reload({ only: ['movements'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The bank movement could not be registered.');
        } finally {
            setSaving(false);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Bank movements',
            href: '/contabilidad/banca',
        },
    ];

    /**
     * The name of one side of the movement: the till or a bank account.
     */
    const nombreDeLaCuenta = (accountCode: string | null): string => {
        if (accountCode === null) {
            return '—';
        }

        if (accountCode === cash_account.code) {
            return cash_account.name ?? 'Cash';
        }

        return bank_accounts.find((banco) => banco.account_code === accountCode)?.name ?? accountCode;
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank movements" />

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
                                {movements.length} {movements.length === 1 ? 'movement' : 'movements'}
                                <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                Business date {business_date}. A movement shares the money of the treasury out: it takes it
                                from one account of the store and puts it in another. The movement that touches the till is
                                the one that moves the cash of the day.
                            </CardDescription>
                        </div>

                        {can('banking.create') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setDraft({
                                        amount: '',
                                        from_type: CASH,
                                        from_bank_account_id: '',
                                        to_type: 'bank',
                                        to_bank_account_id: '',
                                        description: '',
                                        reference: '',
                                    });
                                    setCreating(true);
                                }}
                            >
                                <Plus />
                                New movement
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {movements.length === 0 ? (
                            <p className="text-muted-foreground text-sm">There is no bank movement yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Date</th>
                                            <th className="px-2 py-2 text-left font-medium">From</th>
                                            <th className="px-2 py-2 text-left font-medium">To</th>
                                            <th className="px-2 py-2 text-left font-medium">Description</th>
                                            <th className="px-2 py-2 text-right font-medium">Amount</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {movements.map((movimiento) => (
                                            <tr key={movimiento.id} className="border-b last:border-0">
                                                <td className="px-2 py-2 tabular-nums">{movimiento.business_date}</td>
                                                <td className="px-2 py-2">{nombreDeLaCuenta(movimiento.from.account_code)}</td>
                                                <td className="px-2 py-2">{nombreDeLaCuenta(movimiento.to.account_code)}</td>
                                                <td className="px-2 py-2">{movimiento.description ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {formatAmount(movimiento.amount, currency)}
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

            {/* El ALTA: el importe y las dos cuentas del movimiento. */}
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
                        <DialogTitle>New bank movement</DialogTitle>
                        <DialogDescription>
                            The money moves from one account of the treasury to another: the till, or a bank account. A
                            movement that touches the till needs an open cash day.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="movement-amount">Amount</Label>
                            <Input
                                id="movement-amount"
                                value={draft.amount}
                                onChange={(event) => setDraft({ ...draft, amount: event.target.value })}
                                placeholder="60.00"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="movement-from">From</Label>
                            <Select value={draft.from_type} onValueChange={(value) => setDraft({ ...draft, from_type: value })}>
                                <SelectTrigger id="movement-from" aria-label="Where the money comes from">
                                    <SelectValue placeholder="From" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={CASH}>Cash</SelectItem>
                                    <SelectItem value="bank">Bank account</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {draft.from_type === 'bank' && (
                            <div className="grid gap-2">
                                <Label htmlFor="movement-from-bank">Bank account</Label>
                                <Select
                                    value={draft.from_bank_account_id}
                                    onValueChange={(value) => setDraft({ ...draft, from_bank_account_id: value })}
                                >
                                    <SelectTrigger id="movement-from-bank" aria-label="Bank account that gives the money">
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
                            <Label htmlFor="movement-to">To</Label>
                            <Select value={draft.to_type} onValueChange={(value) => setDraft({ ...draft, to_type: value })}>
                                <SelectTrigger id="movement-to" aria-label="Where the money goes">
                                    <SelectValue placeholder="To" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={CASH}>Cash</SelectItem>
                                    <SelectItem value="bank">Bank account</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {draft.to_type === 'bank' && (
                            <div className="grid gap-2">
                                <Label htmlFor="movement-to-bank">Bank account</Label>
                                <Select
                                    value={draft.to_bank_account_id}
                                    onValueChange={(value) => setDraft({ ...draft, to_bank_account_id: value })}
                                >
                                    <SelectTrigger id="movement-to-bank" aria-label="Bank account that receives the money">
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
                            <Label htmlFor="movement-description">Description</Label>
                            <Input
                                id="movement-description"
                                value={draft.description}
                                onChange={(event) => setDraft({ ...draft, description: event.target.value })}
                                placeholder="Deposit to the bank"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="movement-reference">Reference</Label>
                            <Input
                                id="movement-reference"
                                value={draft.reference}
                                onChange={(event) => setDraft({ ...draft, reference: event.target.value })}
                                placeholder="DEP-0001"
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button disabled={saving} onClick={createMovement}>
                            Register movement
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
