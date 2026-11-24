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
import { type PosCurrency, addAmount, compareAmounts, formatAmount, formatPlainAmount, multiplyAmount, subtractAmount } from '@/lib/pos';
import { printJobLabel, printJobVariant } from '@/lib/print';
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
 * A DENOMINATION of the store: the piece the cashier counts.
 *
 * `kind` comes from the catalog in the language of the DATA (`billete` or
 * `moneda`); the screen speaks the language of the cashier.
 */
export interface CashDayDenomination {
    id: number;
    value: string;
    kind: string;
}

/**
 * The EXPECTED piece count of a denomination: how many pieces of it SHOULD
 * be in the drawer.
 */
export interface CashDayExpectedPiece extends CashDayDenomination {
    count: number;
}

/**
 * The DIFFERENCE of one denomination: how many pieces are extra or missing.
 */
export interface CashDayCountDifference {
    id: number;
    value: string;
    kind: string;
    expected: number;
    counted: number;
    delta: number;
}

/**
 * The COUNT of the day: the catalog of the store, the expected breakdown and
 * what the cashier counted.
 *
 * `expected_error` says what is MISSING when the amount cannot be broken
 * down with the catalog of the store: a count that cannot be broken down is
 * a count that has to be said.
 */
export interface CashDayCount {
    denominations: CashDayDenomination[];
    expected: CashDayExpectedPiece[];
    expected_error: string | null;
    expected_total: string;
    counted: Record<string, number>;
    differences: CashDayCountDifference[];
    threshold: string;
}

/**
 * The state of the screen: there is no register, it is open, or it is
 * closed. Three states, one screen.
 */
export type CashDayState = 'not_opened' | 'open' | 'closed';

/**
 * A PRINT JOB of the Z: the state of the paper of the closing.
 *
 * The state is the one the state machine writes (`queued`, `claimed`,
 * `printing`, `printed`, `failed`), read as it is.
 */
export interface CashDayPrintJob {
    id: number;
    status: string;
    target_id: number;
    target_name: string;
    transport: string;
    attempts: number;
    reason: string | null;
    reprint_of_job_id: number | null;
    last_error: string | null;
    created_at: string | null;
}

/**
 * The SEALED Z: the closing of the day as a FACT.
 *
 * It is `null` when the day is not closed: there is nothing sealed to read.
 *
 * `verified` is the CHECK of the signature: when it is `false`, somebody
 * altered a sealed Z, and the screen has to say so.
 */
export interface CashDayZ {
    id: number;
    closed_at: string | null;
    closed_by: CashDayActor | null;
    content_hash: string;
    verified: boolean;
    opening_balance: string;
    expected_balance: string;
    counted_balance: string;
    difference: string;
    subtotal: string;
    tax_total: string;
    grand_total: string;
    sales_count: number;
    first_document_number: string | null;
    last_document_number: string | null;
    first_control_number: string | null;
    last_control_number: string | null;
    payments: CashDayPayment[];
    /**
     * The REASON of the closing: why the day was closed the way it was. It is
     * there because a difference over the threshold is not closed without an
     * explanation.
     */
    close_reason: string | null;
    /**
     * The COUNTED PIECES: how many pieces of each denomination the cashier
     * counted. It is empty when the day was closed by amount.
     */
    counted_pieces: Record<string, number>;
    reopened_at: string | null;
    reopened_by: CashDayActor | null;
    reopen_reason: string | null;
    /**
     * How many times the Z has been QUEUED for printing. It is the reason why
     * the screen asks for a REASON before printing it again: the Z is the
     * document of the closing, and taking it out twice has to say why.
     */
    print_count: number;
    /**
     * The PRINT JOBS of the Z, with their state.
     *
     * A counter is not a state: the operator has to know whether the paper
     * of the closing is still in the queue, being printed, or failed.
     */
    print_jobs: CashDayPrintJob[];
}

/**
 * A PRINT DESTINATION of the store: a physical printer the Z goes to.
 *
 * The `browser` transport is not here: the browser is the destination of the
 * PAPER (the HTML of the Z), not of the bytes.
 */
export interface CashDayPrintTarget {
    id: number;
    name: string;
    transport: string;
}

