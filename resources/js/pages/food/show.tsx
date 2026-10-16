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
    ready_made: boolean;
    cook_minutes: number;
}

interface Props {
    restaurant: { id: number; name: string; description: string | null; area: string; open: boolean; hours: string; eat_there: boolean; address: string | null; manual_charge: boolean; advance_over: number | null; advance_percent: number };
    items: Item[];
    area: string;
    serves: boolean;
    charge: number | null;
    eatOptions: number[];
    me: { name: string; phone: string | null } | null;
    custom: CustomMenu;
    favorites: Favorite[];
}

/** A restaurant's menu: only what can be ordered now. The cart and the checkout are on this one page. */
export default function FoodShow({ restaurant, items, area, serves, charge, eatOptions, me, custom, favorites }: Props) {
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [cart, setCart] = useState<Record<number, { quantity: number; note: string }>>({});
    const [mode, setMode] = useState<'delivery' | 'eat_there'>('delivery');
    const [f, setF] = useState({ name: me?.name ?? '', phone: me?.phone ?? '', area, address: '', how_to_find: '', eat_time: '', note: '' });
    const [built, setBuilt] = useState<CustomLine[]>([]);
    const lines = items.filter((i) => cart[i.id]?.quantity > 0);
    const subtotal = lines.reduce((sum, i) => sum + i.price * cart[i.id].quantity, 0) + built.reduce((sum, b) => sum + customPrice(custom, b) * b.quantity, 0);
    const delivery = mode === 'delivery' ? (restaurant.manual_charge ? null : charge) : 0;
    const total = subtotal + (delivery ?? 0);
    const advance = restaurant.advance_over !== null && restaurant.advance_percent > 0 && total >= restaurant.advance_over ? (total * restaurant.advance_percent) / 100 : 0;
    const cooking = Math.max(0, ...lines.map((i) => i.cook_minutes), ...built.map((b) => (custom.bases.find((x) => x.id === b.base_id)?.cook_minutes ?? 0) + custom.extra));
    const bump = (id: number, by: number) => setCart((c) => ({ ...c, [id]: { quantity: Math.max(0, Math.min(50, (c[id]?.quantity ?? 0) + by)), note: c[id]?.note ?? '' } }));
    const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setF({ ...f, [k]: e.target.value });

    const send = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(`/food/r/${restaurant.id}/order`, { mode, lines: [...lines.map((i) => ({ item_id: i.id, quantity: cart[i.id].quantity, note: cart[i.id].note })), ...built.map((b) => ({ base_id: b.base_id, ingredient_ids: b.ingredient_ids, quantity: b.quantity, note: b.note }))], ...f, ...(mode === 'eat_there' ? { area: '', address: '' } : {}) });
    };

    return (
        <PublicLayout>
            <Head title={restaurant.name} />
            <div className="mx-auto max-w-3xl space-y-6">
                <Link href="/food" className="text-sm underline underline-offset-4">
                    {t('All restaurants')}
                </Link>
                <div>
                    <h1 className="font-display text-3xl font-extrabold">{restaurant.name}</h1>
                    {restaurant.description && <p className="mt-1 text-[var(--pt-muted)]">{restaurant.description}</p>}
                    <p className="mt-1 text-sm">
                        <span className={`rounded-full px-2 py-0.5 font-semibold ${restaurant.open ? 'bg-emerald-100 text-emerald-900' : 'bg-muted'}`}>{restaurant.open ? t('Open now') : t('Closed now')}</span> {restaurant.hours}
                    </p>
                </div>
                {!restaurant.open && <p role="alert" className="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">{t('This restaurant is closed now. You can look at the menu, but you cannot order.')}</p>}
                {area !== '' && !serves && <p role="alert" className="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">{t('This restaurant does not deliver to {area}. You can still order to eat there.', { area })}</p>}

                <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                    {items.length === 0 && <li className="p-8 text-center text-[var(--pt-muted)]">{t('Nothing on the menu right now.')}</li>}
                    {items.map((i) => (
                        <li key={i.id} className="flex gap-3 p-3">
                            {i.photo && <img src={i.photo} alt="" className="size-20 rounded-xl object-cover" />}
                            <div className="min-w-0 flex-1">
                                <p className="font-semibold">{i.name}</p>
                                {i.description && <p className="text-sm text-[var(--pt-muted)]">{i.description}</p>}
                                <p className="tabular text-sm font-semibold">{money(i.price)}</p>
                                <p className="text-xs text-[var(--pt-muted)]">{i.ready_made ? t('Ready made') : t('About {n} minutes to cook', { n: num(i.cook_minutes) })}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                <Button type="button" size="icon" variant="outline" aria-label={t('One less')} disabled={!cart[i.id]?.quantity} onClick={() => bump(i.id, -1)}>
                                    <Minus className="size-4" />
                                </Button>
                                <span className="tabular w-6 text-center font-semibold">{num(cart[i.id]?.quantity ?? 0)}</span>
                                <Button type="button" size="icon" variant="outline" aria-label={t('One more')} disabled={!restaurant.open} onClick={() => bump(i.id, 1)}>
                                    <Plus className="size-4" />
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>

                <FoodBuilder restaurantId={restaurant.id} menu={custom} favorites={favorites} signedIn={me !== null} open={restaurant.open} onAdd={(l) => setBuilt((b) => [...b, { ...l, key: Date.now() + b.length }])} />

                {(lines.length > 0 || built.length > 0) && restaurant.open && (
                    <form onSubmit={send} className="space-y-4 rounded-2xl border bg-[var(--pt-surface)] p-4">
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
                                    <Input aria-label={t('A note for the kitchen')} className="h-8" maxLength={120} placeholder={t('A note for the kitchen, for example: no onion')} value={cart[i.id].note} onChange={(e) => setCart({ ...cart, [i.id]: { ...cart[i.id], note: e.target.value } })} />
                                </li>
                            ))}
                        </ul>

                        {built.map((b) => {
                            const base = custom.bases.find((x) => x.id === b.base_id);

                            return (
                                <div key={b.key} className="space-y-1 rounded-xl bg-muted/40 p-2 text-sm">
                                    <div className="flex justify-between font-medium">
                                        <span>
                                            {num(b.quantity)} × {base?.name} ({t('your own')})
                                        </span>
                                        <span className="tabular">{money(customPrice(custom, b) * b.quantity)}</span>
                                    </div>
                                    <p className="text-xs text-[var(--pt-muted)]">{b.ingredient_ids.map((id) => custom.ingredients.find((i) => i.id === id)?.name).filter(Boolean).join(', ') || t('Plain')}</p>
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
                                </>
                            )}
                        </div>

                        {mode === 'eat_there' && (
                            <div className="space-y-1 text-sm">
                                <p className="font-medium">{t('When will you come? (optional)')}</p>
                                <div className="flex flex-wrap gap-2">
                                    {eatOptions.map((n) => (
                                        <Button key={n} type="button" size="sm" variant={f.eat_time === t('In {n} minutes', { n: num(n) }) ? 'default' : 'outline'} onClick={() => setF({ ...f, eat_time: f.eat_time === t('In {n} minutes', { n: num(n) }) ? '' : t('In {n} minutes', { n: num(n) }) })}>
                                            {t('In {n} minutes', { n: num(n) })}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}

                        <label className="block text-sm font-medium">
                            {t('Anything else?')}
                            <textarea value={f.note} maxLength={300} rows={2} onChange={set('note')} className="mt-1 block w-full rounded-md border bg-background p-2 text-sm" />
                        </label>

                        <dl className="tabular space-y-1 text-sm">
                            <div className="flex justify-between">
                                <dt>{t('Food')}</dt>
                                <dd>{money(subtotal)}</dd>
                            </div>
                            {mode === 'delivery' && (
                                <div className="flex justify-between">
                                    <dt>{t('Delivery charge')}</dt>
                                    <dd>{delivery === null ? t('The restaurant will tell you') : money(delivery)}</dd>
                                </div>
                            )}
                            <div className="flex justify-between border-t pt-2 font-display text-lg font-bold">
                                <dt>{t('Total')}</dt>
                                <dd>{money(total)}</dd>
                            </div>
                        </dl>
                        {advance > 0 && <p className="rounded-md bg-amber-50 p-2 text-sm text-amber-900">{t('This is a big order. The restaurant asks for an advance of {amount} before it starts.', { amount: money(advance) })}</p>}
                        {mode === 'delivery' && cooking > 0 && <p className="text-xs text-[var(--pt-muted)]">{t('Cooking takes about {n} minutes, then delivery.', { n: num(cooking) })}</p>}
                        <InputError message={errors.order} />
                        <Button type="submit" className="h-12 w-full text-base">
                            {t('Place the order')}
                        </Button>
                    </form>
                )}
            </div>
        </PublicLayout>
    );
}
