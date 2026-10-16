import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

interface Order {
    token: string;
    number: string;
    kind: 'wood' | 'clothing';
    status: string;
    price: number | null;
    at: string;
}

export const customState = (t: (s: string) => string, s: string) =>
    t(
        s === 'requested' ? 'Waiting for our price' : s === 'priced' ? 'Price ready: check and confirm' : s === 'confirmed' ? 'Confirmed' : s === 'making' ? 'Being made' : s === 'trial' ? 'Ready for a trial' : s === 'done' ? 'Ready to collect' : s === 'collected' ? 'Handed over' : 'Cancelled',
    );

/** Made to order (spec ch 16): wood and clothing to your own measurements. */
export default function CustomIndex({ orders, profileCount }: { orders: Order[]; profileCount: number }) {
    const { t, money, num, dateTime } = useI18n();

    return (
        <PublicLayout>
            <Head title={t('Made to order')} />
            <div className="mx-auto max-w-3xl space-y-8">
                <div>
                    <h1 className="font-display text-3xl font-extrabold">{t('Made to order')}</h1>
                    <p className="mt-2 text-[var(--pt-muted)]">{t('Furniture and clothes made to your own measurements. Tell us what you need and we send a price.')}</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Link href="/custom/new/wood" className="rounded-2xl border border-[var(--pt-line)] bg-[var(--pt-surface)] p-6 hover:border-[var(--pt-ink)]">
                        <p className="font-display text-2xl font-bold">{t('Wood')}</p>
                        <p className="mt-1 text-sm text-[var(--pt-muted)]">{t('A bed, a wardrobe, a table. Pick the wood, give the size.')}</p>
                    </Link>
                    <Link href="/custom/new/clothing" className="rounded-2xl border border-[var(--pt-line)] bg-[var(--pt-surface)] p-6 hover:border-[var(--pt-ink)]">
                        <p className="font-display text-2xl font-bold">{t('Clothing')}</p>
                        <p className="mt-1 text-sm text-[var(--pt-muted)]">{t('One outfit, or five for a wedding. Saved measurements fill in by themselves.')}</p>
                    </Link>
                </div>

                <p className="text-sm">
                    <Link href="/custom/profiles" className="font-semibold underline underline-offset-4">
                        {t('Your saved measurements')}
                    </Link>{' '}
                    <span className="tabular text-[var(--pt-muted)]">({num(profileCount)})</span>
                </p>

                <p className="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">{t('Returns do not apply to made-to-order work. Check the measurements carefully before you confirm.')}</p>

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Your jobs')}</h2>
                    {orders.length === 0 ? (
                        <p className="rounded-2xl border border-dashed p-8 text-center text-[var(--pt-muted)]">{t('Nothing ordered yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                            {orders.map((o) => (
                                <li key={o.token}>
                                    <Link href={`/custom/${o.token}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                        <span className="min-w-32 flex-1 font-medium">
                                            {o.number} · {o.kind === 'wood' ? t('Wood') : t('Clothing')}
                                        </span>
                                        <span className="text-sm text-[var(--pt-muted)]">{dateTime(o.at)}</span>
                                        <span className="text-sm font-semibold">{customState(t, o.status)}</span>
                                        <span className="tabular w-24 text-right">{o.price !== null ? money(o.price) : ''}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </PublicLayout>
    );
}
