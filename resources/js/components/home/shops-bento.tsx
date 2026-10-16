import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';

/**
 * Every shop of the market as one photo bento: the pharmacy (open) leads, the rest say honestly that they
 * are opening soon. Restricted sections are never drawn here (rule G1).
 */
interface Card {
    key: string;
    en: string;
    bn: string;
    blurb: string;
    className: string;
    open?: boolean;
    href?: string;
    image?: string;
}

export default function ShopsBento() {
    const { t, isBn } = useI18n();
    const { site } = usePage<SharedData>().props;

    const all: Card[] = [
        {
            key: 'pharmacy',
            en: 'Pharmacy',
            bn: 'ফার্মেসি',
            blurb: 'Search every medicine and compare brands',
            className: 'lg:col-span-6 lg:row-span-2 min-h-80',
            image: '/images/home/pharmacy',
        },
        {
            key: 'food',
            en: 'Food',
            bn: 'খাবার',
            blurb: 'Fast food from local restaurants',
            className: 'lg:col-span-3 lg:row-span-2 min-h-72',
            image: '/images/home/food',
        },
        {
            key: 'games',
            en: 'Gaming',
            bn: 'গেমিং',
            blurb: 'Game top-ups and diamonds',
            className: 'lg:col-span-3 min-h-60',
            image: '/images/home/gaming',
        },
        {
            key: 'custom',
            en: 'Custom',
            bn: 'মাপমতো',
            blurb: 'Wood and clothing made to your size',
            className: 'lg:col-span-3 min-h-60',
            image: '/images/home/custom',
        },
        {
            key: 'market',
            en: 'The Market',
            bn: 'বাজার',
            blurb: 'Everyday things from many sellers',
            className: 'lg:col-span-6 min-h-60',
            image: '/images/home/bazaar',
        },
        {
            key: 'help',
            en: 'Help board',
            bn: 'সাহায্য',
            blurb: 'Find work, find a hand',
            className: 'lg:col-span-6 min-h-60',
            image: '/images/home/help',
        },
    ];

    // The owner decides which cards show, in what order, and which are open (Admin > Site design).
    const cards: Card[] = site.home.sections
        .flatMap((s): Card[] => {
            const c = all.find((x) => x.key === s.key);

            return c ? [{ ...c, open: s.open, href: s.href ?? undefined }] : [];
        });

    return (
        <ul aria-label={t('The shops')} className="grid gap-4 lg:auto-rows-[15rem] lg:grid-cols-12">
            {cards.map((c) => {
                const name = isBn ? c.bn : c.en;
                const body = (
                    <>
                        {c.image && (
                            <img
                                src={`${c.image}-1280.webp`}
                                srcSet={`${c.image}-720.webp 720w, ${c.image}-1280.webp 1280w`}
                                sizes="(min-width: 1024px) 50vw, 100vw"
                                alt=""
                                loading="lazy"
                                decoding="async"
                                className="absolute inset-0 size-full object-cover transition-transform duration-700 group-hover:scale-105 motion-reduce:transition-none"
                            />
                        )}
                        <div aria-hidden className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent" />
                        <div className="relative mt-auto p-6 text-white sm:p-7">
                            <span
                                className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold backdrop-blur ${c.open ? 'bg-white text-[#0e0d0c]' : 'border border-white/30 bg-black/25'}`}
                            >
                                {c.open && <span aria-hidden className="size-2 rounded-full bg-[#1c9a4d] ring-4 ring-[#1c9a4d]/25" />}
                                {c.open ? t('Open now') : t('Opening soon')}
                            </span>
                            <p lang={isBn ? 'bn' : 'en'} className="font-display mt-3 text-3xl leading-none font-bold sm:text-4xl">
                                {name}
                            </p>
                            <p className="mt-1.5 max-w-xs text-white/85">{t(c.blurb)}.</p>
                        </div>
                        {c.open && (
                            <span className="absolute right-5 bottom-5 flex size-12 items-center justify-center rounded-full bg-white text-[#0e0d0c] shadow-lg transition-transform group-hover:translate-x-1 group-hover:-translate-y-1">
                                <ArrowUpRight aria-hidden className="size-6" />
                            </span>
                        )}
                    </>
                );
                const cls = `group relative isolate flex overflow-hidden rounded-[2rem] ${c.className}`;

                return (
                    <li key={c.key} className="contents">
                        {c.open ? (
                            <Link
                                href={c.href ?? '/'}
                                aria-label={t('{name}, open now. {blurb}', { name, blurb: t(c.blurb) })}
                                className={`${cls} outline-none focus-visible:ring-4 focus-visible:ring-[var(--pt-amber)]`}
                            >
                                {body}
                            </Link>
                        ) : (
                            <div role="group" aria-label={t('{name}, opening soon. {blurb}', { name, blurb: t(c.blurb) })} className={cls}>
                                {body}
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
