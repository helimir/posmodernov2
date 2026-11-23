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
import { Landmark, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * A fiscal PROFILE, as the backend hands it over.
 *
 * A profile is the REGIME of a country: the code that identifies it, the
 * currency its documents are written in, the model that signs them and the
 * technical description of the standard.
 *
 * The code, the country, the currency, the validity and the signing model
 * are the regime itself, and they are not edited: a regime is declared. What
 * is edited is how it is written down.
 */
export interface FiscalProfileRow {
    id: number;
    country_id: number;
    country_name: string;
    country_iso_code: string;
    code: string;
    name: string;
    document_standard: string | null;
    schema_version: string | null;
    schema_reference_url: string | null;
    xml_namespace: string | null;
    xsd_url: string | null;
    id_scheme_pattern: string | null;
    numbering_scheme: string | null;
    signer_model: string;
    default_currency_id: number | null;
    currency_code: string | null;
    effective_from: string | null;
    effective_to: string | null;
    requires_control_number: boolean;
    authorized_range_required: boolean;
    holes_allowed: boolean;
    rounding_rule: string | null;
    tax_inclusive_default: boolean;
}

/**
 * A COUNTRY of the catalogue.
 */
export interface FiscalCountryOption {
    id: number;
    iso_code: string;
    name: string;
}

/**
 * A CURRENCY of the catalogue.
 */
export interface FiscalCurrencyOption {
    id: number;
    code: string;
    name: string;
}

/**
 * A CLOSED set of choices: the signing models.
 */
export interface FiscalChoice {
    value: string;
    label: string;
}

export interface FiscalProfilesIndexProps {
    profiles: FiscalProfileRow[];
    countries: FiscalCountryOption[];
    currencies: FiscalCurrencyOption[];
    signer_models: FiscalChoice[];
    rounding_rules: FiscalChoice[];
}

/**
 * The form of the ALTA and of the EDICION.
 */
interface ProfileDraft {
    country_id: string;
    code: string;
    name: string;
    signer_model: string;
    default_currency_id: string;
    effective_from: string;
    effective_to: string;
    document_standard: string;
    schema_version: string;
    schema_reference_url: string;
    xml_namespace: string;
    xsd_url: string;
    id_scheme_pattern: string;
    numbering_scheme: string;
    requires_control_number: boolean;
    authorized_range_required: boolean;
    holes_allowed: boolean;
    rounding_rule: string;
    tax_inclusive_default: boolean;
}

/**
 * The PROFILES: the regimes of the installation.
 *
 * A profile is the fiscal regime of a country, and it is declared once: the
 * code, the country, the currency, the validity and the signing model do not
 * change afterwards. The edit is for the technical description: the version
 * of the schema, the namespace, the XSD and the pattern of the fiscal id.
 */
export default function FiscalProfilesIndex({ profiles, countries, currencies, signer_models, rounding_rules }: FiscalProfilesIndexProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [warnings, setWarnings] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const [open, setOpen] = useState(false);

    const [draft, setDraft] = useState<ProfileDraft>({
        country_id: '',
        code: '',
        name: '',
        signer_model: '',
        default_currency_id: '',
        effective_from: '',
        effective_to: '',
        document_standard: '',
        schema_version: '',
        schema_reference_url: '',
        xml_namespace: '',
        xsd_url: '',
        id_scheme_pattern: '',
        numbering_scheme: '',
        requires_control_number: false,
        authorized_range_required: false,
        holes_allowed: true,
        rounding_rule: rounding_rules[0]?.value ?? '',
        tax_inclusive_default: false,
    });

    /**
     * The form is EMPTY: it is the form of a NEW profile, and the server
     * refuses a bad declaration with its own message.
     */
    const resetForm = () => {
        setEditing(null);
        setDraft({
            country_id: '',
            code: '',
            name: '',
            signer_model: '',
            default_currency_id: '',
            effective_from: '',
            effective_to: '',
            document_standard: '',
            schema_version: '',
            schema_reference_url: '',
            xml_namespace: '',
            xsd_url: '',
            id_scheme_pattern: '',
            numbering_scheme: '',
            requires_control_number: false,
            authorized_range_required: false,
            holes_allowed: true,
            rounding_rule: rounding_rules[0]?.value ?? '',
            tax_inclusive_default: false,
        });
    };

    /**
     * The SAVE: a new profile, or the one that is being edited.
     */
    const save = async () => {
        setError(null);
        setWarnings([]);
        setBusy(true);

        try {
            if (editing === null) {
                const respuesta = await postJson<{ warnings?: string[] }>('/api/fiscal/profiles', {
                    country_id: Number(draft.country_id),
                    code: draft.code,
                    name: draft.name,
                    signer_model: draft.signer_model,
                    default_currency_id: Number(draft.default_currency_id),
                    effective_from: draft.effective_from || null,
                    effective_to: draft.effective_to || null,
                    document_standard: draft.document_standard,
                    schema_version: draft.schema_version,
                    schema_reference_url: draft.schema_reference_url,
                    xml_namespace: draft.xml_namespace,
                    xsd_url: draft.xsd_url,
                    id_scheme_pattern: draft.id_scheme_pattern,
                    numbering_scheme: draft.numbering_scheme,
                    requires_control_number: draft.requires_control_number,
                    authorized_range_required: draft.authorized_range_required,
                    holes_allowed: draft.holes_allowed,
                    rounding_rule: draft.rounding_rule,
                    tax_inclusive_default: draft.tax_inclusive_default,
                });

                setWarnings(respuesta.warnings ?? []);
            } else {
                const respuesta = await putJson<{ warnings?: string[] }>(`/api/fiscal/profiles/${editing}`, {
                    name: draft.name,
                    document_standard: draft.document_standard,
                    schema_version: draft.schema_version,
                    schema_reference_url: draft.schema_reference_url,
                    xml_namespace: draft.xml_namespace,
                    xsd_url: draft.xsd_url,
                    id_scheme_pattern: draft.id_scheme_pattern,
                    numbering_scheme: draft.numbering_scheme,
                });

                setWarnings(respuesta.warnings ?? []);
            }

            setOpen(false);
            resetForm();

            router.reload({ only: ['profiles'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The fiscal profile could not be saved.');
        } finally {
            setBusy(false);
        }
    };

    /**
     * The EDIT: the form is filled with the profile that is being edited.
     */
    const edit = (row: FiscalProfileRow) => {
        setError(null);
        setWarnings([]);
        setEditing(row.id);
        setDraft({
            country_id: String(row.country_id),
            code: row.code,
            name: row.name,
            signer_model: row.signer_model,
            default_currency_id: row.default_currency_id === null ? '' : String(row.default_currency_id),
            effective_from: row.effective_from ?? '',
            effective_to: row.effective_to ?? '',
            document_standard: row.document_standard ?? '',
            schema_version: row.schema_version ?? '',
            schema_reference_url: row.schema_reference_url ?? '',
            xml_namespace: row.xml_namespace ?? '',
            xsd_url: row.xsd_url ?? '',
            id_scheme_pattern: row.id_scheme_pattern ?? '',
            numbering_scheme: row.numbering_scheme ?? '',
            requires_control_number: row.requires_control_number,
            authorized_range_required: row.authorized_range_required,
            holes_allowed: row.holes_allowed,
            rounding_rule: row.rounding_rule ?? rounding_rules[0]?.value ?? '',
            tax_inclusive_default: row.tax_inclusive_default,
        });
        setOpen(true);
    };

    const labelOf = (choices: FiscalChoice[], value: string) => choices.find((choice) => choice.value === value)?.label ?? value;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Fiscal profiles',
            href: '/fiscal/perfiles',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal profiles" />

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
                            <CardTitle className="text-base">About this profile</CardTitle>
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
                                {profiles.length} {profiles.length === 1 ? 'profile' : 'profiles'}
                            </CardTitle>
                            <CardDescription className="mt-1.5">
                                A profile is the fiscal regime of a country: its code, its currency, who signs its documents
                                and how the standard is written down. The regime is declared once; what is edited afterwards is
                                the description of the standard. A profile without a currency cannot emit.
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
                                New profile
                            </Button>
                        )}
                    </CardHeader>

                    <CardContent>
                        {profiles.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no fiscal profile yet. Declare one: without a regime a store has no numbering.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Code</th>
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-left font-medium">Country</th>
                                            <th className="px-2 py-2 text-left font-medium">Currency</th>
                                            <th className="px-2 py-2 text-left font-medium">Signer</th>
                                            <th className="px-2 py-2 text-left font-medium">Standard</th>
                                            <th className="px-2 py-2 text-left font-medium">Valid from</th>
                                            <th className="px-2 py-2 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {profiles.map((row) => (
                                            <tr key={row.id} className="border-b last:border-0">
                                                <td className="px-2 py-2 font-mono text-xs">{row.code}</td>
                                                <td className="px-2 py-2">{row.name}</td>
                                                <td className="px-2 py-2">{row.country_name}</td>
                                                <td className="px-2 py-2">{row.currency_code ?? '—'}</td>
                                                <td className="px-2 py-2">{labelOf(signer_models, row.signer_model)}</td>
                                                <td className="px-2 py-2">
                                                    {row.document_standard ? (
                                                        <Badge variant="secondary">{row.document_standard}</Badge>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="px-2 py-2">{row.effective_from ?? '—'}</td>
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
                        <DialogTitle>{editing === null ? 'New fiscal profile' : 'Edit fiscal profile'}</DialogTitle>
                        <DialogDescription>
                            {editing === null
                                ? 'The profile declares the fiscal regime of a country: its code is its natural key, its currency is the currency of its documents and its validity says from when the regime is in force.'
                                : 'The regime is not mutated: the code, the country, the currency, the validity and the signing model stay as they are. What is corrected is how the standard is written down.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="profile-country">Country</Label>
                            <Select
                                value={draft.country_id}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, country_id: value })}
                            >
                                <SelectTrigger id="profile-country" aria-label="Country">
                                    <SelectValue placeholder="Choose a country" />
                                </SelectTrigger>
                                <SelectContent>
                                    {countries.map((country) => (
                                        <SelectItem key={country.id} value={String(country.id)}>
                                            {country.name} ({country.iso_code})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-code">Code</Label>
                            <Input
                                id="profile-code"
                                value={draft.code}
                                disabled={editing !== null}
                                onChange={(event) => setDraft({ ...draft, code: event.target.value })}
                                placeholder="VE_MEDIOS_DIGITALES"
                            />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="profile-name">Name</Label>
                            <Input
                                id="profile-name"
                                value={draft.name}
                                onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                                placeholder="Digital media (SENIAT)"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-currency">Currency</Label>
                            <Select
                                value={draft.default_currency_id}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, default_currency_id: value })}
                            >
                                <SelectTrigger id="profile-currency" aria-label="Currency of the regime">
                                    <SelectValue placeholder="Choose a currency" />
                                </SelectTrigger>
                                <SelectContent>
                                    {currencies.map((currency) => (
                                        <SelectItem key={currency.id} value={String(currency.id)}>
                                            {currency.code} — {currency.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-signer">Signer</Label>
                            <Select
                                value={draft.signer_model}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, signer_model: value })}
                            >
                                <SelectTrigger id="profile-signer" aria-label="Model that signs the documents">
                                    <SelectValue placeholder="Choose a signer" />
                                </SelectTrigger>
                                <SelectContent>
                                    {signer_models.map((signer) => (
                                        <SelectItem key={signer.value} value={signer.value}>
                                            {signer.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-valid-from">Valid from</Label>
                            <Input
                                id="profile-valid-from"
                                type="date"
                                value={draft.effective_from}
                                disabled={editing !== null}
                                onChange={(event) => setDraft({ ...draft, effective_from: event.target.value })}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-valid-to">Valid until</Label>
                            <Input
                                id="profile-valid-to"
                                type="date"
                                value={draft.effective_to}
                                disabled={editing !== null}
                                onChange={(event) => setDraft({ ...draft, effective_to: event.target.value })}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-standard">Document standard</Label>
                            <Input
                                id="profile-standard"
                                value={draft.document_standard}
                                onChange={(event) => setDraft({ ...draft, document_standard: event.target.value })}
                                placeholder="seniat_means"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-schema-version">Schema version</Label>
                            <Input
                                id="profile-schema-version"
                                value={draft.schema_version}
                                onChange={(event) => setDraft({ ...draft, schema_version: event.target.value })}
                                placeholder="1.0"
                            />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="profile-schema-url">Schema reference</Label>
                            <Input
                                id="profile-schema-url"
                                value={draft.schema_reference_url}
                                onChange={(event) => setDraft({ ...draft, schema_reference_url: event.target.value })}
                                placeholder="https://..."
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-namespace">XML namespace</Label>
                            <Input
                                id="profile-namespace"
                                value={draft.xml_namespace}
                                onChange={(event) => setDraft({ ...draft, xml_namespace: event.target.value })}
                                placeholder="http://..."
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-xsd">XSD</Label>
                            <Input
                                id="profile-xsd"
                                value={draft.xsd_url}
                                onChange={(event) => setDraft({ ...draft, xsd_url: event.target.value })}
                                placeholder="https://.../schema.xsd"
                            />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="profile-id-pattern">Fiscal id pattern</Label>
                            <Input
                                id="profile-id-pattern"
                                value={draft.id_scheme_pattern}
                                onChange={(event) => setDraft({ ...draft, id_scheme_pattern: event.target.value })}
                                placeholder="The official pattern of the fiscal id"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-numbering">Numbering scheme</Label>
                            <Input
                                id="profile-numbering"
                                value={draft.numbering_scheme}
                                onChange={(event) => setDraft({ ...draft, numbering_scheme: event.target.value })}
                                placeholder="serie_emisor+control"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="profile-rounding">Rounding</Label>
                            <Select
                                value={draft.rounding_rule}
                                disabled={editing !== null}
                                onValueChange={(value) => setDraft({ ...draft, rounding_rule: value })}
                            >
                                <SelectTrigger id="profile-rounding" aria-label="Rounding rule">
                                    <SelectValue placeholder="Choose a rounding rule" />
                                </SelectTrigger>
                                <SelectContent>
                                    {rounding_rules.map((rule) => (
                                        <SelectItem key={rule.value} value={rule.value}>
                                            {rule.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {editing === null && (
                            <div className="flex flex-wrap items-center gap-4 sm:col-span-2">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="profile-control-number"
                                        checked={draft.requires_control_number}
                                        onCheckedChange={(value) => setDraft({ ...draft, requires_control_number: value === true })}
                                    />
                                    <Label htmlFor="profile-control-number">Requires a control number</Label>
                                </div>

                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="profile-authorized-range"
                                        checked={draft.authorized_range_required}
                                        onCheckedChange={(value) => setDraft({ ...draft, authorized_range_required: value === true })}
                                    />
                                    <Label htmlFor="profile-authorized-range">Requires an authorized range</Label>
                                </div>

                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="profile-holes"
                                        checked={draft.holes_allowed}
                                        onCheckedChange={(value) => setDraft({ ...draft, holes_allowed: value === true })}
                                    />
                                    <Label htmlFor="profile-holes">Allows holes</Label>
                                </div>

                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="profile-tax-inclusive"
                                        checked={draft.tax_inclusive_default}
                                        onCheckedChange={(value) => setDraft({ ...draft, tax_inclusive_default: value === true })}
                                    />
                                    <Label htmlFor="profile-tax-inclusive">Tax included by default</Label>
                                </div>
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button disabled={busy} onClick={save}>
                            <Landmark />
                            {editing === null ? 'Create profile' : 'Save changes'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
