import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { putJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { useState } from 'react';

/**
 * A person, as the backend hands it over.
 *
 * The role is the one she has, or `null` when she has none: a person without
 * a role cannot do anything, because the permissions come from the role.
 */
export interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: { id: number; name: string; slug: string } | null;
    stores: { id: number; name: string; code_name: string | null }[];
}

/**
 * A role of the system, for the selector.
 */
export interface AdminRole {
    id: number;
    name: string;
    slug: string;
}

export interface UsersIndexProps {
    users: AdminUser[];
    roles: AdminRole[];
}

/**
 * The value that means "this person has no role". Radix does not allow an
 * empty string as the value of an item.
 */
const NONE = 'none';

/**
 * The PEOPLE: who is, what role she has and in which stores she operates.
 */
export default function UsersIndex({ users, roles }: UsersIndexProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState<number | null>(null);

    /**
     * The ROLE of a person: it is assigned, and the server says what it
     * refused and why.
     */
    const assignRole = async (user: AdminUser, roleId: number | null) => {
        setError(null);
        setSaving(user.id);

        try {
            await putJson(`/api/usuarios/${user.id}/rol`, { role_id: roleId });

            router.reload({ only: ['users'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The role could not be assigned.');
        } finally {
            setSaving(null);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Users',
            href: '/usuarios',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {error !== null && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardDescription className="text-destructive text-sm">{error}</CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">People</CardTitle>
                        <CardDescription className="mt-1.5">
                            A person without a role cannot do anything: the permissions come from the role. Every change is
                            written down in the audit log with its author and its address.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="px-2 py-2 text-left font-medium">Name</th>
                                        <th className="px-2 py-2 text-left font-medium">Email</th>
                                        <th className="px-2 py-2 text-left font-medium">Role</th>
                                        <th className="px-2 py-2 text-left font-medium">Stores</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {users.map((user) => (
                                        <tr key={user.id} className="border-b last:border-0">
                                            <td className="px-2 py-2">{user.name}</td>
                                            <td className="px-2 py-2">{user.email}</td>
                                            <td className="px-2 py-2">
                                                {can('user.update') ? (
                                                    <Select
                                                        value={user.role === null ? NONE : String(user.role.id)}
                                                        disabled={saving === user.id}
                                                        onValueChange={(value) => assignRole(user, value === NONE ? null : Number(value))}
                                                    >
                                                        <SelectTrigger aria-label={`Role of ${user.name}`} className="w-48">
                                                            <SelectValue placeholder="No role" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value={NONE}>No role</SelectItem>
                                                            {roles.map((role) => (
                                                                <SelectItem key={role.id} value={String(role.id)}>
                                                                    {role.name}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                ) : (
                                                    <Badge variant="secondary">{user.role?.name ?? 'No role'}</Badge>
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                {user.stores.length === 0 ? (
                                                    <span className="text-muted-foreground">—</span>
                                                ) : (
                                                    <div className="flex flex-wrap gap-1">
                                                        {user.stores.map((store) => (
                                                            <Badge key={store.id} variant="outline">
                                                                {store.name}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
