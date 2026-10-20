import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Suggestion {
    medicine_id: number;
    name: string;
    strength: string | null;
    purchase_percent: number;
    boxes: number;
    suggested: number;
}

interface Props {
    suggestions: Suggestion[];
    running: { id: number; name: string; strength: string | null; percent: number; ends_on: string; live: boolean }[];
    asking: { id: number; name: string; strength: string | null; percent: number; ended_on: string }[];
    margin: number;
    products: {
        id: number;
        name: string;
        strength: string | null;
        percent: number;
        min_boxes: number;
        step_percent: number;
        visible_to: string[];
        stock_mode: string;
        boxes: number;
        state: string;
    }[];
    low: { id: number; name: string; left: number }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Offers', href: '/admin/offers' }];

function SuggestionRow({ s }: { s: Suggestion }) {
    const { t, num } = useI18n();
    const [pct, setPct] = useState(String(s.suggested));
    const [until, setUntil] = useState('');

    return (
        <li className="flex flex-wrap items-center gap-3 px-4 py-3">
            <div className="min-w-48 flex-1">
                <p className="font-medium">
                    {s.name} <span className="text-muted-foreground text-sm font-normal">{s.strength}</span>
                </p>
                <p className="text-muted-foreground text-sm">
                    {t('Bought at {p}%, {n} boxes in the shop', { p: num(s.purchase_percent), n: num(s.boxes) })}
                </p>
            </div>
            <Input
                aria-label={t('Sell at %')}
                className="w-24"
                type="number"
                min="0"
                max="99"
                step="any"
                value={pct}
                onChange={(e) => setPct(e.target.value)}
            />
            <Input aria-label={t('Runs until (optional)')} className="w-40" type="date" value={until} onChange={(e) => setUntil(e.target.value)} />
            <Button
                size="sm"
                disabled={pct === ''}
                onClick={() => router.post('/admin/offers', { medicine_id: s.medicine_id, percent: pct, ends_on: until || null }, { preserveScroll: true })}
            >
                {t('Upload to the shop tier')}
            </Button>
        </li>
    );
}

function ByHand() {
    const { t } = useI18n();
    const [q, setQ] = useState('');
    const [found, setFound] = useState<{ id: number; brand_name: string; strength: string | null }[]>([]);
    const [pick, setPick] = useState<{ id: number; brand_name: string } | null>(null);
    const [pct, setPct] = useState('');
    const [until, setUntil] = useState('');

    useEffect(() => {
        if (pick || q.trim().length < 2) {
            setFound([]);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`/medicines/suggest?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d) => setFound(d.data.slice(0, 6)))
                .catch(() => setFound([]));
        }, 200);
        return () => clearTimeout(timer);
    }, [q, pick]);

    return (
        <section aria-labelledby="hand-h" className="space-y-2 rounded-md border p-3">
            <h2 id="hand-h" className="font-medium">
                {t('Put any medicine on offer')}
            </h2>
            {pick ? (
                <p className="text-sm">
                    <strong>{pick.brand_name}</strong>{' '}
                    <button type="button" className="underline" onClick={() => setPick(null)}>
                        {t('Change')}
                    </button>
                </p>
            ) : (
                <>
                    <Input
                        aria-label={t('Medicine name')}
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('Medicine name')}
                        autoComplete="off"
                    />
                    <ul>
                        {found.map((m) => (
                            <li key={m.id}>
                                <button type="button" className="hover:bg-muted w-full px-2 py-1 text-left text-sm" onClick={() => setPick(m)}>
                                    {m.brand_name} {m.strength}
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <div className="flex gap-2">
                <Input
                    aria-label={t('Sell at %')}
                    className="w-28"
                    type="number"
                    min="0"
                    max="99"
                    step="any"
                    placeholder={t('Sell at %')}
                    value={pct}
                    onChange={(e) => setPct(e.target.value)}
                />
                <Input aria-label={t('Runs until (optional)')} className="w-40" type="date" value={until} onChange={(e) => setUntil(e.target.value)} />
                <Button
                    disabled={!pick || pct === ''}
                    onClick={() =>
                        router.post(
                            '/admin/offers',
                            { medicine_id: pick!.id, percent: pct, ends_on: until || null },
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setPick(null);
                                    setPct('');
                                    setUntil('');
                                    setQ('');
                                },
                            },
                        )
                    }
                >
                    {t('Upload to the shop tier')}
                </Button>
            </div>
        </section>
    );
}

function ProductForm() {
    const { t } = useI18n();
    const [q, setQ] = useState('');
    const [found, setFound] = useState<{ id: number; brand_name: string; strength: string | null }[]>([]);
    const [pick, setPick] = useState<{ id: number; brand_name: string } | null>(null);
    const [f, setF] = useState({
        percent: '',
        min_boxes: '5',
        step_percent: '1',
        weight_kg: '',
        delivery_charge: '',
        stock_mode: 'in_stock',
        visible_to: ['paikari'] as string[],
        viewer_percent: {} as Record<string, string>,
    });

    useEffect(() => {
        if (pick || q.trim().length < 2) {
            setFound([]);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`/medicines/suggest?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d) => setFound(d.data.slice(0, 6)))
                .catch(() => setFound([]));
        }, 200);
        return () => clearTimeout(timer);
    }, [q, pick]);

    const toggle = (v: string) =>
        setF((s) => ({ ...s, visible_to: s.visible_to.includes(v) ? s.visible_to.filter((x) => x !== v) : [...s.visible_to, v] }));
    const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF((s) => ({ ...s, [k]: e.target.value }));
    const ready = pick && f.percent !== '' && Number(f.min_boxes) >= 1 && f.visible_to.length > 0;

    return (
        <section aria-labelledby="prod-new-h" className="space-y-3 rounded-md border p-3">
            <h2 id="prod-new-h" className="font-medium">
                {t('New offer product')}
            </h2>
            {pick ? (
                <p className="text-sm">
                    <strong>{pick.brand_name}</strong>{' '}
                    <button type="button" className="underline" onClick={() => setPick(null)}>
                        {t('Change')}
                    </button>
                </p>
            ) : (
                <>
                    <Input
                        aria-label={t('Medicine name')}
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('Medicine name')}
                        autoComplete="off"
                    />
                    <ul>
                        {found.map((m) => (
                            <li key={m.id}>
                                <button type="button" className="hover:bg-muted w-full px-2 py-1 text-left text-sm" onClick={() => setPick(m)}>
                                    {m.brand_name} {m.strength}
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            <div className="grid gap-2 sm:grid-cols-3">
                <label className="text-muted-foreground text-xs">
                    {t('Offer rate %')}
                    <Input type="number" min="0" max="99" step="any" value={f.percent} onChange={set('percent')} />
                </label>
                <label className="text-muted-foreground text-xs">
                    {t('Minimum boxes')}
                    <Input type="number" min="1" step="1" value={f.min_boxes} onChange={set('min_boxes')} />
                </label>
                <label className="text-muted-foreground text-xs">
                    {t('Extra % for each box above the minimum')}
                    <Input type="number" min="0" max="20" step="any" value={f.step_percent} onChange={set('step_percent')} />
                </label>
                <label className="text-muted-foreground text-xs">
                    {t('Weight (kg), optional')}
                    <Input type="number" min="0" step="any" value={f.weight_kg} onChange={set('weight_kg')} />
                </label>
                <label className="text-muted-foreground text-xs">
                    {t('Delivery charge for this product')}
                    <Input type="number" min="0" step="any" value={f.delivery_charge} onChange={set('delivery_charge')} />
                </label>
                <label className="text-muted-foreground text-xs">
                    {t('Show stock as')}
                    <select
                        value={f.stock_mode}
                        onChange={set('stock_mode')}
                        className="bg-background text-foreground mt-0 block h-9 w-full rounded-md border px-2 text-sm"
                    >
                        <option value="in_stock">{t('Just "In stock"')}</option>
                        <option value="exact">{t('Exact number of boxes')}</option>
                    </select>
                </label>
            </div>
            <fieldset className="flex flex-wrap gap-4 text-sm">
                <legend className="text-muted-foreground mb-1 text-xs">{t('Who can see it')}</legend>
                {[
                    ['paikari', 'Shops'],
                    ['public', 'Public'],
                    ['wholesale', 'Wholesale'],
                ].map(([v, label]) => (
                    <span key={v} className="flex items-center gap-2">
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={f.visible_to.includes(v)} onChange={() => toggle(v)} />
                            {t(label)}
                        </label>
                        {f.visible_to.includes(v) && (
                            <Input
                                aria-label={t('Own rate % for {who}', { who: t(label) })}
                                placeholder={t('Own rate %')}
                                type="number"
                                min="0"
                                max="99"
                                step="any"
                                className="h-8 w-24"
                                value={f.viewer_percent[v] ?? ''}
                                onChange={(e) => setF((s) => ({ ...s, viewer_percent: { ...s.viewer_percent, [v]: e.target.value } }))}
                            />
                        )}
                    </span>
                ))}
            </fieldset>
            <Button
                disabled={!ready}
                onClick={() =>
                    router.post(
                        '/admin/offer-products',
                        { medicine_id: pick!.id, ...f, weight_kg: f.weight_kg || null, delivery_charge: f.delivery_charge || null },
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setPick(null);
                                setQ('');
                            },
                        },
                    )
                }
            >
                {t('Start the offer')}
            </Button>
        </section>
    );
}

export default function Offers({ suggestions, running, asking, margin, products, low }: Props) {
    const { t, num, date } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Offers')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                <InputError message={errors.offer} />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Offers')}</h1>
                    <p className="text-muted-foreground text-sm">
                        {t(
                            'Every shop gets the rate at once. It ends with the month, or when the stock is gone. A shop with its own special rate keeps that rate. The least profit allowed is {m}%.',
                            { m: num(margin) },
                        )}
                    </p>
                </header>

                {asking.length > 0 && (
                    <section aria-labelledby="ask-h" className="space-y-2 rounded-md border border-warning/50 bg-warning/5 p-4">
                        <h2 id="ask-h" className="font-medium">
                            {t('The month is over. Keep these offers on?')}
                        </h2>
                        <ul className="divide-y">
                            {asking.map((o) => (
                                <li key={o.id} className="flex flex-wrap items-center gap-3 py-2">
                                    <span className="min-w-48 flex-1 font-medium">
                                        {o.name} <span className="text-muted-foreground font-normal">{o.strength}</span> · {num(o.percent)}%
                                    </span>
                                    <Button size="sm" onClick={() => router.post(`/admin/offers/${o.id}/renew`, {}, { preserveScroll: true })}>
                                        {t('Run again this month')}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => router.post(`/admin/offers/${o.id}/leave`, {}, { preserveScroll: true })}
                                    >
                                        {t('Leave it off')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {low.length > 0 && (
                    <div role="alert" className="space-y-1 rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm font-semibold">
                        {low.map((l) => (
                            <p key={l.id}>{t('Only {n} boxes left on the {name} offer.', { n: num(l.left), name: l.name })}</p>
                        ))}
                    </div>
                )}

                <section aria-labelledby="prod-h" className="space-y-2">
                    <h2 id="prod-h" className="font-medium">
                        {t('Offer products')}
                    </h2>
                    {products.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">
                            {t('No offer products running.')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {products.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                    <div className="min-w-48 flex-1">
                                        <p className="font-medium">
                                            {p.name} <span className="text-muted-foreground text-sm font-normal">{p.strength}</span>
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            {t('{n} boxes on offer', { n: num(p.boxes) })} · {t('minimum {n} boxes', { n: num(p.min_boxes) })} ·{' '}
                                            {p.state === 'live' ? t('Live') : t('Sold out')}
                                        </p>
                                    </div>
                                    <p className="tabular font-display text-2xl font-extrabold">{num(p.percent)}%</p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => router.post(`/admin/offer-products/${p.id}/off`, {}, { preserveScroll: true })}
                                    >
                                        {t('Switch off')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <ProductForm />
                </section>

                <section aria-labelledby="run-h" className="space-y-2">
                    <h2 id="run-h" className="font-medium">
                        {t('Shop tier offers running now')}
                    </h2>
                    {running.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">{t('No offers running.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {running.map((o) => (
                                <li key={o.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                    <div className="min-w-48 flex-1">
                                        <p className="font-medium">
                                            {o.name} <span className="text-muted-foreground text-sm font-normal">{o.strength}</span>
                                        </p>
                                        <p className="text-muted-foreground text-sm">
                                            {t('Until {d}', { d: date(o.ends_on) })} · {o.live ? t('Live') : t('Paused: nothing in the shop')}
                                        </p>
                                    </div>
                                    <p className="tabular font-display text-2xl font-extrabold">{num(o.percent)}%</p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => router.post(`/admin/offers/${o.id}/off`, {}, { preserveScroll: true })}
                                    >
                                        {t('Switch off')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="sug-h" className="space-y-2">
                    <h2 id="sug-h" className="font-medium">
                        {t('Bought at a high discount')}
                    </h2>
                    {suggestions.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">
                            {t('Nothing to suggest right now.')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {suggestions.map((s) => (
                                <SuggestionRow key={s.medicine_id} s={s} />
                            ))}
                        </ul>
                    )}
                </section>

                <ByHand />
            </div>
        </AppLayout>
    );
}
