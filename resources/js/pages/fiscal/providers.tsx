import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { postJson, putJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { Pencil, Plus, Radio, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

/**
 * A fiscal PROVIDER, as the backend hands it over.
 *
 * A provider is the CHANNEL a regime emits through: the local printer, an
 * external provider or the portal of the authority.
 *
 * `has_implementation` says whether that channel can emit at all. A channel
 * without implementation is not emulated: the screen says so instead of
 * pretending that a document can go through it.
 */
export interface FiscalProviderRow {
    id: number;
    fiscal_profile_id: number;
    profile_code: string;
    profile_name: string;
    code: string;
    name: string;
    kind: string;
    base_url: string | null;
    auth_scheme: string | null;
    credentials_ref: string | null;
    credential_env_prefix: string | null;
    is_active: boolean;
    has_implementation: boolean;
    environments: {
        id: number;
        code: string;
        label: string | null;
        role: string;
        is_testing: boolean;
        is_production: boolean;
    }[];
}

/**
 * A fiscal PROFILE, for the selector.
 */
export interface FiscalProfileOption {
    id: number;
    code: string;
    name: string;
    country_name: string;
}

/**
 * A CHANNEL of the catalogue, with the mark of the ones that cannot emit.
 */
export interface FiscalKind {
    value: string;
    label: string;
    has_implementation: boolean;
}

export interface FiscalProvidersIndexProps {
    providers: FiscalProviderRow[];
    profiles: FiscalProfileOption[];
    kinds: FiscalKind[];
}

/**
 * The form of the ALTA and of the EDICION.
 */
interface ProviderDraft {
    fiscal_profile_id: string;
    code: string;
    name: string;
    kind: string;
    base_url: string;
    auth_scheme: string;
    credentials_ref: string;
    credential_env_prefix: string;
    is_active: boolean;
}

/**
 * The PROVIDERS: the channels the regimes emit through.
 *
 * The channel is DATA and it does not change: what changes is the address
 * of the actor, its reference and its switch. And the secret of a channel is
 * NEVER in the database: the row declares the prefix of the environment
 * variable, and the secret lives there.
 */
export default function FiscalProvidersIndex({ providers, profiles, kinds }: FiscalProvidersIndexProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [warnings, setWarnings] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const [open, setOpen] = useState(false);

    const [draft, setDraft] = useState<ProviderDraft>({
        fiscal_profile_id: '',
        code: '',
        name: '',
        kind: '',
        base_url: '',
        auth_scheme: '',
        credentials_ref: '',
        credential_env_prefix: '',
        is_active: true,
    });

    /**
     * The form is EMPTY: it is the form of a NEW provider, and the server
     * refuses a bad declaration with its own message.
     */
    const resetForm = () => {
        setEditing(null);
        setDraft({
            fiscal_profile_id: '',
            code: '',
            name: '',
            kind: '',
            base_url: '',
            auth_scheme: '',
            credentials_ref: '',
            credential_env_prefix: '',
            is_active: true,
        });
    };

    /**
     * The SAVE: a new provider, or the one that is being edited.
     */
    const save = async () => {
        setError(null);
        setWarnings([]);
        setBusy(true);

        try {
            if (editing === null) {
                const respuesta = await postJson<{ warnings?: string[] }>('/api/fiscal/proveedores', {
                    fiscal_profile_id: Number(draft.fiscal_profile_id),
                    code: draft.code,
                    name: draft.name,
                    kind: draft.kind,
                    base_url: draft.base_url,
                    auth_scheme: draft.auth_scheme,
                    credentials_ref: draft.credentials_ref,
                    credential_env_prefix: draft.credential_env_prefix,
                    is_active: draft.is_active,
                });

                setWarnings(respuesta.warnings ?? []);
            } else {
                const respuesta = await putJson<{ warnings?: string[] }>(`/api/fiscal/proveedores/${editing}`, {
                    name: draft.name,
                    base_url: draft.base_url,
                    auth_scheme: draft.auth_scheme,
                    credentials_ref: draft.credentials_ref,
                    credential_env_prefix: draft.credential_env_prefix,
                    is_active: draft.is_active,
                });

                setWarnings(respuesta.warnings ?? []);
            }

            setOpen(false);
            resetForm();

            router.reload({ only: ['providers'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The fiscal provider could not be saved.');
        } finally {
            setBusy(false);
        }
    };

    /**
     * The EDIT: the form is filled with the provider that is being edited.
     */
    const edit = (row: FiscalProviderRow) => {
        setError(null);
        setWarnings([]);
        setEditing(row.id);
        setDraft({
            fiscal_profile_id: String(row.fiscal_profile_id),
            code: row.code,
            name: row.name,
            kind: row.kind,
            base_url: row.base_url ?? '',
            auth_scheme: row.auth_scheme ?? '',
            credentials_ref: row.credentials_ref ?? '',
            credential_env_prefix: row.credential_env_prefix ?? '',
            is_active: row.is_active,
        });
        setOpen(true);
    };

    const labelOf = (kind: string) => kinds.find((option) => option.value === kind)?.label ?? kind;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Fiscal providers',
            href: '/fiscal/proveedores',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal providers" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {error !== null && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardDescription className="text-destructive text-sm">{error}</CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {warnings.length > 0 && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardTitle className="text-destructive flex items-center gap-2 text-base">
                                <TriangleAlert className="size-4" />
                                About this channel
                            </CardTitle>
                            <CardDescription>
                                <ul className="list-disc space-y-1 pl-4">
                                    {warnings.map((warning) => (
                                        <li key={warning}>{warning}</li>
                                    ))}
                                </ul>
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                <Card>
                    <CardHeader className="flex-row items-start justify-between gap-3 space-y-0">
                        <div>
                            <CardTitle className="text-base">
                                {providers.length} {providers.length === 1 ? 'provider' : 'providers'}
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                A provider is the channel a regime emits through. The channel is data and it does not change:
                                what changes is the address of the actor, its reference and its switch. The secret of a
                                channel is never in the database: the row declares the prefix of the environment variable
                                and the secret lives there. A channel without implementation is not emulated: it is
                                declared, and the screen says that it cannot emit.
                            </CardDescription>
                        </div>

                        {can('fiscal.create') && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setError(null);
                                    setWarnings([]);
                                    resetForm();
                                    setOpen(true);
                                }}
                            >
                                <Plus />
                                New provider
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {providers.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no fiscal provider yet. Declare one: without a channel a regime has no way to emit.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Code</th>
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-left font-medium">Profile</th>
                                            <th className="px-2 py-2 text-left font-medium">Channel</th>
                                            <th className="px-2 py-2 text-left font-medium">Address</th>
                                            <th className="px-2 py-2 text-left font-medium">Credential prefix</th>
                                            <th className="px-2 py-2 text-left font-medium">Environments</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {providers.map((row) => (
                                            <tr key={row.id} className="border-b last:border-0">
                                                <td className="px-2 py-2 font-mono text-xs">{row.code}</td>
                                                <td className="px-2 py-2">{row.name}</td>
                                                <td className="px-2 py-2">
                                                    <span className="font-mono text-xs">{row.profile_code}</span>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <div className="flex flex-col gap-1">
                                                        <span>{labelOf(row.kind)}</span>
                                                        {!row.has_implementation && (
                                                            <Badge variant="destructive">Cannot emit</Badge>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-2 py-2 font-mono text-xs">{row.base_url ?? '—'}</td>
                                                <td className="px-2 py-2 font-mono text-xs">{row.credential_env_prefix ?? '—'}</td>
                                                <td className="px-2 py-2">
                                                    {row.environments.length === 0
                                                        ? '—'
                                                        : row.environments.map((environment) => environment.code).join(', ')}
                                                </td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={row.is_active ? 'default' : 'secondary'}>
                                                        {row.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </td>
                                                <td className="px-2 py-2">
                                                    <div className="flex justify-end gap-2">
                                                        {can('fiscal.update') && (
                                                            <Button variant="outline" size="sm" onClick={() => edit(row)}>
                                                                <Pencil />
                                                                Edit
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

            <Dialog open={open} onOpenChange={(value) => (value ? setOpen(true) : setOpen(false))}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>{editing === null ? 'New fiscal provider' : 'Edit fiscal provider'}</DialogTitle>
                        <DialogDescription>
                            {editing === null
                                ? 'The provider declares the channel of a regime. The secret of the channel is never written here: declare the prefix of the environment variable and put the secret there.'
                                : 'The channel does not change: what changes is the address of the actor, its reference, the prefix of its credentials and its switch. A deactivated channel stops emitting; the numbering is not touched.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="provider-profile">Profile</Label>
                            <Select
                                value={draft.fiscal_profile_id}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, fiscal_profile_id: value })}
                            >
                                <SelectTrigger id="provider-profile" aria-label="Profile">
                                    <SelectValue placeholder="Choose a profile" />
                                </SelectTrigger>
                                <SelectContent>
                                    {profiles.map((profile) => (
                                        <SelectItem key={profile.id} value={String(profile.id)}>
                                            {profile.code} — {profile.country_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="provider-kind">Channel</Label>
                            <Select
                                value={draft.kind}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, kind: value })}
                            >
                                <SelectTrigger id="provider-kind" aria-label="Channel of the provider">
                                    <SelectValue placeholder="Choose a channel" />
                                </SelectTrigger>
                                <SelectContent>
                                    {kinds.map((kind) => (
                                        <SelectItem key={kind.value} value={kind.value}>
                                            {kind.label}
                                            {kind.has_implementation ? '' : ' — cannot emit yet'}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="provider-code">Code</Label>
                            <Input
                                id="provider-code"
                                value={draft.code}
                                disabled={editing !== null}
                                onChange={(event) => setDraft({ ...draft, code: event.target.value })}
                                placeholder="local_printer"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="provider-name">Name</Label>
                            <Input
                                id="provider-name"
                                value={draft.name}
                                onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                                placeholder="The local printer of the system"
                            />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="provider-url">Address</Label>
                            <Input
                                id="provider-url"
                                value={draft.base_url}
                                onChange={(event) => setDraft({ ...draft, base_url: event.target.value })}
                                placeholder="https://..."
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="provider-auth">Authentication</Label>
                            <Input
                                id="provider-auth"
                                value={draft.auth_scheme}
                                onChange={(event) => setDraft({ ...draft, auth_scheme: event.target.value })}
                                placeholder="token"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="provider-prefix">Credential prefix</Label>
                            <Input
                                id="provider-prefix"
                                value={draft.credential_env_prefix}
                                onChange={(event) => setDraft({ ...draft, credential_env_prefix: event.target.value })}
                                placeholder="MY_PROVIDER"
                            />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="provider-credentials-ref">Credentials reference</Label>
                            <Input
                                id="provider-credentials-ref"
                                value={draft.credentials_ref}
                                onChange={(event) => setDraft({ ...draft, credentials_ref: event.target.value })}
                                placeholder="A reference to the agreement, never the secret"
                            />
                        </div>

                        <div className="flex items-center gap-2 sm:col-span-2">
                            <Checkbox
                                id="provider-active"
                                checked={draft.is_active}
                                onCheckedChange={(value) => setDraft({ ...draft, is_active: value === true })}
                            />
                            <Label htmlFor="provider-active">Active</Label>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={busy} onClick={save}>
                            <Radio />
                            {editing === null ? 'Create provider' : 'Save changes'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
