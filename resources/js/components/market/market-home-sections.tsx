import { DISPLAY, SectionHead } from '@/components/storefront/shared';
import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, BadgeCheck, Star } from 'lucide-react';

export interface Department {
    slug: string;
    name: string;
    tagline: string | null;
    count: number;
    image: string | null;
}

export interface MediaItem {
    title: string;
    text: string;
    link: string | null;
    image: string | null;
}

export interface SellerCard {
    id: number;
    name: string;
    products: number;
    trusted: boolean;
    photos: string[];
}

export interface Review {
    name: string;
    place: string | null;
    quote: string;
    rating: number;
}

/** A tile with a picture behind it, the name at the foot and a count. The lead tile is taller and larger. */
function DepartmentTile({ d, lead }: { d: Department; lead?: boolean }) {
    const { t, num } = useI18n();

    return (
        <Link
            href={route('market.index', { sector: d.slug })}
            className={`art-zoom art-lift group relative isolate flex h-full flex-col justify-end overflow-hidden rounded-3xl bg-[var(--t-navy)] p-5 text-white ${lead ? 'min-h-72 sm:min-h-[26rem] sm:p-8' : 'min-h-44 sm:min-h-48'}`}
        >
            {d.image && <img src={d.image} alt="" loading="lazy" decoding="async" className="absolute inset-0 -z-20 size-full object-cover" />}
            <span aria-hidden className="absolute inset-0 -z-10 bg-gradient-to-t from-black/80 via-black/15 to-transparent" />
            <span style={DISPLAY} className={`leading-none text-balance ${lead ? 'text-4xl sm:text-6xl' : 'text-2xl sm:text-3xl'}`}>
                {d.name}
            </span>
            {lead && d.tagline && <span className="mt-2 max-w-sm text-white/80">{d.tagline}</span>}
            <span className="mt-2 flex items-center justify-between gap-2 text-sm text-white/80">
                <span className="tabular">{t('{n} products', { n: num(d.count) })}</span>
                <ArrowUpRight
                    aria-hidden
                    className="size-5 transition-transform duration-300 group-hover:translate-x-1 group-hover:-translate-y-1"
                    strokeWidth={1.6}
                />
            </span>
        </Link>
    );
}

/** The shops as pictures: one large lead tile with four beside it, then the rest in a row. */
export function Departments({ title, text, list }: { title: string | null; text: string | null; list: Department[] }) {
    const [lead, ...rest] = list;
    const beside = rest.slice(0, 4);
    const below = rest.slice(4);

    if (!lead) return null;

    return (
        <section aria-label={title ?? undefined}>
            <SectionHead title={title} text={text} />
            <div className="mt-8 grid grid-cols-2 gap-3 lg:grid-cols-12">
                <div className="col-span-2 lg:col-span-6 lg:row-span-2">
                    <DepartmentTile d={lead} lead />
                </div>
                {beside.map((d) => (
                    <div key={d.slug} className="lg:col-span-3">
                        <DepartmentTile d={d} />
                    </div>
                ))}
            </div>
            {below.length > 0 && (
                <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {below.map((d) => (
                        <DepartmentTile key={d.slug} d={d} />
                    ))}
                </div>
            )}
        </section>
    );
}

/** Wide promotion banners: the picture fills the card, the words sit over its dark edge. */
export function Promos({ items }: { items: MediaItem[] }) {
    const { t } = useI18n();

    return (
        <section aria-label={t('Promotions')} className="grid gap-4 md:grid-cols-2">
            {items.map((i, n) => {
                const body = (
                    <>
                        {i.image && (
                            <img src={i.image} alt="" loading="lazy" decoding="async" className="absolute inset-0 -z-20 size-full object-cover" />
                        )}
                        <span
                            aria-hidden
                            className="absolute inset-0 -z-10 bg-gradient-to-r from-[var(--t-navy)] via-[var(--t-navy)]/70 to-transparent"
                        />
                        <span style={DISPLAY} className="max-w-[16rem] text-4xl leading-none text-balance sm:text-5xl">
                            {i.title}
                        </span>
                        {i.text && <span className="mt-3 max-w-[15rem] text-white/80">{i.text}</span>}
                        {i.link && (
                            <span className="art-btn mt-6 inline-flex h-11 w-fit items-center gap-2 rounded-full bg-white px-6 font-semibold text-[var(--t-navy)]">
                                {t('Explore')}
                                <ArrowUpRight aria-hidden className="size-4" strokeWidth={2} />
                            </span>
                        )}
                    </>
                );
                const cls =
                    'art-zoom group relative isolate flex min-h-72 flex-col justify-end overflow-hidden rounded-3xl bg-[var(--t-navy)] p-6 text-white sm:min-h-80 sm:p-8';

                return i.link ? (
                    <Link key={`${i.title}-${n}`} href={i.link} className={cls}>
                        {body}
                    </Link>
                ) : (
                    <div key={`${i.title}-${n}`} className={cls}>
                        {body}
                    </div>
                );
            })}
        </section>
    );
}

