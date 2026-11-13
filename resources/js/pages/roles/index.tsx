import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

/**
 * A role of the system, as the backend hands it over.
 *
 * `is_superuser` says whether the role is the one that can do everything:
 * its matrix is not editable, because its power does not come from the
 * matrix.
 */
export interface AdminRole {
    id: number;
    name: string;
    slug: string;
    sort_order: number;
    status: boolean;
    permissions_count: number;
    stores_count: number;
    is_superuser: boolean;
}

export interface RolesIndexProps {
    roles: AdminRole[];
}

/**
 * The ROLES: what each one can do and in which stores it operates.
 */
export default function RolesIndex({ roles }: RolesIndexProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Roles',
            href: '/roles',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Roles" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Roles</CardTitle>
                        <CardDescription className="mt-1.5">
                            A role is a contract: the permissions it has are the ones the screen of the role shows, and the
                            superuser has every one of them without needing a matrix.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="px-2 py-2 text-left font-medium">Name</th>
                                        <th className="px-2 py-2 text-left font-medium">Slug</th>
                                        <th className="px-2 py-2 text-right font-medium">Permissions</th>
                                        <th className="px-2 py-2 text-right font-medium">Stores</th>
                                        <th className="px-2 py-2 text-left font-medium">Status</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {roles.map((role) => (
                                        <tr key={role.id} className="border-b last:border-0">
                                            <td className="px-2 py-2">
                                                <Link href={`/roles/${role.id}`} className="underline underline-offset-4">
                                                    {role.name}
                                                </Link>
                                            </td>
                                            <td className="px-2 py-2 text-muted-foreground">{role.slug}</td>
                                            <td className="px-2 py-2 text-right tabular-nums">
                                                {role.is_superuser ? 'All of them' : role.permissions_count}
                                            </td>
                                            <td className="px-2 py-2 text-right tabular-nums">
                                                {role.stores_count === 0 ? 'Every store' : role.stores_count}
                                            </td>
                                            <td className="px-2 py-2">
                                                <Badge variant={role.status ? 'default' : 'secondary'}>
                                                    {role.status ? 'Active' : 'Inactive'}
                                                </Badge>
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
