import Pagination from '@/components/admin/pagination';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Group {
    normalized: string;
    sector: string | null;
    photo: string | null;
    sample: string;
    total: number;
    notes: number;
    counter: number;
    last_at: string;
}

interface Props {
    groups: { data: Group[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Requests', href: '/admin/requests' }];

/** Where in the site the want came from. */
const sectorName = (s: string) => (s === 'medicine' ? 'Medicine' : s === 'food' ? 'Food' : s === 'gaming' ? 'Gaming' : s === 'search' ? 'Whole-site search' : s === 'market' ? 'Marketplace' : s.charAt(0).toUpperCase() + s.slice(1));

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
                            <li key={`${g.sector ?? ''}|${g.normalized}`} className="px-4 py-3">
                                <div className="flex items-baseline justify-between gap-4">
                                    <p className="min-w-0 truncate font-semibold">
                                        {g.sample}
                                        {g.sector && <span className="ml-2 rounded-full border px-2 py-0.5 text-xs font-normal text-muted-foreground">{t(sectorName(g.sector))}</span>}
                                    </p>
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
                                    {` · ${t('{a} from the website, {b} from the counter', { a: num(g.total - g.counter), b: num(g.counter) })}`}
                                </p>
                                {g.photo && (
                                    <a href={g.photo} target="_blank" rel="noreferrer" className="mt-2 inline-block">
                                        <img src={g.photo} alt={t('A photo sent with this request: {name}', { name: g.sample })} className="size-16 rounded-md border object-cover" loading="lazy" />
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination links={groups.links} />
            </div>
        </AppLayout>
    );
}
