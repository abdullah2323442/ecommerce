import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    wallet: {
        everywhere: { total: number; tokens: { id: number; amount: number; note: string | null; expires_at: string | null }[] };
        sector: { sector: string; balance: number }[];
    };
    referral: { code: string; link: string; joined: number; rewarded: number; reward: number };
    history: { id: number; amount: number; note: string | null; status: 'ready' | 'used' | 'expired' | 'taken_back'; at: string }[];
    points: { on: boolean; balance: number; per100: number; worth: number; minimum: number; history: { id: number; points: number; kind: 'earn' | 'reverse' | 'spend'; note: string | null; at: string }[] };
}

/** Reward points: earned on what the buyer buys, turned into an Aqua Token that works everywhere. */
function Points({ points }: { points: Props['points'] }) {
    const { t, money, num, dateTime } = useI18n();
    const page = usePage();
    const errors = page.props.errors as Record<string, string>;
    const done = (page.props.flash as { status?: string } | undefined)?.status;
    const [amount, setAmount] = useState(String(points.balance));
    const [busy, setBusy] = useState(false);
    const n = Math.floor(Number(amount) || 0);
    const value = Math.round((n / 100) * points.worth * 100) / 100;
    const ok = n >= points.minimum && n <= points.balance && value >= 1;

    return (
        <section className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <div className="flex items-baseline justify-between gap-3">
                <h2 className="font-display text-xl font-bold">{t('Reward points')}</h2>
                <span className="tabular font-display text-2xl font-extrabold">{num(points.balance)}</span>
            </div>
            <p className="text-sm text-[var(--pt-muted)]">
                {t('You earn {n} points for every 100 taka you spend. 100 points are worth a token of {amount}. A cancelled or returned order takes its points back.', { n: num(points.per100), amount: money(points.worth) })}
            </p>
            {done && (
                <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
                    {done}
                </p>
            )}
            {points.balance >= points.minimum ? (
                <form
                    className="space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!ok || busy) return;
                        router.post('/wallet/points', { points: n }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
                    }}
                >
                    <label className="block text-sm font-medium">
                        {t('Points to turn into a token')}
                        <input className="mt-1 w-full rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3" type="number" inputMode="numeric" min={points.minimum} max={points.balance} value={amount} onChange={(e) => setAmount(e.target.value)} />
                    </label>
                    {errors.points && <p role="alert" className="text-sm text-destructive">{errors.points}</p>}
                    <button type="submit" disabled={!ok || busy} className="rounded-full bg-[var(--pt-ink)] px-5 py-2.5 text-sm font-semibold text-[var(--pt-paper)] disabled:opacity-50">
                        {busy ? t('Saving…') : ok ? t('Make a token of {amount}', { amount: money(value) }) : t('Make a token')}
                    </button>
                </form>
            ) : (
                <p className="text-sm">{t('You need {n} points before you can make a token.', { n: num(points.minimum) })}</p>
            )}
            {points.history.length > 0 && (
                <ul className="divide-y text-sm">
                    {points.history.map((h) => (
                        <li key={h.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span>{h.note ? t(h.note) : ''}</span>
                            <span className="text-xs text-[var(--pt-muted)]">{dateTime(h.at)}</span>
                            <span className="tabular font-semibold">{h.points > 0 ? `+${num(h.points)}` : `-${num(Math.abs(h.points))}`}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** The two parts of the wallet (spec ch 24): what works everywhere, and what works only in the place it was earned. */
export default function Wallet({ wallet, history, referral, points }: Props) {
    const { t, money, num, date } = useI18n();
    const isAffiliate = (usePage().props as { isAffiliate?: boolean }).isAffiliate;
    const sectorName = (s: string) => (s === 'pharmacy' ? t('Medicine shop') : s);
    const state = (s: Props['history'][number]['status']) => t(s === 'ready' ? 'Ready to use' : s === 'used' ? 'Used' : s === 'expired' ? 'Ended' : 'Taken back');

    return (
        <PublicLayout>
            <Head title={t('Wallet')} />
            <div className="mx-auto max-w-2xl space-y-6">
                <h1 className="font-display text-3xl font-extrabold">{t('Wallet')}</h1>

                <section className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
                    <div className="flex items-baseline justify-between gap-3">
                        <h2 className="font-display text-xl font-bold">{t('Works everywhere')}</h2>
                        <span className="tabular font-display text-2xl font-extrabold">{money(wallet.everywhere.total)}</span>
                    </div>
                    <p className="text-sm text-[var(--pt-muted)]">{t('Your Aqua Tokens. Use one on any order. One token for one order, and it does not go with an offer price.')}</p>
                    {wallet.everywhere.tokens.length === 0 ? (
                        <p className="text-sm">{t('You have no token now.')}</p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {wallet.everywhere.tokens.map((k) => (
                                <li key={k.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>{k.note ? t(k.note) : t('Aqua Token')}</span>
                                    <span className="text-[var(--pt-muted)]">{k.expires_at ? t('until {date}', { date: date(k.expires_at) }) : ''}</span>
                                    <span className="tabular font-semibold">{money(k.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
                    <h2 className="font-display text-xl font-bold">{t('Works in one place only')}</h2>
                    <p className="text-sm text-[var(--pt-muted)]">{t('Money from a return. It comes off your next order in the same place you returned it, and nowhere else.')}</p>
                    {wallet.sector.length === 0 ? (
                        <p className="text-sm">{t('You have none now.')}</p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {wallet.sector.map((c) => (
                                <li key={c.sector} className="flex justify-between py-2">
                                    <span>{sectorName(c.sector)}</span>
                                    <span className="tabular font-semibold">{money(c.balance)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {points.on && <Points points={points} />}

                {isAffiliate && (
                    <p className="rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
                        <Link href="/affiliate" className="font-semibold underline underline-offset-4">
                            {t('Your affiliate link and earnings')}
                        </Link>
                    </p>
                )}

                {referral.reward > 0 && (
                    <section className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <h2 className="font-display text-xl font-bold">{t('Invite a friend')}</h2>
                        <p className="text-sm text-[var(--pt-muted)]">{t('When a friend joins with your link and their first order is done, you get a token of {amount}.', { amount: money(referral.reward) })}</p>
                        <p className="break-all rounded-md bg-[var(--pt-sage)] px-3 py-2 text-sm font-semibold select-all">{referral.link}</p>
                        <p className="text-sm">{t('Joined by your link: {n}. Tokens earned: {m}.', { n: num(referral.joined), m: num(referral.rewarded) })}</p>
                    </section>
                )}

                {history.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('Your tokens so far')}</h2>
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)] text-sm">
                            {history.map((h) => (
                                <li key={h.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                                    <span>{h.note ? t(h.note) : t('Aqua Token')}</span>
                                    <span className="text-[var(--pt-muted)]">{state(h.status)}</span>
                                    <span className="tabular">{money(h.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