/** Editorial collections: tall pictures with a caption under each, the even ones set lower so the row has a rhythm. */
export function Collections({ title, text, items }: { title: string | null; text: string | null; items: MediaItem[] }) {
    const { t } = useI18n();

    return (
        <section aria-label={title ?? undefined}>
            <SectionHead title={title} text={text} />
            <ul className="mt-10 grid grid-cols-2 gap-x-4 gap-y-10 lg:grid-cols-4">
                {items.map((i, n) => {
                    const inner = (
                        <>
                            <span className="art-zoom block aspect-[4/5] overflow-hidden rounded-2xl bg-[var(--t-bg)]">
                                {i.image && <img src={i.image} alt="" loading="lazy" decoding="async" className="size-full object-cover" />}
                            </span>
                            <span style={DISPLAY} className="mt-4 block text-2xl leading-tight">
                                {i.title}
                            </span>
                            {i.text && <span className="mt-1 block text-sm text-[var(--t-muted)]">{i.text}</span>}
                            {i.link && (
                                <span className="mt-3 inline-flex min-h-10 items-center gap-1 text-sm underline underline-offset-4">
                                    {t('Explore')}
                                    <ArrowUpRight
                                        aria-hidden
                                        className="size-4 transition-transform duration-300 group-hover:translate-x-0.5 group-hover:-translate-y-0.5"
                                        strokeWidth={1.6}
                                    />
                                </span>
                            )}
                        </>
                    );

                    return (
                        <li key={`${i.title}-${n}`} className={n % 2 === 1 ? 'lg:mt-12' : ''}>
                            {i.link ? (
                                <Link href={i.link} className="group block">
                                    {inner}
                                </Link>
                            ) : (
                                inner
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/** Shops with a page of their own: the name, a trusted mark, how many products, and three of them in a strip. */
export function Sellers({ title, text, list }: { title: string | null; text: string | null; list: SellerCard[] }) {
    const { t, num } = useI18n();

    return (
        <section aria-label={title ?? undefined}>
            <SectionHead title={title} text={text} />
            <ul className="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {list.map((s) => (
                    <li key={s.id}>
                        <Link
                            href={route('shop.show', s.id)}
                            className="art-lift group block rounded-2xl border border-[var(--t-line)] bg-white p-3 text-[var(--t-ink)]"
                        >
                            <span className="grid h-28 grid-cols-3 gap-1.5 overflow-hidden rounded-xl">
                                {[0, 1, 2].map((n) => (
                                    <span key={n} className="block bg-[var(--t-bg)]">
                                        {s.photos[n] && (
                                            <img src={s.photos[n]} alt="" loading="lazy" decoding="async" className="size-full object-cover" />
                                        )}
                                    </span>
                                ))}
                            </span>
                            <span className="mt-4 flex items-start justify-between gap-3 px-1 pb-1">
                                <span className="min-w-0">
                                    <span style={DISPLAY} className="block truncate text-xl leading-tight">
                                        {s.name}
                                    </span>
                                    <span className="tabular text-sm text-[var(--t-muted)]">{t('{n} products', { n: num(s.products) })}</span>
                                </span>
                                {s.trusted && (
                                    <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-[var(--t-line)] px-2.5 py-1 text-xs font-semibold">
                                        <BadgeCheck aria-hidden className="size-4" strokeWidth={1.8} />
                                        {t('Trusted seller')}
                                    </span>
                                )}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** Words from buyers: the first one set large in the display face, the rest smaller beside it. */
export function Reviews({ title, list }: { title: string | null; list: Review[] }) {
    const { t, num } = useI18n();
    const [first, ...rest] = list;

    if (!first) return null;

    const stars = (n: number) => (
        <span role="img" aria-label={t('{n} out of 5', { n: num(n) })} className="flex gap-0.5 text-[var(--t-gold-ink)]">
            {[1, 2, 3, 4, 5].map((i) => (
                <Star key={i} aria-hidden className="size-4" strokeWidth={1.5} fill={i <= n ? 'currentColor' : 'none'} />
            ))}
        </span>
    );

    return (
        <section aria-label={title ?? undefined} className="grid gap-10 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:gap-16">
            <figure>
                {title && (
                    <figcaption style={DISPLAY} className="text-4xl leading-none text-balance sm:text-5xl">
                        {title}
                    </figcaption>
                )}
                <blockquote style={DISPLAY} className="mt-8 text-3xl leading-snug text-balance sm:text-4xl">
                    {first.quote}
                </blockquote>
                <div className="mt-6 flex flex-wrap items-center gap-4">
                    {stars(first.rating)}
                    <span className="font-semibold">{first.name}</span>
                    {first.place && <span className="text-[var(--t-muted)]">{first.place}</span>}
                </div>
            </figure>
            {rest.length > 0 && (
                <ul className="border-t border-[var(--t-line)]">
                    {rest.map((r, n) => (
                        <li key={`${r.name}-${n}`} className="border-b border-[var(--t-line)] py-6">
                            {stars(r.rating)}
                            <p className="mt-3 leading-relaxed">{r.quote}</p>
                            <p className="mt-3 text-sm">
                                <span className="font-semibold">{r.name}</span>
                                {r.place && <span className="text-[var(--t-muted)]">{`, ${r.place}`}</span>}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
