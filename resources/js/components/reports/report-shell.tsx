import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { usePermissions } from '@/lib/permissions';
import { type PosCurrency } from '@/lib/pos';
import { useCallback, useState } from 'react';

/**
 * The PERIOD of a report, as the backend hands it over.
 *
 * The two dates are DATA: they travel in the request, and the report never
 * invents the day.
 */
export interface ReportPeriod {
    from: string;
    to: string;
}

/**
 * The STORE the report is read in: the active one, with its name.
 */
export interface ReportStore {
    id: number;
    name: string;
    code_name: string | null;
}

/**
 * The SCOPE of the report: the stores of the ROLE of whoever asks.
 *
 * `unlimited` means the role has no scope: it reaches every store.
 */
export interface ReportScope {
    unlimited: boolean;
    stores: number;
}

/**
 * The props EVERY report screen shares.
 */
export interface ReportSharedProps {
    period: ReportPeriod;
    store: ReportStore;
    currency: PosCurrency;
    scope: ReportScope;
}

interface ReportShellProps extends ReportSharedProps {
    /** The title of the report, as it is seen. */
    title: string;
    /** The path of the screen: the period filter navigates to it. */
    path: string;
    /** The KEY of the exportable report: the closed set of the export route. */
    exportKey: string;
    /** The crumbs of the screen. */
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}

/**
 * The frame of EVERY report screen: the title, the period filter, the export
 * button and the print button.
 *
 * The "PDF" of a report is PRINTED FROM THE BROWSER: there is no PDF library
 * in this repository, and the Z and the receipt already print themselves the
 * same way (HTML with `@media print`).
 */
export default function ReportShell({ title, path, exportKey, breadcrumbs = [], period, store, scope, children }: ReportShellProps) {
    const { can } = usePermissions();

    const [desde, setDesde] = useState(period.from);
    const [hasta, setHasta] = useState(period.to);

    /**
     * The PERIOD filter: the two dates are data, and asking for another
     * period navigates to the same screen with them.
     */
    const applyPeriod = useCallback(() => {
        router.get(path, { from: desde, to: hasta }, { preserveState: true, preserveScroll: true });
    }, [desde, hasta, path]);

    /**
     * The CSV: the link carries the same period as the screen, so the file
     * and the screen always say the same thing.
     */
    const exportUrl = `/reportes/exportar/${exportKey}?from=${period.from}&to=${period.to}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <CardTitle className="text-base">{title}</CardTitle>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    {store.name} &middot; {period.from} &rarr; {period.to} &middot;{' '}
                                    {scope.unlimited ? `every store (${scope.stores})` : `${scope.stores} store${scope.stores === 1 ? '' : 's'}`}
                                </p>
                            </div>

                            <div className="no-print flex flex-wrap items-end gap-2">
                                <label className="flex flex-col gap-1 text-xs">
                                    <span className="text-muted-foreground">From</span>
                                    <input
                                        type="date"
                                        value={desde}
                                        onChange={(evento) => setDesde(evento.target.value)}
                                        className="border-input rounded-md border px-2 py-1.5 text-sm"
                                    />
                                </label>

                                <label className="flex flex-col gap-1 text-xs">
                                    <span className="text-muted-foreground">To</span>
                                    <input
                                        type="date"
                                        value={hasta}
                                        onChange={(evento) => setHasta(evento.target.value)}
                                        className="border-input rounded-md border px-2 py-1.5 text-sm"
                                    />
                                </label>

                                <Button size="sm" onClick={applyPeriod}>
                                    Apply
                                </Button>

                                <Button size="sm" variant="outline" onClick={() => window.print()}>
                                    Print
                                </Button>

                                {can('report.export') && (
                                    <Button size="sm" variant="outline" asChild={true}>
                                        <a href={exportUrl}>Export CSV</a>
                                    </Button>
                                )}
                            </div>
                        </div>
                    </CardHeader>

                    <CardContent>{children}</CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
