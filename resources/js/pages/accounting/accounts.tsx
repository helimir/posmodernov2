import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { putJson } from '@/lib/api';
import { AlertTriangle } from 'lucide-react';
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

export interface ChartOfAccountsProps {
    accounts: ChartAccount[];
    roles: ChartRole[];
    missing_roles: string[];
    store: { id: number; name: string; code_name: string | null };
}

/**
 * The value that means "this account plays no role". Radix does not allow an
 * empty string as the value of an item.
 */
const NONE = 'none';

/**
 * The label of an accounting type.
 */
const TYPE_LABELS: Record<string, string> = {
    asset: 'Asset',
    liability: 'Liability',
    equity: 'Equity',
    income: 'Income',
    expense: 'Expense',
};

/**
 * The CHART OF ACCOUNTS of the active store: the catalogue of accounts and
 * the role each one plays in this store.
 */
export default function ChartOfAccounts({ accounts, roles, missing_roles, store }: ChartOfAccountsProps) {
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState<number | null>(null);

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

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Chart of accounts',
            href: '/contabilidad/cuentas',
        },
    ];

    const etiquetaDe = (value: string) => roles.find((rol) => rol.value === value)?.label ?? value;

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

                {error !== null && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardDescription className="text-destructive text-sm">{error}</CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {accounts.length} {accounts.length === 1 ? 'account' : 'accounts'}
                            <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                        </CardTitle>
                        <CardDescription>
                            A role demands its own type of account: the cash and the receivables are assets, the tax payable is a
                            liability and the revenue is an income. The plan of a store is its own, and a role has one account.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        {accounts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no account in the catalogue yet.
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
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {accounts.map((account) => (
                                            <tr key={account.id} className="border-b last:border-0">
                                                <td className="px-2 py-2 tabular-nums">{account.code}</td>
                                                <td className="px-2 py-2">{account.name}</td>
                                                <td className="px-2 py-2">
                                                    <Badge variant="secondary">{TYPE_LABELS[account.type] ?? account.type}</Badge>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <Select
                                                        value={account.role ?? NONE}
                                                        disabled={saving === account.id}
                                                        onValueChange={(value) => changeRole(account, value)}
                                                    >
                                                        <SelectTrigger aria-label={`Role of account ${account.code}`} className="w-44">
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
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
