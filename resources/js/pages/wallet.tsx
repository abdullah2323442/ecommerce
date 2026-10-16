import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head } from '@inertiajs/react';

interface Props {
    wallet: {
        everywhere: { total: number; tokens: { id: number; amount: number; note: string | null; expires_at: string | null }[] };
        sector: { sector: string; balance: number }[];
    };
    referral: { code: string; link: string; joined: number; rewarded: number; reward: number };
    history: { id: number; amount: number; note: string | null; status: 'ready' | 'used' | 'expired' | 'taken_back'; at: string }[];
}

/** The two parts of the wallet (spec ch 24): what works everywhere, and what works only in the place it was earned. */
export default function Wallet({ wallet, history, referral }: Props) {
    const { t, money, num, date } = useI18n();
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
                                    <span>{k.note ?? t('Aqua Token')}</span>
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
                                    <span>{h.note ?? t('Aqua Token')}</span>
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
