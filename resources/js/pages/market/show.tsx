import { track } from '@/lib/track';
import { SaleTag, WasPrice } from '@/components/market/sale-price';
import InputError from '@/components/input-error';
import PageHead, { JsonLd } from '@/components/shell/page-head';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { unitLabel } from '@/lib/units';

interface Grid {
    colors: { name: string; sold_out: boolean; photo: string | null }[];
    sizes: string[];
    cells: { id: number; color: string; size: string; price: number; available: boolean }[];
}

interface Review {
    id: number;
    rating: number;
    body: string | null;
    verified: boolean;
    at: string;
    photos: string[];
}

interface Offer {
    id: number;
    name: string;
    price: number;
    delivery: number;
    total: number;
    rating: string;
    best: boolean;
    this: boolean;
}

interface Props {
    pickup: { where: string } | null;
    cod: boolean;
    digital: boolean;
    gallery: { url: string }[];
    video: string | null;
    reviews: Review[];
    rating: { average: number | null; count: number; stars: Record<string, number> };
    offers: Offer[];
    token: number | null;
    questions: { id: number; question: string; answer: string; at: string }[];
    can_ask: boolean;
    wishlisted: boolean;
    bundles: { id: number; title: string; percent: number; was: number; save: number; now: number; items: { id: number; title: string; price: number }[] }[];
    districts: string[];
    listing: {
        id: number;
        title: string;
        description: string | null;
        seo_title: string | null;
        seo_description: string | null;
        price: number;
        was: number | null;
        sale_percent: number | null;
        sale_ends_at: string | null;
        unit: string;
        sector_name: string;
        photo: string | null;
        seller: string;
        trusted?: boolean;
        premium: boolean;
        delivery_charge: number;
        min_qty: number;
        shop: number | null;
        grid: Grid | null;
        details: { weight: number | null; expires_on: string | null; warranty_months: number | null };
        size: { height: number | null; length: number | null; width: number | null; unit: string } | null;
        in_stock: boolean;
        allow_fraction: boolean;
        return_rule: string | null;
        preorder_days: number | null;
        return_days: number;
        return_note: string | null;
        preorder: boolean;
        can_report: boolean;
    };
}

