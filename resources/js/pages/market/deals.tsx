import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Product {
    id: number;
    title: string;
}

interface Sale {
    listing_id: number;
    title: string;
    percent: number;
    ends_at: string;
}

interface BundleRow {
    id: number;
    title: string;
    percent: number;
    items: Product[];
}

interface Props {
    products: Product[];
    sellable: Product[];
    sales: Sale[];
    bundles: BundleRow[];
    limits: { sale: [number, number]; bundle: [number, number]; size: [number, number]; days: number };
}

/** The moment a sale may end at the latest, as the value a datetime-local field wants. */
function localValue(daysAhead: number): string {
    const d = new Date(Date.now() + daysAhead * 86_400_000);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());

    return d.toISOString().slice(0, 16);
}

function SaleRow({ product, sale, limits }: { product: Product; sale?: Sale; limits: Props['limits'] }) {
    const { t, num, dateTime } = useI18n();
    const [open, setOpen] = useState(false);
    const [percent, setPercent] = useState('20');
    const [ends, setEnds] = useState(localValue(1));
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <li className="space-y-2 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="min-w-40 flex-1 font-medium">{product.title}</p>
                {sale ? (
                    <>
                        <span className="text-sm">{t('{n}% off until {when}', { n: num(sale.percent), when: dateTime(sale.ends_at) })}</span>
                        <Button size="sm" variant="outline" onClick={() => router.delete(`/wholesale/sell/deals/sale/${product.id}`, { preserveScroll: true })}>
                            {t('End the sale')}
                        </Button>
                    </>
                ) : (
                    <Button size="sm" variant="outline" aria-expanded={open} onClick={() => setOpen(!open)}>
                        {t('Put on sale')}
                    </Button>
                )}
            </div>
            {open && !sale && (
                <form
                    className="grid gap-3 rounded-xl border p-3 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(`/wholesale/sell/deals/sale/${product.id}`, { percent: Number(percent), ends_at: ends.replace('T', ' ') }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => setOpen(false) });
                    }}
                >
                    <label className="block text-sm font-medium">
                        {t('Percent off')}
                        <Input className="mt-1" type="number" inputMode="numeric" min={limits.sale[0]} max={limits.sale[1]} value={percent} onChange={(e) => setPercent(e.target.value)} />
                    </label>
                    <label className="block text-sm font-medium">
                        {t('Sale ends')}
                        <Input className="mt-1" type="datetime-local" min={localValue(0)} max={localValue(limits.days)} value={ends} onChange={(e) => setEnds(e.target.value)} />
                    </label>
                    <p className="text-xs text-[var(--pt-muted)] sm:col-span-2">
                        {t('Between {a}% and {b}% off, and it can run for {n} days at most. Buyers see the old price crossed out.', { a: num(limits.sale[0]), b: num(limits.sale[1]), n: num(limits.days) })}
                    </p>
                    <div className="sm:col-span-2">
                        <InputError message={errors.sale} />
                        <Button type="submit" size="sm" disabled={busy}>
                            {busy ? t('Saving…') : t('Start the sale')}
                        </Button>
                    </div>
                </form>
            )}
        </li>
    );
}

