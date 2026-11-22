import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

type Props = {
    store: { id: number; name: string; code_name: string | null };
    difference_reason_threshold: string | null;
    final_consumer_id_threshold: string | null;
    global_difference_reason_threshold: string;
    effective_difference_reason_threshold: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Store settings',
        href: '/settings/store',
    },
];

export default function StoreSettings({
    store,
    difference_reason_threshold,
    final_consumer_id_threshold,
    global_difference_reason_threshold,
    effective_difference_reason_threshold,
}: Props) {
    const { data, setData, put, errors, processing, recentlySuccessful } = useForm({
        difference_reason_threshold: difference_reason_threshold ?? '',
        final_consumer_id_threshold: final_consumer_id_threshold ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('store.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Store settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Store settings"
                        description={`Settings of the store ${store.name}`}
                    />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="difference_reason_threshold">Cash difference threshold</Label>

                            <Input
                                id="difference_reason_threshold"
                                className="mt-1 block w-full"
                                value={data.difference_reason_threshold}
                                onChange={(e) => setData('difference_reason_threshold', e.target.value)}
                                placeholder={global_difference_reason_threshold}
                                inputMode="decimal"
                            />

                            <p className="text-sm text-neutral-600">
                                A cash difference above this amount needs a reason to close the cash day. Leave it empty to use
                                the global threshold ({global_difference_reason_threshold}). The threshold that applies to this
                                store right now is {effective_difference_reason_threshold}.
                            </p>

                            <InputError className="mt-2" message={errors.difference_reason_threshold} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="final_consumer_id_threshold">Final consumer document threshold</Label>

                            <Input
                                id="final_consumer_id_threshold"
                                className="mt-1 block w-full"
                                value={data.final_consumer_id_threshold}
                                onChange={(e) => setData('final_consumer_id_threshold', e.target.value)}
                                placeholder="Never"
                                inputMode="decimal"
                            />

                            <p className="text-sm text-neutral-600">
                                A sale without a customer above this amount cannot be charged without the document of the final
                                consumer. Leave it empty to never ask for it.
                            </p>

                            <InputError className="mt-2" message={errors.final_consumer_id_threshold} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>

                            {recentlySuccessful && <p className="text-sm text-neutral-600">Saved</p>}
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
