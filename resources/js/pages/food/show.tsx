import FoodBuilder, { customPrice, type CustomLine, type CustomMenu, type Favorite } from '@/components/food-builder';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';

interface Item {
    id: number;
    name: string;
    description: string | null;
    price: number;
    photo: string | null;
    category?: string | null;
    ready_made: boolean;
    cook_minutes: number;
}

interface Props {
    restaurant: {
        id: number;
        name: string;
        description: string | null;
        area: string;
        photo?: string | null;
        open: boolean;
        hours: string;
        eat_there: boolean;
        address: string | null;
        manual_charge: boolean;
        by_distance: boolean;
        needs_location: boolean;
        max_km: number | null;
        per_km: number;
        advance_over: number | null;
        advance_percent: number;
    };
    items: Item[];
    area: string;
    serves: boolean;
    charge: number | null;
    distance: number | null;
    by_road: boolean;
    too_far: boolean;
    eatOptions: number[];
    me: { name: string; phone: string | null } | null;
    custom: CustomMenu;
    favorites: Favorite[];
}

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };
const slug = (g: string) => encodeURIComponent(g.toLowerCase().replace(/\s+/g, '-'));

/** A restaurant's menu: only what can be ordered now. The cart and the checkout are on this one page. */
export default function FoodShow({ restaurant, items, area, serves, charge, distance, by_road, too_far, eatOptions, me, custom, favorites }: Props) {
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [cart, setCart] = useState<Record<number, { quantity: number; note: string }>>({});
    const [mode, setMode] = useState<'delivery' | 'eat_there'>('delivery');
    const [f, setF] = useState({ name: me?.name ?? '', phone: me?.phone ?? '', area, address: '', how_to_find: '', eat_time: '', note: '' });
    const [built, setBuilt] = useState<CustomLine[]>([]);
    // The phone's own position, sent to work out the distance; it is not kept.
    const [pos, setPos] = useState<{ lat: number; lng: number } | null>(null);
    const [locating, setLocating] = useState(false);
    const [geoError, setGeoError] = useState<string | null>(null);
    const needsPos = mode === 'delivery' && restaurant.needs_location;
    const blocked = needsPos && (pos === null || too_far);

    const locate = () => {
        if (!navigator.geolocation) {
            setGeoError(t('This phone cannot share its position.'));

            return;
        }

        setLocating(true);
        setGeoError(null);
        navigator.geolocation.getCurrentPosition(
            (p) => {
                const here = { lat: Math.round(p.coords.latitude * 1e5) / 1e5, lng: Math.round(p.coords.longitude * 1e5) / 1e5 };
                setPos(here);
                router.get(window.location.pathname, { ...here, ...(area ? { area } : {}) }, { preserveState: true, preserveScroll: true, replace: true, only: ['charge', 'distance', 'too_far'], onFinish: () => setLocating(false) });
            },
            () => {
                setLocating(false);
                setGeoError(t('We could not get your position. Allow location for this site and try again.'));
            },
            { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 },
        );
    };
    const lines = items.filter((i) => cart[i.id]?.quantity > 0);
    // The menu in its own groups, in the order they first appear; a dish with no group sits under "Menu".
    const groups = Object.entries(items.reduce<Record<string, Item[]>>((all, i) => ((all[i.category || t('Menu')] ??= []).push(i), all), {}));
    const count = lines.reduce((n, i) => n + cart[i.id].quantity, 0) + built.reduce((n, b) => n + b.quantity, 0);
    const subtotal =
        lines.reduce((sum, i) => sum + i.price * cart[i.id].quantity, 0) + built.reduce((sum, b) => sum + customPrice(custom, b) * b.quantity, 0);
    const delivery = mode === 'delivery' ? (restaurant.manual_charge ? null : charge) : 0;
    const total = subtotal + (delivery ?? 0);
    const advance =
        restaurant.advance_over !== null && restaurant.advance_percent > 0 && total >= restaurant.advance_over
            ? (total * restaurant.advance_percent) / 100
            : 0;
    const cooking = Math.max(
        0,
        ...lines.map((i) => i.cook_minutes),
        ...built.map((b) => (custom.bases.find((x) => x.id === b.base_id)?.cook_minutes ?? 0) + custom.extra),
    );
    const bump = (id: number, by: number) =>
        setCart((c) => ({ ...c, [id]: { quantity: Math.max(0, Math.min(50, (c[id]?.quantity ?? 0) + by)), note: c[id]?.note ?? '' } }));
    const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setF({ ...f, [k]: e.target.value });

    const send = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(`/food/r/${restaurant.id}/order`, {
            mode,
            lines: [
                ...lines.map((i) => ({ item_id: i.id, quantity: cart[i.id].quantity, note: cart[i.id].note })),
                ...built.map((b) => ({ base_id: b.base_id, ingredient_ids: b.ingredient_ids, quantity: b.quantity, note: b.note })),
            ],
            ...f,
            ...(mode === 'eat_there' ? { area: '', address: '' } : pos ? pos : {}),
        });
    };

    return (
        <PublicLayout>
            <Head title={restaurant.name} />
            <div className="kfc mx-auto max-w-5xl space-y-8">
                <Link
                    href="/food"
                    className="art-btn inline-flex h-10 items-center rounded-full border-2 border-[var(--k-ink)] px-5 text-sm font-semibold"
                >
                    {t('All restaurants')}
                </Link>
                <header className="relative isolate -mx-4 overflow-hidden bg-[var(--k-red)] text-white sm:mx-0 sm:rounded-[2rem]">
                    {restaurant.photo && (
                        <img
                            src={restaurant.photo}
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
                    <div className="px-6 py-12 sm:px-10 sm:py-16">
                        <h1 style={DISPLAY} className="art-rise max-w-xl text-5xl leading-[0.95] tracking-tight text-balance sm:text-7xl">
                            {restaurant.name}
                        </h1>
                        {restaurant.description && <p className="art-rise art-d1 mt-3 max-w-md text-lg text-white/90">{restaurant.description}</p>}
                        <p className="art-rise art-d2 mt-5 flex flex-wrap items-center gap-3 text-sm">
                            <span
                                className={`rounded-full px-3 py-1 font-semibold ${restaurant.open ? 'bg-white text-[var(--k-red)]' : 'bg-[var(--k-red-d)] text-white'}`}
                            >
                                {restaurant.open ? t('Open now') : t('Closed now')}
                            </span>
                            <span>{restaurant.hours}</span>
                            {restaurant.area && <span className="text-white/80">{restaurant.area}</span>}
                        </p>
                    </div>
                </header>
                {!restaurant.open && (
                    <p role="alert" className="rounded-[1.5rem] border-2 border-[var(--k-red)] px-4 py-3 text-sm">
                        {t('This restaurant is closed now. You can look at the menu, but you cannot order.')}
                    </p>
                )}
                {area !== '' && !serves && (
                    <p role="alert" className="rounded-[1.5rem] border-2 border-[var(--k-red)] px-4 py-3 text-sm">
                        {t('This restaurant does not deliver to {area}. You can still order to eat there.', { area })}
                    </p>
                )}

                {items.length === 0 && (
                    <p className="rounded-[1.5rem] border-2 border-dashed border-[var(--k-red)] p-10 text-center text-[var(--k-muted)]">
                        {t('Nothing on the menu right now.')}
                    </p>
                )}

                {groups.length > 1 && (
                    <nav
                        aria-label={t('Menu groups')}
                        className="no-scrollbar sticky top-16 z-20 -mx-4 flex gap-2 overflow-x-auto bg-white/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-full sm:px-3"
                    >
                        {groups.map(([g]) => (
                            <a
                                key={g}
                                href={`#menu-${slug(g)}`}
                                className="shrink-0 rounded-full border-2 border-[var(--k-ink)] px-5 py-1.5 text-sm font-semibold hover:bg-[var(--k-red)] hover:text-white"
                            >
                                {g}
                            </a>
                        ))}
                    </nav>
                )}

                {groups.map(([g, list]) => (
                    <section key={g} id={`menu-${slug(g)}`} aria-label={g} className="scroll-mt-32">
                        <h2 style={DISPLAY} className="text-3xl leading-none tracking-tight sm:text-4xl">
                            {g}
                        </h2>
                        <ul className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {list.map((i) => {
                                const q = cart[i.id]?.quantity ?? 0;

                                return (
                                    <li
                                        key={i.id}
                                        className="flex flex-col overflow-hidden rounded-[1.5rem] border-2 border-[var(--k-line)] bg-white"
                                    >
                                        <span className="art-zoom block aspect-[4/3] bg-[var(--k-bg)]">
                                            {i.photo && (
                                                <img src={i.photo} alt="" loading="lazy" decoding="async" className="size-full object-cover" />
                                            )}
                                        </span>
                                        <div className="flex flex-1 flex-col p-4">
                                            <p style={DISPLAY} className="text-xl leading-tight">
                                                {i.name}
                                            </p>
                                            {i.description && <p className="mt-1 line-clamp-3 text-sm text-[var(--k-muted)]">{i.description}</p>}
                                            <p className="mt-1 text-xs text-[var(--k-muted)]">
                                                {i.ready_made ? t('Ready made') : t('About {n} minutes to cook', { n: num(i.cook_minutes) })}
                                            </p>
                                            <div className="mt-auto flex items-center justify-between gap-3 pt-4">
                                                <span style={DISPLAY} className="tabular text-2xl text-[var(--k-red)]">
                                                    {money(i.price)}
                                                </span>
                                                {q === 0 ? (
                                                    <button
                                                        type="button"
                                                        disabled={!restaurant.open}
                                                        onClick={() => bump(i.id, 1)}
                                                        className="art-btn inline-flex h-10 items-center gap-1 rounded-full bg-[var(--k-red)] px-5 font-semibold text-white disabled:opacity-40"
                                                    >
                                                        <Plus aria-hidden className="size-4" strokeWidth={3} />
                                                        {t('Add')}
                                                    </button>
                                                ) : (
                                                    <span className="flex items-center gap-2">
                                                        <button
                                                            type="button"
                                                            aria-label={t('One less')}
                                                            onClick={() => bump(i.id, -1)}
                                                            className="grid size-10 place-items-center rounded-full border-2 border-[var(--k-red)] text-[var(--k-red)]"
                                                        >
                                                            <Minus aria-hidden className="size-4" strokeWidth={3} />
                                                        </button>
                                                        <span className="tabular w-6 text-center font-semibold">{num(q)}</span>
                                                        <button
                                                            type="button"
                                                            aria-label={t('One more')}
                                                            disabled={!restaurant.open}
                                                            onClick={() => bump(i.id, 1)}
                                                            className="grid size-10 place-items-center rounded-full bg-[var(--k-red)] text-white"
                                                        >
                                                            <Plus aria-hidden className="size-4" strokeWidth={3} />
                                                        </button>
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                ))}

                <FoodBuilder
                    restaurantId={restaurant.id}
                    menu={custom}
                    favorites={favorites}
                    signedIn={me !== null}
                    open={restaurant.open}
                    onAdd={(l) => setBuilt((b) => [...b, { ...l, key: Date.now() + b.length }])}
                />

                {(lines.length > 0 || built.length > 0) && restaurant.open && (
                    <form id="order" onSubmit={send} className="scroll-mt-24 space-y-4 rounded-[1.5rem] border-2 border-[var(--k-ink)] bg-white p-5">
                        <h2 className="font-display text-xl font-bold">{t('Your order')}</h2>
                        <ul className="space-y-2 text-sm">
                            {lines.map((i) => (
                                <li key={i.id} className="space-y-1">
                                    <div className="flex justify-between">
                                        <span>
                                            {num(cart[i.id].quantity)} × {i.name}
                                        </span>
                                        <span className="tabular">{money(i.price * cart[i.id].quantity)}</span>
                                    </div>
                                    <Input
                                        aria-label={t('A note for the kitchen')}
                                        className="h-8"
                                        maxLength={120}
                                        placeholder={t('A note for the kitchen, for example: no onion')}
                                        value={cart[i.id].note}
                                        onChange={(e) => setCart({ ...cart, [i.id]: { ...cart[i.id], note: e.target.value } })}
                                    />
                                </li>
                            ))}
                        </ul>

                        {built.map((b) => {
                            const base = custom.bases.find((x) => x.id === b.base_id);

                            return (
                                <div key={b.key} className="bg-muted/40 space-y-1 rounded-[1.25rem] p-2 text-sm">
                                    <div className="flex justify-between font-medium">
                                        <span>
                                            {num(b.quantity)} × {base?.name} ({t('your own')})
                                        </span>
                                        <span className="tabular">{money(customPrice(custom, b) * b.quantity)}</span>
                                    </div>
                                    <p className="text-xs text-[var(--pt-muted)]">
                                        {b.ingredient_ids
                                            .map((id) => custom.ingredients.find((i) => i.id === id)?.name)
                                            .filter(Boolean)
                                            .join(', ') || t('Plain')}
                                    </p>
                                    {b.note && <p className="text-xs">{b.note}</p>}
                                    <Button type="button" size="sm" variant="ghost" onClick={() => setBuilt(built.filter((x) => x.key !== b.key))}>
                                        {t('Remove')}
                                    </Button>
                                </div>
                            );
                        })}
                        <div className="flex flex-wrap gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                <input type="radio" checked={mode === 'delivery'} onChange={() => setMode('delivery')} /> {t('Home delivery')}
                            </label>
                            {restaurant.eat_there && (
                                <label className="flex items-center gap-2">
                                    <input type="radio" checked={mode === 'eat_there'} onChange={() => setMode('eat_there')} /> {t("I'll eat there")}
                                </label>
                            )}
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-sm font-medium">
                                {t('Your name')}
                                <Input className="mt-1" value={f.name} onChange={set('name')} />
                            </label>
                            <label className="text-sm font-medium">
                                {t('Mobile number')}
                                <Input className="mt-1" inputMode="tel" value={f.phone} onChange={set('phone')} />
                            </label>
                            <InputError message={errors.name ?? errors.phone} />
                            {mode === 'delivery' && (
                                <>
                                    <label className="text-sm font-medium">
                                        {t('Your area')}
                                        <Input className="mt-1" value={f.area} onChange={set('area')} />
                                    </label>
                                    <label className="text-sm font-medium">
                                        {t('Address')}
                                        <Input className="mt-1" value={f.address} onChange={set('address')} />
                                    </label>
                                    <label className="text-sm font-medium sm:col-span-2">
                                        {t('How to find you (optional)')}
                                        <Input className="mt-1" value={f.how_to_find} onChange={set('how_to_find')} />
                                    </label>
                                    {needsPos && (
                                        <div className="space-y-2 rounded-xl border p-3 text-sm sm:col-span-2">
                                            <p>
                                                {restaurant.by_distance
                                                    ? t('This restaurant charges {amount} for each kilometre.', { amount: money(restaurant.per_km) })
                                                    : t('This restaurant delivers within {n} km.', { n: num(restaurant.max_km ?? 0) })}
                                                {restaurant.by_distance && restaurant.max_km !== null && ` ${t('It delivers within {n} km.', { n: num(restaurant.max_km) })}`}
                                            </p>
                                            <Button type="button" variant="outline" size="sm" disabled={locating} aria-busy={locating} onClick={locate}>
                                                {locating ? t('Finding you…') : pos ? t('Use my position again') : t('Use my position')}
                                            </Button>
                                            {pos && distance !== null && !too_far && <p role="status">{by_road ? t('You are about {km} km away by road.', { km: num(distance) }) : t('You are about {km} km away (an estimate).', { km: num(distance) })}</p>}
                                            {too_far && distance !== null && (
                                                <p role="alert" className="font-semibold text-destructive">
                                                    {t('You are about {km} km away, which is further than this restaurant delivers.', { km: num(distance) })}
                                                </p>
                                            )}
                                            {geoError && (
                                                <p role="alert" className="text-destructive">
                                                    {geoError}
                                                </p>
                                            )}
                                            {!pos && !geoError && <p className="text-[var(--pt-muted)]">{t('Share your position once so we can work out the distance. It is not kept.')}</p>}
                                        </div>
                                    )}
                                </>
                            )}
                        </div>

                        {mode === 'eat_there' && (
                            <div className="space-y-1 text-sm">
                                <p className="font-medium">{t('When will you come? (optional)')}</p>
                                <div className="flex flex-wrap gap-2">
                                    {eatOptions.map((n) => (
                                        <Button
                                            key={n}
                                            type="button"
                                            size="sm"
                                            variant={f.eat_time === t('In {n} minutes', { n: num(n) }) ? 'default' : 'outline'}
                                            onClick={() =>
                                                setF({
                                                    ...f,
                                                    eat_time:
                                                        f.eat_time === t('In {n} minutes', { n: num(n) }) ? '' : t('In {n} minutes', { n: num(n) }),
                                                })
                                            }
                                        >
                                            {t('In {n} minutes', { n: num(n) })}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}

                        <label className="block text-sm font-medium">
                            {t('Anything else?')}
                            <textarea
                                value={f.note}
                                maxLength={300}
                                rows={2}
                                onChange={set('note')}
                                className="bg-background mt-1 block w-full rounded-md border p-2 text-sm"
                            />
                        </label>

                        <dl className="tabular space-y-1 text-sm">
                            <div className="flex justify-between">
                                <dt>{t('Food')}</dt>
                                <dd>{money(subtotal)}</dd>
                            </div>
                            {mode === 'delivery' && (
                                <div className="flex justify-between">
                                    <dt>{t('Delivery charge')}</dt>
                                    <dd>{delivery === null ? (restaurant.by_distance ? t('Shown when we know where you are') : t('The restaurant will tell you')) : money(delivery)}</dd>
                                </div>
                            )}
                            <div className="font-display flex justify-between border-t pt-2 text-lg font-bold">
                                <dt>{t('Total')}</dt>
                                <dd>{money(total)}</dd>
                            </div>
                        </dl>
                        {advance > 0 && (
                            <p className="rounded-md bg-[var(--k-bg)] p-2 text-sm text-[var(--k-ink)]">
                                {t('This is a big order. The restaurant asks for an advance of {amount} before it starts.', {
                                    amount: money(advance),
                                })}
                            </p>
                        )}
                        {mode === 'delivery' && cooking > 0 && (
                            <p className="text-xs text-[var(--pt-muted)]">
                                {t('Cooking takes about {n} minutes, then delivery.', { n: num(cooking) })}
                            </p>
                        )}
                        <InputError message={errors.order} />
                        <Button type="submit" disabled={blocked} className="h-12 w-full text-base">
                            {t('Place the order')}
                        </Button>
                    </form>
                )}
            </div>

            {count > 0 && restaurant.open && (
                <a
                    href="#order"
                    className="kfc art-btn fixed inset-x-4 bottom-24 z-30 mx-auto flex h-14 max-w-md items-center justify-between rounded-full bg-[var(--k-red)] px-6 text-white shadow-[0_12px_30px_-8px_rgba(0,0,0,0.5)] md:bottom-6"
                >
                    <span className="font-semibold">{t('{n} in your order', { n: num(count) })}</span>
                    <span style={DISPLAY} className="tabular text-xl">
                        {money(subtotal)}
                    </span>
                </a>
            )}
        </PublicLayout>
    );
}
