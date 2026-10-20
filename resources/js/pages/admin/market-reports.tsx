import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Alike {
    id: number;
    title: string;
    seller: string;
    same_photo: boolean;
    same_title: boolean;
    since: string | null;
}

interface Row {
    id: number;
    reason: string;
    status: string;
    our_note: string | null;
    at: string;
    listing_id: number;
    title: string;
    seller: string;
    listing_status: string;
    reporter: string | null;
    alike?: Alike[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Market', href: '/admin/market' },
    { title: 'Copied products', href: '/admin/market/reports' },
];

function Card({ r, decide }: { r: Row; decide?: boolean }) {
    const { t, dateTime } = useI18n();
    const [note, setNote] = useState('');
    const go = (decision: 'remove' | 'dismiss') => router.post(`/admin/market/reports/${r.id}/resolve`, { decision, note }, { preserveScroll: true });

    return (
        <li className="space-y-2 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {r.title} · {r.seller}
                </p>
                <span className="text-xs text-muted-foreground">
                    {r.reporter ?? t('Someone')} · {dateTime(r.at)}
                </span>
            </div>
            <p className="text-sm">{r.reason}</p>
            {r.our_note && <p className="text-sm text-muted-foreground">{t('Our note')}: {r.our_note}</p>}
            {decide && (
                <>
                    {r.alike && r.alike.length > 0 && (
                        <div className="rounded-md bg-warning/10 p-2 text-xs">
                            <p className="font-semibold">{t('Others that look the same')}</p>
                            <ul>
                                {r.alike.map((a) => (
                                    <li key={a.id}>
                                        {a.title} · {a.seller} · {a.same_photo && t('same picture')} {a.same_title && t('same title')} {a.since && `· ${dateTime(a.since)}`}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <Input aria-label={t('Our note')} className="h-8 w-72" placeholder={t('What we found (the reporter is told)')} value={note} onChange={(e) => setNote(e.target.value)} />
                        <Button size="sm" variant="destructive" disabled={note.trim().length < 3} onClick={() => go('remove')}>
                            {t('Take it down')}
                        </Button>
                        <Button size="sm" variant="outline" disabled={note.trim().length < 3} onClick={() => go('dismiss')}>
                            {t('Leave it up')}
                        </Button>
                    </div>
                </>
            )}
        </li>
    );
}

export default function MarketReports({ open, done }: { open: Row[]; done: Row[] }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Copied products')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Copied products')}</h1>
                    <p className="text-sm text-muted-foreground">{t('People tell us when a product copies theirs. We look, and take it down or leave it up.')}</p>
                </div>
                <Flash />
                <InputError message={errors.report} />
                <section className="space-y-2">
                    <h2 className="font-medium">{t('To look at')}</h2>
                    {open.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No reports.')}</p> : <ul className="divide-y rounded-md border bg-card">{open.map((r) => <Card key={r.id} r={r} decide />)}</ul>}
                </section>
                <section className="space-y-2">
                    <h2 className="font-medium">{t('Settled')}</h2>
                    {done.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('Nothing settled yet.')}</p> : <ul className="divide-y rounded-md border bg-card">{done.map((r) => <Card key={r.id} r={r} />)}</ul>}
                </section>
            </div>
        </AppLayout>
    );
}
