import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Item {
    id: number;
    shop: string | null;
    phone: string | null;
    note: string | null;
    name: string | null;
    seen: boolean;
    minutes: number;
    late: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Prescriptions', href: '/admin/prescriptions' }];

export default function Prescriptions({ unseen, items }: { unseen: number; items: Item[] }) {
    const { t, num } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Prescriptions')} />
            <div className="mx-auto w-full max-w-4xl space-y-4 p-4">
                <div className="flex items-baseline justify-between gap-3">
                    <h1 className="font-display text-2xl font-extrabold">{t('Prescriptions')}</h1>
                    <p className={`text-sm font-semibold ${unseen > 0 ? 'text-amber-700' : 'text-muted-foreground'}`}>{t('{n} not seen yet', { n: num(unseen) })}</p>
                </div>
                {items.length === 0 ? (
                    <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No prescriptions yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border">
                        {items.map((p) => (
                            <li key={p.id} className={`flex flex-wrap items-center gap-3 px-4 py-3 ${p.late ? 'bg-amber-500/10' : ''}`}>
                                <div className="min-w-48 flex-1">
                                    <p className="font-medium">{p.shop}</p>
                                    <p className="text-sm text-muted-foreground">{[p.phone, p.note].filter(Boolean).join(' · ')}</p>
                                </div>
                                <span className="tabular text-sm text-muted-foreground">{t('{n} min open', { n: num(p.minutes) })}</span>
                                <Button asChild size="sm" variant="outline">
                                    <a href={`/admin/prescriptions/${p.id}/file`} target="_blank" rel="noreferrer">
                                        {t('Open')}
                                    </a>
                                </Button>
                                {!p.seen && (
                                    <Button size="sm" onClick={() => router.post(`/admin/prescriptions/${p.id}/seen`, {}, { preserveScroll: true })}>
                                        {t('Mark seen')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
