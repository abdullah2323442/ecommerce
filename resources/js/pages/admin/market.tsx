import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Listing {
    id: number;
    title: string;
    description: string | null;
    sector: string;
    unit: string;
    seller: string;
    seller_id: number;
    show_name: boolean;
    public_price: number | null;
    wholesale_price: number | null;
    min_qty: number;
    delivery_charge: number;
    has_photo: boolean;
    flag: string | null;
    commission: { kind: 'percent' | 'taka'; value: number } | null;
    request: string | null;
    status: string;
    waiting_days?: number;
    no_photo?: boolean;
}

interface Sector {
    id: number;
    slug: string;
    name: string;
    name_bn: string | null;
    unit: string;
    allow_fraction: boolean;
    fields: string[] | null;
    custom_orders: boolean;
    catalog_required: boolean;
    premium_over: string | null;
    hero_path?: string | null;
    tagline?: string | null;
    tagline_bn?: string | null;
    preorder: boolean;
    chat: boolean;
    return_rule: string | null;
    is_active: boolean;
    sort: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Market', href: '/admin/market' }];

function CommissionBox({ l, review }: { l: Listing; review?: boolean }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [kind, setKind] = useState(l.commission?.kind ?? 'percent');
    const [value, setValue] = useState(String(l.commission?.value ?? ''));
    const [note, setNote] = useState('');

    return (
        <div className="flex flex-wrap items-center gap-2">
            <select aria-label={t('Kind of commission')} value={kind} onChange={(e) => setKind(e.target.value as 'percent' | 'taka')} className="h-8 rounded-md border bg-background px-2 text-sm">
                <option value="percent">{t('percent')}</option>
                <option value="taka">{t('taka')}</option>
            </select>
            <Input aria-label={t('Our commission')} className="h-8 w-24" type="number" min="0" step="any" value={value} onChange={(e) => setValue(e.target.value)} />
            {review ? (
                <>
                    <Button size="sm" disabled={value === ''} onClick={() => router.post(`/admin/market/${l.id}/approve`, { kind, value }, { preserveScroll: true })}>
                        {t('Publish')}
                    </Button>
                    <Input aria-label={t('Why not')} className="h-8 w-48" placeholder={t('Why not (to turn it down)')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <Button size="sm" variant="outline" disabled={note.trim().length < 3} onClick={() => router.post(`/admin/market/${l.id}/reject`, { note }, { preserveScroll: true })}>
                        {t('Turn down')}
                    </Button>
                </>
            ) : (
                <Button size="sm" variant="outline" disabled={value === ''} onClick={() => router.put(`/admin/market/${l.id}/commission`, { kind, value }, { preserveScroll: true })}>
                    {t('Save')}
                </Button>
            )}
            <InputError message={errors.listing ?? errors.value} />
        </div>
    );
}

function Card({ l, review }: { l: Listing; review?: boolean }) {
    const { t, money, num } = useI18n();

    return (
        <li className="space-y-2 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {l.title} <span className="text-sm font-normal text-muted-foreground">· {l.sector}</span>
                </p>
                <p className="text-xs text-muted-foreground">
                    {l.seller}
                    {review && l.waiting_days !== undefined && ` · ${t('waiting {n} days', { n: num(l.waiting_days) })}`}
                </p>
            </div>
            {l.description && <p className="text-sm whitespace-pre-line">{l.description}</p>}
            <p className="tabular text-sm">
                {l.public_price !== null && `${t('Public')} ${money(l.public_price)} `}
                {l.wholesale_price !== null && `${t('Trade')} ${money(l.wholesale_price)} (${t('from {n}', { n: num(l.min_qty) })}) `}
                {`· ${t('Delivery')} ${money(l.delivery_charge)}`}
            </p>
            <div className="flex flex-wrap gap-2 text-xs">
                {l.no_photo && <span className="rounded bg-warning/15 px-1.5 py-0.5 font-semibold text-warning">{t('No photo')}</span>}
                {l.flag && <span className="rounded bg-danger/15 px-1.5 py-0.5 font-semibold text-danger">{t('Contains "{word}"', { word: l.flag })}</span>}
                {l.has_photo && (
                    <a href={`/market/photo/${l.id}`} target="_blank" rel="noreferrer" className="underline">
                        {t('Photo')}
                    </a>
                )}
            </div>
            {l.request && <p className="rounded-md bg-warning/10 p-2 text-sm">{t('The seller asks for less commission')}: {l.request}</p>}
            <CommissionBox l={l} review={review} />
            {!review && (
                <label className="flex items-center gap-2 text-xs">
                    <input type="checkbox" checked={l.show_name} onChange={(e) => router.put(`/admin/market/sellers/${l.seller_id}/shop-name`, { show: e.target.checked }, { preserveScroll: true })} />
                    {t("Show this seller's own name instead of ours")}
                </label>
            )}
        </li>
    );
}

function SectorForm({ s }: { s?: Sector }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({
        slug: s?.slug ?? '', name: s?.name ?? '', name_bn: s?.name_bn ?? '', unit: s?.unit ?? 'piece', premium_over: s?.premium_over ? String(Number(s.premium_over)) : '', return_rule: s?.return_rule ?? '', sort: String(s?.sort ?? 0), tagline: s?.tagline ?? '', tagline_bn: s?.tagline_bn ?? '',
        fields: s?.fields ?? (s ? ['color', 'size', 'measurements'] : []), custom_orders: s?.custom_orders ?? false, catalog_required: s?.catalog_required ?? false,
        allow_fraction: s?.allow_fraction ?? false, preorder: s?.preorder ?? false, chat: s?.chat ?? false, is_active: s?.is_active ?? true,
    });
    const text = (k: 'slug' | 'name' | 'name_bn' | 'unit' | 'premium_over' | 'return_rule' | 'sort' | 'tagline' | 'tagline_bn') => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });
    const flag = (k: 'allow_fraction' | 'preorder' | 'chat' | 'is_active' | 'custom_orders' | 'catalog_required', label: string) => (
        <label className="flex items-center gap-1.5 text-xs">
            <input type="checkbox" checked={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.checked })} />
            {label}
        </label>
    );

    return (
        <form
            className="grid gap-2 rounded-md border bg-card p-3 sm:grid-cols-4"
            onSubmit={(e) => {
                e.preventDefault();
                const body = { ...f, premium_over: f.premium_over || null, name_bn: f.name_bn || null, return_rule: f.return_rule || null, tagline: f.tagline || null, tagline_bn: f.tagline_bn || null };
                s ? router.put(`/admin/market/sectors/${s.id}`, body, { preserveScroll: true }) : router.post('/admin/market/sectors', body, { preserveScroll: true });
            }}
        >
            <Input aria-label={t('Code')} placeholder={t('Code (for example wood)')} value={f.slug} onChange={text('slug')} disabled={!!s} />
            <Input aria-label={t('Name')} placeholder={t('Name')} value={f.name} onChange={text('name')} />
            <Input aria-label={t('Name in Bangla')} placeholder={t('Name in Bangla')} value={f.name_bn} onChange={text('name_bn')} />
            <Input aria-label={t('Unit')} placeholder={t('Unit (foot, yard, kg…)')} value={f.unit} onChange={text('unit')} />
            <Input aria-label={t('Premium from')} type="number" min="0" placeholder={t('Premium from (taka)')} value={f.premium_over} onChange={text('premium_over')} />
            <Input aria-label={t('Return rule')} className="sm:col-span-2" placeholder={t('Return rule shown on the product page')} value={f.return_rule} onChange={text('return_rule')} />
            <Input aria-label={t('Order on the menu')} type="number" min="0" value={f.sort} onChange={text('sort')} />
            <Input aria-label={t('Banner line')} className="sm:col-span-2" maxLength={160} placeholder={t('Line under the banner title')} value={f.tagline} onChange={text('tagline')} />
            <Input aria-label={t('Banner line in Bangla')} className="sm:col-span-2" maxLength={160} placeholder={t('Banner line in Bangla')} value={f.tagline_bn} onChange={text('tagline_bn')} />
            {s && (
                <label className="flex flex-wrap items-center gap-2 text-xs sm:col-span-4">
                    <span className="font-medium">{s.hero_path ? t('Replace the banner picture') : t('Banner picture (wide, about 16 by 9)')}</span>
                    <input type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => e.target.files?.[0] && router.post(`/admin/market/sectors/${s.id}/hero`, { image: e.target.files[0] }, { forceFormData: true, preserveScroll: true })} />
                    {errors.image && <span className="text-danger">{errors.image}</span>}
                </label>
            )}
            <div className="flex flex-wrap items-center gap-3 sm:col-span-3">
                {flag('allow_fraction', t('Fractions allowed'))}
                {flag('preorder', t('Pre-order'))}
                {flag('chat', t('Chat'))}
                {flag('custom_orders', t('Made to order'))}
                {flag('catalog_required', t('Sellers pick from the catalog'))}
                {flag('is_active', t('Open'))}
            </div>
            <fieldset className="flex flex-wrap items-center gap-3 sm:col-span-4">
                <legend className="text-xs font-medium">{t('Fields this kind of business needs')}</legend>
                {[['color', 'Colour'], ['size', 'Size'], ['measurements', 'Measurements'], ['weight', 'Weight'], ['expiry', 'Expiry date'], ['warranty', 'Warranty']].map(([k, label]) => (
                    <label key={k} className="flex items-center gap-1.5 text-xs">
                        <input type="checkbox" checked={f.fields.includes(k)} onChange={(e) => setF({ ...f, fields: e.target.checked ? [...f.fields, k] : f.fields.filter((x) => x !== k) })} />
                        {t(label)}
                    </label>
                ))}
            </fieldset>
            <Button type="submit" size="sm">
                {s ? t('Save') : t('Open this sector')}
            </Button>
            <InputError message={errors.slug ?? errors.name ?? errors.unit} />
        </form>
    );
}

