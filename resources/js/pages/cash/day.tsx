import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { type PosCurrency, formatAmount } from '@/lib/pos';
import { useState, type FormEvent } from 'react';

/**
 * A RESPONSIBLE of one end of the cash register: who opened it and who
 * closed it.
 *
 * It can be `null`: the gap is SHOWN, it is not invented.
 */
export interface CashDayActor {
    id: number;
    name: string;
}

/**
 * The register of the day, as the backend hands it over.
 *
 * `opening_balance` and `closing_balance` are DATA: the counted money. The
 * RUNNING balance is not here: it is DERIVED from the ledger entries of the
 * business date, and a derived value that can be written is a derived value
 * that can get out of sync.
 */
export interface CashDayRegister {
    id: number;
    status: 'open' | 'closed';
    opened_at: string | null;
    opening_balance: string;
    closed_at: string | null;
    closing_balance: string | null;
    opened_by: CashDayActor | null;
    closed_by: CashDayActor | null;
}

/**
 * What ENTERED the register by payment method.
 *
 * `is_cash` is false for a payment of type `credit`: that is a DEBT, not
 * money of the register, and the cashier does not count it.
 */
export interface CashDayPayment {
    id: number | null;
    name: string | null;
    type: string;
    amount: string;
    is_cash: boolean;
}

/**
 * The state of the screen: there is no register, it is open, or it is
 * closed. Three states, one screen.
 */
export type CashDayState = 'not_opened' | 'open' | 'closed';

export interface CashDayProps {
    state: CashDayState;
    store: { id: number; name: string; code_name: string | null };
    business_date: string;
    currency: PosCurrency;
    register: CashDayRegister | null;
    opening_balance: string;
    running_balance: string;
    expected_balance: string;
    counted_balance: string | null;
    difference: string | null;
    payments: CashDayPayment[];
    cash_total: string;
}

/**
 * A row of the figures: the label, the amount and the note.
 */
function Figure({ label, value, currency, note }: { label: string; value: string; currency: PosCurrency; note?: string }) {
    return (
        <div className="space-y-1">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p className="text-xl font-semibold tabular-nums">{formatAmount(value, currency)}</p>
            {note !== undefined && <p className="text-muted-foreground text-xs">{note}</p>}
        </div>
    );
}

/**
 * The cash register of the day.
 *
 * THE SCREEN HAS THREE STATES: there is no register (the opening form), the
 * register is open (the state and the closing form) and the register is
 * closed (the summary of the day).
 *
 * THE WRITES GO THROUGH THE API: the form does not POST to a page route, it
 * writes to `/caja/dia/abrir` or `/caja/dia/cerrar` and then RELOADS the
 * page. That is the rule of the repo: the pages are Inertia and the writes
 * are JSON.
 */
