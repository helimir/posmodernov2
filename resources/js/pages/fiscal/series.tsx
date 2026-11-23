import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { postJson, putJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { Hash, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * A fiscal SERIES, as the backend hands it over.
 *
 * A series is the NUMBERING a store declares: which regime it belongs to,
 * which role it plays (the document numbers, the control numbers or the
 * retention receipt numbers), which authority assigns the number, the prefix
 * that goes in front of it and the authorized range.
 *
 * `next_number` is the counter of the series, and it is NOT editable: the
 * domain moves it, atomically, when it reserves a number.
 */
export interface FiscalSeriesRow {
    id: number;
    fiscal_profile_id: number;
    profile_code: string;
    profile_name: string;
    store_id: number;
    store_name: string;
    provider_id: number | null;
    provider_code: string | null;
    series_role: string;
    assigned_by: string;
    prefix: string | null;
    next_number: number;
    from_number: number;
    to_number: number;
    valid_from: string | null;
    valid_to: string | null;
    credential_ref: string | null;
    control_identifier: string | null;
    sequence_length: number | null;
}

/**
 * A fiscal PROFILE, for the selector: the regime a series can belong to.
 */
export interface FiscalProfileOption {
    id: number;
    code: string;
    name: string;
    country_name: string;
}

/**
 * A STORE, for the selector: where a series can be declared.
 */
export interface FiscalStoreOption {
    id: number;
    name: string;
    code_name: string | null;
}

/**
 * A fiscal PROVIDER, for the selector: the printer that backs a range.
 *
 * The provider carries its profile: the printer that backs a range belongs to
 * the SAME regime as the series, so the selector only offers the ones of the
 * chosen profile.
 */
export interface FiscalProviderOption {
    id: number;
    fiscal_profile_id: number;
    code: string;
    name: string;
    kind: string;
}

/**
 * A CLOSED set of choices: the roles of a series, the authorities.
 */
export interface FiscalChoice {
    value: string;
    label: string;
}

export interface FiscalSeriesIndexProps {
    series: FiscalSeriesRow[];
    profiles: FiscalProfileOption[];
    stores: FiscalStoreOption[];
    providers: FiscalProviderOption[];
    roles: FiscalChoice[];
    authorities: FiscalChoice[];
}

/**
 * The form of the ALTA and of the EDICION.
 */
interface SeriesDraft {
    fiscal_profile_id: string;
    store_id: string;
    series_role: string;
    assigned_by: string;
    prefix: string;
    from_number: string;
    to_number: string;
    provider_id: string;
    valid_from: string;
    valid_to: string;
    credential_ref: string;
    control_identifier: string;
    sequence_length: string;
}

/**
 * The value that means "no provider". Radix does not allow an empty string
 * as the value of an item.
 */
const NONE = 'none';

/**
 * The SERIES of the stores: the numbering each store declares.
 *
 * A series is DECLARED once: the profile, the store, the role, the authority,
 * the prefix, the range and the counter do not change afterwards. What does
 * change is the validity of the series and the reference to the
 * authorization, and that is what the edit form offers.
 */
export default function FiscalSeriesIndex({ series, profiles, stores, providers, roles, authorities }: FiscalSeriesIndexProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [warnings, setWarnings] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const [open, setOpen] = useState(false);

    const [draft, setDraft] = useState<SeriesDraft>({
        fiscal_profile_id: '',
        store_id: '',
        series_role: '',
        assigned_by: '',
        prefix: '',
        from_number: '1',
        to_number: '999999',
        provider_id: NONE,
        valid_from: '',
        valid_to: '',
        credential_ref: '',
        control_identifier: '',
        sequence_length: '',
    });

    /**
     * The form is EMPTY: it is the form of a NEW series, and the server
     * refuses a bad declaration with its own message.
     */
    const resetForm = () => {
        setEditing(null);
        setDraft({
            fiscal_profile_id: '',
            store_id: '',
            series_role: '',
            assigned_by: '',
            prefix: '',
            from_number: '1',
            to_number: '999999',
            provider_id: NONE,
            valid_from: '',
            valid_to: '',
            credential_ref: '',
            control_identifier: '',
            sequence_length: '',
        });
    };

    /**
     * The SAVE: a new series, or the one that is being edited.
     */
    const save = async () => {
        setError(null);
        setWarnings([]);
        setBusy(true);

        try {
            if (editing === null) {
                const respuesta = await postJson<{ warnings?: string[] }>('/api/fiscal/series', {
                    fiscal_profile_id: Number(draft.fiscal_profile_id),
                    store_id: Number(draft.store_id),
                    series_role: draft.series_role,
                    assigned_by: draft.assigned_by,
                    prefix: draft.prefix,
                    from_number: Number(draft.from_number),
                    to_number: Number(draft.to_number),
                    provider_id: draft.provider_id === NONE ? null : Number(draft.provider_id),
                    valid_from: draft.valid_from || null,
                    valid_to: draft.valid_to || null,
                    credential_ref: draft.credential_ref,
                    control_identifier: draft.control_identifier,
                    sequence_length: draft.sequence_length === '' ? null : Number(draft.sequence_length),
                });

                setWarnings(respuesta.warnings ?? []);
            } else {
                const respuesta = await putJson<{ warnings?: string[] }>(`/api/fiscal/series/${editing}`, {
                    provider_id: draft.provider_id === NONE ? null : Number(draft.provider_id),
                    valid_from: draft.valid_from || null,
                    valid_to: draft.valid_to || null,
                    credential_ref: draft.credential_ref,
                });

                setWarnings(respuesta.warnings ?? []);
            }

            setOpen(false);
            resetForm();

            router.reload({ only: ['series'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The fiscal series could not be saved.');
        } finally {
            setBusy(false);
        }
    };

    /**
     * The EDIT: the form is filled with the series that is being edited, and
     * only with what a series lets you change.
     */
    const edit = (row: FiscalSeriesRow) => {
        setError(null);
        setWarnings([]);
        setEditing(row.id);
        setDraft({
            fiscal_profile_id: String(row.fiscal_profile_id),
            store_id: String(row.store_id),
            series_role: row.series_role,
            assigned_by: row.assigned_by,
            prefix: row.prefix ?? '',
            from_number: String(row.from_number),
            to_number: String(row.to_number),
            provider_id: row.provider_id === null ? NONE : String(row.provider_id),
            valid_from: row.valid_from ?? '',
            valid_to: row.valid_to ?? '',
            credential_ref: row.credential_ref ?? '',
            control_identifier: row.control_identifier ?? '',
            sequence_length: row.sequence_length === null ? '' : String(row.sequence_length),
        });
        setOpen(true);
    };

    const labelOf = (choices: FiscalChoice[], value: string) => choices.find((choice) => choice.value === value)?.label ?? value;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Fiscal series',
            href: '/fiscal/series',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal series" />

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
                            <CardTitle className="text-base">About this series</CardTitle>
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
                                {series.length} {series.length === 1 ? 'series' : 'series'}
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                A series declares the numbering of a store: the regime it belongs to, the role it plays, the
                                authority that assigns the number, the prefix and the authorized range. The numbering is
                                declared once: afterwards only its validity and its authorization change. The counter is
                                moved by the system, never by hand.
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
                                New series
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {series.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no fiscal series yet. Declare one: without a series the till cannot number a document.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Store</th>
                                            <th className="px-2 py-2 text-left font-medium">Profile</th>
                                            <th className="px-2 py-2 text-left font-medium">Role</th>
                                            <th className="px-2 py-2 text-left font-medium">Authority</th>
                                            <th className="px-2 py-2 text-left font-medium">Prefix</th>
                                            <th className="px-2 py-2 text-right font-medium">Range</th>
                                            <th className="px-2 py-2 text-right font-medium">Next</th>
                                            <th className="px-2 py-2 text-left font-medium">Valid until</th>
                                            <th className="px-2 py-2 text-left font-medium">Printer</th>
                                            <th className="px-2 py-2 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {series.map((row) => (
                                            <tr key={row.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">{row.store_name}</td>
                                                <td className="px-2 py-2">
                                                    <span className="font-mono text-xs">{row.profile_code}</span>
                                                </td>
                                                <td className="px-2 py-2">{labelOf(roles, row.series_role)}</td>
                                                <td className="px-2 py-2">{labelOf(authorities, row.assigned_by)}</td>
                                                <td className="px-2 py-2 font-mono text-xs">{row.prefix ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    {row.from_number}–{row.to_number}
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">{row.next_number}</td>
                                                <td className="px-2 py-2">{row.valid_to ?? '—'}</td>
                                                <td className="px-2 py-2">{row.provider_code ?? '—'}</td>
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
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{editing === null ? 'New fiscal series' : 'Edit fiscal series'}</DialogTitle>
                        <DialogDescription>
                            {editing === null
                                ? 'The series declares the numbering of a store. The counter starts at the beginning of the range: the series does not hand out any number when it is created.'
                                : 'A declared series is not renumbered: the profile, the store, the role, the authority, the prefix and the range stay as they are. What changes is the validity of the series and the reference to its authorization.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="series-profile">Profile</Label>
                            <Select
                                value={draft.fiscal_profile_id}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, fiscal_profile_id: value })}
                            >
                                <SelectTrigger id="series-profile" aria-label="Profile">
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
                            <Label htmlFor="series-store">Store</Label>
                            <Select
                                value={draft.store_id}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, store_id: value })}
                            >
                                <SelectTrigger id="series-store" aria-label="Store">
                                    <SelectValue placeholder="Choose a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    {stores.map((store) => (
                                        <SelectItem key={store.id} value={String(store.id)}>
                                            {store.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="series-role">Role</Label>
                            <Select
                                value={draft.series_role}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, series_role: value })}
                            >
                                <SelectTrigger id="series-role" aria-label="Role of the series">
                                    <SelectValue placeholder="Choose a role" />
                                </SelectTrigger>
                                <SelectContent>
                                    {roles.map((role) => (
                                        <SelectItem key={role.value} value={role.value}>
                                            {role.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="series-authority">Authority</Label>
                            <Select
                                value={draft.assigned_by}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, assigned_by: value })}
                            >
                                <SelectTrigger id="series-authority" aria-label="Authority that assigns the number">
                                    <SelectValue placeholder="Choose an authority" />
                                </SelectTrigger>
                                <SelectContent>
                                    {authorities.map((authority) => (
                                        <SelectItem key={authority.value} value={authority.value}>
                                            {authority.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="series-prefix">Prefix</Label>
                            <Input
                                id="series-prefix"
                                value={draft.prefix}
                                disabled={editing !== null}
                                onChange={(event) => setDraft({ ...draft, prefix: event.target.value })}
                                placeholder="serie-001"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="series-provider">Printer</Label>
                            <Select value={draft.provider_id} onValueChange={(value) => setDraft({ ...draft, provider_id: value })}>
                                <SelectTrigger id="series-provider" aria-label="Printer that backs the range">
                                    <SelectValue placeholder="No printer" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>No printer</SelectItem>
                                    {providers
                                        .filter(
                                            (provider) =>
                                                draft.fiscal_profile_id === '' ||
                                                provider.fiscal_profile_id === Number(draft.fiscal_profile_id),
                                        )
                                        .map((provider) => (
                                            <SelectItem key={provider.id} value={String(provider.id)}>
                                                {provider.code} — {provider.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="series-from">First number</Label>
                            <Input
                                id="series-from"
                                type="number"
                                min={1}
                                value={draft.from_number}
                                disabled={editing !== null}
                                onChange={(event) => setDraft({ ...draft, from_number: event.target.value })}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="series-to">Last number</Label>
                            <Input
                                id="series-to"
                                type="number"
                                min={1}
                                value={draft.to_number}
                                disabled={editing !== null}
                                onChange={(event) => setDraft({ ...draft, to_number: event.target.value })}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="series-valid-from">Valid from</Label>
                            <Input
                                id="series-valid-from"
                                type="date"
                                value={draft.valid_from}
                                onChange={(event) => setDraft({ ...draft, valid_from: event.target.value })}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="series-valid-to">Valid until</Label>
                            <Input
                                id="series-valid-to"
                                type="date"
                                value={draft.valid_to}
                                onChange={(event) => setDraft({ ...draft, valid_to: event.target.value })}
                            />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="series-credential">Authorization reference</Label>
                            <Input
                                id="series-credential"
                                value={draft.credential_ref}
                                onChange={(event) => setDraft({ ...draft, credential_ref: event.target.value })}
                                placeholder="The document the authority granted the range with"
                            />
                        </div>

                        {editing === null && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="series-control-identifier">Control identifier</Label>
                                    <Input
                                        id="series-control-identifier"
                                        value={draft.control_identifier}
                                        onChange={(event) => setDraft({ ...draft, control_identifier: event.target.value })}
                                        placeholder="00"
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="series-sequence-length">Control sequence length</Label>
                                    <Input
                                        id="series-sequence-length"
                                        type="number"
                                        min={1}
                                        max={8}
                                        value={draft.sequence_length}
                                        onChange={(event) => setDraft({ ...draft, sequence_length: event.target.value })}
                                        placeholder="8"
                                    />
                                </div>
                            </>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={busy} onClick={save}>
                            <Hash />
                            {editing === null ? 'Create series' : 'Save changes'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
