import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import PosLayout from '@/layouts/pos-layout';
import {
    type PosCurrency,
    addAmount,
    compareAmounts,
    formatAmount,
    formatPlainAmount,
    keypadAmount,
    multiplyAmount,
    subtractAmount,
} from '@/lib/pos';
import { Head } from '@inertiajs/react';
import { Loader2, Minus, Plus, Search, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/**
 * The shape of the point-of-sale screen, as the backend hands it over.
 *
 * Every amount is a DECIMAL STRING: the columns are `numeric(25,4)` and a
 * `float` is not money.
 */
export interface PosStore {
    id: number;
    name: string;
    code_name: string | null;
    timezone: string;
}

export interface PosProduct {
    id: number;
    code: string;
    name: string;
    type: 'product' | 'service';
    sell_price: string;
    quantity_in_stock: string;
    unit_name: string | null;
}

export interface PosPaymentMethod {
    id: number;
    code: string;
    name: string;
}

export interface PosIndexProps {
    store: PosStore;
    catalog: PosProduct[];
    payment_methods: PosPaymentMethod[];
    document_series_id: number | null;
    control_series_id: number | null;
    business_date: string;
    currency_code: string;
    currency: PosCurrency;
    catalog_page_size: number;
}

/**
 * The totals of the cart, as the ENGINE calculates them.
 *
 * The browser never adds the tax: it asks the server, and the server asks the
 * tax engine. A second copy of that arithmetic in the browser is a second
 * truth, and the two diverge.
 */
export interface PosBudget {
    subtotal: string;
    tax_total: string;
    discount: string;
    rounding_adjustment: string;
    grand_total: string;
}

/**
 * What the checkout answers once the sale is written.
 */
export interface PosSale {
    sale_id: number;
    invoice_no: string;
    control_number: string | null;
    grand_total: string;
    status: string;
    change: string;
}

/**
 * A line of the cart. The cart lives in the CLIENT: there is no table of
 * carts, and there will not be one.
 */
interface CartLine {
    product: PosProduct;
    quantity: string;
    discount: string;
}

/**
 * The CSRF token of the page. Every POST has to be signed with it: without
 * it the server answers 419 and the till does not work.
 */
function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * A POST of the till, with the CSRF token and with the answer en JSON.
 *
 * A failed answer throws with the message of the server: the till shows it
 * instead of charging blindly.
 */
async function postJson<T>(url: string, payload: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    const data = (await response.json().catch(() => ({}))) as { errorMsg?: string };

    if (!response.ok) {
        throw new Error(data.errorMsg ?? `The request failed with the status ${response.status}.`);
    }

    return data as T;
}

/**
 * The line amount of the cart: price times quantity, minus the discount.
 *
 * It is trivial arithmetic and it carries NO tax: the tax with the rate in
 * force comes from the engine.
 */
function lineAmount(line: CartLine): string {
    return subtractAmount(multiplyAmount(line.product.sell_price, line.quantity), line.discount);
}

/**
 * The screen of the till.
 *
 * The catalog is on the LEFT, the cart on the RIGHT, and the payment panel
 * under the cart. Everything is a touch target: the buttons are big, the
 * keypad is numeric, and the physical keyboard works too.
 *
 * THE TOTAL IS NOT CALCULATED HERE: the screen asks the server for the
 * totals and shows what the tax engine says. While the answer is on its way
 * the screen shows the estimated subtotal, and if the answer fails the screen
 * says so and does NOT allow charging: charging without a total is charging
 * blind.
 */
export default function PosIndex({ store, catalog, payment_methods, business_date, currency }: PosIndexProps) {
    const [products, setProducts] = useState<PosProduct[]>(catalog);
    const [query, setQuery] = useState('');
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);

    const [cart, setCart] = useState<CartLine[]>([]);
    const [budget, setBudget] = useState<PosBudget | null>(null);
    const [budgetError, setBudgetError] = useState<string | null>(null);
    const [budgeting, setBudgeting] = useState(false);

    const [received, setReceived] = useState('');
    const [paymentMethodId, setPaymentMethodId] = useState<string>('');
    const [charging, setCharging] = useState(false);
    const [chargeError, setChargeError] = useState<string | null>(null);
    const [sale, setSale] = useState<PosSale | null>(null);

    const firstSearch = useRef(true);

    /**
     * The lines of the cart, as the server expects them.
     */
    const lines = useMemo(
        () =>
            cart.map((line) => ({
                product_id: line.product.id,
                quantity: line.quantity,
                unit_price: line.product.sell_price,
                discount: line.discount,
            })),
        [cart],
    );

    /**
     * The ESTIMATED subtotal: the sum of the line amounts, without tax.
     *
     * It is what the screen shows while the engine has not answered yet.
     */
    const estimatedSubtotal = useMemo(() => cart.reduce((total, line) => addAmount(total, lineAmount(line)), '0.0000'), [cart]);

    /**
     * The SEARCH: the catalog is replaced by what the server finds.
     */
    useEffect(() => {
        if (firstSearch.current) {
            firstSearch.current = false;

            return;
        }

        let cancelled = false;

        const timer = setTimeout(async () => {
            setSearching(true);
            setSearchError(null);

            try {
                const response = await fetch(`/caja/productos?q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) {
                    throw new Error(`The search failed with the status ${response.status}.`);
                }

                const data = (await response.json()) as { products?: PosProduct[] };

                if (!cancelled) {
                    setProducts(data.products ?? []);
                }
            } catch (error) {
                if (!cancelled) {
                    setSearchError((error as Error).message);
                }
            } finally {
                if (!cancelled) {
                    setSearching(false);
                }
            }
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [query]);

    /**
     * The TOTALS: the server asks the tax engine and answers.
     *
     * The request is DEBOUNCED: the cashier adds products one after another,
     * and the engine does not have to answer every keystroke.
     */
    useEffect(() => {
        if (cart.length === 0) {
            setBudget(null);
            setBudgetError(null);

            return;
        }

        let cancelled = false;

        const timer = setTimeout(async () => {
            setBudgeting(true);

            try {
                const totals = await postJson<PosBudget>('/caja/presupuesto', { lines });

                if (!cancelled) {
                    setBudget(totals);
                    setBudgetError(null);
                }
            } catch (error) {
                if (!cancelled) {
                    setBudget(null);
                    setBudgetError((error as Error).message);
                }
            } finally {
                if (!cancelled) {
                    setBudgeting(false);
                }
            }
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [lines, cart.length]);

    /**
     * Adds a product to the cart, or adds one more unit of it.
     */
    const addToCart = useCallback((product: PosProduct) => {
        setCart((current) => {
            const existing = current.find((line) => line.product.id === product.id);

            if (existing) {
                return current.map((line) => (line.product.id === product.id ? { ...line, quantity: addAmount(line.quantity, '1') } : line));
            }

            return [...current, { product, quantity: '1', discount: '0.0000' }];
        });

        setChargeError(null);
    }, []);

    /**
     * Changes the quantity of a line. A quantity that is not a number is
     * ignored: the line keeps the quantity it had.
     */
    const changeQuantity = useCallback((productId: number, value: string) => {
        setCart((current) => current.map((line) => (line.product.id === productId ? { ...line, quantity: value } : line)));
    }, []);

    /**
     * Changes the discount of a line.
     */
    const changeDiscount = useCallback((productId: number, value: string) => {
        setCart((current) => current.map((line) => (line.product.id === productId ? { ...line, discount: value } : line)));
    }, []);

    /**
     * Removes a line from the cart.
     */
    const removeLine = useCallback((productId: number) => {
        setCart((current) => current.filter((line) => line.product.id !== productId));
    }, []);

    /**
     * Empties the cart.
     */
    const clearCart = useCallback(() => {
        setCart([]);
        setReceived('');
        setChargeError(null);
    }, []);

    /**
     * The digits of the keypad: the amount the customer hands over.
     */
    const receivedAmount = useMemo(() => keypadAmount(received, currency), [received, currency]);

    /**
     * The CHANGE: what is left after paying the total.
     */
    const change = useMemo(() => {
        if (!budget) {
            return '0.0000';
        }

        return subtractAmount(receivedAmount, budget.grand_total);
    }, [receivedAmount, budget]);

    /**
     * The physical keyboard: the same keys as the on-screen keypad.
     */
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const target = event.target;

            if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
                return;
            }

            if (/^[0-9]$/.test(event.key)) {
                setReceived((current) => (current + event.key).slice(0, 12));

                return;
            }

            if (event.key === 'Backspace') {
                setReceived((current) => current.slice(0, -1));

                return;
            }

            if (event.key === 'Escape') {
                setReceived('');
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    /**
     * Charges the cart: the server writes the sale and answers with the
     * document number, the control number, the total and the change.
     */
    const charge = useCallback(async () => {
        if (!budget || cart.length === 0) {
            setChargeError('There is no total to charge yet.');

            return;
        }

        if (paymentMethodId === '') {
            setChargeError('Choose a payment method.');

            return;
        }

        setCharging(true);
        setChargeError(null);

        try {
            const result = await postJson<PosSale>('/caja/cobrar', {
                lines,
                payments: [
                    {
                        payment_method_id: Number(paymentMethodId),
                        amount: receivedAmount,
                    },
                ],
            });

            setSale(result);
        } catch (error) {
            setChargeError((error as Error).message);
        } finally {
            setCharging(false);
        }
    }, [budget, cart.length, lines, paymentMethodId, receivedAmount]);

    /**
     * Closes the summary and empties the cart: the next customer starts from
     * an empty till.
     */
    const closeSummary = useCallback(() => {
        setSale(null);
        clearCart();
    }, [clearCart]);

    const canCharge = budget !== null && budgetError === null && cart.length > 0 && !charging;
    const shortPayment = budget !== null && compareAmounts(receivedAmount, budget.grand_total) < 0;

    return (
        <PosLayout storeName={store.name} businessDate={business_date}>
            <Head title="Point of sale" />

            <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden lg:grid-cols-12">
                {/* THE CATALOG: on the left, the products of the store. */}
                <section className="flex min-h-0 flex-col border-b lg:col-span-7 lg:border-r lg:border-b-0">
                    <div className="shrink-0 p-3">
                        <div className="relative">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Search by name or code"
                                autoComplete="off"
                                autoFocus
                                className="h-11 pl-9 text-base"
                            />
                        </div>

                        {searchError !== null && <p className="text-destructive mt-2 text-sm">{searchError}</p>}
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto px-3 pb-3">
                        {products.length === 0 ? (
                            <p className="text-muted-foreground py-10 text-center text-sm">
                                {searching ? 'Searching…' : 'There are no products to sell here.'}
                            </p>
                        ) : (
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                                {products.map((product) => (
                                    <button
                                        key={product.id}
                                        type="button"
                                        onClick={() => addToCart(product)}
                                        className="bg-card hover:border-primary hover:bg-accent flex min-h-28 flex-col justify-between gap-2 rounded-lg border p-3 text-left transition-colors"
                                    >
                                        <span className="line-clamp-2 font-medium">{product.name}</span>

                                        <span className="text-muted-foreground text-xs">{product.code}</span>

                                        <span className="flex items-end justify-between gap-2">
                                            <span className="text-lg font-semibold tabular-nums">{formatAmount(product.sell_price, currency)}</span>

                                            <span className="text-muted-foreground text-xs tabular-nums">
                                                {product.type === 'service'
                                                    ? 'Service'
                                                    : `${formatPlainAmount(product.quantity_in_stock, currency)} in stock`}
                                            </span>
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </section>

                {/* THE CART: on the right, the lines and the payment. */}
                <section className="flex min-h-0 flex-col lg:col-span-5">
                    <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
                        <div className="flex items-center justify-between px-3 pt-3">
                            <h2 className="font-semibold">Cart</h2>

                            <Button variant="ghost" size="sm" onClick={clearCart} disabled={cart.length === 0}>
                                <Trash2 />
                                Clear
                            </Button>
                        </div>

                        <div className="flex-1 px-3 py-2">
                            {cart.length === 0 ? (
                                <p className="text-muted-foreground py-10 text-center text-sm">Tap a product to start the sale.</p>
                            ) : (
                                <div className="flex flex-col gap-2">
                                    {cart.map((line) => (
                                        <div key={line.product.id} className="bg-card rounded-lg border p-2">
                                            <div className="flex items-start justify-between gap-2">
                                                <span className="font-medium">{line.product.name}</span>

                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() => removeLine(line.product.id)}
                                                    aria-label={`Remove ${line.product.name}`}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>

                                            <div className="mt-1 flex items-center gap-2 text-sm">
                                                <span className="text-muted-foreground tabular-nums">
                                                    {formatAmount(line.product.sell_price, currency)}
                                                </span>

                                                <div className="flex items-center gap-1">
                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        onClick={() => changeQuantity(line.product.id, subtractAmount(line.quantity, '1'))}
                                                        aria-label="One unit less"
                                                    >
                                                        <Minus />
                                                    </Button>

                                                    <Input
                                                        value={line.quantity}
                                                        onChange={(event) => changeQuantity(line.product.id, event.target.value)}
                                                        inputMode="decimal"
                                                        aria-label="Quantity"
                                                        className="h-9 w-16 text-center tabular-nums"
                                                    />

                                                    <Button
                                                        variant="outline"
                                                        size="icon"
                                                        onClick={() => changeQuantity(line.product.id, addAmount(line.quantity, '1'))}
                                                        aria-label="One unit more"
                                                    >
                                                        <Plus />
                                                    </Button>
                                                </div>

                                                <div className="flex items-center gap-1">
                                                    <span className="text-muted-foreground text-xs">Disc.</span>
                                                    <Input
                                                        value={line.discount}
                                                        onChange={(event) => changeDiscount(line.product.id, event.target.value)}
                                                        inputMode="decimal"
                                                        aria-label="Discount"
                                                        className="h-9 w-20 text-right tabular-nums"
                                                    />
                                                </div>

                                                <span className="ml-auto font-semibold tabular-nums">{formatAmount(lineAmount(line), currency)}</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* THE TOTALS: the engine answers, the screen shows. */}
                        <div className="shrink-0 border-t px-3 py-2">
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Subtotal</span>
                                <span className="tabular-nums">{formatAmount(budget?.subtotal ?? estimatedSubtotal, currency)}</span>
                            </div>

                            {budget !== null && compareAmounts(budget.discount, '0') !== 0 && (
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">Discount applied</span>
                                    <span className="tabular-nums">-{formatPlainAmount(budget.discount, currency)}</span>
                                </div>
                            )}

                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">Tax</span>
                                <span className="tabular-nums">{budget === null ? '—' : formatAmount(budget.tax_total, currency)}</span>
                            </div>

                            {budget !== null && compareAmounts(budget.rounding_adjustment, '0') !== 0 && (
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">Rounding adjustment</span>
                                    <span className="tabular-nums">{formatAmount(budget.rounding_adjustment, currency)}</span>
                                </div>
                            )}

                            <div className="mt-1 flex items-center justify-between border-t pt-1 text-lg font-semibold">
                                <span className="flex items-center gap-2">
                                    Total
                                    {budgeting && <Loader2 className="text-muted-foreground size-4 animate-spin" />}
                                </span>
                                <span className="tabular-nums">{budget === null ? '—' : formatAmount(budget.grand_total, currency)}</span>
                            </div>

                            {budgetError !== null && (
                                <p className="bg-destructive/10 text-destructive mt-2 rounded-md px-2 py-1 text-sm">
                                    The total could not be calculated: {budgetError}. The sale cannot be charged.
                                </p>
                            )}
                        </div>
                    </div>

                    {/* THE PAYMENT: the amount to charge, the amount received and the change. */}
                    <div className="bg-muted/30 shrink-0 border-t p-3">
                        <div className="flex flex-col gap-3 sm:flex-row">
                            <div className="flex-1 space-y-2">
                                <div className="bg-card rounded-lg border p-3">
                                    <span className="text-muted-foreground text-xs uppercase">To charge</span>
                                    <div className="text-3xl font-semibold tabular-nums">
                                        {budget === null ? '—' : formatAmount(budget.grand_total, currency)}
                                    </div>
                                </div>

                                <div className="bg-card rounded-lg border p-3">
                                    <span className="text-muted-foreground text-xs uppercase">Received</span>
                                    <div className="text-3xl font-semibold tabular-nums">{formatAmount(receivedAmount, currency)}</div>
                                </div>

                                <div className="bg-card rounded-lg border p-3">
                                    <span className="text-muted-foreground text-xs uppercase">Change</span>
                                    <div className={`text-3xl font-semibold tabular-nums ${shortPayment ? 'text-destructive' : ''}`}>
                                        {budget === null ? '—' : formatAmount(change, currency)}
                                    </div>

                                    {shortPayment && <p className="text-destructive text-sm">The amount received is less than the total.</p>}
                                </div>

                                <Select value={paymentMethodId} onValueChange={setPaymentMethodId}>
                                    <SelectTrigger className="h-11 w-full">
                                        <SelectValue placeholder="Payment method" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {payment_methods.map((method) => (
                                            <SelectItem key={method.id} value={String(method.id)}>
                                                {method.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid w-full shrink-0 grid-cols-3 gap-2 sm:w-64">
                                {['7', '8', '9', '4', '5', '6', '1', '2', '3'].map((digit) => (
                                    <Button
                                        key={digit}
                                        type="button"
                                        variant="outline"
                                        className="h-14 text-xl"
                                        onClick={() => setReceived((current) => (current + digit).slice(0, 12))}
                                    >
                                        {digit}
                                    </Button>
                                ))}

                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-14 text-xl"
                                    onClick={() => setReceived((current) => (current + '00').slice(0, 12))}
                                >
                                    00
                                </Button>

                                <Button type="button" variant="outline" className="h-14 text-xl" onClick={() => setReceived('')}>
                                    C
                                </Button>

                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-14 text-xl"
                                    onClick={() => setReceived((current) => current.slice(0, -1))}
                                >
                                    ⌫
                                </Button>

                                <Button type="button" className="col-span-3 h-16 text-lg" onClick={charge} disabled={!canCharge}>
                                    {charging ? <Loader2 className="animate-spin" /> : null}
                                    Charge
                                </Button>
                            </div>
                        </div>

                        {chargeError !== null && (
                            <p className="bg-destructive/10 text-destructive mt-2 rounded-md px-2 py-1 text-sm">{chargeError}</p>
                        )}
                    </div>
                </section>
            </div>

            {/* THE SUMMARY: what was sold, and the way out. */}
            <Dialog open={sale !== null} onOpenChange={(open) => !open && closeSummary()}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Sale completed</DialogTitle>
                        <DialogDescription>The sale was written and the numbers were reserved.</DialogDescription>
                    </DialogHeader>

                    {sale !== null && (
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">Document</dt>
                            <dd className="font-medium">{sale.invoice_no}</dd>

                            <dt className="text-muted-foreground">Control number</dt>
                            <dd className="font-medium">{sale.control_number ?? '—'}</dd>

                            <dt className="text-muted-foreground">Total</dt>
                            <dd className="font-medium tabular-nums">{formatAmount(sale.grand_total, currency)}</dd>

                            <dt className="text-muted-foreground">Change</dt>
                            <dd className="font-medium tabular-nums">{formatAmount(sale.change, currency)}</dd>

                            <dt className="text-muted-foreground">Status</dt>
                            <dd>
                                <Badge variant={sale.status === 'paid' ? 'default' : 'secondary'}>{sale.status}</Badge>
                            </dd>
                        </dl>
                    )}

                    <DialogFooter>
                        <Button onClick={closeSummary}>Close and continue</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </PosLayout>
    );
}
