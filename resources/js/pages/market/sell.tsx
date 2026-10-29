import SellerBrand from '@/components/seller-brand';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    title: string;
    description: string | null;
    status: 'pending' | 'published' | 'rejected' | 'paused';
    sector: string;
    sector_id: number;
    unit: string;
    public_price: number | null;
    wholesale_price: number | null;
    min_qty: number;
    delivery_charge: number;
    stock: number | null;
    review_note: string | null;
    commission_request: string | null;
    has_photo: boolean;
    has_video: boolean;
    item_weight: number | null;
    expires_on: string | null;
    warranty_months: number | null;
    alt_names: string | null;
    preorder_days: number | null;
    return_days: number | null;
    return_note: string | null;
    seo_title: string | null;
    seo_description: string | null;
    photos: { id: number; url: string }[];
    digital: { name: string | null; max: number; days: number } | null;
    pickup: boolean;
    cod_allowed: boolean;
    catalog_product_id: number | null;
    cost_price: number | null;
    low_stock_at: number | null;
    extra_sector_ids: number[];
    variants: { color: string; size: string; stock: number | null; price: number | null; has_photo: boolean }[];
    size: { height: number | null; length: number | null; width: number | null; unit: string };
    commission: { kind: 'percent' | 'taka'; value: number } | null;
}

interface Props {
    listings: Row[];
    sectors: { id: number; name: string; unit: string; allow_fraction: boolean; fields: string[]; catalog_required: boolean; catalog: { id: number; name: string }[] }[];
    shop: { address: string | null; name_shown: boolean };
    asked: { id: number; name: string; status: string; note: string | null }[];
    paused: boolean;
    trust: { trusted: boolean; clean: number; needed: number; limit: number | null };
    deposit: { on: boolean; required: number; held: number; short: number };
    token: { amount: number; min_order: number; per_buyer: number; active: boolean; ends_at: string | null } | null;
}

const text = (v: number | null) => (v === null ? '' : String(v));

