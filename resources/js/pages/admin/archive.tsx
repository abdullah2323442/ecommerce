import Flash from '@/components/admin/flash';
import Pagination from '@/components/admin/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Source = 'activity' | 'login' | 'notice';

interface Row {
    id: number;
    source: Source;
    at: string;
    summary: string;
    details: Record<string, unknown>;
}

interface Props {
    rows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    counts: Record<Source, number>;
    months: number;
    filters: { q: string; source: Source | null; from: string | null; to: string | null };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Archive', href: '/admin/archive' }];

/** Old records kept apart so daily work stays light, and still findable by words, kind and date. */
export default function Archive({ rows, counts, months, filters }: Props) {
    const { t, num, dateTime } = useI18n();
    const [q, setQ] = useState(filters.q);
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [busy, setBusy] = useState(false);
    const total = counts.activity + counts.login + counts.notice;
    const kinds: { key: Source; label: string }[] = [
        { key: 'activity', label: t('Staff activity') },
        { key: 'login', label: t('Sign-ins') },
        { key: 'notice', label: t('Read notices') },
    ];

    const go = (source: Source | null) => router.get('/admin/archive', { q: q || undefined, source: source ?? undefined, from: from || undefined, to: to || undefined }, { preserveState: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Archive')} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4">
                <Flash />
                <header className="space-y-1">
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Archive')}</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {months > 0
                            ? t('Records older than {n} months move here at night, so daily work stays quick. Nothing is deleted, and you can still search it.', { n: num(months) })
                            : t('Archiving is switched off. Set the number of months in Settings to start.')}
                    </p>
                    <p className="text-sm text-muted-foreground">{t('Orders, memos, money and stock are never moved.')}</p>
                </header>

                <section className="rounded-xl border bg-card p-4">
                    <p className="tabular font-display text-4xl font-extrabold">{num(total)}</p>
                    <p className="text-sm text-muted-foreground">{t('records in the archive')}</p>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        {kinds.map((k) => (
                            <Button key={k.key} size="sm" variant={filters.source === k.key ? 'default' : 'outline'} aria-pressed={filters.source === k.key} onClick={() => go(filters.source === k.key ? null : k.key)}>
                                {k.label} <span className="tabular ml-1">{num(counts[k.key])}</span>
                            </Button>
                        ))}
                        <Button
                            size="sm"
                            variant="outline"
                            className="ml-auto"
                            disabled={busy || months === 0}
                            aria-busy={busy}
                            onClick={() => router.post('/admin/archive/run', {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
                        >
                            {busy ? t('Moving…') : t('Move old records now')}
                        </Button>
                    </div>
                </section>

                <form
                    className="grid gap-3 sm:grid-cols-[1fr_9rem_9rem_auto]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        go(filters.source);
                    }}
                >
                    <label className="text-sm font-medium">
                        {t('Words to look for')}
                        <Input className="mt-1" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('A name, an action, a number')} />
                    </label>
                    <label className="text-sm font-medium">
                        {t('From')}
                        <Input className="mt-1" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                    </label>
                    <label className="text-sm font-medium">
                        {t('To')}
                        <Input className="mt-1" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                    </label>
                    <Button type="submit" variant="outline" className="self-end">
                        {t('Search')}
                    </Button>
                </form>

                {rows.data.length === 0 ? (
                    <p className="rounded-xl border p-6 text-sm text-muted-foreground">
                        {total === 0 ? t('Nothing is in the archive yet. Records move here once they are older than the months you set.') : t('Nothing in the archive matches that.')}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border text-sm">
                        {rows.data.map((r) => (
                            <li key={r.id} className="space-y-1 px-3 py-2">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span className="font-medium">{kinds.find((k) => k.key === r.source)?.label}</span>
                                    <span className="text-xs text-muted-foreground">{dateTime(r.at)}</span>
                                </div>
                                <p className="break-words [overflow-wrap:anywhere]">{r.summary}</p>
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination links={rows.links} />
                <Link href="/admin/activity" className="text-sm font-semibold underline underline-offset-4">
                    {t('Back to recent activity')}
                </Link>
            </div>
        </AppLayout>
    );
}
