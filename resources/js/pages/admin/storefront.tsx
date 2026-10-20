import Flash from '@/components/admin/flash';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select as Listbox, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ExternalLink, Eye, EyeOff, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';

type Item = {
    title: string;
    title_bn: string | null;
    text: string | null;
    text_bn: string | null;
};

interface Block {
    id: number;
    kind: string;
    is_active: boolean;
    title: string | null;
    title_bn: string | null;
    text: string | null;
    text_bn: string | null;
    button: string | null;
    button_bn: string | null;
    source: string | null;
    item_limit: number;
    items: Item[];
    image: string | null;
}

interface Props {
    sectors: { id: number; slug: string; name: string; sections: number }[];
    pages: { key: string; name: string; sections: number }[];
    page: string | null;
    view: string | null;
    sector: string | null;
    blocks: Block[];
    kinds: Record<string, { label: string; fields: string[] }>;
    sources: Record<string, string>;
    max: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Shop fronts', href: '/admin/storefront' }];

const area = 'mt-1 min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm';

/** A line in English and the same line in Bangla, side by side. */
function Pair({
    label,
    en,
    bn,
    max,
    long,
    onEn,
    onBn,
}: {
    label: string;
    en: string | null;
    bn: string | null;
    max: number;
    long?: boolean;
    onEn: (v: string) => void;
    onBn: (v: string) => void;
}) {
    const { t } = useI18n();

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <label className="block text-sm font-medium">
                {label} ({t('English')})
                {long ? (
                    <textarea className={area} maxLength={max} value={en ?? ''} onChange={(e) => onEn(e.target.value)} />
                ) : (
                    <Input className="mt-1" maxLength={max} value={en ?? ''} onChange={(e) => onEn(e.target.value)} />
                )}
            </label>
            <label className="block text-sm font-medium">
                {label} ({t('Bangla')})
                {long ? (
                    <textarea className={area} maxLength={max} value={bn ?? ''} onChange={(e) => onBn(e.target.value)} />
                ) : (
                    <Input className="mt-1" maxLength={max} value={bn ?? ''} onChange={(e) => onBn(e.target.value)} />
                )}
            </label>
        </div>
    );
}

