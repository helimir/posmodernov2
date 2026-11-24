import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { postJson } from '@/lib/api';
import { usePermissions } from '@/lib/permissions';
import { printJobLabel, printJobVariant } from '@/lib/print';
import { Printer, RotateCcw } from 'lucide-react';
import { useState } from 'react';

/**
 * A print job of the queue, as the backend hands it over.
 *
 * The `reason` says WHY the job exists: `reprint` for a reprint. And
 * `reprint_of_job_id` points at the original: a reprint is a NEW job against
 * the SAME frozen bytes.
 */
export interface PrintJobRow {
    id: number;
    status: string;
    target_id: number;
    target_name: string;
    transport: string;
    attempts: number;
    reason: string | null;
    reprint_of_job_id: number | null;
    last_error: string | null;
    lease_expires_at: string | null;
    created_at: string | null;
    can_retry: boolean;
    can_reprint: boolean;
}

export interface PrintJobsIndexProps {
    jobs: PrintJobRow[];
    store: {
        id: number;
        name: string;
        code_name: string | null;
    };
}

/**
 * The REASON of a job, in the words of the screen.
 */
function reasonLabel(job: PrintJobRow): string | null {
    if (job.reason === 'reprint') {
        return `Reprint of job #${job.reprint_of_job_id}`;
    }

    if (job.reason !== null && job.reason !== '') {
        return job.reason;
    }

    return null;
}

/**
 * The QUEUE of print jobs of the store.
 *
 * A job that failed can be RETRIED: it goes back to the queue with one more
 * attempt, with the SAME frozen bytes. And a job that already went out can be
 * REPRINTED: the very same bytes, one more time, without issuing a new
 * fiscal document.
 */
export default function PrintJobsIndex({ jobs, store }: PrintJobsIndexProps) {
    const { can } = usePermissions();
    const [error, setError] = useState<string | null>(null);
    const [working, setWorking] = useState<number | null>(null);

    /**
     * The RETRY: the job goes back to the queue, with one more attempt.
     */
    const retry = async (job: PrintJobRow) => {
        setError(null);
        setWorking(job.id);

        try {
            await postJson(`/api/print-jobs/${job.id}/reintentar`, {});

            router.reload({ only: ['jobs'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The print job could not be retried.');
        } finally {
            setWorking(null);
        }
    };

    /**
     * The REPRINT: the SAME frozen bytes, one more time.
     */
    const reprint = async (job: PrintJobRow) => {
        setError(null);
        setWorking(job.id);

        try {
            await postJson(`/api/print-jobs/${job.id}/reimprimir`, {});

            router.reload({ only: ['jobs'] });
        } catch (fallo) {
            setError(fallo instanceof Error ? fallo.message : 'The ticket could not be reprinted.');
        } finally {
            setWorking(null);
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Print queue',
            href: '/impresion/trabajos',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Print queue" />

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
                        <CardTitle className="text-base">Print queue of {store.name}</CardTitle>
                        <CardDescription className="mt-1.5">
                            A failed job can be retried: it goes back to the queue with one more attempt and with the same frozen bytes. A job that
                            already went out can be reprinted: the very same bytes, one more time, without issuing a new fiscal document.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        {jobs.length === 0 ? (
                            <p className="text-muted-foreground text-sm">There is no print job in this store yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Job</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-left font-medium">Destination</th>
                                            <th className="px-2 py-2 text-right font-medium">Attempts</th>
                                            <th className="px-2 py-2 text-left font-medium">Reason</th>
                                            <th className="px-2 py-2 text-left font-medium">Failure</th>
                                            {(can('printing.retry') || can('printing.reprint')) && (
                                                <th className="px-2 py-2 text-right font-medium">Actions</th>
                                            )}
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {jobs.map((job) => (
                                            <tr key={job.id} className="border-b last:border-0">
                                                <td className="px-2 py-2 font-mono">#{job.id}</td>
                                                <td className="px-2 py-2">
                                                    <Badge variant={printJobVariant(job.status)}>{printJobLabel(job.status)}</Badge>
                                                </td>
                                                <td className="px-2 py-2">{job.target_name}</td>
                                                <td className="px-2 py-2 text-right tabular-nums">{job.attempts}</td>
                                                <td className="px-2 py-2">{reasonLabel(job) ?? '—'}</td>
                                                <td className="px-2 py-2">{job.last_error ?? '—'}</td>

                                                {(can('printing.retry') || can('printing.reprint')) && (
                                                    <td className="px-2 py-2 text-right">
                                                        <div className="flex justify-end gap-2">
                                                            {can('printing.retry') && job.can_retry && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={working === job.id}
                                                                    onClick={() => {
                                                                        retry(job);
                                                                    }}
                                                                >
                                                                    <RotateCcw />
                                                                    Retry
                                                                </Button>
                                                            )}

                                                            {can('printing.reprint') && job.can_reprint && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={working === job.id}
                                                                    onClick={() => {
                                                                        reprint(job);
                                                                    }}
                                                                >
                                                                    <Printer />
                                                                    Reprint
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
        </AppLayout>
    );
}
