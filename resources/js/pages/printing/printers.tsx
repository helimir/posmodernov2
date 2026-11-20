import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { postJson, putJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * A printer of the catalog, as the backend hands it over.
 *
 * The `code` is the NATURAL KEY of the printer: it is what a destination
 * looks up, and the server normalises it (no spaces around, upper case).
 */
export interface PrinterRow {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    targets_count: number;
}

export interface PrintersIndexProps {
    printers: PrinterRow[];
}

/**
 * The CATALOG of printers: the identity of the models of printer.
 *
 * A store declares the printers it has here, and the destinations point at
 * one of them. The code is the key: it is normalised by the server, and a
 * repeated code is refused with its reason.
 */
export default function PrintersIndex({ printers }: PrintersIndexProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const [code, setCode] = useState('');
    const [name, setName] = useState('');
    const [isActive, setIsActive] = useState(true);

    /**
     * The form is EMPTY: it is the form of a NEW printer, and the server
     * refuses a repeated code with its own message.
     */
    const resetForm = () => {
        setEditing(null);
        setCode('');
        setName('');
        setIsActive(true);
    };

    /**
     * The SAVE: a new printer, or the one that is being edited. The server
     * decides what is valid, and says why when it is not.
     */
    const save = async () => {
        setError(null);
        setSaving(true);

        try {
            if (editing === null) {
                await postJson('/api/impresion/impresoras', { code, name });
            } else {
                await putJson(`/api/impresion/impresoras/${editing}`, { code, name, is_active: isActive });
            }

            resetForm();

            router.reload({ only: ['printers'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The printer could not be saved.');
        } finally {
            setSaving(false);
        }
    };

    /**
     * The EDIT: the form is filled with the printer that is being edited.
     */
    const edit = (printer: PrinterRow) => {
        setError(null);
        setEditing(printer.id);
        setCode(printer.code);
        setName(printer.name);
        setIsActive(printer.is_active);
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Printers',
            href: '/impresion/impresoras',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Printers" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {error !== null && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardDescription className="text-destructive text-sm">{error}</CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {can('printing.create') && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">{editing === null ? 'New printer' : 'Edit printer'}</CardTitle>
                            <CardDescription className="mt-1.5">
                                The code is the key of the catalog: it is stored in upper case and without spaces around, and it cannot be repeated.
                                Every change is written down in the audit log.
                            </CardDescription>
                        </CardHeader>

                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="printer-code">Code</Label>
                                    <Input
                                        id="printer-code"
                                        value={code}
                                        onChange={(event) => setCode(event.target.value)}
                                        placeholder="EPI-80MM"
                                        aria-label="Code"
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="printer-name">Name</Label>
                                    <Input
                                        id="printer-name"
                                        value={name}
                                        onChange={(event) => setName(event.target.value)}
                                        placeholder="Thermal printer of the counter"
                                        aria-label="Name"
                                    />
                                </div>

                                {editing !== null && (
                                    <div className="flex items-center gap-2 self-end pb-2">
                                        <Checkbox id="printer-active" checked={isActive} onCheckedChange={(value) => setIsActive(value === true)} />
                                        <Label htmlFor="printer-active">Active</Label>
                                    </div>
                                )}

                                <div className="flex items-end gap-2">
                                    <Button onClick={save} disabled={saving}>
                                        <Plus />
                                        {editing === null ? 'Add printer' : 'Save changes'}
                                    </Button>

                                    {editing !== null && (
                                        <Button variant="outline" onClick={resetForm} disabled={saving}>
                                            Cancel
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {printers.length} {printers.length === 1 ? 'printer' : 'printers'}
                        </CardTitle>
                    </CardHeader>

                    <CardContent>
                        {printers.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no printer in the catalog yet. Add one: a print destination always points at a printer.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Code</th>
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-right font-medium">Destinations</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            {can('printing.update') && <th className="px-2 py-2 text-right font-medium">Actions</th>}
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {printers.map((printer) => (
                                            <tr key={printer.id} className="border-b last:border-0">
                                                <td className="px-2 py-2 font-mono">{printer.code}</td>
                                                <td className="px-2 py-2">{printer.name}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">{printer.targets_count}</td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={printer.is_active ? 'default' : 'secondary'}>
                                                        {printer.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </td>

                                                {can('printing.update') && (
                                                    <td className="px-2 py-2 text-right">
                                                        <Button variant="outline" size="sm" onClick={() => edit(printer)}>
                                                            <Pencil />
                                                            Edit
                                                        </Button>
                                                    </td>
                                                )}
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