function BlockCard({
    block,
    index,
    total,
    kinds,
    sources,
}: {
    block: Block;
    index: number;
    total: number;
    kinds: Props['kinds'];
    sources: Props['sources'];
}) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [sure, setSure] = useState(false);
    const [draft, setDraft] = useState<Block>(block);
    const [saving, setSaving] = useState(false);
    const kind = kinds[block.kind];
    const has = (f: string) => kind?.fields.includes(f);
    const set = <K extends keyof Block>(k: K, v: Block[K]) => setDraft((d) => ({ ...d, [k]: v }));
    const setItem = (n: number, k: keyof Item, v: string) =>
        set(
            'items',
            draft.items.map((it, i) => (i === n ? { ...it, [k]: v } : it)),
        );

    const save = () => {
        setSaving(true);
        router.put(
            route('admin.storefront.update', block.id),
            {
                is_active: draft.is_active,
                title: draft.title,
                title_bn: draft.title_bn,
                text: draft.text,
                text_bn: draft.text_bn,
                button: draft.button,
                button_bn: draft.button_bn,
                source: draft.source,
                item_limit: draft.item_limit,
                items: draft.items,
            },
            { preserveScroll: true, onFinish: () => setSaving(false), onSuccess: () => setOpen(false) },
        );
    };

    const toggle = () => router.put(route('admin.storefront.update', block.id), { is_active: !block.is_active }, { preserveScroll: true });
    const move = (direction: 'up' | 'down') => router.post(route('admin.storefront.move', block.id), { direction }, { preserveScroll: true });
    const upload = (file: File | undefined) => {
        if (file) router.post(route('admin.storefront.image', block.id), { image: file }, { forceFormData: true, preserveScroll: true });
    };
    const summary = block.title ?? block.button ?? block.items[0]?.title ?? '';
    const iconBtn = 'inline-flex size-10 items-center justify-center rounded-md border hover:bg-muted disabled:opacity-40';

    return (
        <li className={`bg-card rounded-xl border ${block.is_active ? '' : 'opacity-70'}`}>
            <div className="flex flex-wrap items-center gap-2 p-3 sm:p-4">
                <span className="text-muted-foreground w-6 text-sm tabular-nums">{index + 1}</span>
                <div className="min-w-0 flex-1">
                    <p className="font-semibold">{t(kind?.label ?? block.kind)}</p>
                    {summary && <p className="text-muted-foreground truncate text-sm">{summary}</p>}
                </div>
                {!block.is_active && <span className="rounded-full border px-2 py-0.5 text-xs">{t('Hidden')}</span>}
                <button type="button" className={iconBtn} aria-label={t('Move up')} disabled={index === 0} onClick={() => move('up')}>
                    <ArrowUp aria-hidden className="size-4" />
                </button>
                <button type="button" className={iconBtn} aria-label={t('Move down')} disabled={index === total - 1} onClick={() => move('down')}>
                    <ArrowDown aria-hidden className="size-4" />
                </button>
                <button
                    type="button"
                    className={iconBtn}
                    aria-pressed={block.is_active}
                    aria-label={block.is_active ? t('Hide this section') : t('Show this section')}
                    onClick={toggle}
                >
                    {block.is_active ? <Eye aria-hidden className="size-4" /> : <EyeOff aria-hidden className="size-4" />}
                </button>
                <button type="button" className={iconBtn} aria-expanded={open} aria-label={t('Edit')} onClick={() => setOpen((o) => !o)}>
                    {open ? <X aria-hidden className="size-4" /> : <Pencil aria-hidden className="size-4" />}
                </button>
                {sure ? (
                    <span className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            onClick={() => router.delete(route('admin.storefront.destroy', block.id), { preserveScroll: true })}
                        >
                            {t('Remove')}
                        </Button>
                        <Button type="button" variant="outline" size="sm" onClick={() => setSure(false)}>
                            {t('Keep')}
                        </Button>
                    </span>
                ) : (
                    <button type="button" className={iconBtn} aria-label={t('Remove this section')} onClick={() => setSure(true)}>
                        <Trash2 aria-hidden className="size-4" />
                    </button>
                )}
            </div>

            {open && (
                <form
                    className="space-y-4 border-t p-3 sm:p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        save();
                    }}
                >
                    {has('title') && (
                        <Pair
                            label={t('Heading')}
                            en={draft.title}
                            bn={draft.title_bn}
                            max={160}
                            onEn={(v) => set('title', v)}
                            onBn={(v) => set('title_bn', v)}
                        />
                    )}
                    {has('text') && (
                        <Pair
                            label={t('Line under it')}
                            en={draft.text}
                            bn={draft.text_bn}
                            max={500}
                            long
                            onEn={(v) => set('text', v)}
                            onBn={(v) => set('text_bn', v)}
                        />
                    )}
                    {has('button') && (
                        <Pair
                            label={t('Button')}
                            en={draft.button}
                            bn={draft.button_bn}
                            max={60}
                            onEn={(v) => set('button', v)}
                            onBn={(v) => set('button_bn', v)}
                        />
                    )}

                    {has('source') && (
                        <div className="block text-sm font-medium">
                            <span id={`src-${block.id}`}>{t('Fill it from')}</span>
                            <Listbox value={draft.source ?? ''} onValueChange={(v) => set('source', v)}>
                                <SelectTrigger aria-labelledby={`src-${block.id}`} className="mt-1 w-full max-w-md">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="max-h-72">
                                    {Object.entries(sources).map(([k, label]) => (
                                        <SelectItem key={k} value={k}>
                                            {label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Listbox>
                        </div>
                    )}

                    {has('limit') && (
                        <label className="block text-sm font-medium">
                            {t('How many to show')}
                            <Input
                                className="mt-1 w-24"
                                type="number"
                                min={1}
                                max={12}
                                value={draft.item_limit}
                                onChange={(e) => set('item_limit', Number(e.target.value))}
                            />
                        </label>
                    )}

                    {has('image') && (
                        <div className="space-y-2">
                            <p className="text-sm font-medium">{t('Picture')}</p>
                            {block.image && <img src={block.image} alt="" className="h-32 w-full max-w-md rounded-lg border object-cover" />}
                            <div className="flex flex-wrap items-center gap-3">
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    aria-label={t('Upload a picture')}
                                    onChange={(e) => upload(e.target.files?.[0])}
                                    className="text-sm"
                                />
                                {block.image && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => router.delete(route('admin.storefront.image.remove', block.id), { preserveScroll: true })}
                                    >
                                        {t('Remove picture')}
                                    </Button>
                                )}
                            </div>
                            <p className="text-muted-foreground text-xs">
                                {t('PNG, JPG or WebP, up to 4 MB. With no picture, the shop picture or the product photo is used.')}
                            </p>
                        </div>
                    )}

                    {has('items') && (
                        <div className="space-y-3">
                            {draft.items.map((it, n) => (
                                <fieldset key={n} className="space-y-3 rounded-lg border p-3">
                                    <legend className="px-1 text-xs font-medium">{t('Item {n}', { n: n + 1 })}</legend>
                                    <Pair
                                        label={t('Title')}
                                        en={it.title}
                                        bn={it.title_bn}
                                        max={160}
                                        onEn={(v) => setItem(n, 'title', v)}
                                        onBn={(v) => setItem(n, 'title_bn', v)}
                                    />
                                    <Pair
                                        label={t('Text')}
                                        en={it.text}
                                        bn={it.text_bn}
                                        max={500}
                                        long
                                        onEn={(v) => setItem(n, 'text', v)}
                                        onBn={(v) => setItem(n, 'text_bn', v)}
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            set(
                                                'items',
                                                draft.items.filter((_, i) => i !== n),
                                            )
                                        }
                                    >
                                        {t('Remove item')}
                                    </Button>
                                </fieldset>
                            ))}
                            {draft.items.length < 8 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => set('items', [...draft.items, { title: '', title_bn: null, text: null, text_bn: null }])}
                                >
                                    <Plus aria-hidden className="size-4" />
                                    {t('Add an item')}
                                </Button>
                            )}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={saving}>
                            {saving ? t('Saving') : t('Save')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setDraft(block);
                                setOpen(false);
                            }}
                        >
                            {t('Cancel')}
                        </Button>
                    </div>
                </form>
            )}
        </li>
    );
}