/** One listing's fields, for a new one or an existing one. A new photo is optional. */
function ListingForm({ sectors, row, onDone, shop }: { sectors: Props['sectors']; row?: Row; onDone?: () => void; shop: Props['shop'] }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({
        sector_id: String(row?.sector_id ?? sectors[0]?.id ?? ''),
        title: row?.title ?? '',
        description: row?.description ?? '',
        public_price: text(row?.public_price ?? null),
        wholesale_price: text(row?.wholesale_price ?? null),
        min_qty: row ? String(row.min_qty) : '1',
        delivery_charge: row ? String(row.delivery_charge) : '0',
        stock: text(row?.stock ?? null),
        commission_request: row?.commission_request ?? '',
        pickup: row?.pickup ?? false,
        cod_allowed: row?.cod_allowed ?? true,
        confirm_price: false,
        catalog_product_id: text(row?.catalog_product_id ?? null),
        item_weight: text(row?.item_weight ?? null),
        expires_on: row?.expires_on ?? '',
        warranty_months: text(row?.warranty_months ?? null),
        alt_names: row?.alt_names ?? '',
        preorder_days: text(row?.preorder_days ?? null),
        return_days: text(row?.return_days ?? null),
        return_note: row?.return_note ?? '',
        seo_title: row?.seo_title ?? '',
        seo_description: row?.seo_description ?? '',
        cost_price: text(row?.cost_price ?? null),
        low_stock_at: text(row?.low_stock_at ?? null),
        item_length: text(row?.size.length ?? null),
        item_width: text(row?.size.width ?? null),
        item_height: text(row?.size.height ?? null),
        item_size_unit: row?.size.unit ?? 'inch',
    });
    const [photo, setPhoto] = useState<File | null>(null);
    const [ask, setAsk] = useState('');
    const [extra, setExtra] = useState<number[]>(row?.extra_sector_ids ?? []);
    const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value });
    const sector = sectors.find((s) => String(s.id) === f.sector_id);
    const unit = sector?.unit ?? '';
    const uses = (k: string) => sector?.fields.includes(k) ?? false;

    const send = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(row ? `/wholesale/sell/${row.id}` : '/wholesale/sell', { ...f, extra_sector_ids: extra.join(','), photo }, { forceFormData: true, preserveScroll: true, onSuccess: () => onDone?.() });
    };

    return (
        <form onSubmit={send} className="grid gap-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 sm:grid-cols-2">
            <div>
                <label htmlFor={`s-sector-${row?.id ?? 'new'}`} className="text-sm font-medium">
                    {t('Kind of business')}
                </label>
                <select id={`s-sector-${row?.id ?? 'new'}`} value={f.sector_id} onChange={set('sector_id')} className="mt-1 block h-10 w-full rounded-md border bg-background px-2 text-sm">
                    {sectors.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </select>
            </div>
            <div>
                <label className="text-sm font-medium">
                    {t('Title')}
                    <Input className="mt-1" value={f.title} maxLength={150} onChange={set('title')} />
                </label>
                <InputError message={errors.title} />
            </div>
            <div className="sm:col-span-2">
                <label className="text-sm font-medium">
                    {t('Details')}
                    <textarea rows={3} maxLength={3000} value={f.description} onChange={set('description')} className="mt-1 w-full rounded-md border bg-background p-2 text-sm" />
                </label>
            </div>
            <label className="text-sm font-medium">
                {t('Your price for the public, per {unit}', { unit })}
                <Input className="mt-1" type="number" min="0" step="any" value={f.public_price} onChange={set('public_price')} />
            </label>
            <label className="text-sm font-medium">
                {t('Your price for trade buyers, per {unit}', { unit })}
                <Input className="mt-1" type="number" min="0" step="any" value={f.wholesale_price} onChange={set('wholesale_price')} />
            </label>
            <label className="text-sm font-medium">
                {t('Smallest order for trade buyers')}
                <Input className="mt-1" type="number" min="0" step="any" value={f.min_qty} onChange={set('min_qty')} />
            </label>
            <label className="text-sm font-medium">
                {t('Delivery charge you set')}
                <Input className="mt-1" type="number" min="0" step="any" value={f.delivery_charge} onChange={set('delivery_charge')} />
            </label>
            <label className="text-sm font-medium">
                {t('How many you have')}
                <Input className="mt-1" type="number" min="0" step="any" value={f.stock} onChange={set('stock')} />
            </label>
            <label className="text-sm font-medium">
                {t('Your own cost for one (only you see it)')}
                <Input className="mt-1" type="number" min="0" step="any" value={f.cost_price} onChange={set('cost_price')} />
            </label>
            <label className="text-sm font-medium">
                {t('Tell me when it falls to')}
                <Input className="mt-1" type="number" min="0" step="any" value={f.low_stock_at} onChange={set('low_stock_at')} />
            </label>
            {sectors.length > 1 && (
                <fieldset className="sm:col-span-2">
                    <legend className="text-sm font-medium">{t('Also show it in')}</legend>
                    <p className="text-xs text-[var(--pt-muted)]">{t('It stays one product with one stock.')}</p>
                    <div className="mt-1 flex flex-wrap gap-3 text-sm">
                        {sectors
                            .filter((s) => String(s.id) !== f.sector_id)
                            .map((s) => (
                                <label key={s.id} className="flex items-center gap-1.5">
                                    <input type="checkbox" checked={extra.includes(s.id)} onChange={(e) => setExtra(e.target.checked ? [...extra, s.id] : extra.filter((x) => x !== s.id))} />
                                    {s.name}
                                </label>
                            ))}
                    </div>
                </fieldset>
            )}
            {sector && (sector.catalog.length > 0 || sector.catalog_required) && (
                <div className="space-y-1 sm:col-span-2">
                    <label className="text-sm font-medium">
                        {sector.catalog_required ? t('Pick the product from the catalog') : t('Is it in the catalog? (so buyers can compare)')}
                        <select value={f.catalog_product_id} onChange={set('catalog_product_id')} className="mt-1 block h-10 w-full rounded-md border bg-background px-2 text-sm">
                            <option value="">{t('— not in the list —')}</option>
                            {sector.catalog.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input aria-label={t('Ask us to add a product')} className="h-8 w-64" placeholder={t('Not there? Write its name')} value={ask} onChange={(e) => setAsk(e.target.value)} />
                        <Button type="button" size="sm" variant="outline" disabled={ask.trim().length < 2} onClick={() => router.post('/wholesale/sell/catalog-request', { sector_id: f.sector_id, name: ask }, { preserveScroll: true, onSuccess: () => setAsk('') })}>
                            {t('Ask us to add it')}
                        </Button>
                    </div>
                    <InputError message={errors.catalog} />
                </div>
            )}
            {shop.name_shown && shop.address && (
                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" checked={f.pickup} onChange={(e) => setF({ ...f, pickup: e.target.checked })} />
                    {t('Buyers can collect this from my shop')}
                </label>
            )}
            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="checkbox" checked={f.cod_allowed} onChange={(e) => setF({ ...f, cod_allowed: e.target.checked })} />
                {t('Buyers can pay when it arrives (cash on delivery)')}
                {!f.cod_allowed && <span className="text-xs text-muted-foreground">{t('Off: buyers must pay online first, and you are paid after it is delivered.')}</span>}
            </label>
            {uses('weight') && (
                <label className="text-sm font-medium">
                    {t('Weight in kg')}
                    <Input className="mt-1" type="number" min="0" step="any" value={f.item_weight} onChange={set('item_weight')} />
                </label>
            )}
            <label className="text-sm font-medium sm:col-span-2">
                {t('Other names people call it, separated by commas (optional)')}
                <Input className="mt-1" maxLength={200} value={f.alt_names} onChange={set('alt_names')} />
            </label>
            <label className="text-sm font-medium">
                {t('If it runs out, the buyer can pre-order. It will be ready within')}
                <select className="mt-1 block h-9 w-full rounded-md border bg-background px-2 text-sm" value={f.preorder_days} onChange={set('preorder_days')}>
                    <option value="">{t('We decide')}</option>
                    {[2, 3, 4, 5].map((d) => (
                        <option key={d} value={d}>
                            {t('{n} days', { n: d })}
                        </option>
                    ))}
                </select>
            </label>
            <label className="text-sm font-medium">
                {t('Days the buyer can send it back (1 to 30, empty for our usual)')}
                <Input className="mt-1" type="number" min="1" max="30" step="1" value={f.return_days} onChange={set('return_days')} />
            </label>
            <label className="text-sm font-medium">
                {t('Return conditions, for example: unused, in its box (optional)')}
                <Input className="mt-1" maxLength={200} value={f.return_note} onChange={set('return_note')} />
            </label>
            <label className="text-sm font-medium sm:col-span-2">
                {t('Search page title (optional)')}
                <Input className="mt-1" maxLength={70} value={f.seo_title} onChange={set('seo_title')} />
            </label>
            <label className="text-sm font-medium sm:col-span-2">
                {t('Search page description (optional)')}
                <Input className="mt-1" maxLength={160} value={f.seo_description} onChange={set('seo_description')} />
            </label>
            {uses('expiry') && (
                <label className="text-sm font-medium">
                    {t('Expiry date')}
                    <Input className="mt-1" type="date" value={f.expires_on} onChange={set('expires_on')} />
                </label>
            )}
            {uses('warranty') && (
                <label className="text-sm font-medium">
                    {t('Warranty in months')}
                    <Input className="mt-1" type="number" min="0" step="1" value={f.warranty_months} onChange={set('warranty_months')} />
                </label>
            )}
            {uses('measurements') && (
            <fieldset className="grid gap-2 sm:col-span-2 sm:grid-cols-4">
                <legend className="text-sm font-medium">{t('Size of the item (optional, for wood and furniture)')}</legend>
                <Input aria-label={t('Length')} placeholder={t('Length')} type="number" min="0" step="any" value={f.item_length} onChange={set('item_length')} />
                <Input aria-label={t('Width')} placeholder={t('Width')} type="number" min="0" step="any" value={f.item_width} onChange={set('item_width')} />
                <Input aria-label={t('Height')} placeholder={t('Height')} type="number" min="0" step="any" value={f.item_height} onChange={set('item_height')} />
                <select aria-label={t('Unit')} value={f.item_size_unit} onChange={set('item_size_unit')} className="h-9 rounded-md border bg-background px-2 text-sm">
                    <option value="inch">{t('inch')}</option>
                    <option value="foot">{t('foot')}</option>
                    <option value="cm">{t('cm')}</option>
                </select>
            </fieldset>
            )}
            <div>
                <label htmlFor={`s-photo-${row?.id ?? 'new'}`} className="text-sm font-medium">
                    {t('Photo')}
                </label>
                <input id={`s-photo-${row?.id ?? 'new'}`} type="file" accept="image/*" className="mt-1 block text-sm" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                <InputError message={errors.photo} />
            </div>
            {row && (
                <label className="text-sm font-medium sm:col-span-2">
                    {t('Want a lower commission? Tell us how much and why')}
                    <Input className="mt-1" maxLength={300} value={f.commission_request} onChange={set('commission_request')} />
                </label>
            )}
            {errors.price_warning && (
                <div role="alert" className="space-y-2 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm sm:col-span-2">
                    <p className="font-medium">{errors.price_warning}</p>
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={f.confirm_price} onChange={(e) => setF({ ...f, confirm_price: e.target.checked })} />
                        {t('Yes, the price is right. Change it.')}
                    </label>
                </div>
            )}
            <div className="sm:col-span-2">
                <InputError message={errors.listing} />
                <Button type="submit">{row ? t('Save') : t('Send for approval')}</Button>
                {row && onDone && (
                    <Button type="button" variant="ghost" className="ml-2" onClick={onDone}>
                        {t('Cancel')}
                    </Button>
                )}
            </div>
        </form>
    );
}

/** Colours down the side, sizes across the top, a number in each cell (spec ch 15). Price and a photo per colour are optional. */
function GridEditor({ row }: { row: Row }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [open, setOpen] = useState(false);
    const colors0 = [...new Set(row.variants.map((v) => v.color))].filter(Boolean);
    const sizes0 = [...new Set(row.variants.map((v) => v.size))].filter(Boolean);
    const [colors, setColors] = useState(colors0.join(', '));
    const [sizes, setSizes] = useState(sizes0.join(', '));
    const [cells, setCells] = useState<Record<string, { stock: string; price: string }>>(Object.fromEntries(row.variants.map((v) => [`${v.color}|${v.size}`, { stock: text(v.stock), price: text(v.price) }])));
    const list = (s: string) => [...new Set(s.split(',').map((x) => x.trim()).filter(Boolean))];
    const cs = list(colors);
    const ss = list(sizes);
    const rows = (cs.length ? cs : ['']).flatMap((c) => (ss.length ? ss : ['']).map((s) => ({ c, s })));
    const cell = (c: string, s: string) => cells[`${c}|${s}`] ?? { stock: '', price: '' };
    const put = (c: string, s: string, k: 'stock' | 'price', v: string) => setCells({ ...cells, [`${c}|${s}`]: { ...cell(c, s), [k]: v } });

    if (!open) {
        return (
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {row.variants.length ? t('Colours and sizes ({n})', { n: row.variants.length }) : t('Add colours and sizes')}
            </Button>
        );
    }

    return (
        <div className="space-y-3 rounded-[1.25rem] border p-3 text-sm">
            <p className="text-[var(--pt-muted)]">{t('Write the colours and the sizes with commas. Put how many you have in each cell. Leave the price empty to use the product price. An empty list removes the grid.')}</p>
            <div className="grid gap-2 sm:grid-cols-2">
                <label className="font-medium">
                    {t('Colours')}
                    <Input className="mt-1" value={colors} onChange={(e) => setColors(e.target.value)} placeholder="Red, Blue, Green" />
                </label>
                <label className="font-medium">
                    {t('Sizes')}
                    <Input className="mt-1" value={sizes} onChange={(e) => setSizes(e.target.value)} placeholder="S, M, L, XL" />
                </label>
            </div>
            {rows[0] && (rows[0].c !== '' || rows[0].s !== '') && (
                <div className="overflow-x-auto">
                    <table className="w-full text-start">
                        <thead>
                            <tr className="text-xs text-[var(--pt-muted)]">
                                <th className="text-start">{t('Colour')} / {t('Size')}</th>
                                <th className="text-start">{t('How many')}</th>
                                <th className="text-start">{t('Price (optional)')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(({ c, s }) => (
                                <tr key={`${c}|${s}`}>
                                    <td className="py-1 pe-2 font-medium">{[c, s].filter(Boolean).join(' ')}</td>
                                    <td className="pe-2">
                                        <Input aria-label={t('How many')} className="h-8 w-24" type="number" min="0" step="any" value={cell(c, s).stock} onChange={(e) => put(c, s, 'stock', e.target.value)} />
                                    </td>
                                    <td>
                                        <Input aria-label={t('Price (optional)')} className="h-8 w-28" type="number" min="0" step="any" value={cell(c, s).price} onChange={(e) => put(c, s, 'price', e.target.value)} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <InputError message={errors.variants} />
            <div className="flex flex-wrap gap-2">
                <Button size="sm" onClick={() => router.put(`/wholesale/sell/${row.id}/variants`, { cells: (rows[0] && (rows[0].c !== '' || rows[0].s !== '') ? rows : []).map(({ c, s }) => ({ color: c, size: s, stock: cell(c, s).stock, price: cell(c, s).price })) }, { preserveScroll: true, onSuccess: () => setOpen(false) })}>
                    {t('Save the grid')}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                    {t('Cancel')}
                </Button>
            </div>
            {cs.length > 0 && row.variants.length > 0 && (
                <div className="space-y-1">
                    <p className="font-medium">{t('A photo for each colour')}</p>
                    {colors0.map((c) => (
                        <label key={c} className="flex items-center gap-2">
                            <span className="w-24">{c}</span>
                            <input type="file" accept="image/*" className="text-sm" onChange={(e) => e.target.files?.[0] && router.post(`/wholesale/sell/${row.id}/color-photo`, { color: c, photo: e.target.files[0] }, { forceFormData: true, preserveScroll: true })} />
                            {row.variants.find((v) => v.color === c)?.has_photo && <span className="text-xs text-success">{t('Has a photo')}</span>}
                        </label>
                    ))}
                </div>
            )}
        </div>
    );
}

/** A file the buyer downloads after paying, a few times and for a few days (spec ch 13). */
function DigitalFile({ row }: { row: Row }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [open, setOpen] = useState(false);
    const [max, setMax] = useState(String(row.digital?.max ?? 3));
    const [days, setDays] = useState(String(row.digital?.days ?? 7));

    if (!open) {
        return (
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {row.digital ? t('File to download') : t('Add a file to download')}
            </Button>
        );
    }

    return (
        <div className="w-full space-y-2 rounded-[1.25rem] border p-3 text-sm">
            <p className="text-[var(--pt-muted)]">{t('For an e-book, music, a picture or any file. The buyer pays online first, then downloads it. There is no delivery and no cash on delivery.')}</p>
            {row.digital && (
                <p className="flex flex-wrap items-center gap-3">
                    <strong>{row.digital.name}</strong>
                    <span className="text-[var(--pt-muted)]">{t('{n} downloads, {d} days', { n: num(row.digital.max), d: num(row.digital.days) })}</span>
                    <Button size="sm" variant="ghost" onClick={() => window.confirm(t('Remove the file?')) && router.delete(`/wholesale/sell/${row.id}/digital`, { preserveScroll: true })}>
                        {t('Remove')}
                    </Button>
                </p>
            )}
            <div className="flex flex-wrap items-end gap-3">
                <label className="text-xs">
                    {t('Downloads allowed')}
                    <Input className="mt-1 w-24" inputMode="numeric" value={max} onChange={(e) => setMax(e.target.value)} />
                </label>
                <label className="text-xs">
                    {t('Days to download')}
                    <Input className="mt-1 w-24" inputMode="numeric" value={days} onChange={(e) => setDays(e.target.value)} />
                </label>
            </div>
            <input
                type="file"
                accept=".pdf,.epub,.zip,.mp3,.mp4,.jpg,.jpeg,.png,.txt,.docx,.xlsx"
                className="block text-sm"
                onChange={(e) => e.target.files?.[0] && router.post(`/wholesale/sell/${row.id}/digital`, { file: e.target.files[0], max, days }, { forceFormData: true, preserveScroll: true })}
            />
            <InputError message={errors.digital ?? errors.file} />
            <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                {t('Close')}
            </Button>
        </div>
    );
}

/** More pictures of one product. The first is the main one; any other can be made the main one. */
function MorePhotos({ row }: { row: Row }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {t('More photos ({n})', { n: row.photos.length })}
            </Button>
        );
    }

    return (
        <div className="w-full space-y-2 rounded-[1.25rem] border p-3 text-sm">
            <p className="text-[var(--pt-muted)]">{t('Up to five more photos. Buyers can tap a photo to look closer.')}</p>
            <div className="flex flex-wrap gap-3">
                {row.photos.map((p) => (
                    <div key={p.id} className="space-y-1">
                        <img src={p.url} alt="" className="h-20 w-20 rounded-lg border object-cover" />
                        <div className="flex gap-1">
                            <Button size="sm" variant="ghost" onClick={() => router.put(`/wholesale/sell/photos/${p.id}/main`, {}, { preserveScroll: true })}>
                                {t('Main')}
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => router.delete(`/wholesale/sell/photos/${p.id}`, { preserveScroll: true })}>
                                {t('Remove')}
                            </Button>
                        </div>
                    </div>
                ))}
            </div>
            <input type="file" accept="image/*" className="block text-sm" onChange={(e) => e.target.files?.[0] && router.post(`/wholesale/sell/${row.id}/photos`, { photo: e.target.files[0] }, { forceFormData: true, preserveScroll: true })} />
            <InputError message={errors.photo} />
            <div className="space-y-1 border-t pt-2">
                <p className="font-medium">{row.has_video ? t('This product has a video') : t('Add a video')}</p>
                <p className="text-[var(--pt-muted)]">{t('One short clip, MP4 or WebM, up to 20 MB. Buyers watch it under the photos.')}</p>
                <input type="file" accept="video/mp4,video/webm" aria-label={t('Add a video')} className="block text-sm" onChange={(e) => e.target.files?.[0] && router.post(`/wholesale/sell/${row.id}/video`, { video: e.target.files[0] }, { forceFormData: true, preserveScroll: true })} />
                {row.has_video && (
                    <Button size="sm" variant="ghost" onClick={() => router.delete(`/wholesale/sell/${row.id}/video`, { preserveScroll: true })}>
                        {t('Remove the video')}
                    </Button>
                )}
                <InputError message={errors.video} />
            </div>
            <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                {t('Close')}
            </Button>
        </div>
    );
}

/** Where buyers may collect (spec ch 20). It only works while the owner shows the seller's own name. */
function ShopAddress({ shop }: { shop: Props['shop'] }) {
    const { t } = useI18n();
    const [address, setAddress] = useState(shop.address ?? '');

    return (
        <section className="space-y-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
            <div>
                <h2 className="font-semibold">{t('Where buyers can collect')}</h2>
                <p className="text-[var(--pt-muted)]">{shop.name_shown ? t('Write your shop address. Then you can let buyers collect a product from you, with no delivery charge.') : t('Buyers can collect from you only when we show your own name. Ask us.')}</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <Input aria-label={t('Shop address')} className="max-w-md" maxLength={250} value={address} onChange={(e) => setAddress(e.target.value)} />
                <Button size="sm" onClick={() => router.put('/wholesale/shop-address', { address }, { preserveScroll: true })}>
                    {t('Save')}
                </Button>
            </div>
        </section>
    );
}

function TokenForm({ token }: { token: Props['token'] }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ amount: token ? String(token.amount) : '', min_order: token ? String(token.min_order || '') : '', per_buyer: token ? String(token.per_buyer) : '1', ends_at: token?.ends_at ?? '', active: token?.active ?? true });
    const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: k === 'active' ? e.target.checked : e.target.value });

    return (
        <section className="space-y-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
            <div>
                <h2 className="font-semibold">{t('Your Aqua Token')}</h2>
                <p className="text-[var(--pt-muted)]">{t('One token for all your buyers, only on your own products. The amount comes off what the buyer pays and off what you are paid.')}</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-4">
                <label className="font-medium">
                    {t('Amount off')}
                    <Input className="mt-1" inputMode="decimal" value={f.amount} onChange={set('amount')} />
                </label>
                <label className="font-medium">
                    {t('Smallest order (optional)')}
                    <Input className="mt-1" inputMode="decimal" value={f.min_order} onChange={set('min_order')} />
                </label>
                <label className="font-medium">
                    {t('Times one buyer can use it')}
                    <Input className="mt-1" inputMode="numeric" value={f.per_buyer} onChange={set('per_buyer')} />
                </label>
                <label className="font-medium">
                    {t('Last day (optional)')}
                    <Input className="mt-1" type="date" value={f.ends_at} onChange={set('ends_at')} />
                </label>
            </div>
            <InputError message={errors.amount ?? errors.per_buyer ?? errors.ends_at} />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={f.active} onChange={set('active')} />
                    {t('Buyers can use it now')}
                </label>
                <Button size="sm" disabled={f.amount.trim() === ''} onClick={() => router.put('/wholesale/sell-token', { ...f, active: f.active ? 1 : 0 }, { preserveScroll: true })}>
                    {t('Save my token')}
                </Button>
            </div>
        </section>
    );
}

