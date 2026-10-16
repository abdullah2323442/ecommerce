import UnresolvedList, { type Unresolved } from '@/components/admin/unresolved-list';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'End of day', href: '/admin/end-of-day' }];

/** What the counter staff see before closing: nothing about money, only what is left to do. */
export default function EndOfDay({ unresolved }: { unresolved: Unresolved[] }) {
    const { t } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('End of day')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('End of day')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Check this before you close the shop.')}</p>
                </div>
                <UnresolvedList items={unresolved} />
            </div>
        </AppLayout>
    );
}
