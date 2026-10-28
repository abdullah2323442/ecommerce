import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Props {
    campaign: {
        id: number;
        name: string;
        body: string;
        audience_label: string;
        status: 'draft' | 'sending' | 'done' | 'cancelled';
        recipients: number;
        progress: { queued: number; sent: number; failed: number };
        at: string;
    };
    people: { phone: string; status: 'queued' | 'sent' | 'failed'; note: string | null; at: string | null }[];
    segments: number;
    service: { on: boolean; live: boolean };
    footer: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bulk texts', href: '/admin/sms' }];

/** One campaign: what it says, who it went to, how it is going, and the test and send buttons. */
export default function SmsShow({ campaign: c, people, segments, service, footer }: Props) {
    const { t, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [phone, setPhone] = useState('');
    const [busy, setBusy] = useState(false);
    const done = c.progress.sent + c.progress.failed;
    const state = { draft: t('Not sent yet'), sending: t('Going out'), done: t('Sent'), cancelled: t('Stopped') }[c.status];
    const word = { queued: t('Waiting'), sent: t('Sent'), failed: t('Not sent') };

    // While it is going out the numbers move on their own.
    useEffect(() => {
        if (c.status !== 'sending') return;
        const id = window.setInterval(() => document.visibilityState === 'visible' && router.reload({ only: ['campaign', 'people'] }), 5000);

        return () => window.clearInterval(id);
    }, [c.status]);

    return (
        <AppLayout breadcrumbs={[...breadcrumbs, { title: c.name, href: `/admin/sms/${c.id}` }]}>
            <Head title={c.name} />
            <div className="mx-auto w-full max-w-4xl space-y-5 p-4">
                <Flash />
                <header className="space-y-1">
                    <Link href="/admin/sms" className="text-sm underline underline-offset-4">
                        {t('Bulk texts')}
                    </Link>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{c.name}</h1>
                    <p className="text-sm text-muted-foreground">
                        {c.audience_label} · {dateTime(c.at)}
                    </p>
                </header>

                <section className="rounded-xl border bg-card p-4">
                    <p className="tabular font-display text-4xl font-extrabold">{num(c.recipients)}</p>
                    <p className="text-sm text-muted-foreground">
                        {t('people')} · {state}
                        {c.status !== 'draft' && ` · ${t('{a} sent, {b} not sent, {c} waiting', { a: num(c.progress.sent), b: num(c.progress.failed), c: num(c.progress.queued) })}`}
                    </p>
                    {c.status === 'sending' && (
                        <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuemin={0} aria-valuemax={c.recipients} aria-valuenow={done}>
                            <div className="h-full bg-foreground" style={{ width: `${c.recipients ? Math.round((done / c.recipients) * 100) : 0}%` }} />
                        </div>
                    )}
                </section>

                <section className="space-y-2 rounded-xl border p-4">
                    <h2 className="font-display text-lg font-bold">{t('What people read')}</h2>
                    <p className="whitespace-pre-line rounded-lg bg-muted/40 p-3 text-sm">
                        {c.body}
                        {footer}
                    </p>
                    <p className="text-xs text-muted-foreground">{t('{n} text for each person.', { n: num(segments) })}</p>
                </section>

                {c.status === 'draft' && (
                    <section className="space-y-4 rounded-xl border bg-card p-4">
                        {!service.live && (
                            <p role="status" className="text-sm text-muted-foreground">
                                {service.on ? t('The text service is in test mode: sending only writes to the log.') : t('No text service is on: sending only writes to the log.')}
                            </p>
                        )}
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post(`/admin/sms/${c.id}/test`, { phone }, { preserveScroll: true });
                            }}
                        >
                            <label className="text-sm font-medium">
                                {t('Send a test to your own phone')}
                                <Input className="mt-1 w-56" inputMode="tel" placeholder="01712345678" value={phone} onChange={(e) => setPhone(e.target.value)} />
                            </label>
                            <Button type="submit" variant="outline" disabled={phone.trim().length < 11}>
                                {t('Send the test')}
                            </Button>
                        </form>
                        <InputError message={errors.test} />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                disabled={busy}
                                aria-busy={busy}
                                onClick={() =>
                                    window.confirm(t('Send this to {n} people now?', { n: num(c.recipients) })) &&
                                    router.post(`/admin/sms/${c.id}/start`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })
                                }
                            >
                                {busy ? t('Starting…') : t('Send to {n} people', { n: num(c.recipients) })}
                            </Button>
                        </div>
                        <InputError message={errors.start} />
                    </section>
                )}

                {(c.status === 'sending' || c.status === 'draft') && (
                    <Button variant="outline" onClick={() => window.confirm(t('Stop this campaign? What has not gone out will not be sent.')) && router.post(`/admin/sms/${c.id}/cancel`, {}, { preserveScroll: true })}>
                        {c.status === 'draft' ? t('Drop this campaign') : t('Stop sending')}
                    </Button>
                )}

                {people.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-display text-lg font-bold">{t('Who')}</h2>
                        <ul className="divide-y rounded-xl border text-sm">
                            {people.map((p) => (
                                <li key={p.phone + (p.at ?? '')} className="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                                    <span className="tabular">{p.phone}</span>
                                    <span className={p.status === 'failed' ? 'font-semibold text-destructive' : ''}>
                                        {word[p.status]}
                                        {p.note && ` · ${p.note}`}
                                    </span>
                                    <span className="text-xs text-muted-foreground">{p.at ? dateTime(p.at) : ''}</span>
                                </li>
                            ))}
                        </ul>
                        {c.recipients > people.length && <p className="text-xs text-muted-foreground">{t('The first {n} are shown.', { n: num(people.length) })}</p>}
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
