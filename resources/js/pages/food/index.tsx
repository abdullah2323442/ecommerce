import { DISPLAY, FaqList, type Front, type FrontBlock, SectionHead, ServiceStrip, StepsList } from '@/components/storefront/shared';
import LocateButton from '@/components/locate-button';
import RequestButton from '@/components/requests/request-button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, Bike, Store, X } from 'lucide-react';
import { useState } from 'react';

interface Card {
    id: number;
    name: string;
    description: string | null;
    area: string;
    open: boolean;
    photo: string | null;
    hours: string;
}

interface Dish {
    id: number;
    restaurant: number;
    restaurant_name: string;
    name: string;
    description: string | null;
    category: string | null;
    price: number;
    photo: string | null;
}

interface Props {
    area: string;
    mode: 'delivery' | 'dine';
    restaurants: Card[];
    areas: string[];
    /** The page the admin built (Admin > Shop fronts), or null for the plain layout. */
    front: (Front & { category: string | null }) | null;
}

/**
 * Food, set out the way a fast-food chain's own site is: a loud red opening that asks how you want your meal (brought to the door or eaten there), then
 * what to eat, the dishes, and the restaurants. Delivery needs an area (it decides who can bring it); eating there does not. The sections, their order and
 * all their words come from the admin.
 */
