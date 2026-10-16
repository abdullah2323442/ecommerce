import Flash from '@/components/admin/flash';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    number: string;
    subject: string;
    name: string;
    phone: string;
    topic: string;
    status: string;
    at: string;
    assignee: string | null;
    late: boolean;
}

interface Props {
    tickets: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    status: 'open' | 'answered' | 'closed';
    q: string;
    mine: boolean;
    counts: Record<string, number>;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Support tickets', href: '/admin/support' }];
const TABS: [string, string][] = [
    ['open', 'Waiting for us'],
    ['answered', 'We answered'],
    ['closed', 'Closed'],
];

export default function SupportIndex({ tickets, status, q, mine, counts }: Props) {
    const { t, num, dateTime } = useI18n();
    const [search, setSearch] = useState(q);
    const go = (extra: Record<string, string | boolean>) => router.get('/admin/support', { status, q: search, mine: mine || undefined, ...extra }, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Support tickets')} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4">
                <Flash />
                <h1 className="text-2xl font-bold">{t('Support tickets')}</h1>
                <div className="flex flex-wrap items-center gap-2">
                    {TABS.map(([k, label]) => (
                        <button key={k} type="button" onClick={() => go({ status: k })} className={`rounded-full border px-3 py-1 text-sm ${status === k ? 'bg-foreground text-background' : ''}`}>
                            {t(label)} ({num(counts[k] ?? 0)})
                        </button>
                    ))}
                    <label className="ms-2 flex items-center gap-1 text-sm">
                        <input type="checkbox" checked={mine} onChange={(e) => go({ mine: e.target.checked || (undefined as unknown as boolean) })} />
                        {t('Mine only')}
                    </label>
                    <form
                        className="ms-auto"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ q: search });
                        }}
                    >
                        <Input aria-label={t('Search')} placeholder={t('Name, phone or title')} className="h-9 w-56" value={search} onChange={(e) => setSearch(e.target.value)} />
                    </form>
                </div>

                {tickets.data.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">{t('Nothing here.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border bg-card text-sm">
                        {tickets.data.map((x) => (
                            <li key={x.id}>
                                <Link href={`/admin/support/${x.id}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2 hover:bg-muted/50">
                                    <span className="w-16 text-muted-foreground">{x.number}</span>
                                    <span className="min-w-48 flex-1 font-medium">{x.subject}</span>
                                    <span className="text-muted-foreground">
                                        {x.name} · {x.phone}
                                    </span>
                                    {x.late && <span className="rounded bg-red-100 px-1.5 text-xs font-semibold text-red-900">{t('Late')}</span>}
                                    {x.assignee && <span className="text-xs text-muted-foreground">{x.assignee}</span>}
                                    <span className="text-xs text-muted-foreground">{dateTime(x.at)}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <nav className="flex flex-wrap gap-1">
                    {tickets.links.filter((l) => l.url).map((l, i) => (
                        <Link key={i} href={l.url as string} preserveState className={`rounded border px-2 py-1 text-xs ${l.active ? 'bg-foreground text-background' : ''}`} dangerouslySetInnerHTML={{ __html: l.label }} />
                    ))}
                </nav>
            </div>
        </AppLayout>
    );
}
