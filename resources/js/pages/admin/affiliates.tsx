import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    name: string;
    phone: string | null;
    code: string;
    percent: number;
    active: boolean;
    clicks: number;
    linked: boolean;
    orders: number;
    owed: number;
    paid: number;
    link: string;
    note: string | null;
}

interface Chosen extends Row {
    days: number;
    earnings: { id: number; kind: 'earn' | 'takeback'; status: 'owed' | 'paid' | 'void'; base: number; percent: number; amount: number; at: string }[];
    payouts: { id: number; amount: number; method: string; note: string | null; at: string }[];
}

interface Props {
    affiliates: Row[];
    chosen: Chosen | null;
    days: number;
    totals: { owed: number; paid: number; clicks: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Affiliates', href: '/admin/affiliates' }];
const METHODS = ['cash', 'bkash', 'nagad', 'rocket', 'bank'] as const;

/** Affiliates: people outside the business who send buyers and earn a share of what those buyers spend. */
export default function Affiliates({ affiliates, chosen, days, totals }: Props) {
    const { t, num, money, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ name: '', phone: '', percent: '2', code: '', note: '' });
    const [method, setMethod] = useState<string>('bkash');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const methodLabel: Record<string, string> = { cash: t('Cash'), bkash: 'bKash', nagad: 'Nagad', rocket: 'Rocket', bank: t('Bank') };
    const kind = (k: 'earn' | 'takeback') => (k === 'earn' ? t('An order') : t('Taken back'));
    const state = (s: 'owed' | 'paid' | 'void') => ({ owed: t('Owed'), paid: t('Paid'), void: t('Cancelled') })[s];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Affiliates')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <Flash />
                <header className="space-y-1">
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Affiliates')}</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('An affiliate sends buyers to the site with their own link and earns a share of what those buyers spend, once the order is complete. The share is on the goods, not the delivery. A visitor keeps the link for {n} days.', { n: num(days) })}
                    </p>
                </header>

                <section className="rounded-xl border bg-card p-4">
                    <p className="tabular font-display text-4xl font-extrabold">{money(totals.owed)}</p>
                    <p className="text-sm text-muted-foreground">
                        {t('owed to affiliates now')} · {t('{n} paid so far', { n: money(totals.paid) })} · {t('{n} visits from links', { n: num(totals.clicks) })}
                    </p>
                </section>

                <section className="grid gap-6 lg:grid-cols-[1fr_20rem]">
                    <div className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('Your affiliates')}</h2>
                        {affiliates.length === 0 ? (
                            <p className="rounded-xl border p-6 text-sm text-muted-foreground">{t('No affiliate yet. Add the first one on the right.')}</p>
                        ) : (
                            <ul className="divide-y rounded-xl border text-sm">
                                {affiliates.map((a) => (
                                    <li key={a.id}>
                                        <Link
                                            href={`/admin/affiliates?affiliate=${a.id}`}
                                            aria-current={chosen?.id === a.id ? 'true' : undefined}
                                            className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3 hover:bg-muted focus-visible:bg-muted focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <span className="min-w-36 flex-1">
                                                <span className="block font-semibold">{a.name}</span>
                                                <span className="tabular text-xs text-muted-foreground">
                                                    {a.code} · {num(a.percent)}%{!a.active && ` · ${t('Switched off')}`}
                                                </span>
                                            </span>
                                            <span className="tabular text-xs text-muted-foreground">{t('{n} orders', { n: num(a.orders) })}</span>
                                            <span className="tabular font-semibold">{money(a.owed)}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <form
                        className="space-y-3 self-start rounded-xl border bg-card p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.post('/admin/affiliates', f, { onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setF({ name: '', phone: '', percent: '2', code: '', note: '' }) });
                        }}
                    >
                        <h2 className="font-display text-lg font-bold">{t('Add an affiliate')}</h2>
                        <label className="block text-sm font-medium">
                            {t('Name')}
                            <Input className="mt-1" value={f.name} maxLength={120} onChange={(e) => setF({ ...f, name: e.target.value })} />
                        </label>
                        <label className="block text-sm font-medium">
                            {t('Mobile number (optional)')}
                            <Input className="mt-1" inputMode="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
                        </label>
                        <p className="text-xs text-muted-foreground">{t('If they already have an account with this number, they can see their own link and earnings under their account.')}</p>
                        <div className="grid grid-cols-2 gap-3">
                            <label className="block text-sm font-medium">
                                {t('Share (%)')}
                                <Input className="mt-1" inputMode="decimal" value={f.percent} onChange={(e) => setF({ ...f, percent: e.target.value })} />
                            </label>
                            <label className="block text-sm font-medium">
                                {t('Code (optional)')}
                                <Input className="mt-1 uppercase" maxLength={24} value={f.code} onChange={(e) => setF({ ...f, code: e.target.value.replace(/[^A-Za-z0-9]/g, '') })} />
                            </label>
                        </div>
                        <InputError message={errors.affiliate} />
                        <Button type="submit" disabled={busy || f.name.trim().length < 2} aria-busy={busy}>
                            {busy ? t('Saving…') : t('Add the affiliate')}
                        </Button>
                    </form>
                </section>

                {chosen && (
                    <section className="space-y-4 rounded-xl border bg-card p-4" aria-labelledby="chosen-h">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 id="chosen-h" className="font-display text-2xl font-bold">
                                {chosen.name}
                            </h2>
                            <span className="tabular font-display text-3xl font-extrabold">{money(chosen.owed)}</span>
                        </div>
                        <p className="break-all rounded-md bg-muted/40 px-3 py-2 text-sm select-all">{chosen.link}</p>
                        <p className="text-xs text-muted-foreground">
                            {t('{n} visits', { n: num(chosen.clicks) })} · {t('{n} orders', { n: num(chosen.orders) })} · {t('{n} paid so far', { n: money(chosen.paid) })}
                            {chosen.note && ` · ${chosen.note}`}
                        </p>

                        <div className="flex flex-wrap items-end gap-3">
                            <label className="text-sm font-medium">
                                {t('Share (%)')}
                                <Input
                                    key={`${chosen.id}-${chosen.percent}`}
                                    className="mt-1 w-24"
                                    inputMode="decimal"
                                    defaultValue={chosen.percent}
                                    onBlur={(e) => Number(e.target.value) > 0 && Number(e.target.value) !== chosen.percent && router.put(`/admin/affiliates/${chosen.id}`, { percent: e.target.value }, { preserveScroll: true })}
                                />
                            </label>
                            <Button size="sm" variant="outline" onClick={() => router.put(`/admin/affiliates/${chosen.id}`, { active: !chosen.active }, { preserveScroll: true })}>
                                {chosen.active ? t('Switch off') : t('Switch on')}
                            </Button>
                            <span className="text-xs text-muted-foreground">{t('A new share counts for new orders; what is earned keeps its share.')}</span>
                        </div>
                        <InputError message={errors.affiliate} />

                        {chosen.owed > 0 && (
                            <div className="space-y-2 rounded-lg border p-3">
                                <p className="text-sm font-medium">{t('Pay {amount} now', { amount: money(chosen.owed) })}</p>
                                <ToggleGroup type="single" value={method} onValueChange={(v) => v && setMethod(v)} variant="outline" size="sm" className="flex-wrap justify-start" aria-label={t('How it was paid')}>
                                    {METHODS.map((m) => (
                                        <ToggleGroupItem key={m} value={m}>
                                            {methodLabel[m]}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                                <Input aria-label={t('Note')} placeholder={t('Note (a transaction number)')} maxLength={200} value={note} onChange={(e) => setNote(e.target.value)} />
                                <InputError message={errors.pay} />
                                <Button disabled={busy} onClick={() => window.confirm(t('Write down that {amount} was paid?', { amount: money(chosen.owed) })) && router.post(`/admin/affiliates/${chosen.id}/pay`, { method, note }, { preserveScroll: true, onSuccess: () => setNote('') })}>
                                    {t('It was paid')}
                                </Button>
                            </div>
                        )}

                        {chosen.earnings.length > 0 && (
                            <div className="space-y-1">
                                <h3 className="font-display text-lg font-bold">{t('What orders earned')}</h3>
                                <ul className="divide-y rounded-lg border text-sm">
                                    {chosen.earnings.map((e) => (
                                        <li key={e.id} className="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                                            <span>
                                                {kind(e.kind)} · {money(e.base)} {t('at {n}%', { n: num(e.percent) })}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {state(e.status)} · {dateTime(e.at)}
                                            </span>
                                            <span className="tabular font-semibold">{money(e.amount)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {chosen.payouts.length > 0 && (
                            <div className="space-y-1">
                                <h3 className="font-display text-lg font-bold">{t('Paid out')}</h3>
                                <ul className="divide-y rounded-lg border text-sm">
                                    {chosen.payouts.map((p) => (
                                        <li key={p.id} className="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                                            <span>
                                                {methodLabel[p.method] ?? p.method}
                                                {p.note && ` · ${p.note}`}
                                            </span>
                                            <span className="text-xs text-muted-foreground">{dateTime(p.at)}</span>
                                            <span className="tabular font-semibold">{money(p.amount)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
