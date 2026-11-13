import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { putJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { useState } from 'react';

/**
 * A permission of the CATALOGUE, as the backend hands it over.
 *
 * The catalogue is CLOSED: the screen shows every permission of the system
 * with a mark on the ones the role has, and it never writes a slug of its
 * own.
 */
export interface CatalogPermission {
    slug: string;
    module: string;
    action: string;
    label: string;
    granted: boolean;
}

/**
 * A store of the system, with the mark of the ones in the scope of the role.
 */
export interface ScopeStore {
    id: number;
    name: string;
    code_name: string | null;
    in_scope: boolean;
}

export interface RoleShowProps {
    role: { id: number; name: string; slug: string; sort_order: number; status: boolean; is_superuser: boolean };
    permissions: CatalogPermission[];
    stores: ScopeStore[];
}

/**
 * The ROLE: the permissions it has and the stores where it operates.
 */
export default function RoleShow({ role, permissions, stores }: RoleShowProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const [granted, setGranted] = useState<string[]>(permissions.filter((permission) => permission.granted).map((permission) => permission.slug));

    const [scope, setScope] = useState<number[]>(stores.filter((store) => store.in_scope).map((store) => store.id));

    /**
     * The PERMISSIONS of the role: the server writes them and says what it
     * refused.
     */
    const savePermissions = async () => {
        setError(null);
        setBusy(true);

        try {
            await putJson(`/api/roles/${role.id}/permisos`, { permissions: granted });

            router.reload({ only: ['permissions'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The permissions could not be saved.');
        } finally {
            setBusy(false);
        }
    };

    /**
     * The SCOPE of stores of the role: an EMPTY list means every store.
     */
    const saveStores = async () => {
        setError(null);
        setBusy(true);

        try {
            await putJson(`/api/roles/${role.id}/tiendas`, { stores: scope });

            router.reload({ only: ['stores'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The stores could not be saved.');
        } finally {
            setBusy(false);
        }
    };

    const toggle = (slug: string, checked: boolean) => {
        setGranted((current) => (checked ? [...current, slug] : current.filter((item) => item !== slug)));
    };

    const toggleStore = (storeId: number, checked: boolean) => {
        setScope((current) => (checked ? [...current, storeId] : current.filter((item) => item !== storeId)));
    };

    /**
     * The permissions GROUPED by module: the catalogue is closed, and the
     * groups come from the slugs themselves.
     */
    const modules = Array.from(new Set(permissions.map((permission) => permission.module)));

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Roles',
            href: '/roles',
        },
        {
            title: role.name,
            href: `/roles/${role.id}`,
        },
    ];

    const editable = can('role.update') && !role.is_superuser;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={role.name} />

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
                        <CardTitle className="text-base">{role.name}</CardTitle>
                        <CardDescription className="mt-1.5">
                            {role.is_superuser
                                ? 'The superuser can do everything: its matrix is not editable, because its power does not come from the matrix.'
                                : 'The permissions of the role. The screen shows the whole catalogue, with a mark on the ones the role has.'}
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="grid gap-6">
                        {modules.map((module) => (
                            <div key={module} className="grid gap-2">
                                <h3 className="text-sm font-medium capitalize">{module.replace('_', ' ')}</h3>

                                <div className="grid gap-2">
                                    {permissions
                                        .filter((permission) => permission.module === module)
                                        .map((permission) => (
                                            <label key={permission.slug} className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={granted.includes(permission.slug)}
                                                    disabled={!editable}
                                                    onCheckedChange={(checked) => toggle(permission.slug, checked === true)}
                                                />
                                                <span>{permission.label}</span>
                                            </label>
                                        ))}
                                </div>
                            </div>
                        ))}

                        {editable && (
                            <div>
                                <Button size="sm" disabled={busy} onClick={savePermissions}>
                                    Save permissions
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Stores</CardTitle>
                        <CardDescription className="mt-1.5">
                            The stores where this role operates. With no store marked, the role reaches every store.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="grid gap-3">
                        <div className="grid gap-2">
                            {stores.map((store) => (
                                <label key={store.id} className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={scope.includes(store.id)}
                                        disabled={!editable}
                                        onCheckedChange={(checked) => toggleStore(store.id, checked === true)}
                                    />
                                    <span>{store.name}</span>
                                    {store.code_name !== null && <Badge variant="outline">{store.code_name}</Badge>}
                                </label>
                            ))}
                        </div>

                        {editable && (
                            <div>
                                <Button size="sm" disabled={busy} onClick={saveStores}>
                                    Save stores
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
