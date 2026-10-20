import SectionHero from '@/components/home/section-hero';
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
            <div className="mx-auto max-w-5xl space-y-8">
                <SectionHero image="/images/home/custom" title={t('Made to order')} line={t('Furniture and clothes made to your own measurements. Tell us what you need and we send a price.')} />

                <div className="grid gap-3 sm:grid-cols-2">
                    {[
                        { href: '/custom/new/wood', img: '/sector-hero/wood', title: t('Wood'), line: t('A bed, a wardrobe, a table. Pick the wood, give the size.') },
                        { href: '/custom/new/clothing', img: '/sector-hero/clothing', title: t('Clothing'), line: t('One outfit, or five for a wedding. Saved measurements fill in by themselves.') },
                    ].map((c) => (
                        <Link key={c.href} href={c.href} className="relative isolate flex min-h-[18rem] flex-col justify-end overflow-hidden bg-[var(--pt-ink)] p-6 text-white">
                            <img src={c.img} alt="" loading="lazy" decoding="async" className="absolute inset-0 -z-20 size-full object-cover" />
                            <div aria-hidden className="absolute inset-0 -z-10 bg-gradient-to-t from-black/75 via-black/15 to-transparent" />
                            <p style={{ fontFamily: "'Cormorant Garamond', 'Hind Siliguri', Georgia, serif" }} className="text-4xl leading-none font-medium">{c.title}</p>
                            <p className="mt-2 max-w-sm text-sm text-white/85">{c.line}</p>
                            <p className="mt-3 text-sm underline underline-offset-4">{t('Start an order')}</p>
                        </Link>
                    ))}
                </div>

                <ol aria-label={t('How it works')} className="grid gap-6 border-t border-[var(--pt-ink)] pt-5 sm:grid-cols-3">
                    {[
                        [t('Tell us what you need'), t('Describe it and give your measurements.')],
                        [t('We send a price'), t('Check it, then confirm when you are happy.')],
                        [t('We make it'), t('Follow the work and collect it when it is ready.')],
                    ].map(([title, line], i) => (
                        <li key={title}>
                            <p style={{ fontFamily: "'Cormorant Garamond', 'Hind Siliguri', Georgia, serif" }} className="text-2xl font-medium">{i + 1}. {title}</p>
                            <p className="mt-1 text-sm text-[var(--pt-muted)]">{line}</p>
                        </li>
                    ))}
                </ol>

                <p className="text-sm">
                    <Link href="/custom/profiles" className="font-semibold underline underline-offset-4">
                        {t('Your saved measurements')}
                    </Link>{' '}
                    <span className="tabular text-[var(--pt-muted)]">({num(profileCount)})</span>
                </p>

                <p className="border border-[var(--pt-line)] p-4 text-sm">{t('Returns do not apply to made-to-order work. Check the measurements carefully before you confirm.')}</p>

                <section className="space-y-2">
                    <h2 style={{ fontFamily: "'Cormorant Garamond', 'Hind Siliguri', Georgia, serif" }} className="border-t border-[var(--pt-ink)] pt-4 text-3xl font-medium">{t('Your jobs')}</h2>
                    {orders.length === 0 ? (
                        <p className="border border-dashed border-[var(--pt-ink)] p-8 text-center text-[var(--pt-muted)]">{t('Nothing ordered yet.')}</p>
                    ) : (
                        <ul className="divide-y divide-[var(--pt-line)] border-y border-[var(--pt-line)]">
                            {orders.map((o) => (
                                <li key={o.token}>
                                    <Link href={`/custom/${o.token}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-surface)]">
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
