import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useCallback, useState } from 'react';

/**
 * A line of the audit trail, as the backend hands it over.
 *
 * `context` is the DETAIL of what happened, already decoded: the amount that
 * was opened, the reason of a closing, the money of a payment.
 */
export interface AuditLogEntry {
    id: number;
    action: string;
    entity_type: string;
    entity_id: number | null;
    context: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
    user_name: string | null;
    store_name: string | null;
}

/**
 * The filters of the trail: WHO, WHAT, ON WHAT and WHEN.
 */
export interface AuditLogFilters {
    action: string;
    entity_type: string;
    user_id: number | null;
    from: string;
    to: string;
}

export interface AuditLogPagination {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface AuditLogProps {
    logs: AuditLogEntry[];
    filters: AuditLogFilters;
    pagination: AuditLogPagination;
    actors: { id: number; name: string }[];
    actions: string[];
    entity_types: string[];
}

/**
 * The AUDIT TRAIL: who did what, on what, and from where.
 *
 * The trail is APPEND-ONLY: there is no edit and no delete, here or
 * anywhere. A line that could be rewritten would prove nothing, so the
 * screen only reads, filters and paginates.
 */
export default function AuditLog({ logs, filters, pagination, actors, actions, entity_types }: AuditLogProps) {
    const [accion, setAccion] = useState(filters.action);
    const [entidad, setEntidad] = useState(filters.entity_type);
    const [actor, setActor] = useState(filters.user_id === null ? '' : String(filters.user_id));
    const [desde, setDesde] = useState(filters.from);
    const [hasta, setHasta] = useState(filters.to);

    /**
     * The FILTERS, applied: the server does the filtering, because the
     * trail is bigger than a page and the count has to be the real one.
     */
    const filtrar = useCallback(() => {
        router.get(
            '/contabilidad/auditoria',
            {
                action: accion || undefined,
                entity_type: entidad || undefined,
                user_id: actor || undefined,
                from: desde || undefined,
                to: hasta || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    }, [accion, entidad, actor, desde, hasta]);

    const goToPage = useCallback(
        (page: number) => {
            router.get(
                '/contabilidad/auditoria',
                {
                    action: filters.action || undefined,
                    entity_type: filters.entity_type || undefined,
                    user_id: filters.user_id ?? undefined,
                    from: filters.from || undefined,
                    to: filters.to || undefined,
                    page: page > 1 ? page : undefined,
                },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Audit trail',
            href: '/contabilidad/auditoria',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit trail" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            {pagination.total} {pagination.total === 1 ? 'entry' : 'entries'} in the audit trail
                        </CardTitle>
                    </CardHeader>

                    <CardContent className="flex flex-wrap items-end gap-3">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="action">Action</Label>
                            <Select value={accion} onValueChange={setAccion}>
                                <SelectTrigger id="action" className="w-56">
                                    <SelectValue placeholder="Every action" />
                                </SelectTrigger>
                                <SelectContent>
                                    {actions.map((valor) => (
                                        <SelectItem key={valor} value={valor}>
                                            {valor}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-col gap-1">
                            <Label htmlFor="entity_type">Entity</Label>
                            <Select value={entidad} onValueChange={setEntidad}>
                                <SelectTrigger id="entity_type" className="w-48">
                                    <SelectValue placeholder="Every entity" />
                                </SelectTrigger>
                                <SelectContent>
                                    {entity_types.map((valor) => (
                                        <SelectItem key={valor} value={valor}>
                                            {valor}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-col gap-1">
                            <Label htmlFor="user_id">Who</Label>
                            <Select value={actor} onValueChange={setActor}>
                                <SelectTrigger id="user_id" className="w-48">
                                    <SelectValue placeholder="Everyone" />
                                </SelectTrigger>
                                <SelectContent>
                                    {actors.map((valor) => (
                                        <SelectItem key={valor.id} value={String(valor.id)}>
                                            {valor.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-col gap-1">
                            <Label htmlFor="from">From</Label>
                            <Input id="from" type="date" value={desde} onChange={(e) => setDesde(e.target.value)} />
                        </div>

                        <div className="flex flex-col gap-1">
                            <Label htmlFor="to">To</Label>
                            <Input id="to" type="date" value={hasta} onChange={(e) => setHasta(e.target.value)} />
                        </div>

                        <Button onClick={filtrar}>Filter</Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent>
                        {logs.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Nothing has been written in the audit trail yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">When</th>
                                            <th className="px-2 py-2 text-left font-medium">Who</th>
                                            <th className="px-2 py-2 text-left font-medium">Action</th>
                                            <th className="px-2 py-2 text-left font-medium">Entity</th>
                                            <th className="px-2 py-2 text-left font-medium">Store</th>
                                            <th className="px-2 py-2 text-left font-medium">From</th>
                                            <th className="px-2 py-2 text-left font-medium">Detail</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {logs.map((fila) => (
                                            <tr key={fila.id} className="border-b last:border-0">
                                                <td className="px-2 py-2 whitespace-nowrap">{fila.created_at}</td>
                                                <td className="px-2 py-2">{fila.user_name ?? 'System'}</td>
                                                <td className="px-2 py-2 font-mono text-xs">{fila.action}</td>
                                                <td className="px-2 py-2 whitespace-nowrap">
                                                    {fila.entity_type}
                                                    {fila.entity_id === null ? '' : ` #${fila.entity_id}`}
                                                </td>
                                                <td className="px-2 py-2">{fila.store_name ?? '—'}</td>
                                                <td className="px-2 py-2 whitespace-nowrap">{fila.ip_address ?? '—'}</td>
                                                <td className="px-2 py-2 text-xs">
                                                    {fila.context === null ? '—' : JSON.stringify(fila.context)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-end gap-2">
                        <Button
                            variant="outline"
                            disabled={pagination.page <= 1}
                            onClick={() => goToPage(pagination.page - 1)}
                        >
                            Previous
                        </Button>
                        <span className="text-muted-foreground text-sm">
                            Page {pagination.page} of {pagination.last_page}
                        </span>
                        <Button
                            variant="outline"
                            disabled={pagination.page >= pagination.last_page}
                            onClick={() => goToPage(pagination.page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