export default function FoodIndex({ area, mode, restaurants, areas, front }: Props) {
    const { t, money, num } = useI18n();
    const [value, setValue] = useState(area);
    const category = front?.category ?? null;

    const pick = (next: 'delivery' | 'dine') =>
        router.get('/food', next === 'dine' ? { mode: 'dine' } : { area: value || undefined }, { preserveState: true, replace: true });

    const blocks: FrontBlock[] = front?.blocks ?? [
        {
            id: 0,
            kind: 'hero',
            title: t('Hungry? Let us bring it.'),
            text: t('Fast food and biryani from restaurants near you. Choose how you want your meal.'),
            button: null,
        },
        { id: -1, kind: 'restaurants', title: t('Restaurants'), text: null, button: null, restaurants },
    ];

    const hero = (b: FrontBlock) => {
        const dish = b.dish as Dish | null | undefined;
        const picture = b.image ?? dish?.photo ?? null;

        return (
            <section
                key={b.id}
                aria-labelledby="food-title"
                className="relative isolate -mx-4 overflow-hidden bg-[var(--k-red)] text-white sm:mx-0 sm:rounded-[2rem]"
            >
                {picture && (
                    <img
                        src={picture}
                        alt=""
                        fetchPriority="high"
                        decoding="async"
                        className="absolute inset-y-0 right-0 -z-20 h-full w-full object-cover sm:w-3/5 sm:[mask-image:linear-gradient(to_right,transparent,black_40%)]"
                    />
                )}
                <span
                    aria-hidden
                    className="absolute inset-0 -z-10 bg-gradient-to-r from-[var(--k-red)] via-[var(--k-red)]/85 to-[var(--k-red)]/20 sm:via-[var(--k-red)]/60"
                />
                <div className="px-6 py-12 sm:px-12 sm:py-20">
                    <h1
                        id="food-title"
                        style={DISPLAY}
                        className="art-rise max-w-xl text-5xl leading-[0.95] font-normal tracking-tight text-balance sm:text-7xl"
                    >
                        {b.title}
                    </h1>
                    {b.text && <p className="art-rise art-d1 mt-4 max-w-md text-lg text-white/90">{b.text}</p>}

                    <div className="art-rise art-d2 mt-8 grid max-w-md grid-cols-2 gap-3" role="group" aria-label={t('How do you want your meal?')}>
                        {(
                            [
                                ['delivery', t('Delivery'), Bike],
                                ['dine', t('Eat there'), Store],
                            ] as const
                        ).map(([k, label, Icon]) => (
                            <button
                                key={k}
                                type="button"
                                aria-pressed={mode === k}
                                onClick={() => pick(k)}
                                style={DISPLAY}
                                className={`flex h-14 items-center justify-center gap-2 rounded-2xl text-xl shadow-[0_6px_0_0_var(--k-red-d)] transition-transform duration-200 active:translate-y-1 active:shadow-[0_2px_0_0_var(--k-red-d)] ${mode === k ? 'bg-white text-[var(--k-red)]' : 'border-2 border-white/80 bg-[var(--k-red-d)]/50 text-white hover:bg-[var(--k-red-d)]/80'}`}
                            >
                                <Icon aria-hidden className="size-5" strokeWidth={2} />
                                {label}
                            </button>
                        ))}
                    </div>

                    {mode === 'delivery' && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get('/food', { area: value }, { preserveState: true });
                            }}
                            className="art-rise mt-5 flex max-w-md items-center gap-2 rounded-full bg-white p-1.5 pl-5 text-[var(--k-ink)]"
                        >
                            <label className="sr-only" htmlFor="food-area">
                                {t('Your area')}
                            </label>
                            <input
                                id="food-area"
                                list="food-areas"
                                value={value}
                                maxLength={80}
                                placeholder={t('Your area, for example Mirpur')}
                                onChange={(e) => setValue(e.target.value)}
                                className="h-11 min-w-0 flex-1 bg-transparent outline-none placeholder:text-[var(--k-muted)]"
                            />
                            <datalist id="food-areas">
                                {areas.map((a) => (
                                    <option key={a} value={a} />
                                ))}
                            </datalist>
                            <button
                                type="submit"
                                disabled={value.trim() === ''}
                                className="art-btn h-11 shrink-0 rounded-full bg-[var(--k-red)] px-6 font-semibold text-white disabled:opacity-40"
                            >
                                {t('Show restaurants')}
                            </button>
                        </form>
                    )}
                    {mode === 'delivery' && (
                        <div className="mt-3 max-w-md rounded-xl bg-white p-2 text-[var(--k-ink)]">
                            <LocateButton
                                onFound={(f) => {
                                    const place = f.area || f.district || '';
                                    setValue(place);
                                    if (place !== '') {
                                        router.get('/food', { area: place }, { preserveState: true });
                                    }
                                }}
                            />
                        </div>
                    )}
                </div>
            </section>
        );
    };

    const dishCard = (d: Dish) => (
        <li key={d.id}>
            <Link
                href={`/food/r/${d.restaurant}`}
                className="art-lift group flex h-full flex-col overflow-hidden rounded-[1.5rem] border-2 border-[var(--k-line)] bg-white hover:border-[var(--k-red)]"
            >
                <span className="art-zoom block aspect-[4/3] bg-[var(--k-bg)]">
                    {d.photo && <img src={d.photo} alt="" loading="lazy" decoding="async" className="size-full object-cover" />}
                </span>
                <span className="flex flex-1 flex-col p-4">
                    {d.category && <span className="text-xs text-[var(--k-muted)]">{d.category}</span>}
                    <span style={DISPLAY} className="mt-0.5 block text-xl leading-tight">
                        {d.name}
                    </span>
                    <span className="mt-1 text-sm text-[var(--k-muted)]">{d.restaurant_name}</span>
                    <span className="mt-auto flex items-center justify-between pt-4">
                        <span style={DISPLAY} className="tabular text-2xl text-[var(--k-red)]">
                            {money(d.price)}
                        </span>
                        <span className="inline-flex h-9 items-center rounded-full bg-[var(--k-red)] px-4 text-sm font-semibold text-white">
                            {t('Order now')}
                        </span>
                    </span>
                </span>
            </Link>
        </li>
    );

    const section = (b: FrontBlock) => {
        switch (b.kind) {
            case 'hero':
                return hero(b);
            case 'cuisines': {
                const cats = (b.categories as { name: string; count: number; photo: string | null }[]) ?? [];

                return (
                    <section key={b.id} aria-label={b.title ?? undefined}>
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <SectionHead title={b.title} />
                            {category && (
                                <Link
                                    href={mode === 'dine' ? '/food?mode=dine' : '/food'}
                                    className="inline-flex items-center gap-1.5 rounded-full border-2 border-[var(--k-red)] px-4 py-1.5 text-sm font-semibold text-[var(--k-red)]"
                                >
                                    {category}
                                    <X aria-hidden className="size-4" strokeWidth={2.2} />
                                    <span className="sr-only">{t('Show everything')}</span>
                                </Link>
                            )}
                        </div>
                        <div className="art-marquee-wrap no-scrollbar -mx-4 mt-6 overflow-hidden motion-reduce:overflow-x-auto sm:mx-0">
                            <ul className="art-marquee flex w-max">
                                {[...cats, ...cats].map((c, n) => {
                                    const copy = n >= cats.length;

                                    return (
                                        <li key={`${c.name}-${n}`} aria-hidden={copy || undefined} className="w-44 shrink-0 pe-3 sm:w-48">
                                            <Link
                                                href={`/food?category=${encodeURIComponent(c.name)}${mode === 'dine' ? '&mode=dine' : ''}`}
                                                tabIndex={copy ? -1 : undefined}
                                                aria-current={c.name === category ? 'page' : undefined}
                                                className={`art-zoom art-lift relative isolate flex aspect-[3/4] flex-col justify-end overflow-hidden rounded-[1.5rem] bg-[var(--k-ink)] p-4 text-white ${c.name === category ? 'ring-4 ring-[var(--k-red)]' : ''}`}
                                            >
                                                {c.photo && (
                                                    <img
                                                        src={c.photo}
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async"
                                                        className="absolute inset-0 -z-20 size-full object-cover"
                                                    />
                                                )}
                                                <span
                                                    aria-hidden
                                                    className="absolute inset-0 -z-10 bg-gradient-to-t from-black/75 via-transparent to-transparent"
                                                />
                                                <span style={DISPLAY} className="text-2xl leading-tight">
                                                    {c.name}
                                                </span>
                                                <span className="tabular text-xs text-white/80">{t('{n} dishes', { n: num(c.count) })}</span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    </section>
                );
            }
            case 'dishes': {
                const dishes = (b.dishes as Dish[]) ?? [];

                return (
                    <section key={b.id} aria-label={b.title ?? undefined}>
                        <SectionHead title={b.title} text={b.text} />
                        <ul className="mt-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">{dishes.map(dishCard)}</ul>
                    </section>
                );
            }
            case 'banner': {
                const d = b.dish as Dish | undefined;

                if (!d) return null;

                return (
                    <section
                        key={b.id}
                        aria-label={b.title ?? undefined}
                        className="relative isolate -mx-4 overflow-hidden bg-[var(--k-ink)] text-white sm:mx-0 sm:rounded-[2rem]"
                    >
                        {b.image && (
                            <img
                                src={b.image}
                                alt=""
                                loading="lazy"
                                decoding="async"
                                className="absolute inset-y-0 right-0 -z-20 h-full w-full object-cover sm:w-3/5 sm:[mask-image:linear-gradient(to_right,transparent,black_35%)]"
                            />
                        )}
                        <span
                            aria-hidden
                            className="absolute inset-0 -z-10 bg-gradient-to-r from-[var(--k-ink)] via-[var(--k-ink)]/80 to-transparent"
                        />
                        <div className="max-w-md px-6 py-14 sm:px-12 sm:py-20">
                            {b.title && <p className="text-sm text-white/70">{b.title}</p>}
                            <p style={DISPLAY} className="mt-2 text-4xl leading-tight text-balance sm:text-5xl">
                                {d.name}
                            </p>
                            <p className="mt-1 text-white/70">{d.restaurant_name}</p>
                            <p style={DISPLAY} className="tabular mt-4 text-3xl text-[var(--k-red)] brightness-150">
                                {money(d.price)}
                            </p>
                            {b.button && (
                                <Link
                                    href={`/food/r/${d.restaurant}`}
                                    className="art-btn mt-6 inline-flex h-12 items-center gap-2 rounded-full bg-[var(--k-red)] px-7 font-semibold text-white"
                                >
                                    {b.button}
                                    <ArrowUpRight aria-hidden className="size-5" strokeWidth={2} />
                                </Link>
                            )}
                        </div>
                    </section>
                );
            }
            case 'steps':
                return <StepsList key={b.id} title={b.title} items={b.items ?? []} accent="var(--k-red)" />;
            case 'restaurants': {
                const list = (b.restaurants as Card[]) ?? [];

                return (
                    <section key={b.id} aria-label={b.title ?? undefined}>
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <h2 style={DISPLAY} className="text-4xl leading-none text-balance sm:text-5xl">
                                {mode === 'dine'
                                    ? t('Eat there')
                                    : area === ''
                                      ? (b.title ?? t('All restaurants'))
                                      : t('Delivering to {area}', { area })}
                            </h2>
                            <p className="tabular text-sm text-[var(--k-muted)]">{t('{n} restaurants', { n: num(list.length) })}</p>
                        </div>

                        {list.length === 0 ? (
                            <div className="mt-6 space-y-4 rounded-[1.5rem] border-2 border-dashed border-[var(--k-red)] p-10 text-center text-[var(--k-muted)]">
                                <p>{t('No restaurant delivers to this area yet.')}</p>
                                <div className="flex justify-center text-left text-[var(--k-ink)]">
                                    <RequestButton key={area.toLowerCase()} query={area ? t('Food delivery in {area}', { area }) : ''} sector="food" />
                                </div>
                            </div>
                        ) : (
                            <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {list.map((r) => (
                                    <li key={r.id} className={r.open ? '' : 'opacity-60'}>
                                        <Link
                                            href={`/food/r/${r.id}`}
                                            className="art-lift block overflow-hidden rounded-[1.5rem] border-2 border-[var(--k-line)] bg-white hover:border-[var(--k-red)]"
                                        >
                                            <span className="art-zoom block aspect-[16/10] bg-[var(--k-bg)]">
                                                {r.photo && (
                                                    <img src={r.photo} alt="" loading="lazy" decoding="async" className="size-full object-cover" />
                                                )}
                                            </span>
                                            <span className="block p-5">
                                                <span style={DISPLAY} className="block text-2xl leading-tight">
                                                    {r.name}
                                                </span>
                                                {r.description && (
                                                    <span className="mt-1 line-clamp-2 block text-sm text-[var(--k-muted)]">{r.description}</span>
                                                )}
                                                <span className="mt-3 flex items-center gap-2 text-sm">
                                                    <span
                                                        className={`rounded-full px-3 py-1 font-semibold ${r.open ? 'bg-[var(--k-red)] text-white' : 'bg-[var(--k-line)] text-[var(--k-ink)]'}`}
                                                    >
                                                        {r.open ? t('Open now') : t('Closed now')}
                                                    </span>
                                                    <span className="text-[var(--k-muted)]">{r.hours}</span>
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                );
            }
            case 'service':
                return <ServiceStrip key={b.id} items={b.items ?? []} label={t('Why order here')} />;
            case 'faq':
                return <FaqList key={b.id} title={b.title} items={b.items ?? []} />;
            default:
                return null;
        }
    };

    return (
        <PublicLayout>
            <Head title={t('Food')} />
            <div className="kfc mx-auto max-w-5xl space-y-16">{blocks.map(section)}</div>
        </PublicLayout>
    );
}