export default function Storefront({ sectors, pages, page, view, sector, blocks, kinds, sources, max }: Props) {
    const { t } = useI18n();
    const current = sectors.find((s) => s.slug === sector);
    const ready = page !== null || current !== undefined;
    const seedUrl = page !== null ? route('admin.storefront.seed-page', page) : current ? route('admin.storefront.seed', current.id) : '';
    const addUrl = page !== null ? route('admin.storefront.store-page', page) : current ? route('admin.storefront.store', current.id) : '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Shop fronts')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">{t('Shop fronts')}</h1>
                        <p className="text-muted-foreground text-sm">
                            {t(
                                'Build the front page of each shop from sections. Change the words, the order and what each section shows. Visitors see it at once.',
                            )}
                        </p>
                    </div>
                    {view && (
                        <a
                            href={view}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-sm underline underline-offset-4"
                        >
                            {t('See the shop')}
                            <ExternalLink aria-hidden className="size-4" />
                        </a>
                    )}
                </div>

                <nav aria-label={t('Shops')} className="flex flex-wrap gap-2">
                    {sectors.map((s) => (
                        <Link
                            key={s.slug}
                            href={route('admin.storefront.index', { sector: s.slug })}
                            aria-current={page === null && s.slug === sector ? 'page' : undefined}
                            className={`rounded-full border px-4 py-1.5 text-sm ${page === null && s.slug === sector ? 'border-foreground bg-foreground text-background' : 'hover:bg-muted'}`}
                        >
                            {s.name}
                            <span className="ms-2 tabular-nums opacity-60">{s.sections}</span>
                        </Link>
                    ))}
                </nav>
                <nav aria-label={t('Pages')} className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground me-1 text-sm">{t('Pages')}</span>
                    {pages.map((p) => (
                        <Link
                            key={p.key}
                            href={route('admin.storefront.index', { page: p.key })}
                            aria-current={p.key === page ? 'page' : undefined}
                            className={`rounded-full border px-4 py-1.5 text-sm ${p.key === page ? 'border-foreground bg-foreground text-background' : 'hover:bg-muted'}`}
                        >
                            {t(p.name)}
                            <span className="ms-2 tabular-nums opacity-60">{p.sections}</span>
                        </Link>
                    ))}
                </nav>

                {ready && blocks.length === 0 && (
                    <div className="space-y-3 rounded-xl border border-dashed p-8 text-center">
                        <p className="font-semibold">{t('This front page has no sections yet.')}</p>
                        <p className="text-muted-foreground mx-auto max-w-md text-sm">
                            {t('Until it has sections, visitors see the plain product list. Start from the standard sections and change them.')}
                        </p>
                        <Button type="button" onClick={() => router.post(seedUrl, {}, { preserveScroll: true })}>
                            {t('Add the standard sections')}
                        </Button>
                    </div>
                )}

                {blocks.length > 0 && (
                    <ol className="space-y-3">
                        {blocks.map((b, i) => (
                            <BlockCard
                                key={`${b.id}-${b.is_active}-${b.title}`}
                                block={b}
                                index={i}
                                total={blocks.length}
                                kinds={kinds}
                                sources={sources}
                            />
                        ))}
                    </ol>
                )}

                {ready && blocks.length > 0 && blocks.length < max && (
                    <section aria-label={t('Add a section')} className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-semibold">{t('Add a section')}</h2>
                        <div className="flex flex-wrap gap-2">
                            {Object.entries(kinds).map(([k, v]) => (
                                <Button
                                    key={k}
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => router.post(addUrl, { kind: k }, { preserveScroll: true })}
                                >
                                    <Plus aria-hidden className="size-4" />
                                    {t(v.label)}
                                </Button>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
