import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { printJobLabel, printJobVariant } from '@/lib/print';

/**
 * A print job of MY OWN tickets, as the backend hands it over.
 *
 * The `status` is the one the state machine writes (`queued`, `claimed`,
 * `printing`, `printed`, `failed`), read as it is: the screen does not
 * invent a state of its own.
 */
export interface OwnPrintJobRow {
    id: number;
    status: string;
    target_id: number;
    target_name: string;
    last_error: string | null;
    created_at: string | null;
}

export interface OwnPrintJobsProps {
    jobs: OwnPrintJobRow[];
    store: {
        id: number;
        name: string;
        code_name: string | null;
    };
}

/**
 * MY OWN tickets: the print jobs of the store that I queued, and the ones
 * without an actor, which are the ones of the system.
 *
 * The jobs of another cashier are not here, and neither are the jobs of
 * another store: they do not exist for the person asking.
 *
 * AND THE SCREEN IS A READING. It shows the state of the paper and, when the
 * job failed, WHY. It does not print anything again, and it does not send a
 * failed job back to the queue: the data cannot tell a job whose paper never
 * came out from a job whose paper came out and whose report failed, and a
 * button that can duplicate a ticket is worse than no button at all. That
 * decision belongs to whoever owns the whole queue, with the whole queue in
 * front of them.
 */
export default function OwnPrintJobs({ jobs, store }: OwnPrintJobsProps) {
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'My tickets',
            href: '/impresion/mis-trabajos',
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My tickets" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">My tickets of {store.name}</CardTitle>
                        <CardDescription className="mt-1.5">
                            The print jobs you queued, and the ones of the system. This is where you see whether your paper came
                            out, and why it did not when it did not.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        {jobs.length === 0 ? (
                            <p className="text-muted-foreground text-sm">You have no print job in this store yet.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="px-2 py-2 text-left font-medium">Job</th>
                                            <th className="px-2 py-2 text-left font-medium">Status</th>
                                            <th className="px-2 py-2 text-left font-medium">Destination</th>
                                            <th className="px-2 py-2 text-left font-medium">Date</th>
                                            <th className="px-2 py-2 text-left font-medium">Failure</th>
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
                                                <td className="px-2 py-2 whitespace-nowrap">{job.created_at ?? '—'}</td>
                                                <td className="px-2 py-2">{job.last_error ?? '—'}</td>
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
