import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { postJson, putJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { Pencil, Plug, Plus } from 'lucide-react';
import { useState } from 'react';

/**
 * A print destination of the store, as the backend hands it over.
 *
 * The destination declares EVERYTHING about its printer: the transport, the
 * endpoint, the width, the code page and the QR. The system does not guess
 * any of it.
 */
export interface PrintTargetRow {
    id: number;
    name: string;
    printer_id: number;
    printer_code: string;
    printer_name: string;
    transport: 'browser' | 'lan_agent' | 'server_socket';
    endpoint: string | null;
    printer_profile: string;
    char_per_line: number | null;
    charset: string;
    max_qr_payload_bytes: number;
    supports_qr: boolean;
    is_active: boolean;
}

/**
 * A printer of the catalog, for the selector.
 */
export interface PrinterOption {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    targets_count: number;
}

/**
 * A profile of the printing library, with the code pages it can encode.
 */
export interface PrinterProfile {
    id: string;
    name: string;
    code_pages: string[];
}

/**
 * A print agent of the store, with the inventory it declared in its
 * heartbeat.
 *
 * An agent reports the printers it can reach. A destination that the
 * inventory does not cover is NOT rejected: a printer may be off at that
 * moment. The screen says so, because a silent mismatch is the wrong kind
 * of mismatch.
 */
export interface PrintAgentRow {
    id: number;
    name: string;
    instance_id: string | null;
    is_active: boolean;
    revoked: boolean;
    last_heartbeat_at: string | null;
    printers: { code: string; name: string }[];
    capabilities: string[];
    uncovered_targets: {
        target_id: number;
        name: string;
        printer_code: string;
        printer_name: string;
        reason: string;
    }[];
}

export interface PrintTargetsIndexProps {
    targets: PrintTargetRow[];
    printers: PrinterOption[];
    profiles: PrinterProfile[];
    store: { id: number; name: string; code_name: string | null };
    agents: PrintAgentRow[];
}

/**
 * The RESULT of the connection test of one destination.
 */
interface ConnectionTest {
    id: number;
    status: 'connected' | 'failed' | 'not_testable' | 'not_applicable';
    message: string;
    warnings: string[];
}

const NONE = 'none';

/**
 * The DESTINATIONS of the store: where its tickets go.
 *
 * A store declares its destinations here, and the till does not choose: with
 * one active destination the ticket goes there, and with more than one the
 * ticket stays pending, because choosing for the cashier is guessing.
 */
export default function PrintTargetsIndex({ targets, printers, profiles, store, agents }: PrintTargetsIndexProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState<number | null>(null);
    const [test, setTest] = useState<ConnectionTest | null>(null);
    const [editing, setEditing] = useState<number | null>(null);

    const [printerId, setPrinterId] = useState<string>(NONE);
    const [name, setName] = useState('');
    const [transport, setTransport] = useState<PrintTargetRow['transport']>('server_socket');
    const [endpoint, setEndpoint] = useState('tcp://192.168.1.50:9100');
    const [profile, setProfile] = useState('default');
    const [charPerLine, setCharPerLine] = useState('42');
    const [charset, setCharset] = useState('CP850');
    const [maxQrPayloadBytes, setMaxQrPayloadBytes] = useState('512');
    const [supportsQr, setSupportsQr] = useState(true);
    const [isActive, setIsActive] = useState(true);

    /**
     * The CODE PAGES of the selected profile: the profile decides which code
     * pages it can encode, and the destination cannot declare one that the
     * profile does not know.
     */
    const codePages = profiles.find((item) => item.id === profile)?.code_pages ?? [];

    /**
     * The form is EMPTY: it is the form of a NEW destination, and the server
     * refuses a bad endpoint with its own message.
     */
    const resetForm = () => {
        setEditing(null);
        setPrinterId(NONE);
        setName('');
        setTransport('server_socket');
        setEndpoint('tcp://192.168.1.50:9100');
        setProfile('default');
        setCharPerLine('42');
        setCharset('CP850');
        setMaxQrPayloadBytes('512');
        setSupportsQr(true);
        setIsActive(true);
    };

    /**
     * The SAVE: a new destination, or the one that is being edited.
     */
    const save = async () => {
        setError(null);
        setSaving(true);

        const payload = {
            printer_id: Number(printerId),
            name,
            transport,
            // The BROWSER is the destination: it has no endpoint.
            endpoint: transport === 'browser' ? null : endpoint,
            printer_profile: profile,
            char_per_line: Number(charPerLine),
            charset,
            max_qr_payload_bytes: Number(maxQrPayloadBytes),
            supports_qr: supportsQr,
            is_active: isActive,
        };

        try {
            if (editing === null) {
                await postJson('/api/impresion/destinos', payload);
            } else {
                await putJson(`/api/impresion/destinos/${editing}`, payload);
            }

            resetForm();

            router.reload({ only: ['targets'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The print destination could not be saved.');
        } finally {
            setSaving(false);
        }
    };

    /**
     * The EDIT: the form is filled with the destination that is being edited.
     */
    const edit = (target: PrintTargetRow) => {
        setError(null);
        setEditing(target.id);
        setPrinterId(String(target.printer_id));
        setName(target.name);
        setTransport(target.transport);
        setEndpoint(target.endpoint ?? '');
        setProfile(target.printer_profile);
        setCharPerLine(target.char_per_line === null ? '' : String(target.char_per_line));
        setCharset(target.charset);
        setMaxQrPayloadBytes(String(target.max_qr_payload_bytes));
        setSupportsQr(target.supports_qr);
        setIsActive(target.is_active);
    };

    /**
     * The TEST of the connection: the server opens the connection and closes
     * it. No ticket is printed: here it is proved, not printed.
     */
    const testConnection = async (target: PrintTargetRow) => {
        setError(null);
        setTesting(target.id);

        try {
            const resultado = await postJson<{
                status: ConnectionTest['status'];
                message: string;
                warnings: string[];
            }>(`/api/impresion/destinos/${target.id}/probar`, {});

            setTest({
                id: target.id,
                status: resultado.status,
                message: resultado.message,
                warnings: resultado.warnings ?? [],
            });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The connection could not be tested.');
        } finally {
            setTesting(null);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Print destinations',
            href: '/impresion/destinos',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Print destinations" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {error !== null && (
                    <Card className="border-destructive">
                        <CardHeader>
                            <CardDescription className="text-destructive text-sm">{error}</CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {test !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Connection test: {test.status === 'connected' ? 'the printer answered' : test.status}
                            </CardTitle>
                            <CardDescription className="mt-1.5">{test.message}</CardDescription>

                            {test.warnings.length > 0 && (
                                <ul className="text-muted-foreground mt-1.5 list-disc pl-5 text-sm">
                                    {test.warnings.map((warning) => (
                                        <li key={warning}>{warning}</li>
                                    ))}
                                </ul>
                            )}
                        </CardHeader>
                    </Card>
                )}

                {can('printing.create') && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">{editing === null ? 'New print destination' : 'Edit print destination'}</CardTitle>
                            <CardDescription className="mt-1.5">
                                The destination declares what its printer can do: how the bytes are written, where, how wide the paper is, which code
                                page it uses and whether it can print a QR code. The system does not guess any of it. The browser transport has no
                                endpoint: the browser is the destination.
                            </CardDescription>
                        </CardHeader>

                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="target-printer">Printer</Label>
                                    <Select value={printerId} onValueChange={setPrinterId}>
                                        <SelectTrigger id="target-printer" aria-label="Printer">
                                            <SelectValue placeholder="Choose a printer" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {printers.map((printer) => (
                                                <SelectItem key={printer.id} value={String(printer.id)}>
                                                    {printer.code} — {printer.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="target-name">Name</Label>
                                    <Input
                                        id="target-name"
                                        value={name}
                                        onChange={(event) => setName(event.target.value)}
                                        placeholder="Counter"
                                        aria-label="Name"
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="target-transport">Transport</Label>
                                    <Select value={transport} onValueChange={(value) => setTransport(value as PrintTargetRow['transport'])}>
                                        <SelectTrigger id="target-transport" aria-label="Transport">
                                            <SelectValue placeholder="Choose a transport" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="server_socket">The server writes the bytes</SelectItem>
                                            <SelectItem value="lan_agent">The LAN agent writes the bytes</SelectItem>
                                            <SelectItem value="browser">The browser is the destination</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="target-endpoint">Endpoint</Label>
                                    <Input
                                        id="target-endpoint"
                                        value={endpoint}
                                        onChange={(event) => setEndpoint(event.target.value)}
                                        placeholder="tcp://192.168.1.50:9100"
                                        aria-label="Endpoint"
                                        disabled={transport === 'browser'}
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="target-profile">Profile</Label>
                                    <Select value={profile} onValueChange={setProfile}>
                                        <SelectTrigger id="target-profile" aria-label="Profile">
                                            <SelectValue placeholder="Choose a profile" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {profiles.map((item) => (
                                                <SelectItem key={item.id} value={item.id}>
                                                    {item.id} — {item.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="target-charset">Code page</Label>
                                    <Select value={charset} onValueChange={setCharset}>
                                        <SelectTrigger id="target-charset" aria-label="Code page">
                                            <SelectValue placeholder="Choose a code page" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {codePages.map((page) => (
                                                <SelectItem key={page} value={page}>
                                                    {page}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="target-width">Characters per line</Label>
                                    <Input
                                        id="target-width"
                                        type="number"
                                        min={1}
                                        value={charPerLine}
                                        onChange={(event) => setCharPerLine(event.target.value)}
                                        aria-label="Characters per line"
                                    />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="target-qr-limit">QR payload limit (bytes)</Label>
                                    <Input
                                        id="target-qr-limit"
                                        type="number"
                                        min={1}
                                        value={maxQrPayloadBytes}
                                        onChange={(event) => setMaxQrPayloadBytes(event.target.value)}
                                        aria-label="QR payload limit"
                                    />
                                </div>

                                <div className="flex items-end gap-4 pb-2">
                                    <div className="flex items-center gap-2">
                                        <Checkbox id="target-qr" checked={supportsQr} onCheckedChange={(value) => setSupportsQr(value === true)} />
                                        <Label htmlFor="target-qr">Prints QR codes</Label>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <Checkbox id="target-active" checked={isActive} onCheckedChange={(value) => setIsActive(value === true)} />
                                        <Label htmlFor="target-active">Active</Label>
                                    </div>
                                </div>

                                <div className="flex items-end gap-2">
                                    <Button onClick={save} disabled={saving}>
                                        <Plus />
                                        {editing === null ? 'Add destination' : 'Save changes'}
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

                {agents.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Print agents
                                <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                            </CardTitle>

                            <CardDescription className="mt-1.5">
                                The agent declares the printers it can reach in its heartbeat. A destination that its inventory does not cover is
                                a warning, not a rejection: a printer may be off at that moment.
                            </CardDescription>
                        </CardHeader>

                        <CardContent>
                            {agents.map((agent) => (
                                <div key={agent.id} className="text-sm">
                                    <div className="font-medium">
                                        {agent.name}
                                        {agent.revoked ? ' — revoked' : ''}
                                        {!agent.revoked && !agent.is_active ? ' — inactive' : ''}
                                    </div>

                                    {agent.uncovered_targets.length > 0 ? (
                                        <ul className="text-muted-foreground list-disc pl-5">
                                            {agent.uncovered_targets.map((target) => (
                                                <li key={target.target_id}>
                                                    {target.name} ({target.printer_code}) is not in the agent inventory.
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="text-muted-foreground">
                                            Every LAN destination of this store is in the agent inventory.
                                        </p>
                                    )}
                                </div>
                            ))}

                            {can('printing.read') && (
                                <p className="mt-3 text-sm">
                                    <a href="/impresion/agentes" className="underline">
                                        Manage the print agents
                                    </a>
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {targets.length} {targets.length === 1 ? 'destination' : 'destinations'}
                            <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                        </CardTitle>
                    </CardHeader>

                    <CardContent>
                        {targets.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no print destination in this store yet. Add one: without a destination the till does not print anything.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-left font-medium">Printer</th>
                                            <th className="px-2 py-2 text-left font-medium">Transport</th>
                                            <th className="px-2 py-2 text-left font-medium">Endpoint</th>
                                            <th className="px-2 py-2 text-right font-medium">Width</th>
                                            <th className="px-2 py-2 text-left font-medium">Code page</th>
                                            <th className="px-2 py-2 text-left font-medium">QR</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {targets.map((target) => (
                                            <tr key={target.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">{target.name}</td>
                                                <td className="px-2 py-2">{target.printer_code}</td>
                                                <td className="px-2 py-2">{target.transport}</td>
                                                <td className="px-2 py-2 font-mono text-xs">{target.endpoint ?? '—'}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">{target.char_per_line ?? '—'}</td>
                                                <td className="px-2 py-2">{target.charset}</td>
                                                <td className="px-2 py-2">{target.supports_qr ? `${target.max_qr_payload_bytes} bytes` : '—'}</td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={target.is_active ? 'default' : 'secondary'}>
                                                        {target.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                </td>

                                                <td className="px-2 py-2">
                                                    <div className="flex justify-end gap-2">
                                                        {can('printing.test') && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={testing === target.id}
                                                                onClick={() => testConnection(target)}
                                                            >
                                                                <Plug />
                                                                Test
                                                            </Button>
                                                        )}

                                                        {can('printing.update') && (
                                                            <Button variant="outline" size="sm" onClick={() => edit(target)}>
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
        </AppLayout>
    );
}
