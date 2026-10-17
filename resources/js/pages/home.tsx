import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Banknote, Headset, ShieldCheck, Truck } from 'lucide-react';

interface Aisle {
    slug: string;
    name: string;
    line: string | null;
    image: string | null;
    count: number;
    items: { id: number; title: string; price: number; unit: string | null; photo: string | null }[];
}

interface Props {
    stats: { medicines: number; companies: number; generics: number };
    aisles: Aisle[];
}

const SERIF = { fontFamily: "'Cormorant Garamond', 'Hind Siliguri', Georgia, serif" };

/** A quiet section heading: a rule, the title, and one link. Nothing moves. */
function Head2({ title, href, link }: { title: string; href?: string; link?: string }) {
    return (
        <div className="flex items-baseline justify-between gap-4 border-t border-[var(--pt-ink)] pt-4">
            <h2 style={SERIF} className="text-3xl leading-none font-medium tracking-tight sm:text-4xl">
                {title}
            </h2>
            {href && link && (
                <Link href={href} className="shrink-0 text-sm underline underline-offset-4">
                    {link}
                </Link>
            )}
        </div>
    );
}

export default function Home({ stats, aisles }: Props) {
    const { t, num, money } = useI18n();
    const { site } = usePage<SharedData>().props;
    const open = (key: string) => site.home.sections.find((s) => s.key === key);

    // Shops that are not products on a shelf: each one opens only when the owner has opened it.
    const services = [
        { key: 'pharmacy', title: t('Pharmacy'), line: t('{n} medicines, searched by brand or generic name.', { n: num(stats.medicines) }), image: '/images/home/pharmacy', href: route('medicines.index') },
        { key: 'food', title: t('Food'), line: t('Fast food from local restaurants, ordered online.'), image: '/images/home/food', href: open('food')?.open ? open('food')?.href : null },
        { key: 'custom', title: t('Made to order'), line: t('Wood and clothing made to your size.'), image: '/images/home/custom', href: open('custom')?.open ? open('custom')?.href : null },
        { key: 'games', title: t('Gaming'), line: t('Game top-ups and diamonds, paid online.'), image: '/images/home/gaming', href: open('games')?.open ? open('games')?.href : null },
    ];

    const trust = [
        { Icon: Banknote, title: t('Cash on delivery'), line: t('Pay when it reaches your door.') },
        { Icon: Truck, title: t('Delivery across Bangladesh'), line: t('Dhaka and every district.') },
        { Icon: ShieldCheck, title: t('Checked sellers'), line: t('Real shops, with reviews you can read.') },
        { Icon: Headset, title: t('Help in Bangla'), line: t('Questions answered by real people.') },
    ];

    return (
        <PublicLayout bleed calm>
            <Head title={t('Every shop. One place.')}>
                <meta name="description" content={t('Furniture, jewellery, electronics, books, home, beauty, groceries, clothing, medicine, food and game top-ups, in one place.')} />
            </Head>

            <div className="mx-auto w-full max-w-6xl px-4 pb-24">
                {/* Opening: one sentence, one photograph, and the reasons to trust us. */}
                <section aria-label={t('Welcome')} className="relative isolate -mx-4 mt-0 overflow-hidden bg-[#efe6d6] sm:mx-0 sm:mt-6">
                    <img src="/images/home/hero.jpg" srcSet="/images/home/hero-900.jpg 900w, /images/home/hero.jpg 1566w" sizes="(min-width: 1152px) 1152px, 100vw" alt="" fetchPriority="high" decoding="async" className="absolute inset-0 -z-20 size-full object-cover object-[75%_50%]" />
                    <div aria-hidden className="absolute inset-0 -z-10 bg-gradient-to-r from-[#efe6d6] via-[#efe6d6]/70 to-transparent sm:via-[#efe6d6]/30" />
                    <div className="flex min-h-[28rem] max-w-xl flex-col justify-center px-6 py-14 sm:min-h-[34rem] sm:px-12">
                        <h1 style={SERIF} className="text-5xl leading-[0.95] font-medium tracking-tight text-balance sm:text-7xl">
                            {t('Every shop. One place.')}
                        </h1>
                        <p className="mt-5 max-w-sm text-lg leading-relaxed">{t('Well-made things from small sellers, with the price shown plainly and the delivery handled for you.')}</p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Link href={route('market.index')} className="inline-flex h-12 items-center bg-[var(--pt-ink)] px-7 text-[var(--pt-paper)]">
                                {t('Browse the market')}
                            </Link>
                            <Link href={route('search')} className="inline-flex h-12 items-center border border-[var(--pt-ink)] px-7">
                                {t('Search')}
                            </Link>
                        </div>
                    </div>
                </section>

                <ul aria-label={t('Why shop here')} className="grid grid-cols-2 gap-x-4 gap-y-6 border-b border-[var(--pt-line)] py-8 md:grid-cols-4">
                    {trust.map(({ Icon, title, line }) => (
                        <li key={title} className="flex items-start gap-3">
                            <Icon aria-hidden className="mt-0.5 size-6 shrink-0" strokeWidth={1.4} />
                            <span>
                                <span className="block font-medium">{title}</span>
                                <span className="block text-sm text-[var(--pt-muted)]">{line}</span>
                            </span>
                        </li>
                    ))}
                </ul>

                {/* Every category, each with its own picture. */}
                <section aria-label={t('Categories')} className="mt-8">
                    <Head2 title={t('Shop by category')} />
                    <ul className="mt-6 grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">
                        {aisles.map((a) => (
                            <li key={a.slug}>
                                <Link href={`/market?sector=${a.slug}`} className="block">
                                    <div className="aspect-[4/5] overflow-hidden bg-[var(--pt-line)]">{a.image && <img src={a.image} alt="" loading="lazy" decoding="async" className="size-full object-cover" />}</div>
                                    <p style={SERIF} className="mt-3 text-2xl leading-tight font-medium">
                                        {a.name}
                                    </p>
                                    <p className="tabular text-sm text-[var(--pt-muted)]">{t('{n} products', { n: num(a.count) })}</p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>

                {/* One shelf per category: four products each. */}
                {aisles.map((a) => (
                    <section key={a.slug} aria-label={a.name} className="mt-20">
                        <Head2 title={a.name} href={`/market?sector=${a.slug}`} link={t('See all')} />
                        {a.line && <p className="mt-2 max-w-md text-[var(--pt-muted)]">{a.line}</p>}
                        <ul className="mt-6 grid grid-cols-2 gap-x-4 gap-y-8 md:grid-cols-4">
                            {a.items.map((p) => (
                                <li key={p.id}>
                                    <Link href={route('market.show', p.id)} className="block">
                                        <div className="aspect-square overflow-hidden bg-[var(--pt-line)]">{p.photo && <img src={p.photo} alt="" loading="lazy" decoding="async" className="size-full object-cover" />}</div>
                                        <p className="mt-3 line-clamp-2 leading-snug">{p.title}</p>
                                        <p className="tabular mt-1 text-sm text-[var(--pt-muted)]">
                                            {money(p.price)}
                                            {p.unit ? ` / ${p.unit}` : ''}
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}

                {/* The shops that are not shelves: one large tile and three smaller, text on the picture. */}
                <section aria-label={t('More shops')} className="mt-24">
                    <Head2 title={t('More than things')} />
                    <ul className="mt-6 grid gap-3 lg:h-[34rem] lg:grid-cols-12 lg:grid-rows-2">
                        {services.map((s, i) => {
                            const body = (
                                <>
                                    <img src={`${s.image}-1280.webp`} alt="" loading="lazy" decoding="async" className="absolute inset-0 -z-10 size-full object-cover" />
                                    <div aria-hidden className="absolute inset-0 -z-10 bg-gradient-to-t from-black/70 via-black/10 to-transparent" />
                                    <div className="p-5 sm:p-7">
                                        <p style={SERIF} className={`leading-none font-medium ${i === 0 ? 'text-4xl sm:text-5xl' : 'text-3xl'}`}>
                                            {s.title}
                                        </p>
                                        <p className="mt-2 max-w-sm text-sm text-white/85">{s.line}</p>
                                        <p className="mt-3 text-sm underline underline-offset-4">{s.href ? t('Open') : t('Opening soon')}</p>
                                    </div>
                                </>
                            );
                            const tile = 'relative isolate flex size-full min-h-[16rem] flex-col justify-end overflow-hidden bg-[var(--pt-ink)] text-white';

                            return (
                                <li key={s.key} className={['lg:col-span-7 lg:row-span-2', 'lg:col-span-5', 'lg:col-span-3', 'lg:col-span-2'][i]}>
                                    {s.href ? (
                                        <Link href={s.href} className={tile}>
                                            {body}
                                        </Link>
                                    ) : (
                                        <div className={tile}>{body}</div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </section>
            </div>
        </PublicLayout>
    );
}
