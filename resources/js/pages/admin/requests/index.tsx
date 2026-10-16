import Pagination from '@/components/admin/pagination';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Group {
    normalized: string;
    sample: string;
    total: number;
    notes: number;
    last_at: string;
}

interface Props {
    groups: { data: Group[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Requests', href: '/admin/requests' }];

export default function RequestsIndex({ groups }: Props) {
    const { t, num, date } = useI18n();
    const max = Math.max(1, ...groups.data.map((g) => g.total));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Requests')} />

            <div className="mx-auto w-full max-w-3xl space-y-5 p-4 md:p-6">
                <header>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('What people could not find')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Searches that found nothing, where the visitor pressed “I was looking for this”. The most asked-for come first, so you know what to stock.')}</p>
                </header>

                {groups.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">{t('No requests yet. They appear here when someone searches for a medicine you do not list and asks for it.')}</p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {groups.data.map((g) => (
                            <li key={g.normalized} className="px-4 py-3">
                                <div className="flex items-baseline justify-between gap-4">
                                    <p className="min-w-0 truncate font-semibold">{g.sample}</p>
                                    <p className="shrink-0 text-sm tabular-nums">
                                        <strong className="text-base">{num(g.total)}</strong> {g.total === 1 ? t('person') : t('people')}
                                    </p>
                                </div>
                                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted" role="presentation">
                                    <div className="h-full rounded-full bg-[var(--chart-1)]" style={{ width: `${(g.total / max) * 100}%` }} />
                                </div>
                                <p className="mt-1.5 text-xs text-muted-foreground">
                                    {t('Last asked {date}', { date: date(g.last_at) })}
                                    {g.notes > 0 ? ` · ${t('{n} with a note', { n: num(g.notes) })}` : ''}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination links={groups.links} />
            </div>
        </AppLayout>
    );
}
