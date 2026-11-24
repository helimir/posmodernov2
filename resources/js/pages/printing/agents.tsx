import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { Ban, RotateCcw } from 'lucide-react';
import { useState } from 'react';

/**
 * A print agent of the store, as the backend hands it over.
 *
 * The agent reports the printers it can reach in its heartbeat. A
 * destination that the inventory does not cover is NOT rejected: a printer
 * may be off at that moment. The screen says so, because a silent mismatch
 * is the wrong kind of mismatch.
 */
export interface PrintAgentRow {
    id: number;
    name: string;
    instance_id: string | null;
    /**
     * The SWITCH: an inactive agent can be turned on again.
     */
    is_active: boolean;
    /**
     * The REVOCATION: it is definitive, and only an operator lifts it.
     */
    revoked: boolean;
    revoked_at: string | null;
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

export interface PrintAgentsIndexProps {
    agents: PrintAgentRow[];
    store: {
        id: number;
        name: string;
        code_name: string | null;
    };
}

/**
 * The AGENTS of the store: the terminals that write the tickets of the LAN
 * destinations.
 *
 * The REVOCATION is definitive: once an agent is revoked it does not talk
 * any more, not even with a correct token, and it cannot be registered
 * again with its `instance_id` until an operator lifts the revocation. And
 * lifting the revocation does NOT bring the token back: the agent registers
 * again and gets a new one.
 *
 * That is why the screen asks before revoking: the token of a compromised
 * agent must not come back to life by accident.
 */
export default function PrintAgentsIndex({ agents, store }: PrintAgentsIndexProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [working, setWorking] = useState<number | null>(null);
    const [confirming, setConfirming] = useState<PrintAgentRow | null>(null);

    /**
     * The REVOCATION: the agent stops talking, right now.
     */
    const revoke = async (agent: PrintAgentRow) => {
        setError(null);
        setWorking(agent.id);

        try {
            await postJson(`/api/print-agents/${agent.id}/revoke`, {});

            setConfirming(null);

            router.reload({ only: ['agents'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The print agent could not be revoked.');
        } finally {
            setWorking(null);
        }
    };

    /**
     * The LIFTING of the revocation: the agent can register again, with a
     * NEW token. The old one stays dead.
     */
    const restore = async (agent: PrintAgentRow) => {
        setError(null);
        setWorking(agent.id);

        try {
            await postJson(`/api/print-agents/${agent.id}/restore`, {});

            router.reload({ only: ['agents'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The revocation could not be lifted.');
        } finally {
            setWorking(null);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Print agents',
            href: '/impresion/agentes',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Print agents" />

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
                        <CardTitle className="text-base">
                            {agents.length} {agents.length === 1 ? 'print agent' : 'print agents'}
                            <span className="text-muted-foreground ml-2 text-sm font-normal">in {store.name}</span>
                        </CardTitle>
                        <CardDescription className="mt-1.5">
                            A print agent is the terminal that writes the tickets of the LAN destinations. It reports the printers it can reach
                            in its heartbeat, and a destination that its inventory does not cover is a warning, not a rejection: a printer may be
                            off at that moment.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        {agents.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                There is no print agent in this store yet. An agent registers itself with the store registration secret.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Name</th>
                                            <th className="px-2 py-2 text-left font-medium">Installation</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-left font-medium">Last heartbeat</th>
                                            <th className="px-2 py-2 text-left font-medium">Inventory</th>
                                            <th className="px-2 py-2 text-left font-medium">Destinations</th>
                                            {can('printing.update') && <th className="px-2 py-2 text-right font-medium">Actions</th>}
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {agents.map((agent) => (
                                            <tr key={agent.id} className="border-b last:border-0">
                                                <td className="px-2 py-2 font-medium">{agent.name}</td>
                                                <td className="px-2 py-2 font-mono text-xs">{agent.instance_id ?? '—'}</td>
                                                <td className="px-2 py-2">
                                                    {agent.revoked ? (
                                                        <div className="flex flex-col gap-1">
                                                            <Badge variant="destructive">Revoked</Badge>
                                                            {agent.revoked_at !== null && (
                                                                <span className="text-muted-foreground text-xs">{agent.revoked_at}</span>
                                                            )}
                                                        </div>
                                                    ) : agent.is_active ? (
                                                        <Badge variant="default">Active</Badge>
                                                    ) : (
                                                        <Badge variant="secondary">Inactive</Badge>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2">{agent.last_heartbeat_at ?? 'Never'}</td>
                                                <td className="px-2 py-2">
                                                    {agent.printers.length === 0 ? (
                                                        <span className="text-muted-foreground">Not declared</span>
                                                    ) : (
                                                        <ul>
                                                            {agent.printers.map((printer) => (
                                                                <li key={printer.code}>
                                                                    {printer.code}
                                                                    {printer.name ? ` — ${printer.name}` : ''}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </td>
                                                <td className="px-2 py-2">
                                                    {agent.uncovered_targets.length === 0 ? (
                                                        <span className="text-muted-foreground">Every LAN destination is in the inventory</span>
                                                    ) : (
                                                        <ul className="text-muted-foreground">
                                                            {agent.uncovered_targets.map((target) => (
                                                                <li key={target.target_id}>
                                                                    {target.name} ({target.printer_code}) is not in the agent inventory
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </td>

                                                {can('printing.update') && (
                                                    <td className="px-2 py-2 text-right">
                                                        <div className="flex justify-end gap-2">
                                                            {agent.revoked ? (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={working === agent.id}
                                                                    onClick={() => {
                                                                        restore(agent);
                                                                    }}
                                                                >
                                                                    <RotateCcw />
                                                                    Lift the revocation
                                                                </Button>
                                                            ) : (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={working === agent.id}
                                                                    onClick={() => setConfirming(agent)}
                                                                >
                                                                    <Ban />
                                                                    Revoke
                                                                </Button>
                                                            )}
                                                        </div>
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

            {/*
              La REVOCACION: se pide antes de hacerla, porque es
              DEFINITIVA y porque no devuelve el token.
            */}
            <Dialog open={confirming !== null} onOpenChange={(open) => !open && setConfirming(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Revoke the print agent</DialogTitle>
                        <DialogDescription>
                            Revoking “{confirming?.name}” is definitive: the agent stops talking, even with a correct token, and it cannot
                            register again with its installation id until an operator lifts the revocation. Lifting it does NOT bring the token
                            back: the agent registers again and gets a new one.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(null)} disabled={working !== null}>
                            Cancel
                        </Button>

                        <Button
                            variant="destructive"
                            disabled={working !== null}
                            onClick={() => {
                                if (confirming !== null) {
                                    revoke(confirming);
                                }
                            }}
                        >
                            {working !== null ? 'Revoking…' : 'Revoke the agent'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