export default function Sell({ listings, sectors, paused, trust, token, asked, shop, deposit }: Props) {
    const { t, money, num } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const sellerOrders = (usePage().props.auth as { sellerOrders?: number }).sellerOrders ?? 0;
    const [editing, setEditing] = useState<number | null>(null);
    const state = (s: Row['status']) => (s === 'pending' ? t('Waiting for us') : s === 'published' ? t('Shown to buyers') : s === 'paused' ? t('Paused') : t('Not published'));

    return (
        <PublicLayout>
            <Head title={t('Sell')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <SellerBrand />
                    <h1 className="font-display text-3xl font-extrabold">{t('Sell')}</h1>
                    <div className="flex gap-4">
                        <Link href="/wholesale/sell/orders" className="text-sm font-semibold underline underline-offset-4">
                            {t('Orders for your products')}
                            {sellerOrders ? <span className="tabular ml-1 rounded-full bg-warning px-1.5 text-xs text-white">{num(sellerOrders)}</span> : null}
                        </Link>
                        <Link href="/wholesale/sell/offers" className="text-sm font-semibold underline underline-offset-4">
                            {t('Price offers')}
                        </Link>
                        <Link href="/wholesale/sell/deals" className="text-sm font-semibold underline underline-offset-4">
                            {t('Sales and bundles')}
                        </Link>
                        <Link href="/wholesale/sell/services" className="text-sm font-semibold underline underline-offset-4">
                            {t('Services')}
                        </Link>
                        <Link href="/wholesale/sell/questions" className="text-sm font-semibold underline underline-offset-4">
                            {t('Questions from buyers')}
                        </Link>
                        <Link href="/wholesale/sell/reports" className="text-sm font-semibold underline underline-offset-4">
                            {t('How you are doing')}
                        </Link>
                        <Link href="/wholesale/sell/statements" className="text-sm font-semibold underline underline-offset-4">
                            {t('Commission statements')}
                        </Link>
                        <Link href="/wholesale/account" className="text-sm font-semibold underline underline-offset-4">
                            {t('Your account')}
                        </Link>
                    </div>
                </div>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}

                {deposit.on && deposit.required > 0 && (
                    <p role="status" className={`rounded-[1.5rem] border px-4 py-3 text-sm ${deposit.short > 0 ? 'border-warning/40 bg-warning/10' : 'bg-[var(--pt-surface)]'}`}>
                        {deposit.short > 0
                            ? t('You are asked to keep {required} with us as a deposit and we hold {held}. Send {short} more before you send in new products. Tell us when you have paid it.', { required: money(deposit.required), held: money(deposit.held), short: money(deposit.short) })
                            : t('Your deposit of {held} is with us. It is given back when you leave, or kept for a reason we write down.', { held: money(deposit.held) })}
                    </p>
                )}

                {!trust.trusted && trust.limit !== null && (
                    <p className="rounded-[1.5rem] border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
                        {t('You are new here, so products can be up to {limit} for now. After {m} finished orders with no complaint the limit goes. You have {n}.', { limit: money(trust.limit), m: num(trust.needed), n: num(trust.clean) })}
                    </p>
                )}

                <section className="flex flex-wrap items-center justify-between gap-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                    <div>
                        <h2 className="font-semibold">{t('Holiday mode')}</h2>
                        <p className="text-[var(--pt-muted)]">{paused ? t('New orders are off. Orders already made carry on.') : t('Going away? Turn off new orders. Orders already made carry on.')}</p>
                    </div>
                    <Button size="sm" variant="outline" onClick={() => router.put('/wholesale/vacation', { paused: !paused }, { preserveScroll: true })}>
                        {paused ? t('I am back') : t('I am going away')}
                    </Button>
                </section>

                <TokenForm token={token} />
                <ShopAddress shop={shop} />

                {asked.length > 0 && (
                    <section className="space-y-1 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                        <h2 className="font-semibold">{t('Products you asked us to add')}</h2>
                        <ul>
                            {asked.map((a) => (
                                <li key={a.id}>
                                    {a.name} · {a.status === 'pending' ? t('Waiting for us') : a.status === 'approved' ? t('Added') : `${t('Not added')}${a.note ? `: ${a.note}` : ''}`}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {sectors.length === 0 ? (
                    <p className="rounded-[1.5rem] border border-dashed p-8 text-center text-sm text-[var(--pt-muted)]">{t('Your account has no kind of business to sell in yet. Ask us.')}</p>
                ) : (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('Add a listing')}</h2>
                        <p className="text-sm text-[var(--pt-muted)]">{t('We read every listing and add our own commission to your price before it is shown. Buyers pay your price and our commission together.')}</p>
                        <ListingForm sectors={sectors} shop={shop} />
                    </section>
                )}

                <section aria-labelledby="mine-h" className="space-y-2">
                    <h2 id="mine-h" className="font-display text-xl font-bold">
                        {t('Your listings')}
                    </h2>
                    {listings.length === 0 ? (
                        <p className="text-sm text-[var(--pt-muted)]">{t('Nothing yet.')}</p>
                    ) : (
                        <ul className="space-y-3">
                            {listings.map((l) => (
                                <li key={l.id} className="space-y-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <p className="font-semibold">{l.title}</p>
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold">{state(l.status)}</span>
                                    </div>
                                    <p className="tabular text-sm text-[var(--pt-muted)]">
                                        {l.sector}
                                        {l.public_price !== null && ` · ${t('Public')} ${money(l.public_price)}`}
                                        {l.wholesale_price !== null && ` · ${t('Trade')} ${money(l.wholesale_price)}`}
                                        {l.stock !== null && ` · ${t('{n} left', { n: num(l.stock) })}`}
                                    </p>
                                    {l.commission && <p className="text-xs text-[var(--pt-muted)]">{l.commission.kind === 'percent' ? t('Our commission is {n}% on top of your price.', { n: num(l.commission.value) }) : t('Our commission is {n} taka on top of your price.', { n: num(l.commission.value) })}</p>}
                                    {l.status === 'rejected' && l.review_note && <p className="text-sm">{l.review_note}</p>}
                                    {l.status === 'pending' && !l.has_photo && <p className="text-xs text-warning">{t('A photo helps buyers and gets it read sooner.')}</p>}
                                    {editing === l.id ? (
                                        <ListingForm sectors={sectors} shop={shop} row={l} onDone={() => setEditing(null)} />
                                    ) : (
                                        <div className="flex flex-wrap gap-2">
                                            <MorePhotos row={l} />
                                            <DigitalFile row={l} />
                                            {sectors.find((s) => s.id === l.sector_id)?.fields.some((x) => x === 'color' || x === 'size') && <GridEditor row={l} />}
                                            <Button size="sm" variant="outline" onClick={() => setEditing(l.id)}>
                                                {t('Change')}
                                            </Button>
                                            {(l.status === 'published' || l.status === 'paused') && (
                                                <Button size="sm" variant="outline" onClick={() => router.put(`/wholesale/sell/${l.id}/pause`, { paused: l.status === 'published' }, { preserveScroll: true })}>
                                                    {l.status === 'published' ? t('Pause') : t('Show again')}
                                                </Button>
                                            )}
                                            <Button size="sm" variant="ghost" onClick={() => window.confirm(t('Remove this listing?')) && router.delete(`/wholesale/sell/${l.id}`, { preserveScroll: true })}>
                                                {t('Remove')}
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </PublicLayout>
    );
}
