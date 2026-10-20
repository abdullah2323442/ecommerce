import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export interface Base {
    id: number;
    kind: string;
    name: string;
    price: number;
    cook_minutes: number;
}

export interface Ingredient {
    id: number;
    name: string;
    search: string;
    group: string;
    price: number;
    sold_out: boolean;
}

export interface CustomMenu {
    bases: Base[];
    ingredients: Ingredient[];
    rules: { a: number; b: number }[];
    max: number;
    extra: number;
}

export interface Favorite {
    id: number;
    name: string;
    base_id: number;
    ingredient_ids: number[];
    price: number | null;
    ok: boolean;
    problem: string | null;
}

export interface CustomLine {
    key: number;
    base_id: number;
    ingredient_ids: number[];
    quantity: number;
    note: string;
}

const GROUPS: Record<string, string> = { meat: 'Meat', cheese: 'Cheese', vegetables: 'Vegetables', sauces: 'Sauces', extras: 'Extras', spices: 'Spices' };

/** The price of a build, the same sum the server makes again when the order is placed. */
export const customPrice = (menu: CustomMenu, line: Pick<CustomLine, 'base_id' | 'ingredient_ids'>) =>
    (menu.bases.find((b) => b.id === line.base_id)?.price ?? 0) + line.ingredient_ids.reduce((sum, id) => sum + (menu.ingredients.find((i) => i.id === id)?.price ?? 0), 0);

/** Build your own (spec ch 17): a base, then ingredients from this restaurant's list at its own prices. The limit and the pairs that do not go together are enforced as you tick. */
export default function FoodBuilder({ restaurantId, menu, favorites, signedIn, open, onAdd }: { restaurantId: number; menu: CustomMenu; favorites: Favorite[]; signedIn: boolean; open: boolean; onAdd: (line: Omit<CustomLine, 'key'>) => void }) {
    const { t, money, num } = useI18n();
    const [baseId, setBaseId] = useState<number>(menu.bases[0]?.id ?? 0);
    const [picked, setPicked] = useState<number[]>([]);
    const [search, setSearch] = useState('');
    const [qty, setQty] = useState(1);
    const [note, setNote] = useState('');
    const [fav, setFav] = useState('');
    const base = menu.bases.find((b) => b.id === baseId);
    const clash = (id: number) => menu.rules.some((r) => (r.a === id && picked.includes(r.b)) || (r.b === id && picked.includes(r.a)));
    const why = (id: number) => (picked.includes(id) ? null : clash(id) ? t('Does not go with what you picked') : picked.length >= menu.max ? t('You can put at most {n} things in one dish.', { n: num(menu.max) }) : null);
    const total = customPrice(menu, { base_id: baseId, ingredient_ids: picked });
    const groups = useMemo(() => Object.keys(GROUPS).map((g) => ({ g, items: menu.ingredients.filter((i) => i.group === g && (search === '' || i.search.includes(search.toLowerCase()))) })).filter((x) => x.items.length > 0), [menu, search]);

    if (menu.bases.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="build-h" className="space-y-4 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <div>
                <h2 id="build-h" className="font-display text-xl font-bold">
                    {t('Build your own')}
                </h2>
                <p className="text-sm text-[var(--pt-muted)]">{t('Pick a base, then add what you like. Custom food takes about {n} minutes longer.', { n: num(menu.extra) })}</p>
            </div>

            {favorites.length > 0 && (
                <div className="space-y-1">
                    <p className="text-sm font-semibold">{t('Your favourites')}</p>
                    <ul className="space-y-1">
                        {favorites.map((f) => (
                            <li key={f.id} className="flex flex-wrap items-center gap-2 text-sm">
                                <span className="font-medium">{f.name}</span>
                                {f.ok ? <span className="tabular text-[var(--pt-muted)]">{money(f.price ?? 0)}</span> : <span className="text-danger">{f.problem}</span>}
                                <Button size="sm" variant="outline" disabled={!f.ok || !open} onClick={() => onAdd({ base_id: f.base_id, ingredient_ids: f.ingredient_ids, quantity: 1, note: '' })}>
                                    {t('Add')}
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => router.delete(`/food/favorites/${f.id}`, { preserveScroll: true })}>
                                    {t('Remove')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={t('Base')}>
                {menu.bases.map((b) => (
                    <button key={b.id} type="button" role="radio" aria-checked={b.id === baseId} onClick={() => setBaseId(b.id)} className={`rounded-xl border px-3 py-2 text-start text-sm ${b.id === baseId ? 'border-[var(--pt-ink)] bg-[var(--pt-sage)]' : ''}`}>
                        <span className="block font-semibold">{b.name}</span>
                        <span className="tabular text-[var(--pt-muted)]">{money(b.price)}</span>
                    </button>
                ))}
            </div>

            <Input aria-label={t('Search ingredients')} placeholder={t('Search ingredients')} value={search} onChange={(e) => setSearch(e.target.value)} />

            <div className="space-y-3">
                {groups.map(({ g, items }) => (
                    <fieldset key={g}>
                        <legend className="mb-1 text-sm font-semibold">{t(GROUPS[g])}</legend>
                        <div className="grid gap-1 sm:grid-cols-2">
                            {items.map((i) => {
                                const blocked = i.sold_out || why(i.id) !== null;

                                return (
                                    <label key={i.id} className={`flex items-center gap-2 rounded-lg border px-2 py-1 text-sm ${blocked ? 'opacity-50' : ''}`} title={i.sold_out ? t('Sold out today') : (why(i.id) ?? '')}>
                                        <input type="checkbox" checked={picked.includes(i.id)} disabled={blocked} onChange={(e) => setPicked(e.target.checked ? [...picked, i.id] : picked.filter((x) => x !== i.id))} />
                                        <span className="flex-1">{i.name}</span>
                                        <span className="tabular text-[var(--pt-muted)]">{i.sold_out ? t('Sold out today') : `+ ${money(i.price)}`}</span>
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>
                ))}
            </div>

            <div className="space-y-2 border-t pt-3">
                <p className="tabular text-sm">
                    {base?.name} {picked.length > 0 && `+ ${num(picked.length)} / ${num(menu.max)}`} · <strong className="font-display text-xl">{money(total)}</strong>
                </p>
                <div className="flex flex-wrap items-center gap-2">
                    <Input aria-label={t('How many')} className="h-9 w-16" inputMode="numeric" value={qty} onChange={(e) => setQty(Math.max(1, Math.min(50, Number(e.target.value) || 1)))} />
                    <Input aria-label={t('A note for the kitchen')} className="h-9 min-w-48 flex-1" maxLength={120} placeholder={t('A note for the kitchen, for example: no onion')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <Button disabled={!open || !base} onClick={() => (onAdd({ base_id: baseId, ingredient_ids: picked, quantity: qty, note }), setPicked([]), setNote(''), setQty(1))}>
                        {t('Add to my order')}
                    </Button>
                </div>
                {signedIn && (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <Input aria-label={t('Name this favourite')} className="h-8 w-48" maxLength={60} placeholder={t('Name this favourite')} value={fav} onChange={(e) => setFav(e.target.value)} />
                        <Button size="sm" variant="outline" disabled={fav.trim() === '' || !base} onClick={() => router.post('/food/favorites', { restaurant_id: restaurantId, name: fav, base_id: baseId, ingredient_ids: picked }, { preserveScroll: true, onSuccess: () => setFav('') })}>
                            {t('Save as a favourite')}
                        </Button>
                    </div>
                )}
            </div>
        </section>
    );
}
