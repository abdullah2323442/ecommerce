import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head } from '@inertiajs/react';

interface Props {
    affiliate: {
        name: string;
        code: string;
        percent: number;
        active: boolean;
        clicks: number;
        orders: number;
        owed: number;
        paid: number;
        link: string;
        days: number;
        earnings: { id: number; kind: 'earn' | 'takeback'; status: 'owed' | 'paid' | 'void'; base: number; percent: number; amount: number; at: string }[];
        payouts: { id: number; amount: number; method: string; note: string | null; at: string }[];
    };
}

/** An affiliate's own page: the link to share, what has come of it, and what is owed. */
export default function Affiliate({ affiliate: a }: Props) {
    const { t, num, money, dateTime } = useI18n();
    const state = (s: 'owed' | 'paid' | 'void') => ({ owed: t('Owed'), paid: t('Paid'), void: t('Cancelled') })[s];

    return (
        <PublicLayout>
            <Head title={t('Your affiliate link')} />
            <div className="mx-auto max-w-2xl space-y-6">
                <h1 className="font-display text-3xl font-extrabold">{t('Your affiliate link')}</h1>
                <section className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
                    <p className="tabular font-display text-4xl font-extrabold">{money(a.owed)}</p>
                    <p className="text-sm text-[var(--pt-muted)]">{t('owed to you now')} · {t('{n} paid so far', { n: money(a.paid) })}</p>
                    <p className="break-all rounded-md bg-[var(--pt-sage)] px-3 py-2 text-sm font-semibold select-all">{a.link}</p>
                    <p className="text-sm text-[var(--pt-muted)]">
                        {t('Share this link. When someone opens it and orders within {n} days, you earn {p}% of what they spend, once the order is complete.', { n: num(a.days), p: num(a.percent) })}
                    </p>
                    {!a.active && <p className="text-sm font-semibold">{t('Your link is switched off for now.')}</p>}
                    <p className="text-sm">{t('{n} visits', { n: num(a.clicks) })} · {t('{n} orders', { n: num(a.orders) })}</p>
                </section>

                {a.earnings.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('What orders earned')}</h2>
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)] text-sm">
                            {a.earnings.map((e) => (
                                <li key={e.id} className="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2">
                                    <span>{e.kind === 'earn' ? t('An order') : t('Taken back')}</span>
                                    <span className="text-xs text-[var(--pt-muted)]">
                                        {state(e.status)} · {dateTime(e.at)}
                                    </span>
                                    <span className="tabular font-semibold">{money(e.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {a.payouts.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('Paid out')}</h2>
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)] text-sm">
                            {a.payouts.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-baseline justify-between gap-2 px-4 py-2">
                                    <span>{p.method}</span>
                                    <span className="text-xs text-[var(--pt-muted)]">{dateTime(p.at)}</span>
                                    <span className="tabular font-semibold">{money(p.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
