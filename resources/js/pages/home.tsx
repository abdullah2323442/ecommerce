import CountUp from '@/components/home/count-up';
import { DiamondBand, Rosette } from '@/components/home/ornaments';
import Pharmacy, { type PharmacyData } from '@/components/home/pharmacy';
import Showcase from '@/components/home/showcase';
import Testimonials, { type Voice } from '@/components/home/testimonials';
import { useFit } from '@/components/home/use-fit';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, Banknote, Headset, ShieldCheck, Truck } from 'lucide-react';

interface Item {
    id: number;
    title: string;
    blurb: string;
    price: number;
    unit: string | null;
    photo: string | null;
}

interface Aisle {
    slug: string;
    name: string;
    line: string | null;
    image: string | null;
    count: number;
    items: Item[];
}

interface Props {
    stats: { medicines: number; companies: number; generics: number };
    featured: PharmacyData['featured'];
    companies: PharmacyData['companies'];
    forms: PharmacyData['forms'];
    aisles: Aisle[];
    bands: { from: number; to: number | null; count: number; photos: string[] }[];
    testimonials: Voice[];
}

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };

/** A section title: Changa One, large, with a short run of diamonds under it. */
function Title({ children, light = false }: { children: string; light?: boolean }) {
    return (
        <div>
            <h2
                style={DISPLAY}
                className={`text-6xl leading-[1.05] font-normal tracking-tight text-balance ${light ? 'text-[var(--pt-paper)]' : ''}`}
            >
                {children}
            </h2>
        </div>
    );
}

/**
 * The home page, top to bottom: a ticker of shops, a dark opening with the photograph in an arch, the category bento, one product given a
 * whole row, a pharmacy band, a mixed strip, a budget index, the numbers behind the shop, four arched doors, help and selling, and a dark close.
 * Every figure on it is counted from the live catalogue.
 */
