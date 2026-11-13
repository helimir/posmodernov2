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
import { deleteJson, postJson, putJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { AlertTriangle, Plus, Power, PowerOff, Trash2 } from 'lucide-react';
import { useState } from 'react';

/**
 * An account of the catalogue, as the backend hands it over.
 *
 * `role` is the role the account plays in the ACTIVE store, or `null` when it
 * plays none. `type` is the accounting type of the account, and it is what
 * the role demands: a revenue account cannot be the cash of a store.
 */
export interface ChartAccount {
    id: number;
    code: string;
    name: string;
    type: 'asset' | 'liability' | 'equity' | 'income' | 'expense';
    is_active: boolean;
    role: string | null;
}

/**
 * A role of the plan, with the account type it demands.
 */
export interface ChartRole {
    value: string;
    label: string;
    required_type: string;
}

/**
 * An accounting TYPE of the catalogue.
 *
 * The set is CLOSED and the backend sends it: the screen does not write the
 * types by hand, because a list written by hand is a list that gets old.
 */
export interface ChartAccountType {
    value: string;
    label: string;
}

export interface ChartOfAccountsProps {
    accounts: ChartAccount[];
    roles: ChartRole[];
    types: ChartAccountType[];
    missing_roles: string[];
    store: { id: number; name: string; code_name: string | null };
}

/**
 * The value that means "this account plays no role". Radix does not allow an
 * empty string as the value of an item.
 */
const NONE = 'none';

/**
 * The form of the ALTA: the code, the name and the type of the account.
 */
interface AccountDraft {
    code: string;
    name: string;
    type: string;
}

/**
 * The action that waits for a CONFIRMATION.
 */
type PendingAction = 'deactivate' | 'activate' | 'delete';

/**
 * The CHART OF ACCOUNTS of the active store: the catalogue of accounts and
 * the role each one plays in this store.
 */
export default function ChartOfAccounts({ accounts, roles, types, missing_roles, store }: ChartOfAccountsProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [warnings, setWarnings] = useState<string[]>([]);
    const [saving, setSaving] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);
    const [busy, setBusy] = useState(false);
    const [draft, setDraft] = useState<AccountDraft>({ code: '', name: '', type: '' });
    const [pending, setPending] = useState<{ account: ChartAccount; action: PendingAction } | null>(null);

    /**
     * The ROLE of an account changes: the backend decides whether the account
     * can play that role, and says why when it cannot.
     */
    const changeRole = async (account: ChartAccount, role: string) => {
        setError(null);
        setSaving(account.id);

        try {
            await putJson(`/api/contabilidad/cuentas/${account.id}/rol`, { role: role === NONE ? null : role });

            router.reload({ only: ['accounts', 'missing_roles'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The role could not be saved.');
        } finally {
            setSaving(null);
        }
    };

    /**
     * The ALTA: the account enters the catalogue with its code, its name and
     * its type. The code is the natural key of the account, and the backend
     * normalises it and refuses a repeated one.
     */
    const createAccount = async () => {
        setError(null);
        setBusy(true);

        try {
            await postJson('/api/contabilidad/cuentas', {
                code: draft.code,
                name: draft.name,
                type: draft.type,
            });

            setCreating(false);
            setDraft({ code: '', name: '', type: types[0]?.value ?? '' });
            router.reload({ only: ['accounts', 'missing_roles'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The account could not be created.');
        } finally {
            setBusy(false);
        }
    };

    /**
     * The DEACTIVATE and the ACTIVATE: the account stops (or starts again)
     * fulfilling its role, and the plan of the store may become incomplete.
     * The backend says which plan, and the screen shows it.
     */
    const toggleActive = async (account: ChartAccount, activar: boolean) => {
        setError(null);
        setWarnings([]);
        setSaving(account.id);
        setPending(null);

        try {
            const respuesta = await postJson<{ warnings?: string[] }>(
                `/api/contabilidad/cuentas/${account.id}/${activar ? 'encender' : 'desactivar'}`,
                {},
            );

            setWarnings(respuesta.warnings ?? []);
            router.reload({ only: ['accounts', 'missing_roles'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The account could not be saved.');
        } finally {
            setSaving(null);
        }
    };

    /**
     * The DELETE: only the account that was NEVER USED leaves the catalogue.
     * An account with history is deactivated, not deleted, and the backend
     * says why when it refuses.
     */
    const removeAccount = async (account: ChartAccount) => {
        setError(null);
        setSaving(account.id);
        setPending(null);

        try {
            await deleteJson(`/api/contabilidad/cuentas/${account.id}`);

            router.reload({ only: ['accounts', 'missing_roles'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The account could not be deleted.');
        } finally {
            setSaving(null);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Chart of accounts',
            href: '/contabilidad/cuentas',
        },
    ];

    const etiquetaDe = (value: string) => roles.find((rol) => rol.value === value)?.label ?? value;

    const etiquetaDeTipo = (value: string) => types.find((tipo) => tipo.value === value)?.label ?? value;

    /**
     * The CONFIRMATION of the action that waits: what it does and what it
     * costs.
     */
    const confirmacion = () => {
        if (pending === null) {
            return null;
        }

        if (pending.action === 'deactivate') {
            return {
                title: `Deactivate the account ${pending.account.code}?`,
                description:
                    'It will leave the plan: it stops fulfilling its role, and the plan of the store may become incomplete. The till cannot charge without every role. Assign the role to another account to complete it.',
                label: 'Deactivate',
                destructive: true,
            };
        }

        if (pending.action === 'activate') {
            return {
                title: `Activate the account ${pending.account.code}?`,
                description:
                    'It will be active again, but it will play NO role: it left the plan when it was deactivated. You have to assign the role again to put it back in the plan.',
                label: 'Activate',
                destructive: false,
            };
        }

        return {
            title: `Delete the account ${pending.account.code}?`,
            description:
                'An account can only be deleted when it was never used: no movements, no role and no plan in any store. An account with history is deactivated, not deleted.',
            label: 'Delete',
            destructive: true,
        };
    };

    const pedido = confirmacion();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chart of accounts" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {missing_roles.length > 0 && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardTitle className="text-destructive flex items-center gap-2 text-base">
                                <AlertTriangle className="size-4" />
                                The plan of this store is incomplete
                            </CardTitle>
                            <CardDescription>
                                The till cannot charge without these roles: {missing_roles.map((rol) => etiquetaDe(rol)).join(', ')}.
                                Assign them before the next sale.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {warnings.length > 0 && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardTitle className="text-destructive flex items-center gap-2 text-base">
                                <AlertTriangle className="size-4" />
                                The plan of a store changed
                            </CardTitle>
                            <CardDescription>
                                <ul className="list-disc space-y-1 pl-4">
                                    {warnings.map((aviso) => (
                                        <li key={aviso}>{aviso}</li>
                                    ))}
                                </ul>
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

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
                                {accounts.length} {accounts.length === 1 ? 'account' : 'accounts'}
                                <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                A role demands its own type of account: the cash and the receivables are assets, the tax payable
                                is a liability and the revenue is an income. The plan of a store is its own, and a role has one
                                account.
                            </CardDescription>
                        </div>

                        {can('accounting.create') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setDraft({ code: '', name: '', type: types[0]?.value ?? '' });
                                    setCreating(true);
                                }}
                            >
                                <Plus />
                                New account
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {accounts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no account in the catalogue yet. Create one.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Code</th>
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-left font-medium">Type</th>
                                            <th className="px-2 py-2 text-left font-medium">Role in this store</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {accounts.map((account) => (
                                            <tr
                                                key={account.id}
                                                className={
                                                    account.is_active
                                                        ? 'border-b last:border-0'
                                                        : 'border-b last:border-0 text-muted-foreground'
                                                }
                                            >
                                                <td className="px-2 py-2 tabular-nums">{account.code}</td>
                                                <td className="px-2 py-2">{account.name}</td>
                                                <td className="px-2 py-2">
                                                    <Badge variant="secondary">{etiquetaDeTipo(account.type)}</Badge>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <Select
                                                        value={account.role ?? NONE}
                                                        disabled={saving === account.id || !account.is_active || !can('accounting.update')}
                                                        onValueChange={(value) => changeRole(account, value)}
                                                    >
                                                        <SelectTrigger
                                                            aria-label={`Role of account ${account.code}`}
                                                            title={
                                                                account.is_active
                                                                    ? undefined
                                                                    : 'An inactive account cannot play a role.'
                                                            }
                                                            className="w-44"
                                                        >
                                                            <SelectValue placeholder="No role" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value={NONE}>No role</SelectItem>
                                                            {roles.map((rol) => (
                                                                <SelectItem key={rol.value} value={rol.value}>
                                                                    {rol.label}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={account.is_active ? 'default' : 'secondary'}>
                                                        {account.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <div className="flex justify-end gap-2">
                                                        {can('accounting.update') && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={saving === account.id}
                                                                onClick={() =>
                                                                    setPending({
                                                                        account,
                                                                        action: account.is_active ? 'deactivate' : 'activate',
                                                                    })
                                                                }
                                                            >
                                                                {account.is_active ? <PowerOff /> : <Power />}
                                                                {account.is_active ? 'Deactivate' : 'Activate'}
                                                            </Button>
                                                        )}

                                                        {can('accounting.delete') && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={saving === account.id}
                                                                onClick={() => setPending({ account, action: 'delete' })}
                                                            >
                                                                <Trash2 />
                                                                Delete
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

            {/* El ALTA: el codigo, el nombre y el tipo de la cuenta. */}
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
                        <DialogTitle>New account</DialogTitle>
                        <DialogDescription>
                            The code is the natural key of the account: it is unique in the catalogue and it is stored without
                            spaces and in upper case.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="account-code">Code</Label>
                            <Input
                                id="account-code"
                                value={draft.code}
                                onChange={(event) => setDraft({ ...draft, code: event.target.value })}
                                placeholder="550000"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="account-name">Name</Label>
                            <Input
                                id="account-name"
                                value={draft.name}
                                onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                                placeholder="Withheld tax"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="account-type">Type</Label>
                            <Select
                                value={draft.type}
                                onValueChange={(value) => setDraft({ ...draft, type: value })}
                            >
                                <SelectTrigger id="account-type" aria-label="Type of the account">
                                    <SelectValue placeholder="Type of the account" />
                                </SelectTrigger>
                                <SelectContent>
                                    {types.map((tipo) => (
                                        <SelectItem key={tipo.value} value={tipo.value}>
                                            {tipo.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button disabled={busy} onClick={createAccount}>
                            Create account
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* La CONFIRMACION de lo que va a romper el plan. */}
            <Dialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPending(null);
                    }
                }}
            >
                <DialogContent>
                    {pedido !== null && (
                        <>
                            <DialogHeader>
                                <DialogTitle>{pedido.title}</DialogTitle>
                                <DialogDescription>{pedido.description}</DialogDescription>
                            </DialogHeader>

                            <DialogFooter>
                                <Button variant="outline" onClick={() => setPending(null)}>
                                    Cancel
                                </Button>
                                <Button
                                    variant={pedido.destructive ? 'destructive' : 'default'}
                                    disabled={saving !== null}
                                    onClick={() => {
                                        if (pending === null) {
                                            return;
                                        }

                                        if (pending.action === 'delete') {
                                            removeAccount(pending.account);

                                            return;
                                        }

                                        toggleActive(pending.account, pending.action === 'activate');
                                    }}
                                >
                                    {pedido.label}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