function ProhibitedWords({ words }: { words: string }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [text, setText] = useState(words);

    return (
        <form
            className="space-y-2 rounded-md border bg-card p-3"
            onSubmit={(e) => {
                e.preventDefault();
                router.post('/admin/market/prohibited', { words: text }, { preserveScroll: true });
            }}
        >
            <h2 className="font-medium">{t('Words we do not allow')}</h2>
            <p className="text-xs text-muted-foreground">{t('Write them with commas or on separate lines. A listing that holds one of them is marked for you when it waits to be read. Nothing is turned down by itself.')}</p>
            <textarea aria-label={t('Words we do not allow')} rows={3} maxLength={2000} value={text} onChange={(e) => setText(e.target.value)} className="w-full rounded-md border bg-background p-2 text-sm" />
            <InputError message={errors.words} />
            <Button type="submit" size="sm">
                {t('Save')}
            </Button>
        </form>
    );
}

export default function AdminMarket({ queue, asking, published, sectors, prohibited }: { queue: Listing[]; asking: Listing[]; published: Listing[]; sectors: Sector[]; prohibited: string }) {
    const { t } = useI18n();
    const section = (title: string, items: Listing[], empty: string, review?: boolean) => (
        <section className="space-y-2">
            <h2 className="font-medium">
                {title} <span className="text-muted-foreground tabular-nums">({items.length})</span>
            </h2>
            {items.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{empty}</p> : <ul className="divide-y rounded-md border bg-card">{items.map((l) => <Card key={l.id} l={l} review={review} />)}</ul>}
        </section>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Market')} />
            <div className="mx-auto w-full max-w-4xl space-y-8 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Market')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Read each listing, set our commission, and publish. Buyers pay the seller price and our commission together.')}</p>
                </div>
                <Flash />
                {section(t('Waiting to be read'), queue, t('No listing is waiting.'), true)}
                {section(t('Asking for less commission'), asking, t('Nobody is asking.'))}
                {section(t('Published'), published, t('Nothing is published yet.'))}

                <ProhibitedWords words={prohibited} />

                <section className="space-y-2">
                    <h2 className="font-medium">{t('Sectors')}</h2>
                    {sectors.map((s) => (
                        <SectorForm key={s.id} s={s} />
                    ))}
                    <SectorForm />
                </section>
            </div>
        </AppLayout>
    );
}