export default function CashDay({
    state,
    store,
    business_date,
    currency,
    register,
    opening_balance,
    running_balance,
    expected_balance,
    counted_balance,
    difference,
    payments,
    cash_total,
}: CashDayProps) {
    const { can } = usePermissions();
    const [openingBalance, setOpeningBalance] = useState('0.0000');
    const [countedBalance, setCountedBalance] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    /**
     * The OPENING: the register of the day, with the money counted by hand.
     */
    const abrir = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setSaving(true);
        setError(null);

        try {
            await postJson('/caja/dia/abrir', { opening_balance: openingBalance });

            router.reload();
        } catch (fallo) {
            setError((fallo as Error).message);
        } finally {
            setSaving(false);
        }
    };

    /**
     * The CLOSING: the register of the day, with the money counted by hand.
     */
    const cerrar = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setSaving(true);
        setError(null);

        try {
            await postJson('/caja/dia/cerrar', { closing_balance: countedBalance });

            router.reload();
        } catch (fallo) {
            setError((fallo as Error).message);
        } finally {
            setSaving(false);
        }
    };

    /**
     * The DIFFERENCE, with its sign. A difference of zero is a BALANCED
     * register, and anything else is shown: the cashier has to see it.
     */
    const descuadre = difference === null ? null : difference === '0.0000' || difference === '-0.0000';
    const sobra = difference !== null && !descuadre && !difference.startsWith('-');
    const absoluto = difference === null ? null : difference.replace('-', '');

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Cash day',
            href: '/caja/dia',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash day" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader className="flex-row items-center justify-between gap-3">
                        <CardTitle>
                            Cash day
                            <span className="text-muted-foreground ml-2 text-sm font-normal">
                                {business_date} · {store.name}
                            </span>
                        </CardTitle>

                        {state === 'open' && <Badge>Open</Badge>}
                        {state === 'closed' && <Badge variant="secondary">Closed</Badge>}
                        {state === 'not_opened' && <Badge variant="outline">Not opened</Badge>}
                    </CardHeader>

                    {state === 'not_opened' && (
                        <CardContent>
                            <p className="text-muted-foreground mb-4 text-sm">
                                There is no cash register for this business day. Count the money in the drawer and open it:
                                without an open register nothing can be charged.
                            </p>

                            <form onSubmit={abrir} className="flex flex-col gap-4 sm:flex-row sm:items-end">
                                <div className="space-y-1.5">
                                    <Label htmlFor="opening_balance">Opening balance</Label>
                                    <Input
                                        id="opening_balance"
                                        value={openingBalance}
                                        onChange={(event) => setOpeningBalance(event.target.value)}
                                        inputMode="decimal"
                                        placeholder="0.0000"
                                        required
                                    />
                                    <p className="text-muted-foreground text-xs">The money counted by hand in the drawer.</p>
                                </div>

                                {can('cash_day.open') && (
                                    <Button type="submit" disabled={saving}>
                                        {saving ? 'Opening…' : 'Open cash register'}
                                    </Button>
                                )}
                            </form>
                        </CardContent>
                    )}

                    {state === 'open' && (
                        <CardContent className="grid gap-6 sm:grid-cols-3">
                            <Figure label="Opening balance" value={opening_balance} currency={currency} />

                            <Figure
                                label="Running balance"
                                value={running_balance}
                                currency={currency}
                                note="Derived from the entries of this business day."
                            />

                            <Figure label="Expected" value={expected_balance} currency={currency} note="Opening balance plus the running balance." />
                        </CardContent>
                    )}

                    {state === 'closed' && (
                        <CardContent className="grid gap-6 sm:grid-cols-5">
                            <Figure label="Opening balance" value={opening_balance} currency={currency} />

                            <Figure label="Running balance" value={running_balance} currency={currency} />

                            <Figure label="Expected" value={expected_balance} currency={currency} />

                            <Figure label="Counted" value={counted_balance ?? '0.0000'} currency={currency} />

                            <div className="space-y-1">
                                <p className="text-muted-foreground text-xs">Difference</p>
                                <p className="text-xl font-semibold tabular-nums">
                                    {counted_balance === null ? '—' : formatAmount(difference ?? '0.0000', currency)}
                                </p>
                                {descuadre === true && <Badge variant="secondary">Balanced</Badge>}
                                {descuadre === false && (
                                    <Badge variant="destructive">{sobra ? 'Over by' : 'Short by'} {absoluto}</Badge>
                                )}
                            </div>
                        </CardContent>
                    )}
                </Card>

                {(state !== 'not_opened' || payments.length > 0) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">What entered the register</CardTitle>
                        </CardHeader>

                        <CardContent>
                            {payments.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Nothing has entered the register yet.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b">
                                                <th className="px-2 py-2 text-left font-medium">Payment method</th>
                                                <th className="px-2 py-2 text-left font-medium">Type</th>
                                                <th className="px-2 py-2 text-right font-medium">Amount</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {payments.map((payment, index) => (
                                                <tr key={payment.id ?? index} className="border-b last:border-0">
                                                    <td className="px-2 py-2">{payment.name ?? '—'}</td>
                                                    <td className="px-2 py-2">
                                                        {payment.type}
                                                        {!payment.is_cash && (
                                                            <Badge variant="outline" className="ml-2">
                                                                Not cash
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="px-2 py-2 text-right tabular-nums">{formatAmount(payment.amount, currency)}</td>
                                                </tr>
                                            ))}
                                        </tbody>

                                        <tfoot>
                                            <tr className="border-t">
                                                <td className="px-2 py-2 font-medium" colSpan={2}>
                                                    Cash of the register
                                                </td>
                                                <td className="px-2 py-2 text-right font-semibold tabular-nums">{formatAmount(cash_total, currency)}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {state === 'open' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Close the cash register</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <form onSubmit={cerrar} className="flex flex-col gap-4 sm:flex-row sm:items-end">
                                <div className="space-y-1.5">
                                    <Label htmlFor="closing_balance">Counted balance</Label>
                                    <Input
                                        id="closing_balance"
                                        value={countedBalance}
                                        onChange={(event) => setCountedBalance(event.target.value)}
                                        inputMode="decimal"
                                        placeholder={expected_balance}
                                        required
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        The money counted by hand. Expected: {formatAmount(expected_balance, currency)}.
                                    </p>
                                </div>

                                {can('cash_day.close') && (
                                    <Button type="submit" disabled={saving}>
                                        {saving ? 'Closing…' : 'Close cash register'}
                                    </Button>
                                )}
                            </form>
                        </CardContent>
                    </Card>
                )}

                {state === 'closed' && register !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Who was responsible</CardTitle>
                        </CardHeader>

                        <CardContent className="grid gap-6 sm:grid-cols-2">
                            <div className="space-y-1">
                                <p className="text-muted-foreground text-xs">Opened by</p>
                                <p className="text-sm font-medium">{register.opened_by?.name ?? '—'}</p>
                            </div>

                            <div className="space-y-1">
                                <p className="text-muted-foreground text-xs">Closed by</p>
                                <p className="text-sm font-medium">{register.closed_by?.name ?? '—'}</p>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {error !== null && (
                    <p className="bg-destructive/10 text-destructive rounded-md px-3 py-2 text-sm">{error}</p>
                )}
            </div>
        </AppLayout>
    );
}