/**
 * A CLOSING in the history of the day.
 *
 * The history is why the Z is a row and not a calculation: a day that was
 * reopened keeps its first Z, marked.
 */
export interface CashDayZHistoryEntry {
    id: number;
    closed_at: string | null;
    closed_by: CashDayActor | null;
    content_hash: string;
    verified: boolean;
    expected_balance: string;
    counted_balance: string;
    difference: string;
    reopened_at: string | null;
    reopened_by: CashDayActor | null;
    reopen_reason: string | null;
}

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
    count: CashDayCount;
    z: CashDayZ | null;
    z_history: CashDayZHistoryEntry[];
    print_targets: CashDayPrintTarget[];
}

/**
 * The KIND of a piece, in the language of the screen.
 *
 * The catalog stores it in the language of the data (`billete`, `moneda`);
 * the cashier reads `Bill` and `Coin`.
 */
function kindLabel(kind: string): string {
    return kind === 'moneda' ? 'Coin' : 'Bill';
}

/**
 * The DIFFERENCE of a piece, in words: "2 bills of 50 extra".
 */
function differenceLabel(difference: CashDayCountDifference, currency: PosCurrency): string {
    const label = kindLabel(difference.kind);
    const pieces = Math.abs(difference.delta);
    const piece = `${pieces} ${pieces === 1 ? label.toLowerCase() : `${label.toLowerCase()}s`} of ${formatPlainAmount(
        difference.value,
        currency,
    )}`;

    return difference.delta > 0 ? `${piece} extra` : `${piece} missing`;
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
    count,
    z,
    z_history,
    print_targets,
}: CashDayProps) {
    const { can } = usePermissions();
    const [openingBalance, setOpeningBalance] = useState('0.0000');
    const [countedBalance, setCountedBalance] = useState('');
    const [piezas, setPiezas] = useState<Record<string, number>>({});
    const [motivo, setMotivo] = useState('');
    const [reopenReason, setReopenReason] = useState('');
    const [printReason, setPrintReason] = useState('');
    const [printingZ, setPrintingZ] = useState(false);
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
     * The CLOSING: the register of the day, with the money counted.
     *
     * THE COUNT HAS ONE TRUTH: when the store has a catalog of
     * denominations, the cashier counts PIECES and the system adds them up.
     * The amount is NOT sent along: the counted pieces and a typed amount are
     * two truths, and the closing only has one.
     */
    const cerrar = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setSaving(true);
        setError(null);

        try {
            if (hayCatalogo) {
                await postJson('/caja/dia/cerrar', { counted_pieces: conteoEnvio, reason: motivo });
            } else {
                await postJson('/caja/dia/cerrar', { closing_balance: countedBalance, reason: motivo });
            }

            router.reload();
        } catch (fallo) {
            setError((fallo as Error).message);
        } finally {
            setSaving(false);
        }
    };

    /**
     * The PRINT of the Z: the closing goes to the queue of the destination,
     * and the LAN agent writes the bytes.
     *
     * AND A REPRINT NEEDS A REASON: the Z is the document of the closing, and
     * taking it out again has to leave a trace of WHY.
     */
    const imprimirZ = async (targetId: number) => {
        setPrintingZ(true);
        setError(null);

        try {
            await postJson('/caja/dia/z/imprimir', {
                print_target_id: targetId,
                reason: printReason.trim() === '' ? undefined : printReason.trim(),
            });

            setPrintReason('');
            router.reload();
        } catch (fallo) {
            setError((fallo as Error).message);
        } finally {
            setPrintingZ(false);
        }
    };

    /**
     * The COUNT of a piece: what the cashier counted.
     */
    const contar = (id: number, value: string) => {
        const numero = Number.parseInt(value, 10);

        setPiezas((actual) => ({
            ...actual,
            [String(id)]: Number.isNaN(numero) || numero < 0 ? 0 : numero,
        }));
    };

    /**
     * The CATALOG of the store: without it there are no pieces to count, and
     * the closing is by amount.
     */
    const hayCatalogo = count.denominations.length > 0;

    /**
     * The pieces COUNTED: the ones the cashier typed while the register is
     * open, and the ones the sealed Z kept when the day is closed.
     */
    const countedPieces = state === 'closed' ? count.counted : piezas;

    /**
     * The EXPECTED count of each denomination: how many pieces SHOULD be in
     * the drawer.
     */
    const expectedPieces: Record<string, number> = {};

    for (const piece of count.expected) {
        expectedPieces[String(piece.id)] = piece.count;
    }

    /**
     * The TOTAL COUNTED: the system adds up the pieces. When the day was
     * closed by amount, the Z keeps the amount instead.
     */
    const sumaPiezas = count.denominations.reduce(
        (total, denomination) => addAmount(total, multiplyAmount(denomination.value, String(countedPieces[String(denomination.id)] ?? 0))),
        '0.0000',
    );

    const hayConteo = Object.keys(countedPieces).length > 0;

    const totalContado = state === 'closed' && !hayConteo ? (counted_balance ?? '0.0000') : sumaPiezas;

    /**
     * The DIFFERENCE of the count: the counted total against the expected.
     */
    const diferencia = subtractAmount(totalContado, expected_balance);

    const diferenciaAbsoluta = diferencia.startsWith('-') ? diferencia.slice(1) : diferencia;

    /**
     * Does the difference need a REASON? A difference over the threshold is
     * an EXCEPTION, and an exception without an explanation is not an
     * exception.
     */
    const exigeMotivo = compareAmounts(diferencia, '0.0000') !== 0 && compareAmounts(diferenciaAbsoluta, count.threshold) >= 0;

    /**
     * The count that travels to the server: only the pieces that were
     * counted.
     */
    const conteoEnvio: Record<string, number> = {};

    for (const denomination of count.denominations) {
        const pieces = countedPieces[String(denomination.id)] ?? 0;

        if (pieces > 0) {
            conteoEnvio[String(denomination.id)] = pieces;
        }
    }

    /**
     * The REOPENING: undoing a closing needs a REASON, and the previous Z
     * stays in the history, marked.
     */
    const reabrir = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setSaving(true);
        setError(null);

        try {
            await postJson('/caja/dia/reabrir', { reason: reopenReason });

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

    /**
     * The CLOSINGS that were undone: the day was reopened at least once.
     */
    const reabiertas = z_history.filter((cierre) => cierre.reopened_at !== null);

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
                            <Figure
                                label="Opening balance"
                                value={opening_balance}
                                currency={currency}
                                note="The money counted by hand in the drawer."
                            />

                            <Figure
                                label="Running balance"
                                value={running_balance}
                                currency={currency}
                                note="The book: the entries of this business day, the opening one included."
                            />

                            <Figure
                                label="Expected"
                                value={expected_balance}
                                currency={currency}
                                note="The running balance: the opening balance is already in the book."
                            />
                        </CardContent>
                    )}

                    {state === 'closed' && (
                        <CardContent className="grid gap-6 sm:grid-cols-5">
                            <Figure
                                label="Opening balance"
                                value={opening_balance}
                                currency={currency}
                                note="The money counted by hand in the drawer."
                            />

                            <Figure
                                label="Running balance"
                                value={running_balance}
                                currency={currency}
                                note="The book right now: the opening one included."
                            />

                            <Figure
                                label="Expected"
                                value={expected_balance}
                                currency={currency}
                                note="The running balance: the opening balance is already in the book."
                            />

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

                {z !== null && (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between gap-3">
                            <CardTitle className="text-base">Sealed closing (Z)</CardTitle>
                            {z.verified ? (
                                <Badge variant="secondary">Sealed</Badge>
                            ) : (
                                <Badge variant="destructive">Signature does not match</Badge>
                            )}
                        </CardHeader>

                        <CardContent className="space-y-6">
                            {z.verified === false && (
                                <p className="bg-destructive/10 text-destructive rounded-md px-3 py-2 text-sm">
                                    The signature of this Z does not match its content: somebody altered a sealed closing. Its
                                    figures cannot be trusted.
                                </p>
                            )}

                            <div className="grid gap-6 sm:grid-cols-4">
                                <Figure label="Subtotal" value={z.subtotal} currency={currency} />

                                <Figure label="Tax" value={z.tax_total} currency={currency} />

                                <Figure label="Total" value={z.grand_total} currency={currency} />

                                <div className="space-y-1">
                                    <p className="text-muted-foreground text-xs">Sales</p>
                                    <p className="text-xl font-semibold tabular-nums">{z.sales_count}</p>
                                </div>
                            </div>

                            <div className="grid gap-6 sm:grid-cols-3">
                                <Figure label="Expected" value={z.expected_balance} currency={currency} />

                                <Figure label="Counted" value={z.counted_balance} currency={currency} />

                                <Figure label="Difference" value={z.difference} currency={currency} />
                            </div>

                            <dl className="grid gap-4 text-sm sm:grid-cols-2">
                                <div className="space-y-1">
                                    <dt className="text-muted-foreground text-xs">Closed by</dt>
                                    <dd className="font-medium">{z.closed_by?.name ?? '—'}</dd>
                                </div>

                                <div className="space-y-1">
                                    <dt className="text-muted-foreground text-xs">Closed at</dt>
                                    <dd className="font-medium">{z.closed_at ?? '—'}</dd>
                                </div>

                                <div className="space-y-1">
                                    <dt className="text-muted-foreground text-xs">Documents</dt>
                                    <dd className="font-medium">
                                        {z.first_document_number ?? '—'}
                                        {z.last_document_number !== null && z.last_document_number !== z.first_document_number
                                            ? ` → ${z.last_document_number}`
                                            : ''}
                                    </dd>
                                </div>

                                <div className="space-y-1">
                                    <dt className="text-muted-foreground text-xs">Controls</dt>
                                    <dd className="font-medium">
                                        {z.first_control_number ?? '—'}
                                        {z.last_control_number !== null && z.last_control_number !== z.first_control_number
                                            ? ` → ${z.last_control_number}`
                                            : ''}
                                    </dd>
                                </div>

                                <div className="space-y-1 sm:col-span-2">
                                    <dt className="text-muted-foreground text-xs">Signature</dt>
                                    <dd className="font-mono text-xs break-all">{z.content_hash}</dd>
                                </div>

                                {z.close_reason !== null && (
                                    <div className="space-y-1 sm:col-span-2">
                                        <dt className="text-muted-foreground text-xs">Reason</dt>
                                        <dd className="font-medium">{z.close_reason}</dd>
                                    </div>
                                )}
                            </dl>

                            {z.payments.length > 0 && (
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
                                            {z.payments.map((payment, index) => (
                                                <tr key={payment.id ?? index} className="border-b last:border-0">
                                                    <td className="px-2 py-2">{payment.name ?? '—'}</td>
                                                    <td className="px-2 py-2">{payment.type}</td>
                                                    <td className="px-2 py-2 text-right tabular-nums">
                                                        {formatAmount(payment.amount, currency)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {reabiertas.length > 0 && (
                                <p className="text-muted-foreground text-xs">
                                    This day was reopened {reabiertas.length} time(s): the closures below keep the reason.
                                </p>
                            )}

                            {/*
                              The PRINT of the Z: the PAPER (the HTML of the
                              sealed Z) and the PRINTER (the bytes, queued for
                              the LAN agent).
                            */}
                            {can('cash_day.print') && (
                                <div className="space-y-3 border-t pt-4">
                                    <div className="flex flex-wrap gap-2">
                                        <Button asChild variant="outline">
                                            <a href="/caja/dia/z" target="_blank" rel="noreferrer">
                                                View the Z
                                            </a>
                                        </Button>

                                        {print_targets.map((target) => (
                                            <Button
                                                key={target.id}
                                                variant="outline"
                                                onClick={() => imprimirZ(target.id)}
                                                disabled={printingZ}
                                            >
                                                {printingZ ? 'Queueing…' : `Print the Z to ${target.name}`}
                                            </Button>
                                        ))}
                                    </div>

                                    {z.print_count > 0 && (
                                        <div className="space-y-2">
                                            <p className="text-muted-foreground text-xs">
                                                This Z has been printed {z.print_count} time(s). Printing it again needs a
                                                reason: the Z is the document of the closing.
                                            </p>

                                            <Input
                                                value={printReason}
                                                onChange={(event) => setPrintReason(event.target.value)}
                                                placeholder="Reason for the reprint"
                                            />
                                        </div>
                                    )}
                                </div>
                            )}

                            {/*
                              EL ESTADO DE LOS TRABAJOS del Z: un contador no
                              es un estado. El operador tiene que saber si el
                              papel del cierre sigue en la cola, se esta
                              imprimiendo o fallo.

                              Y SE VE SIN PODER IMPRIMIR: el estado es una
                              LECTURA, y leer la caja del dia no es imprimir
                              el Z.
                            */}
                            {z.print_jobs.length > 0 && (
                                <div className="mt-3 overflow-x-auto border-t pt-3">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-b">
                                                <th className="px-2 py-1 text-left font-medium">Job</th>
                                                <th className="px-2 py-1 text-left font-medium">Status</th>
                                                <th className="px-2 py-1 text-left font-medium">Destination</th>
                                                <th className="px-2 py-1 text-right font-medium">Attempts</th>
                                                <th className="px-2 py-1 text-left font-medium">Failure</th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {z.print_jobs.map((job) => (
                                                <tr key={job.id} className="border-b last:border-0">
                                                    <td className="px-2 py-1 font-mono">#{job.id}</td>
                                                    <td className="px-2 py-1">
                                                        <Badge variant={printJobVariant(job.status)}>{printJobLabel(job.status)}</Badge>
                                                    </td>
                                                    <td className="px-2 py-1">{job.target_name}</td>
                                                    <td className="px-2 py-1 text-right tabular-nums">{job.attempts}</td>
                                                    <td className="px-2 py-1">{job.last_error ?? '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {z_history.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Closings of this day</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-3">
                            {z_history.map((cierre) => (
                                <div key={cierre.id} className="rounded-md border px-3 py-2 text-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="font-medium">
                                            Closed by {cierre.closed_by?.name ?? '—'} · {cierre.closed_at ?? '—'}
                                        </p>

                                        {cierre.reopened_at === null ? (
                                            <Badge variant="secondary">In force</Badge>
                                        ) : (
                                            <Badge variant="outline">Reopened</Badge>
                                        )}
                                    </div>

                                    <p className="text-muted-foreground text-xs">
                                        Expected {formatAmount(cierre.expected_balance, currency)} · Counted{' '}
                                        {formatAmount(cierre.counted_balance, currency)} · Difference{' '}
                                        {formatAmount(cierre.difference, currency)}
                                    </p>

                                    {cierre.reopened_at !== null && (
                                        <p className="text-muted-foreground text-xs">
                                            Reopened by {cierre.reopened_by?.name ?? '—'} · {cierre.reopened_at} ·{' '}
                                            {cierre.reopen_reason ?? '—'}
                                        </p>
                                    )}

                                    {cierre.verified === false && (
                                        <p className="text-destructive text-xs">
                                            The signature of this closing does not match its content: somebody altered it.
                                        </p>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

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

                {hayCatalogo && (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between gap-3">
                            <CardTitle className="text-base">Cash count by denomination</CardTitle>
                            <Badge variant="outline">{count.denominations.length} denominations</Badge>
                        </CardHeader>

                        <CardContent className="space-y-4">
                            {count.expected_error !== null && (
                                <p className="bg-destructive/10 text-destructive rounded-md px-3 py-2 text-sm">
                                    {count.expected_error}
                                </p>
                            )}

                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Denomination</th>
                                            <th className="px-2 py-2 text-left font-medium">Kind</th>
                                            <th className="px-2 py-2 text-right font-medium">Expected</th>
                                            <th className="px-2 py-2 text-right font-medium">Counted</th>
                                            <th className="px-2 py-2 text-right font-medium">Difference</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {count.denominations.map((denomination) => {
                                            const esperado = expectedPieces[String(denomination.id)] ?? 0;
                                            const contado = countedPieces[String(denomination.id)] ?? 0;
                                            const delta = contado - esperado;

                                            return (
                                                <tr key={denomination.id} className="border-b last:border-0">
                                                    <td className="px-2 py-2 tabular-nums">
                                                        {formatPlainAmount(denomination.value, currency)}
                                                    </td>

                                                    <td className="px-2 py-2">{kindLabel(denomination.kind)}</td>

                                                    <td className="px-2 py-2 text-right tabular-nums">{esperado}</td>

                                                    <td className="px-2 py-2 text-right">
                                                        {state === 'open' ? (
                                                            <Input
                                                                id={`pieza-${denomination.id}`}
                                                                type="number"
                                                                min={0}
                                                                step={1}
                                                                value={contado === 0 ? '' : String(contado)}
                                                                onChange={(event) => contar(denomination.id, event.target.value)}
                                                                className="w-24 text-right"
                                                                aria-label={`Counted pieces of ${formatPlainAmount(denomination.value, currency)}`}
                                                            />
                                                        ) : hayConteo ? (
                                                            <span className="tabular-nums">{contado}</span>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </td>

                                                    <td className="px-2 py-2 text-right tabular-nums">
                                                        {state === 'open' || hayConteo ? (
                                                            delta === 0 ? (
                                                                <span className="text-muted-foreground">0</span>
                                                            ) : (
                                                                <span className={delta > 0 ? 'text-destructive' : 'text-destructive'}>
                                                                    {delta > 0 ? `+${delta}` : delta}
                                                                </span>
                                                            )
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>

                                    <tfoot>
                                        <tr className="border-t">
                                            <td className="px-2 py-2 font-medium" colSpan={2}>
                                                Total amount
                                            </td>

                                            <td className="px-2 py-2 text-right font-semibold tabular-nums">
                                                {formatAmount(count.expected_total, currency)}
                                            </td>

                                            <td className="px-2 py-2 text-right font-semibold tabular-nums">
                                                {formatAmount(totalContado, currency)}
                                            </td>

                                            <td className="px-2 py-2 text-right font-semibold tabular-nums">
                                                {formatAmount(diferencia, currency)}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            {count.differences.length > 0 && (
                                <ul className="text-muted-foreground space-y-1 text-sm">
                                    {count.differences.map((piece) => (
                                        <li key={piece.id}>{differenceLabel(piece, currency)}</li>
                                    ))}
                                </ul>
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
                                {hayCatalogo ? (
                                    <div className="space-y-1.5">
                                        <Label>Counted total</Label>
                                        <p className="text-xl font-semibold tabular-nums">{formatAmount(totalContado, currency)}</p>
                                        <p className="text-muted-foreground text-xs">
                                            The system adds up the pieces you counted. Expected:{' '}
                                            {formatAmount(expected_balance, currency)}.
                                        </p>
                                    </div>
                                ) : (
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
                                )}

                                {exigeMotivo && (
                                    <div className="space-y-1.5">
                                        <Label htmlFor="close_reason">Reason</Label>
                                        <Input
                                            id="close_reason"
                                            value={motivo}
                                            onChange={(event) => setMotivo(event.target.value)}
                                            placeholder="Why is the register off?"
                                            required
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            The difference is {formatAmount(diferencia, currency)}: a difference over the threshold
                                            has to be explained.
                                        </p>
                                    </div>
                                )}

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

                {state === 'closed' && can('cash_day.reopen') && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Reopen the cash day</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <p className="text-muted-foreground mb-4 text-sm">
                                Reopening undoes the sealed closing and needs a reason. The previous Z stays in the history,
                                marked. Correcting a sale of a closed day requires reopening the day first.
                            </p>

                            <form onSubmit={reabrir} className="flex flex-col gap-4 sm:flex-row sm:items-end">
                                <div className="space-y-1.5">
                                    <Label htmlFor="reopen_reason">Reason</Label>
                                    <Input
                                        id="reopen_reason"
                                        value={reopenReason}
                                        onChange={(event) => setReopenReason(event.target.value)}
                                        placeholder="Why is this day reopened?"
                                        required
                                    />
                                    <p className="text-muted-foreground text-xs">The reason is kept with the closing.</p>
                                </div>

                                <Button type="submit" disabled={saving}>
                                    {saving ? 'Reopening…' : 'Reopen cash day'}
                                </Button>
                            </form>
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