function OrderForm({
    listing,
    districts,
    token,
    color,
    setColor,
    pickup,
    cod,
    digital,
}: {
    listing: Props['listing'];
    districts: string[];
    token: number | null;
    color: string;
    setColor: (c: string) => void;
    pickup: Props['pickup'];
    cod: boolean;
    digital: boolean;
}) {
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const form = useForm({
        quantity: String(listing.min_qty > 1 ? listing.min_qty : 1),
        name: '',
        phone: '',
        district: districts[0] ?? '',
        area: '',
        address: '',
        how_to_find: '',
        note: '',
        use_token: true as boolean,
        variant_id: '',
        pickup: false as boolean,
    });
    const grid = listing.grid;
    const [size, setSize] = useState('');
    // The cell the buyer chose: its price replaces the product's price (spec ch 15).
    const cell = grid ? grid.cells.find((c) => c.color === color && c.size === size && (c.available || listing.preorder)) : undefined;
    // Out of stock but the sector takes pre-orders: the order is kept and ships when the seller has it again (spec ch 15).
    const waiting = listing.preorder && (grid ? !!cell && !cell.available : !listing.in_stock);
    const each = cell ? cell.price : listing.price;
    const goods = Number(form.data.quantity || 0) * each;
    const off = token && form.data.use_token ? Math.min(token, goods) : 0;
    // Collecting saves the delivery charge (spec ch 20).
    const delivery = form.data.pickup ? 0 : listing.delivery_charge;
    const total = goods + delivery - off;

    return (
        <form
            className="space-y-4 rounded-[1.75rem] border border-[var(--art-gold-2)] bg-[var(--pt-surface)] p-6"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({ ...d, variant_id: cell ? cell.id : '' }));
                form.post(`/market/${listing.id}/order`);
            }}
        >
            <h2 className="font-display text-3xl">{t('Order this')}</h2>
            {grid && (
                <div className="space-y-3">
                    {grid.colors.some((c) => c.name !== '') && (
                        <div role="radiogroup" aria-label={t('Colour')} className="flex flex-wrap gap-2">
                            {grid.colors.map((c) => (
                                <button
                                    key={c.name}
                                    type="button"
                                    role="radio"
                                    aria-checked={color === c.name}
                                    disabled={c.sold_out && !listing.preorder}
                                    onClick={() => (setColor(c.name), setSize(''))}
                                    className={`rounded-full border px-3 py-1 text-sm ${color === c.name ? 'border-[var(--pt-ink)] bg-[var(--pt-sage)]' : ''} ${c.sold_out ? (listing.preorder ? 'text-[var(--pt-muted)]' : 'cursor-not-allowed text-[var(--pt-muted)] line-through opacity-50') : ''}`}
                                >
                                    {c.name}
                                    {c.sold_out && (
                                        <span className="ms-1 text-xs no-underline">({listing.preorder ? t('Pre-order') : t('Sold out')})</span>
                                    )}
                                </button>
                            ))}
                        </div>
                    )}
                    {grid.sizes.some((s) => s !== '') && (
                        <div role="radiogroup" aria-label={t('Size')} className="flex flex-wrap gap-2">
                            {grid.sizes.map((s) => {
                                const c = grid.cells.find((x) => x.color === color && x.size === s);
                                const ok = !!c && (c.available || listing.preorder);

                                return (
                                    <button
                                        key={s}
                                        type="button"
                                        role="radio"
                                        aria-checked={size === s}
                                        disabled={!ok}
                                        onClick={() => setSize(s)}
                                        className={`rounded-md border px-3 py-1 text-sm ${size === s ? 'border-[var(--pt-ink)] bg-[var(--pt-sage)]' : ''} ${ok ? '' : 'cursor-not-allowed text-[var(--pt-muted)] line-through opacity-50'}`}
                                    >
                                        {s}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    {!cell && <p className="text-sm text-[var(--pt-muted)]">{t('Pick a colour and a size.')}</p>}
                </div>
            )}
            {pickup && (
                <fieldset className="space-y-1 rounded-md bg-[var(--pt-sage)] px-3 py-2 text-sm">
                    <legend className="sr-only">{t('How do you want it?')}</legend>
                    <label className="flex items-center gap-2">
                        <input type="radio" name="how" checked={!form.data.pickup} onChange={() => form.setData('pickup', false)} />
                        {t('Send it to me')}
                    </label>
                    <label className="flex items-center gap-2">
                        <input type="radio" name="how" checked={form.data.pickup} onChange={() => form.setData('pickup', true)} />
                        {t('I will collect it, no delivery charge')}
                    </label>
                    {form.data.pickup && <p className="text-xs text-[var(--pt-muted)]">{t('Collect from: {where}', { where: pickup.where })}</p>}
                </fieldset>
            )}
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm font-medium">
                    {t('How many ({unit})', { unit: listing.unit })}
                    <Input
                        className="mt-1"
                        type="number"
                        min={listing.allow_fraction ? '0.01' : '1'}
                        step={listing.allow_fraction ? 'any' : '1'}
                        value={form.data.quantity}
                        onChange={(e) => form.setData('quantity', e.target.value)}
                    />
                    <InputError message={errors.quantity} />
                </label>
                <label className="text-sm font-medium">
                    {t('Your name')}
                    <Input className="mt-1" value={form.data.name} maxLength={120} onChange={(e) => form.setData('name', e.target.value)} />
                    <InputError message={errors.name} />
                </label>
                <label className="text-sm font-medium">
                    {t('Mobile number')}
                    <Input className="mt-1" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                    <InputError message={errors.phone} />
                </label>
                {!form.data.pickup && !digital && (
                    <>
                        <label className="text-sm font-medium">
                            {t('District')}
                            <select
                                value={form.data.district}
                                onChange={(e) => form.setData('district', e.target.value)}
                                className="bg-background mt-1 block h-10 w-full rounded-md border px-2 text-sm"
                            >
                                {districts.map((d) => (
                                    <option key={d} value={d}>
                                        {d}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            {t('Area')}
                            <Input className="mt-1" value={form.data.area} maxLength={80} onChange={(e) => form.setData('area', e.target.value)} />
                            <InputError message={errors.area} />
                        </label>
                        <label className="text-sm font-medium">
                            {t('Address')}
                            <Input
                                className="mt-1"
                                value={form.data.address}
                                maxLength={250}
                                onChange={(e) => form.setData('address', e.target.value)}
                            />
                            <InputError message={errors.address} />
                        </label>
                    </>
                )}
            </div>
            <label className="block text-sm font-medium">
                {t('Note')}
                <Input className="mt-1" value={form.data.note} maxLength={300} onChange={(e) => form.setData('note', e.target.value)} />
            </label>
            {token !== null && (
                <label className="flex items-center gap-2 rounded-md bg-[var(--pt-sage)] px-3 py-2 text-sm">
                    <input type="checkbox" checked={form.data.use_token} onChange={(e) => form.setData('use_token', e.target.checked)} />
                    {t('Use my Aqua Token (up to {amount} off)', { amount: money(token) })}
                </label>
            )}
            <p className="tabular text-sm">
                {digital ? t('Total') : t('Total with delivery')}: <strong>{money(total)}</strong> ·{' '}
                {digital
                    ? t('You pay online, then download it right away.')
                    : cod
                      ? t('You pay when it arrives.')
                      : t('You pay online first. The seller sends it after that.')}
            </p>
            {waiting && (
                <p className="rounded-md bg-[var(--pt-sage)] px-3 py-2 text-sm">
                    {listing.preorder_days
                        ? t('It is not in stock now. The seller will have it ready within {n} days.', { n: num(listing.preorder_days) })
                        : t('It is not in stock now. Your pre-order is kept and the seller sends it when they have it again.')}
                </p>
            )}
            {/* Beside the buttons, so a buyer on a phone sees what happened to the click (added, or why not). */}
            {flash && (
                <p role="status" className="rounded-md border border-[var(--pt-ink)] bg-[var(--pt-surface)] px-3 py-2 text-sm text-[var(--pt-ink)]">
                    {flash}
                </p>
            )}
            <InputError message={errors.order} />
            <Button
                type="button"
                variant="outline"
                disabled={form.processing || (!!grid && !cell) || Number(form.data.quantity) <= 0}
                onClick={() => {
                    track('AddToCart', { id: listing.id, name: listing.title, value: listing.price, quantity: Number(form.data.quantity) || 1 });
                    router.post('/market-cart', { listing_id: listing.id, variant_id: cell ? cell.id : null, quantity: form.data.quantity }, { preserveScroll: true });
                }}
            >
                {t('Add to the market cart')}
            </Button>
            <Button type="submit" disabled={form.processing || (!!grid && !cell)}>
                {waiting ? t('Send the pre-order') : t('Send the order')}
            </Button>
        </form>
    );
}

function Questions({ id, questions, canAsk }: { id: number; questions: Props['questions']; canAsk: boolean }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [text, setText] = useState('');

    return (
        <section aria-labelledby="qa-h" className="space-y-3">
            <h2 id="qa-h" className="font-display text-3xl">
                {t('Questions and answers')}
            </h2>
            {questions.length === 0 && <p className="text-sm text-[var(--pt-muted)]">{t('Nobody has asked yet.')}</p>}
            <ul className="space-y-3">
                {questions.map((q) => (
                    <li key={q.id} className="rounded-[1.25rem] border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3 text-sm">
                        <p className="font-semibold">{q.question}</p>
                        <p className="mt-1">{q.answer}</p>
                    </li>
                ))}
            </ul>
            {canAsk && (
                <form
                    className="space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(`/market/${id}/questions`, { question: text }, { preserveScroll: true, onSuccess: () => setText('') });
                    }}
                >
                    <label className="block text-sm font-medium">
                        {t('Ask about this product')}
                        <Input className="mt-1" value={text} maxLength={500} onChange={(e) => setText(e.target.value)} />
                    </label>
                    <InputError message={errors.question} />
                    <Button type="submit" variant="outline" disabled={text.trim().length < 8}>
                        {t('Send the question')}
                    </Button>
                </form>
            )}
        </section>
    );
}

function WhyNot({ id, minQty, price }: { id: number; minQty: number; price: number }) {
    const { t, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [offer, setOffer] = useState('');
    const reasons: [string, string][] = [
        ['price', 'The price is too high'],
        ['cheaper', 'Found it cheaper elsewhere'],
        ['not_wanted', 'Not what I wanted'],
        ['delivery', 'The delivery charge is too high'],
        ['not_now', 'I do not need it now'],
    ];

    if (!open) {
        return (
            <button type="button" className="text-sm underline underline-offset-4" onClick={() => setOpen(true)}>
                {t('Not buying? Tell us why')}
            </button>
        );
    }

    return (
        <div className="space-y-2 rounded-[1.25rem] border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3 text-sm">
            <p className="font-semibold">{t('Why are you not buying?')}</p>
            <div role="radiogroup" aria-label={t('Why are you not buying?')} className="space-y-1">
                {reasons.map(([k, label]) => (
                    <label key={k} className="flex items-center gap-2">
                        <input type="radio" name="why" checked={reason === k} onChange={() => setReason(k)} />
                        {t(label)}
                    </label>
                ))}
            </div>
            {reason === 'price' && (
                <label className="block font-medium">
                    {t('Name your price for each (today {price})', { price: money(price) })}
                    <Input className="mt-1" inputMode="decimal" value={offer} onChange={(e) => setOffer(e.target.value)} />
                </label>
            )}
            <InputError message={errors.why} />
            <Button
                size="sm"
                disabled={reason === ''}
                onClick={() =>
                    router.post(
                        `/market/${id}/why`,
                        { reason, price: reason === 'price' && offer.trim() !== '' ? offer : null, quantity: minQty },
                        { preserveScroll: true, onSuccess: () => setOpen(false) },
                    )
                }
            >
                {t('Send')}
            </Button>
        </div>
    );
}

function Stars({ n }: { n: number }) {
    return (
        <span aria-hidden className="tracking-tight text-[var(--pt-gold,#c9a24b)]">
            {'★'.repeat(Math.round(n))}
            <span className="text-[var(--pt-line)]">{'★'.repeat(5 - Math.round(n))}</span>
        </span>
    );
}

/** The pictures: a big one with thumbnails; a click opens it large (zoom). */
function Gallery({ pictures, color }: { pictures: { url: string }[]; color: string | null }) {
    const { t } = useI18n();
    const [at, setAt] = useState(0);
    const [zoom, setZoom] = useState(false);
    const list = color ? [{ url: color }, ...pictures] : pictures;
    const shown = list[Math.min(at, Math.max(0, list.length - 1))];

    if (!shown) {
        return <div aria-hidden className="aspect-[4/5] w-full rounded-t-full bg-[var(--pt-line)]" />;
    }

    return (
        <div className="space-y-2">
            <button type="button" aria-label={t('Look closer')} onClick={() => setZoom(true)} className="block w-full cursor-zoom-in">
                <span className="block rounded-t-full border border-[var(--art-gold-2)] p-1.5">
                    <img src={shown.url} alt="" className="aspect-[4/5] w-full rounded-t-full object-cover" />
                </span>
            </button>
            {list.length > 1 && (
                <div className="flex gap-2 overflow-x-auto">
                    {list.map((p, i) => (
                        <button
                            key={p.url}
                            type="button"
                            aria-label={t('Photo {n}', { n: i + 1 })}
                            aria-current={i === at}
                            onClick={() => setAt(i)}
                            className={`h-16 w-16 shrink-0 overflow-hidden rounded-lg border ${i === at ? 'border-[var(--pt-ink)]' : 'border-[var(--pt-line)]'}`}
                        >
                            <img src={p.url} alt="" className="h-full w-full object-cover" />
                        </button>
                    ))}
                </div>
            )}
            {zoom && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label={t('Look closer')}
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                    onClick={() => setZoom(false)}
                >
                    <img src={shown.url} alt="" className="max-h-full max-w-full rounded-lg object-contain" />
                    <button
                        type="button"
                        autoFocus
                        aria-label={t('Close')}
                        className="absolute end-4 top-4 rounded-full bg-white/90 px-3 py-1 text-sm font-semibold"
                        onClick={() => setZoom(false)}
                    >
                        {t('Close')}
                    </button>
                </div>
            )}
        </div>
    );
}

/** Send the page to someone: the phone's own share sheet, or copy the address. */
function Share({ title }: { title: string }) {
    const { t } = useI18n();
    const [done, setDone] = useState(false);

    return (
        <button
            type="button"
            className="text-sm underline underline-offset-4"
            onClick={async () => {
                const url = window.location.href;
                try {
                    if (navigator.share) {
                        await navigator.share({ title, url });
                    } else {
                        await navigator.clipboard.writeText(url);
                        setDone(true);
                    }
                } catch {
                    // The person closed the share sheet: nothing to do.
                }
            }}
        >
            {done ? t('Address copied') : t('Share')}
        </button>
    );
}

function Reviews({ reviews, rating }: { reviews: Review[]; rating: Props['rating'] }) {
    const { t, num, date } = useI18n();

    return (
        <section aria-labelledby="rev-h" className="space-y-3">
            <div className="flex flex-wrap items-baseline gap-3">
                <h2 id="rev-h" className="font-display text-3xl">
                    {t('Reviews')}
                </h2>
                {rating.average !== null && (
                    <p className="tabular text-sm">
                        <Stars n={rating.average} /> <strong>{num(rating.average)}</strong> · {t('{n} reviews', { n: num(rating.count) })}
                    </p>
                )}
            </div>
            {reviews.length === 0 && <p className="text-sm text-[var(--pt-muted)]">{t('No review yet. Only people who bought it can write one.')}</p>}
            <ul className="space-y-3">
                {reviews.map((r) => (
                    <li key={r.id} className="space-y-1 rounded-[1.25rem] border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3 text-sm">
                        <p className="flex flex-wrap items-center gap-2">
                            <Stars n={r.rating} />
                            {r.verified && (
                                <span className="rounded bg-[var(--pt-surface)] px-1.5 py-0.5 text-xs font-semibold text-[var(--pt-ink)]">
                                    {t('Verified buyer')}
                                </span>
                            )}
                            <span className="text-xs text-[var(--pt-muted)]">{date(r.at)}</span>
                        </p>
                        {r.body && <p>{r.body}</p>}
                        {r.photos.length > 0 && (
                            <div className="flex gap-2">
                                {r.photos.map((u) => (
                                    <a key={u} href={u} target="_blank" rel="noreferrer">
                                        <img
                                            src={u}
                                            alt=""
                                            loading="lazy"
                                            className="h-20 w-20 rounded-lg border border-[var(--pt-line)] object-cover"
                                        />
                                    </a>
                                ))}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

function ReportCopy({ id }: { id: number }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');

    return open ? (
        <div className="space-y-2 rounded-[1.25rem] border p-3 text-sm">
            <label className="block font-medium">
                {t('What is copied?')}
                <Input
                    className="mt-1"
                    value={reason}
                    maxLength={300}
                    placeholder={t('For example: these are my photos')}
                    onChange={(e) => setReason(e.target.value)}
                />
            </label>
            <InputError message={errors.report} />
            <Button
                size="sm"
                disabled={reason.trim().length < 3}
                onClick={() => router.post(`/market/${id}/report`, { reason }, { preserveScroll: true, onSuccess: () => setOpen(false) })}
            >
                {t('Send')}
            </Button>
        </div>
    ) : (
        <button type="button" onClick={() => setOpen(true)} className="text-xs text-[var(--pt-muted)] underline underline-offset-4">
            {t('Report this as a copy')}
        </button>
    );
}

export default function MarketShow({
    listing,
    districts,
    token,
    questions,
    can_ask,
    wishlisted,
    bundles,
    offers,
    gallery,
    video,
    reviews,
    rating,
    pickup,
    cod,
    digital,
}: Props) {
    const { t, money, num, date } = useI18n();
    const [color, setColor] = useState('');
    useEffect(() => track('ViewContent', { id: listing.id, name: listing.title, value: listing.price }), [listing.id]); // eslint-disable-line react-hooks/exhaustive-deps
    // Choosing a colour changes the photo.
    const colorPhoto = listing.grid?.colors.find((c) => c.name === color && c.photo)?.photo ?? null;

    return (
        <PublicLayout>
            <PageHead
                title={listing.seo_title || listing.title}
                description={listing.seo_description || (listing.description ?? `${listing.title} · ${listing.sector_name}`)}
                image={gallery[0]?.url ?? null}
            >
                <JsonLd
                    data={{
                        '@context': 'https://schema.org',
                        '@type': 'Product',
                        name: listing.title,
                        description: listing.description ?? undefined,
                        image: gallery.map((g) => g.url),
                        ...(rating.average !== null
                            ? { aggregateRating: { '@type': 'AggregateRating', ratingValue: rating.average, reviewCount: rating.count } }
                            : {}),
                        offers: {
                            '@type': 'Offer',
                            price: listing.price,
                            priceCurrency: 'BDT',
                            availability: listing.in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                        },
                    }}
                />
            </PageHead>
            <div className="mx-auto max-w-5xl space-y-8">
                <Link href="/market" className="art-btn inline-flex h-10 items-center rounded-full border border-[var(--pt-ink)] px-5 text-sm">
                    {t('Back to the market')}
                </Link>
                <article className="grid gap-10 sm:grid-cols-2">
                    <div className="space-y-4">
                        <Gallery key={colorPhoto ?? 'main'} pictures={gallery} color={colorPhoto} />
                        {video && <video src={video} controls preload="metadata" playsInline aria-label={t('Video of this product')} className="aspect-video w-full rounded-[1.5rem] border bg-muted" />}
                    </div>
                    <div className="space-y-4">
                        <p className="text-xs font-semibold text-[var(--pt-muted)]">
                            {listing.sector_name}
                            {listing.premium && ` · ${t('Premium')}`}
                        </p>
                        <h1 className="font-display text-4xl leading-[1.08] text-balance sm:text-5xl">{listing.title}</h1>
                        <p className="flex flex-wrap items-center gap-3 text-sm">
                            {rating.average !== null && (
                                <span className="tabular">
                                    <Stars n={rating.average} /> {num(rating.average)} ({num(rating.count)})
                                </span>
                            )}
                            <Share title={listing.title} />
                        </p>
                        <p className="tabular font-display text-4xl">
                            <WasPrice was={listing.was} className="text-2xl" />
                            {money(listing.price)} <span className="text-base font-normal text-[var(--pt-muted)]">/ {unitLabel(listing.unit)}</span>
                        </p>
                        {listing.sale_percent != null && <SaleTag percent={listing.sale_percent} endsAt={listing.sale_ends_at} long />}
                        <dl className="divide-y divide-[var(--pt-line)] border-y border-[var(--pt-line)] text-sm [&>div]:py-2.5">
                            <div className="flex justify-between gap-3">
                                <dt className="text-[var(--pt-muted)]">{t('Sold by')}</dt>
                                <dd className="font-semibold">
                                    {listing.shop ? (
                                        <Link href={`/shop/${listing.shop}`} className="underline underline-offset-4">
                                            {listing.seller}
                                        </Link>
                                    ) : (
                                        listing.seller
                                    )}
                                    {listing.trusted && (
                                        <span className="ms-2 rounded bg-[var(--pt-surface)] px-1.5 text-xs font-semibold text-[var(--pt-ink)]">
                                            {t('Trusted seller')}
                                        </span>
                                    )}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[var(--pt-muted)]">{t('Delivery charge')}</dt>
                                <dd className="tabular font-semibold">{listing.delivery_charge > 0 ? money(listing.delivery_charge) : t('Free')}</dd>
                            </div>
                            {listing.min_qty > 1 && (
                                <div className="flex justify-between gap-3">
                                    <dt className="text-[var(--pt-muted)]">{t('Smallest order')}</dt>
                                    <dd className="tabular font-semibold">{num(listing.min_qty)}</dd>
                                </div>
                            )}
                            {listing.size && (
                                <div className="flex justify-between gap-3">
                                    <dt className="text-[var(--pt-muted)]">{t('Size')}</dt>
                                    <dd className="tabular font-semibold">
                                        {[
                                            listing.size.length && `${t('Length')} ${num(listing.size.length)}`,
                                            listing.size.width && `${t('Width')} ${num(listing.size.width)}`,
                                            listing.size.height && `${t('Height')} ${num(listing.size.height)}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}{' '}
                                        {t(listing.size.unit === 'foot' ? 'foot' : listing.size.unit === 'cm' ? 'cm' : 'inch')}
                                    </dd>
                                </div>
                            )}
                            {listing.details.weight !== null && (
                                <div className="flex justify-between gap-3">
                                    <dt className="text-[var(--pt-muted)]">{t('Weight')}</dt>
                                    <dd className="tabular font-semibold">{t('{n} kg', { n: num(listing.details.weight) })}</dd>
                                </div>
                            )}
                            {listing.details.expires_on && (
                                <div className="flex justify-between gap-3">
                                    <dt className="text-[var(--pt-muted)]">{t('Expiry date')}</dt>
                                    <dd className="font-semibold">{date(listing.details.expires_on)}</dd>
                                </div>
                            )}
                            {listing.details.warranty_months !== null && (
                                <div className="flex justify-between gap-3">
                                    <dt className="text-[var(--pt-muted)]">{t('Warranty')}</dt>
                                    <dd className="tabular font-semibold">{t('{n} months', { n: num(listing.details.warranty_months) })}</dd>
                                </div>
                            )}
                            <div className="flex justify-between gap-3">
                                <dt className="text-[var(--pt-muted)]">{t('Availability')}</dt>
                                <dd className="font-semibold">
                                    {listing.in_stock ? t('Available') : listing.preorder ? t('Pre-order') : t('Sold out')}
                                </dd>
                            </div>
                        </dl>
                        {listing.description && <p className="whitespace-pre-line">{listing.description}</p>}
                        {listing.can_report && <ReportCopy id={listing.id} />}
                        {(listing.return_note || listing.return_rule) && (
                            <p className="rounded-[1.25rem] border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3 text-sm">
                                <strong>{t('Returns')}: </strong>
                                {t('Within {n} days of getting it.', { n: num(listing.return_days) })} {listing.return_note || listing.return_rule}
                            </p>
                        )}
                    </div>
                </article>
                {bundles.map((b) => (
                    <section key={b.id} className="space-y-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="font-display text-xl font-bold">{b.title}</h2>
                            <SaleTag percent={b.percent} endsAt={null} />
                        </div>
                        <ul className="divide-y text-sm">
                            {b.items.map((i) => (
                                <li key={i.id} className="flex justify-between gap-3 py-1.5">
                                    {i.id === listing.id ? (
                                        <span className="font-medium">{i.title}</span>
                                    ) : (
                                        <Link href={`/market/${i.id}`} className="underline underline-offset-4">
                                            {i.title}
                                        </Link>
                                    )}
                                    <span className="tabular">{money(i.price)}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="flex flex-wrap items-baseline justify-between gap-2">
                            <span className="text-sm text-[var(--pt-muted)]">{t('Buy them all together and save {amount}', { amount: money(b.save) })}</span>
                            <span className="tabular font-display text-2xl">
                                <WasPrice was={b.was} className="text-base" />
                                {money(b.now)}
                            </span>
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                b.items.forEach((i) => track('AddToCart', { id: i.id, name: i.title, value: i.price }));
                                router.post(`/market-cart/bundle/${b.id}`, {}, { preserveScroll: true });
                            }}
                        >
                            {t('Add all to the market cart')}
                        </Button>
                    </section>
                ))}
                {(listing.in_stock || listing.preorder) && (
                    <OrderForm
                        listing={listing}
                        districts={districts}
                        token={token}
                        color={color}
                        setColor={setColor}
                        pickup={pickup}
                        cod={cod}
                        digital={digital}
                    />
                )}
                {can_ask && (
                    <Button
                        variant="outline"
                        size="sm"
                        aria-pressed={wishlisted}
                        onClick={() => router.post(`/market/${listing.id}/wishlist`, {}, { preserveScroll: true })}
                    >
                        {wishlisted ? t('Saved to wishlist') : t('Save to wishlist')}
                    </Button>
                )}
                {can_ask && <WhyNot id={listing.id} minQty={listing.min_qty} price={listing.price} />}
                {offers.length > 1 && (
                    <section aria-labelledby="offers-h" className="space-y-2">
                        <h2 id="offers-h" className="font-display text-3xl">
                            {t('The same product from {n} sellers', { n: num(offers.length) })}
                        </h2>
                        <ul className="divide-y rounded-[1.5rem] border border-[var(--pt-line)] bg-[var(--pt-surface)] text-sm">
                            {offers.map((o) => (
                                <li key={o.id} className={o.this ? 'bg-[var(--pt-sage)]' : ''}>
                                    <Link href={`/market/${o.id}`} className="flex flex-wrap items-center gap-x-5 gap-y-1 px-4 py-3">
                                        <span className="min-w-32 flex-1 font-medium">
                                            {o.name}{' '}
                                            {o.best && (
                                                <span className="ms-1 rounded bg-[var(--pt-surface)] px-1.5 py-0.5 text-xs font-semibold text-[var(--pt-ink)]">
                                                    {t('Best choice')}
                                                </span>
                                            )}
                                        </span>
                                        <span className="tabular">{money(o.price)}</span>
                                        <span className="tabular text-[var(--pt-muted)]">
                                            {t('Delivery')} {o.delivery > 0 ? money(o.delivery) : t('Free')}
                                        </span>
                                        <span className="text-[var(--pt-muted)]">{t(o.rating)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <p className="text-xs text-[var(--pt-muted)]">
                            {t('The best choice counts the price, the delivery charge and how the seller has done, not the lowest price alone.')}
                        </p>
                    </section>
                )}
                <Reviews reviews={reviews} rating={rating} />
                <Questions id={listing.id} questions={questions} canAsk={can_ask} />
            </div>
        </PublicLayout>
    );
}
