import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDownToLine, ArrowUpFromLine, CircleAlert, CircleCheck, Clock } from 'lucide-react';

interface EventRow {
    id: number;
    direction: 'in' | 'out';
    kind: 'sale' | 'count' | 'online_sale' | 'short_list';
    ref: string;
    status: 'pending' | 'ok' | 'partial' | 'failed';
    attempts: number;
    summary: string | null;
    error: string | null;
    at: string;
}

interface Props {
    link: { on: boolean; live: boolean; has_token: boolean; has_address: boolean; pushes: boolean; pushes_missed: boolean };
    base: string;
    counts: { ok: number; partial: number; failed: number; pending: number };
    events: EventRow[];
    token: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'PharmaTrack', href: '/admin/pharmatrack' }];

/** PharmaTrack: one stock for the pharmacy. Is the link on, what passed between the two programs, what failed, and the password PharmaTrack needs. */
export default function PharmaTrack({ link, base, counts, events, token }: Props) {
    const { t, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const working = link.on && link.live;
    const kind = (e: EventRow) => ({ sale: t('A sale made in PharmaTrack'), count: t('A stock count from PharmaTrack'), online_sale: t('An online order told to PharmaTrack'), short_list: t('A medicine added to the PharmaTrack short list') })[e.kind];
    const state = (s: EventRow['status']) =>
        s === 'ok' ? (
            <span className="inline-flex items-center gap-1">
                <CircleCheck aria-hidden className="size-4" />
                {t('Done')}
            </span>
        ) : s === 'pending' ? (
            <span className="inline-flex items-center gap-1">
                <Clock aria-hidden className="size-4" />
                {t('Waiting')}
            </span>
        ) : (
            <span className="inline-flex items-center gap-1 font-semibold text-destructive">
                <CircleAlert aria-hidden className="size-4" />
                {s === 'partial' ? t('Needs a look') : t('Failed')}
            </span>
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PharmaTrack" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <Flash />
                <header className="space-y-1">
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">PharmaTrack</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('Medicines you could not supply go straight to the short list in PharmaTrack. For one shared stock, PharmaTrack can also write its shop sales here, and online orders can be told to it.')}
                    </p>
                </header>

                <section className="rounded-xl border bg-card p-4">
                    <p className="font-display text-3xl font-extrabold">{working ? t('The link is working') : link.on ? t('Switched on, but in test mode') : t('The link is off')}</p>
                    <p className="text-sm text-muted-foreground">
                        {working ? t('Medicines you could not supply are sent to the short list in PharmaTrack, and PharmaTrack can write here.') : t('Nothing moves between the two programs until it is on and set to Live.')}{' '}
                        <Link href="/admin/connections" className="font-semibold underline underline-offset-4">
                            {t('Connections')}
                        </Link>
                    </p>
                    <ul className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                        <li>{link.has_token ? t('A password for PharmaTrack is made.') : t('No password is made yet. Switch the link on in Connections first.')}</li>
                        <li>{link.has_address ? (link.pushes_missed ? t('Medicines we could not supply are added to the short list in PharmaTrack.') : t('Medicines we could not supply are not sent (switched off).')) : t('No PharmaTrack address is set, so nothing is sent to it.')}</li>
                        <li>{link.has_address && link.pushes ? t('Finished online orders are sent to PharmaTrack.') : t('Finished online orders are not sent to PharmaTrack.')}</li>
                    </ul>
                </section>

                <section className="space-y-3 rounded-xl border p-4">
                    <h2 className="font-display text-xl font-bold">{t('What PharmaTrack needs')}</h2>
                    <p className="text-sm text-muted-foreground">{t('Give these to whoever looks after PharmaTrack. Every message carries the password in an Authorization: Bearer header, over https.')}</p>
                    <dl className="space-y-2 text-sm">
                        {[
                            ['GET', `${base}/stock`, t('the stock, a page at a time')],
                            ['POST', `${base}/sales`, t('a sale made in PharmaTrack')],
                            ['POST', `${base}/counts`, t('a stock count')],
                        ].map(([method, url, what]) => (
                            <div key={url} className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <dt className="tabular w-12 font-mono text-xs font-semibold">{method}</dt>
                                <dd className="min-w-0 flex-1 break-all font-mono text-xs select-all">{url}</dd>
                                <dd className="text-muted-foreground">{what}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="text-xs text-muted-foreground">{t('The full description is in docs/pharmatrack-sync.md.')}</p>

                    {token && (
                        <div role="status" className="space-y-1 rounded-lg border-2 border-foreground p-3">
                            <p className="text-sm font-semibold">{t('The new password. Copy it now, it is not shown again:')}</p>
                            <p className="break-all font-mono text-sm select-all">{token}</p>
                        </div>
                    )}
                    <InputError message={errors.link} />
                    <Button variant="outline" disabled={!link.on} onClick={() => window.confirm(t('Make a new password? The old one stops working at once.')) && router.post('/admin/pharmatrack/token', {}, { preserveScroll: true })}>
                        {link.has_token ? t('Make a new password') : t('Make the password')}
                    </Button>
                </section>

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-display text-xl font-bold">{t('What passed between the two')}</h2>
                        {counts.failed > 0 && (
                            <Button size="sm" variant="outline" onClick={() => router.post('/admin/pharmatrack/retry', {}, { preserveScroll: true })}>
                                {t('Send the {n} that failed again', { n: num(counts.failed) })}
                            </Button>
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {t('{a} done, {b} need a look, {c} failed, {d} waiting.', { a: num(counts.ok), b: num(counts.partial), c: num(counts.failed), d: num(counts.pending) })}
                    </p>
                    {events.length === 0 ? (
                        <p className="rounded-xl border p-6 text-sm text-muted-foreground">{t('Nothing has passed yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-xl border text-sm">
                            {events.map((e) => (
                                <li key={e.id} className="space-y-1 px-3 py-2">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="inline-flex items-center gap-1 font-medium">
                                            {e.direction === 'in' ? <ArrowDownToLine aria-hidden className="size-4" /> : <ArrowUpFromLine aria-hidden className="size-4" />}
                                            {kind(e)}
                                        </span>
                                        <span>{state(e.status)}</span>
                                        <span className="text-xs text-muted-foreground">{dateTime(e.at)}</span>
                                    </div>
                                    <p className="tabular break-all text-xs text-muted-foreground">{e.ref}</p>
                                    {(e.summary || e.error) && <p className="text-xs">{e.error ?? e.summary}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