function NewBundle({ products, limits }: { products: Product[]; limits: Props['limits'] }) {
    const { t, num } = useI18n();
    const [title, setTitle] = useState('');
    const [percent, setPercent] = useState('10');
    const [picked, setPicked] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;
    const ok = title.trim().length >= 3 && picked.length >= limits.size[0] && picked.length <= limits.size[1];

    return (
        <form
            className="space-y-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4"
            onSubmit={(e) => {
                e.preventDefault();
                if (!ok || busy) return;
                router.post('/wholesale/sell/deals/bundles', { title, percent: Number(percent), listing_ids: picked }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => { setTitle(''); setPicked([]); } });
            }}
        >
            <h3 className="font-display text-lg font-bold">{t('Make a bundle')}</h3>
            <div className="grid gap-3 sm:grid-cols-[1fr_8rem]">
                <label className="block text-sm font-medium">
                    {t('Name of the bundle')}
                    <Input className="mt-1" maxLength={80} value={title} onChange={(e) => setTitle(e.target.value)} />
                </label>
                <label className="block text-sm font-medium">
                    {t('Percent off')}
                    <Input className="mt-1" type="number" inputMode="numeric" min={limits.bundle[0]} max={limits.bundle[1]} value={percent} onChange={(e) => setPercent(e.target.value)} />
                </label>
            </div>
            <fieldset>
                <legend className="text-sm font-medium">{t('Pick {a} to {b} of your products', { a: num(limits.size[0]), b: num(limits.size[1]) })}</legend>
                <ul className="mt-1 max-h-72 divide-y overflow-y-auto rounded-xl border text-sm">
                    {products.map((p) => (
                        <li key={p.id}>
                            <label className="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={picked.includes(p.id)}
                                    onChange={(e) => setPicked(e.target.checked ? [...picked, p.id] : picked.filter((i) => i !== p.id))}
                                />
                                {p.title}
                            </label>
                        </li>
                    ))}
                </ul>
            </fieldset>
            <p className="text-xs text-[var(--pt-muted)]">{t('A buyer gets the percent off when all of these are in the cart together. A product with colours or sizes, or a download, cannot be in a bundle.')}</p>
            <InputError message={errors.bundle} />
            <Button type="submit" disabled={!ok || busy}>
                {busy ? t('Saving…') : t('Save the bundle')}
            </Button>
        </form>
    );
}

/** A seller's flash sales and bundles: lowering a price for a while, or for products bought together. */
export default function Deals({ products, sellable, sales, bundles, limits }: Props) {
    const { t, num } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;

    return (
        <PublicLayout>
            <Head title={t('Sales and bundles')} />
            <div className="mx-auto max-w-3xl space-y-8">
                <Link href="/wholesale/sell" className="text-sm underline underline-offset-4">
                    {t('Sell')}
                </Link>
                <div>
                    <h1 className="font-display text-3xl font-extrabold">{t('Sales and bundles')}</h1>
                    <p className="text-sm text-[var(--pt-muted)]">{t('A deal comes out of your price, not ours. Our commission is worked out on the lower price.')}</p>
                </div>
                {flash && (
                    <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
                        {flash}
                    </p>
                )}

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Flash sales')}</h2>
                    {sellable.length === 0 ? (
                        <p className="text-sm text-[var(--pt-muted)]">{t('Only products buyers can see can go on sale. None of yours is up yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-[1.5rem] border bg-[var(--pt-surface)] px-4 text-sm">
                            {sellable.map((p) => (
                                <SaleRow key={p.id} product={p} sale={sales.find((s) => s.listing_id === p.id)} limits={limits} />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="font-display text-xl font-bold">{t('Bundles')}</h2>
                    {bundles.length === 0 ? (
                        <p className="text-sm text-[var(--pt-muted)]">{t('You have no bundle yet.')}</p>
                    ) : (
                        <ul className="space-y-2">
                            {bundles.map((b) => (
                                <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                                    <span className="min-w-40 flex-1">
                                        <span className="block font-semibold">{b.title}</span>
                                        <span className="text-[var(--pt-muted)]">{b.items.map((i) => i.title).join(', ')}</span>
                                    </span>
                                    <span className="tabular font-semibold">{t('{n}% off', { n: num(b.percent) })}</span>
                                    <Button size="sm" variant="outline" onClick={() => router.delete(`/wholesale/sell/deals/bundles/${b.id}`, { preserveScroll: true })}>
                                        {t('Remove')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {products.length >= limits.size[0] ? (
                        <NewBundle products={products} limits={limits} />
                    ) : (
                        <p className="text-sm text-[var(--pt-muted)]">{t('You need at least {n} products that buyers can see to make a bundle.', { n: num(limits.size[0]) })}</p>
                    )}
                </section>
            </div>
        </PublicLayout>
    );
}