export default function Home({ stats, featured = [], companies = [], forms = [], aisles, bands, testimonials }: Props) {
    const { t, num, money } = useI18n();
    const { site } = usePage<SharedData>().props;
    // 72rem of content plus its 1rem gutters.
    const scale = useFit(1184);
    const fitted = scale < 1;
    const open = (key: string) => site.home.sections.find((s) => s.key === key);

    // Whole taka for the budget bands: a range reads faster without the paisa.
    const taka = (n: number) => `৳${num(n)}`;
    const products = aisles.reduce((n, a) => n + a.count, 0);
    // Two products from each shop, interleaved, so the strip reads as a mix and not as one shop after another.
    const strip = [0, 1].flatMap((n) => aisles.map((a) => ({ ...a.items[n], shop: a.name }))).filter((p) => p.id !== undefined);
    // The window shows the pharmacy too: a real pack with its printed price, linking to that medicine.
    const pharmacyShop = {
        slug: 'pharmacy',
        name: t('Pharmacy'),
        count: stats.medicines,
        href: route('medicines.index'),
        items: featured
            .filter((m) => m.image_url && m.mrp_piece)
            .map((m) => ({
                id: m.id,
                href: route('medicines.show', m.id),
                title: m.brand_name,
                blurb: [m.generic_name, m.strength, m.company?.name].filter(Boolean).join(', '),
                price: Number(m.mrp_piece),
                unit: t('piece'),
                photo: m.image_url,
            })),
    };
    const windowShops = [...aisles.slice(0, 1), ...(pharmacyShop.items.length > 0 ? [pharmacyShop] : []), ...aisles.slice(1)];
    // Every department sits in the category grid with its own picture: the shops, then pharmacy, food, game top-ups and made to order.
    const pick = (slug: string) => aisles.find((x) => x.slug === slug);
    const shopTile = (slug: string) => {
        const x = pick(slug);

        return x
            ? { key: x.slug, name: x.name, sub: t('{n} products', { n: num(x.count) }), image: x.image, href: `/market?sector=${x.slug}` }
            : null;
    };
    const dept = (key: string, name: string, sub: string, image: string, href: string | null | undefined) => ({
        key,
        name,
        sub: href ? sub : t('Opening soon'),
        image,
        href: href ?? null,
    });
    const tiles = [
        shopTile('wood'),
        dept('pharmacy', t('Pharmacy'), t('{n} medicines', { n: num(stats.medicines) }), '/images/home/pharmacy-cat.jpg', route('medicines.index')),
        dept('food', t('Food'), t('Local restaurants'), '/images/home/food-1280.webp', open('food')?.open ? open('food')?.href : null),
        shopTile('clothing'),
        shopTile('electronics'),
        shopTile('home'),
        shopTile('beauty'),
        shopTile('books'),
        shopTile('grocery'),
        shopTile('jewellery'),
        dept('games', t('Game top-up'), t('Paid online'), '/images/home/gaming-1280.webp', open('games')?.open ? open('games')?.href : null),
        dept(
            'custom',
            t('Made to order'),
            t('Wood and clothing'),
            '/images/home/custom-1280.webp',
            open('custom')?.open ? open('custom')?.href : null,
        ),
        // any shop the list above does not name still gets its tile
        ...aisles
            .filter((x) => !['wood', 'clothing', 'electronics', 'home', 'beauty', 'books', 'grocery', 'jewellery'].includes(x.slug))
            .map((x) => shopTile(x.slug)),
    ].filter((x): x is NonNullable<typeof x> => x !== null);

    const promises = [
        { Icon: Banknote, title: t('Cash on delivery'), line: t('Pay when it reaches your door.') },
        { Icon: Truck, title: t('Delivery across Bangladesh'), line: t('Dhaka and every district.') },
        { Icon: ShieldCheck, title: t('Checked sellers'), line: t('Real shops, with reviews you can read.') },
        { Icon: Headset, title: t('Help in Bangla'), line: t('Questions answered by real people.') },
    ];

    const numbers: [number, string][] = [
        [products, t('products on the shelves')],
        [aisles.length, t('kinds of shop')],
        [stats.medicines, t('medicines to search')],
        [stats.companies, t('pharmaceutical companies')],
    ];

    const faq = [
        [
            t('Can I pay when it arrives?'),
            t('For most products, yes. The product page tells you whether cash on delivery is on. Game top-ups are paid online.'),
        ],
        [
            t('Is the medicine price the real price?'),
            t('Prices shown are the printed MRP. Some medicines need a prescription, and the medicine page says so. Always follow your doctor.'),
        ],
        [t('Where do you deliver?'), t('Across Bangladesh. You see the delivery charge before you confirm.')],
        [t('Can I send something back?'), t('Read the return policy for what can be returned. Made-to-order work cannot be.')],
        [t('Who do I talk to if something is wrong?'), t('Write to us from Contact us. A person answers, in Bangla.')],
    ];

    const help = [
        [t('Help board'), t('Ask for a worker, a product, any help.'), '/help'],
        [t('Return policy'), t('What you can send back, and how.'), route('info.returns')],
        [t('Contact us'), t('A real person answers, in Bangla.'), '/support/new'],
    ];

    return (
        <PublicLayout bleed calm>
            <Head title={t('Made well. Found here.')}>
                <meta
                    name="description"
                    content={t(
                        'Furniture, jewellery, electronics, books, home, beauty, groceries, clothing, medicine, food and game top-ups, in one place.',
                    )}
                />
            </Head>

            <div style={fitted ? { width: 1184, zoom: scale } : undefined}>
                {/* Ticker */}
                <div aria-hidden className="art art-marquee-wrap overflow-hidden bg-[var(--art-gold-2)] py-2 text-[var(--art-night)]">
                    <div className="art-marquee flex w-max gap-10 whitespace-nowrap" style={DISPLAY}>
                        {[0, 1].map((k) => (
                            <div key={k} className="flex gap-10">
                                {[
                                    t('Cash on delivery'),
                                    t('Pharmacy'),
                                    ...aisles.map((a) => a.name),
                                    t('Food'),
                                    t('Game top-up'),
                                    t('Delivery across Bangladesh'),
                                    t('Checked sellers'),
                                ].map((w, i) => (
                                    <span key={`${k}-${i}`} className="inline-flex items-center gap-10 text-sm">
                                        {w}
                                        <span className="size-1.5 rotate-45 bg-[var(--art-night)]" />
                                    </span>
                                ))}
                            </div>
                        ))}
                    </div>
                </div>

                {/* Opening: dark, with the photograph in an arch. */}
                <section aria-labelledby="home-title" className="art relative isolate overflow-hidden bg-[var(--art-night)] text-[var(--pt-paper)]">
                    <div className="mx-auto grid w-full max-w-6xl grid-cols-12 items-center gap-14 px-4 pt-20 pb-16">
                        <div className="col-span-6">
                            <h1 id="home-title" style={DISPLAY} className="art-rise text-7xl leading-[1] font-normal tracking-tight text-balance">
                                {t('Made well. Found here.')}
                            </h1>
                            <p className="art-rise art-d1 mt-7 max-w-md text-lg leading-relaxed opacity-80">
                                {t(
                                    'Furniture, jewellery, books, beauty, groceries and more from sellers across Bangladesh. Plain prices, delivery handled.',
                                )}
                            </p>
                            <div className="art-rise art-d2 mt-9 flex flex-wrap gap-3">
                                <Link
                                    href={route('market.index')}
                                    className="art-btn inline-flex h-14 items-center gap-2 rounded-full bg-[var(--art-gold-2)] px-8 font-medium text-[var(--art-night)]"
                                >
                                    {t('Browse the market')}
                                    <ArrowUpRight aria-hidden className="size-5" strokeWidth={1.8} />
                                </Link>
                                <Link
                                    href={route('medicines.index')}
                                    className="inline-flex h-14 items-center rounded-full border border-[var(--art-gold-2)] px-8"
                                >
                                    {t('Find a medicine')}
                                </Link>
                            </div>
                        </div>

                        <div className="col-span-6 flex justify-center">
                            <div className="relative w-full max-w-sm">
                                <Rosette className="absolute top-1/2 left-1/2 -z-10 w-[165%] max-w-none -translate-x-1/2 -translate-y-1/2" />
                                <div className="art-float art-d3 relative rounded-t-full border border-[var(--art-gold-2)] bg-[var(--art-night)] p-2 shadow-[0_30px_80px_-20px_rgba(0,0,0,0.8)]">
                                    <img
                                        src="/images/home/hero-900.jpg"
                                        srcSet="/images/home/hero-900.jpg 900w, /images/home/hero.jpg 1566w"
                                        sizes="24rem"
                                        alt=""
                                        fetchPriority="high"
                                        decoding="async"
                                        className="aspect-[3/4] w-full rounded-t-full object-cover object-[80%_50%]"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <ul
                        aria-label={t('Why shop here')}
                        className="mx-auto grid w-full max-w-6xl grid-cols-4 gap-x-6 gap-y-8 border-t border-[var(--art-gold-3)] px-4 py-10"
                    >
                        {promises.map(({ Icon, title, line }) => (
                            <li key={title} className="flex gap-3">
                                <Icon aria-hidden className="mt-1 size-6 shrink-0 text-[var(--art-gold-2)]" strokeWidth={1.5} />
                                <div>
                                    <p style={DISPLAY} className="text-lg leading-tight">
                                        {title}
                                    </p>
                                    <p className="mt-1 text-sm opacity-70">{line}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>

                <div className="mx-auto w-full max-w-6xl px-4">
                    {/* Every shop: the first one leads. */}
                    <section aria-label={t('Shop by category')} className="reveal mt-24">
                        <Title>{t('Shop by category')}</Title>
                        <ul className="mt-10 grid grid-cols-4 grid-rows-[repeat(4,minmax(0,15rem))] gap-3">
                            {tiles.map((x, i) => {
                                const lead = i === 0;
                                const wide = i === tiles.length - 1 && tiles.length === 12;
                                const body = (
                                    <>
                                        {x.image && (
                                            <img
                                                src={x.image}
                                                alt=""
                                                loading={lead ? 'eager' : 'lazy'}
                                                decoding="async"
                                                className="absolute inset-0 -z-20 size-full object-cover"
                                            />
                                        )}
                                        <div
                                            aria-hidden
                                            className="absolute inset-0 -z-10 bg-gradient-to-t from-black/75 via-black/10 to-transparent"
                                        />
                                        <div aria-hidden className="absolute inset-2.5 -z-10 rounded-[1.35rem] border border-white/30" />
                                        <p style={DISPLAY} className={`leading-none font-normal ${lead ? 'text-5xl' : 'text-2xl'}`}>
                                            {x.name}
                                        </p>
                                        <p className="tabular mt-1 text-sm text-white/80">{x.sub}</p>
                                    </>
                                );
                                const tile =
                                    'art-zoom relative isolate flex size-full min-h-48 flex-col justify-end overflow-hidden rounded-[1.75rem] bg-[var(--pt-ink)] p-6 text-white';

                                return (
                                    <li key={x.key} className={`${lead ? 'col-span-2 row-span-2' : ''} ${wide ? 'col-span-2' : ''} min-h-48`}>
                                        {x.href ? (
                                            <Link href={x.href} className={tile}>
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

                    {/* One window for every shop. */}
                    <Showcase shops={windowShops} />

                    {/* The pharmacy: a main part of the shop, so it has its own band. */}
                    <Pharmacy stats={stats} featured={featured} companies={companies} forms={forms} />

                    {/* A mixed strip of products: swipe on a phone, scroll on a desktop. */}
                    {strip.length > 0 && (
                        <section aria-label={t('Picked for you')} className="reveal mt-28">
                            <div className="flex items-end justify-between gap-4">
                                <Title>{t('Picked for you')}</Title>
                                <Link
                                    href={route('market.index')}
                                    className="inline-flex shrink-0 items-center gap-1 text-sm underline underline-offset-4"
                                >
                                    {t('See all')}
                                    <ArrowUpRight aria-hidden className="size-4" strokeWidth={1.6} />
                                </Link>
                            </div>
                            {/* The strip drifts to the right on its own. The list is drawn twice so the loop has no seam; the copy is hidden from
                            assistive technology, and hovering or tabbing into it pauses it. With reduced motion it is an ordinary swipeable row. */}
                            <div
                                className={`art-drift-wrap no-scrollbar mt-10 overflow-hidden ${fitted ? '-mx-4' : 'relative left-1/2 w-screen -translate-x-1/2'} [mask-image:linear-gradient(to_right,transparent,black_10%,black_90%,transparent)]`}
                            >
                                <div className="art-drift flex w-max gap-4">
                                    {[0, 1].map((copy) => (
                                        <ul key={copy} aria-hidden={copy === 1} className="flex shrink-0 gap-4">
                                            {strip.map((p) => (
                                                <li key={`${copy}-${p.shop}-${p.id}`} className="w-64 shrink-0">
                                                    <Link href={route('market.show', p.id)} tabIndex={copy === 1 ? -1 : undefined} className="block">
                                                        <div className="art-zoom aspect-[4/5] rounded-t-full rounded-b-[1.25rem] bg-[var(--pt-line)]">
                                                            {p.photo && (
                                                                <img
                                                                    src={p.photo}
                                                                    alt=""
                                                                    loading="lazy"
                                                                    decoding="async"
                                                                    className="size-full object-cover"
                                                                />
                                                            )}
                                                        </div>
                                                        <p className="mt-3 line-clamp-1">{p.title}</p>
                                                        <p className="tabular text-sm text-[var(--pt-muted)]">
                                                            {money(p.price)}
                                                            {p.unit ? ` / ${p.unit}` : ''}
                                                        </p>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    ))}
                                </div>
                            </div>
                        </section>
                    )}

                    {/* Shop by budget: a typographic index, one row per price range. */}
                    <section aria-label={t('Shop by budget')} className="reveal mt-28">
                        <div className="flex flex-wrap items-end justify-between gap-6">
                            <Title>{t('Shop by budget')}</Title>
                            <p className="max-w-xs text-[var(--pt-muted)]">
                                {t('Start from what you want to spend. Every range has something in it.')}
                            </p>
                        </div>
                        <ul className="mt-10 border-t border-[var(--pt-ink)]">
                            {bands.map((r) => (
                                <li key={r.from} className="border-b border-[var(--pt-line)]">
                                    <Link
                                        href={`/market?from=${r.from}${r.to === null ? '' : `&to=${r.to}`}`}
                                        className="group grid grid-cols-12 items-center gap-6 px-2 py-7 transition-colors duration-500 hover:bg-[var(--art-night)] hover:text-[var(--pt-paper)]"
                                    >
                                        <span style={DISPLAY} className="col-span-7 text-5xl leading-none tracking-tight">
                                            {r.to === null
                                                ? t('{a} and above', { a: taka(r.from) })
                                                : r.from === 0
                                                  ? t('Under {b}', { b: taka(r.to) })
                                                  : `${taka(r.from)} ${t('to')} ${taka(r.to)}`}
                                        </span>
                                        <span className="tabular col-span-2 text-lg opacity-70">{t('{n} products', { n: num(r.count) })}</span>
                                        <span className="col-span-3 flex items-center justify-end">
                                            <span className="flex -space-x-5">
                                                {r.photos.map((u) => (
                                                    <img
                                                        key={u}
                                                        src={u}
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async"
                                                        className="size-16 rounded-full border-2 border-[var(--pt-paper)] object-cover transition-transform duration-500 group-hover:translate-x-1 group-hover:border-[var(--art-night)]"
                                                    />
                                                ))}
                                            </span>
                                            <ArrowUpRight
                                                aria-hidden
                                                className="ml-5 size-7 shrink-0 transition-transform duration-500 group-hover:translate-x-1 group-hover:-translate-y-1"
                                                strokeWidth={1.5}
                                            />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>

                {/* The numbers behind the shop, counted live. */}
                <section
                    aria-label={t('The shop in numbers')}
                    className="reveal art relative isolate mt-28 overflow-hidden bg-[var(--art-night)] text-[var(--pt-paper)]"
                >
                    <Rosette className="absolute -top-40 -right-48 -z-10 size-[36rem] opacity-30" />
                    <div className="mx-auto w-full max-w-6xl px-4 py-20">
                        <Title light>{t('The shop in numbers')}</Title>
                        <dl className="mt-12 grid grid-cols-4 gap-x-6 gap-y-10">
                            {numbers.map(([n, label]) => (
                                <div key={label} className="flex flex-col border-t border-[var(--art-gold-3)] pt-4">
                                    <dt className="order-2 mt-2 text-sm opacity-75">{label}</dt>
                                    <dd style={DISPLAY} className="tabular text-6xl leading-none text-[var(--art-gold-2)]">
                                        <CountUp value={n} />
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </section>

                <div className="mx-auto w-full max-w-6xl px-4 pb-24">
                    {/* Help, and a way to sell. */}
                    <section aria-label={t('Help and selling')} className="reveal mt-28 grid grid-cols-12 gap-3">
                        <div className="col-span-7 rounded-[1.75rem] border border-[var(--pt-line)] p-8">
                            <h2 style={DISPLAY} className="text-4xl font-normal">
                                {t('Need a hand?')}
                            </h2>
                            <ul className="mt-6 divide-y divide-[var(--pt-line)]">
                                {help.map(([title, line, href]) => (
                                    <li key={title}>
                                        <Link href={href} className="flex items-center justify-between gap-4 py-4">
                                            <span>
                                                <span className="block text-lg">{title}</span>
                                                <span className="block text-sm text-[var(--pt-muted)]">{line}</span>
                                            </span>
                                            <ArrowUpRight aria-hidden className="size-5 shrink-0" strokeWidth={1.6} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="art col-span-5 flex flex-col justify-between gap-8 rounded-[1.75rem] bg-[var(--art-night)] p-8 text-[var(--pt-paper)]">
                            <div>
                                <h2 style={DISPLAY} className="text-4xl font-normal text-balance">
                                    {t('Sell with us')}
                                </h2>
                                <p className="mt-4 opacity-80">
                                    {t(
                                        'Have something well made? Open a shop and reach buyers across Bangladesh. We take the payment and the delivery off your hands.',
                                    )}
                                </p>
                            </div>
                            <Link
                                href="/sell"
                                className="inline-flex h-12 w-fit items-center gap-2 rounded-full bg-[var(--art-gold-2)] px-7 font-medium text-[var(--art-night)]"
                            >
                                {t('Open a shop')}
                                <ArrowUpRight aria-hidden className="size-5" strokeWidth={1.8} />
                            </Link>
                        </div>
                    </section>

                    {/* The shop's own words. */}
                    <section aria-label={t('Small shops, plain prices')} className="reveal mt-28 grid grid-cols-12 items-center gap-16">
                        <div className="col-span-5">
                            <Title>{t('Small shops, plain prices')}</Title>
                            <p className="mt-8 max-w-md text-lg leading-relaxed text-[var(--pt-muted)]">
                                {t(
                                    'Every product comes from a seller we have checked. The price on the page already has our share in it, so nothing is added at the end.',
                                )}
                            </p>
                            <Link href={route('market.index')} className="mt-8 inline-flex items-center gap-1 underline underline-offset-4">
                                {t('Browse the market')}
                                <ArrowUpRight aria-hidden className="size-4" strokeWidth={1.6} />
                            </Link>
                        </div>
                        <div className="col-span-7">
                            <div className="overflow-hidden rounded-t-full rounded-b-[2rem] border border-[var(--art-gold-2)] p-2">
                                <img
                                    src="/images/home/custom-1280.webp"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                    className="aspect-[16/11] w-full rounded-t-full rounded-b-[1.5rem] object-cover"
                                />
                            </div>
                        </div>
                    </section>

                    {/* Questions people ask before they buy. */}
                    <section aria-label={t('Questions')} className="reveal mt-28 grid grid-cols-12 gap-16">
                        <div className="col-span-4">
                            <Title>{t('Questions')}</Title>
                            <Link href="/faq" className="mt-8 inline-flex items-center gap-1 underline underline-offset-4">
                                {t('All questions and answers')}
                                <ArrowUpRight aria-hidden className="size-4" strokeWidth={1.6} />
                            </Link>
                        </div>
                        <div className="col-span-8 divide-y divide-[var(--pt-line)] border-y border-[var(--pt-line)]">
                            {faq.map(([q, answer]) => (
                                <details key={q} className="group py-5">
                                    <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-lg">
                                        {q}
                                        <span
                                            aria-hidden
                                            className="grid size-8 shrink-0 place-items-center rounded-full border border-[var(--pt-ink)] text-xl leading-none group-open:hidden"
                                        >
                                            +
                                        </span>
                                        <span
                                            aria-hidden
                                            className="hidden size-8 shrink-0 place-items-center rounded-full bg-[var(--pt-ink)] text-xl leading-none text-[var(--pt-paper)] group-open:grid"
                                        >
                                            -
                                        </span>
                                    </summary>
                                    <p className="mt-3 max-w-xl text-[var(--pt-muted)]">{answer}</p>
                                </details>
                            ))}
                        </div>
                    </section>
                </div>

                {/* Last word: a dark card on paper, with three arches of what is on the shelves. */}
                <section aria-label={t('Start shopping')} className="reveal mx-auto w-full max-w-6xl px-4 pb-4">
                    <div className="art relative isolate grid grid-cols-12 items-center gap-12 overflow-hidden rounded-[2rem] bg-[var(--art-night)] px-14 py-20 text-[var(--pt-paper)]">
                        <Rosette className="absolute top-1/2 -right-32 -z-10 size-[34rem] -translate-y-1/2 opacity-25" />
                        <div className="col-span-7">
                            <DiamondBand className="max-w-40" />
                            <p style={DISPLAY} className="mt-6 max-w-2xl text-6xl leading-[1.05] font-normal tracking-tight text-balance">
                                {t('Start with something good.')}
                            </p>
                            <p className="mt-5 max-w-md text-lg opacity-80">
                                {t('From a chair to a medicine: pick what you need, and pay when it reaches you.')}
                            </p>
                            <div className="mt-9 flex flex-wrap gap-3">
                                <Link
                                    href={route('market.index')}
                                    className="art-btn inline-flex h-14 items-center gap-2 rounded-full bg-[var(--art-gold-2)] px-9 font-medium text-[var(--art-night)]"
                                >
                                    {t('Browse the market')}
                                    <ArrowUpRight aria-hidden className="size-5" strokeWidth={1.8} />
                                </Link>
                                <Link href="/sell" className="inline-flex h-14 items-center rounded-full border border-[var(--art-gold-2)] px-8">
                                    {t('Open a shop')}
                                </Link>
                            </div>
                        </div>
                        <ul aria-hidden className="col-span-5 grid grid-cols-3 items-start gap-3">
                            {aisles
                                .filter((a) => a.image)
                                .slice(1, 3)
                                .concat([{ slug: 'pharmacy', image: '/images/home/pharmacy-720.webp' } as (typeof aisles)[number]])
                                .map((a, i) => (
                                    <li key={a.slug} className={i === 1 ? 'mt-10' : ''}>
                                        <div className="rounded-t-full border border-[var(--art-gold-2)] p-1.5">
                                            <img
                                                src={a.image ?? ''}
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                                className="aspect-[3/5] w-full rounded-t-full object-cover"
                                            />
                                        </div>
                                    </li>
                                ))}
                        </ul>
                    </div>
                </section>

                <Testimonials voices={testimonials} />
            </div>
        </PublicLayout>
    );
}
