import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem, type HomeBlock, type HomePair } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye, EyeOff, GripVertical, Monitor, Plus, Redo2, Smartphone, Tablet, Trash2, Undo2 } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react';

type Types = Record<string, { single: boolean; fields: Record<string, string> }>;

interface Props {
    blocks: HomeBlock[];
    types: Types;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Site design', href: '/admin/site' },
    { title: 'Home page builder', href: '/admin/site/builder' },
];

const DEVICES = { desktop: 1280, tablet: 820, mobile: 390 } as const;
type Device = keyof typeof DEVICES;

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

/** A block's standard look, for a page that starts over. */
const STANDARD: HomeBlock[] = ['hero', 'categories', 'arrivals', 'pharmacy', 'food', 'games', 'promises'].map((t) => ({ id: t, type: t as HomeBlock['type'], enabled: true, props: {} }));

export default function SiteBuilder({ blocks: saved, types }: Props) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;

    // The page being edited, with a history to step back and forward through.
    const [history, setHistory] = useState<HomeBlock[][]>([saved]);
    const [at, setAt] = useState(0);
    const blocks = history[at];
    const lastEdit = useRef(0);
    const [selected, setSelected] = useState<string | null>(null);
    const [device, setDevice] = useState<Device>('desktop');
    const [adding, setAdding] = useState(false);
    const [dragging, setDragging] = useState<string | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const dirty = useMemo(() => JSON.stringify(blocks) !== JSON.stringify(saved), [blocks, saved]);
    const chosen = blocks.find((b) => b.id === selected) ?? null;

    const label = (type: string) =>
        t(({ hero: 'Opening', categories: 'Categories', arrivals: 'New arrivals', pharmacy: 'Pharmacy', food: 'Food', games: 'Game top-up', promises: 'Promises', banner: 'Banner', text: 'Text', spacer: 'Space' } as Record<string, string>)[type] ?? type);

    /** Change the page. Quick typing in one box is one step back, not fifty. */
    const commit = useCallback(
        (next: HomeBlock[], merge = false) => {
            const now = Date.now();
            const fold = merge && now - lastEdit.current < 900;
            lastEdit.current = now;
            setHistory((h) => (fold ? [...h.slice(0, at), next] : [...h.slice(0, at + 1), next]));
            setAt((i) => (fold ? i : i + 1));
        },
        [at],
    );

    // ---- the live copy of the home page
    const frame = useRef<HTMLIFrameElement>(null);
    const wrap = useRef<HTMLDivElement>(null);
    const [ready, setReady] = useState(false);
    const [scale, setScale] = useState(1);

    const send = useCallback((msg: unknown) => frame.current?.contentWindow?.postMessage(msg, window.location.origin), []);

    useEffect(() => {
        const on = (e: MessageEvent) => {
            if (e.origin !== window.location.origin || e.source !== frame.current?.contentWindow) return;
            if (e.data?.type === 'ready') setReady(true);
            if (e.data?.type === 'select') setSelected(e.data.id);
        };
        window.addEventListener('message', on);

        return () => window.removeEventListener('message', on);
    }, []);

    useEffect(() => {
        if (ready) send({ type: 'blocks', blocks });
    }, [ready, blocks, send]);

    useEffect(() => {
        if (ready) send({ type: 'select', id: selected });
    }, [ready, selected, send]);

    useEffect(() => {
        const fit = () => setScale(Math.min(1, (wrap.current?.clientWidth ?? DEVICES[device]) / DEVICES[device]));
        fit();
        const ro = new ResizeObserver(fit);
        if (wrap.current) ro.observe(wrap.current);

        return () => ro.disconnect();
    }, [device]);

    useEffect(() => {
        const warn = (e: BeforeUnloadEvent) => {
            if (dirty) e.preventDefault();
        };
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    // ---- editing
    const move = (id: string, to: number) => {
        const from = blocks.findIndex((b) => b.id === id);
        if (from < 0 || to < 0 || to >= blocks.length || from === to) return;
        const next = [...blocks];
        next.splice(to, 0, next.splice(from, 1)[0]);
        commit(next);
    };
    const patch = (id: string, change: Partial<HomeBlock>, merge = false) => commit(blocks.map((b) => (b.id === id ? { ...b, ...change } : b)), merge);
    const setProp = (id: string, name: string, value: HomeBlock['props'][string]) => {
        const b = blocks.find((x) => x.id === id);
        if (!b) return;
        const props = { ...b.props };
        if (value === undefined || value === '' || value === null) delete props[name];
        else props[name] = value;
        patch(id, { props }, true);
    };
    const add = (type: string) => {
        const id = `${type}-${Math.random().toString(36).slice(2, 7)}`;
        commit([...blocks, { id, type: type as HomeBlock['type'], enabled: true, props: {} }]);
        setSelected(id);
        setAdding(false);
    };
    const remove = (id: string) => {
        commit(blocks.filter((b) => b.id !== id));
        if (selected === id) setSelected(null);
    };
    const save = () => {
        setBusy(true);
        router.put('/admin/site/blocks', { blocks } as never, { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: () => {
                    setHistory([blocks]);
                    setAt(0);
                } });
    };

    const addable = Object.entries(types).filter(([type, d]) => !d.single || !blocks.some((b) => b.type === type));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Home page builder')} />
            <div className="flex h-[calc(100svh-4rem)] flex-col gap-3 p-3 md:p-4">
                <div className="glass flex flex-wrap items-center gap-2 rounded-2xl px-3 py-2">
                    <h1 className="classic mr-2 text-xl font-semibold">{t('Home page builder')}</h1>
                    <Button size="icon" variant="ghost" aria-label={t('Undo')} disabled={at === 0} onClick={() => setAt(at - 1)}>
                        <Undo2 className="size-4" />
                    </Button>
                    <Button size="icon" variant="ghost" aria-label={t('Redo')} disabled={at >= history.length - 1} onClick={() => setAt(at + 1)}>
                        <Redo2 className="size-4" />
                    </Button>
                    <div role="group" aria-label={t('Screen size')} className="mx-1 flex rounded-full border p-0.5">
                        {([['desktop', Monitor], ['tablet', Tablet], ['mobile', Smartphone]] as const).map(([d, Icon]) => (
                            <button key={d} type="button" aria-pressed={device === d} aria-label={t(d === 'desktop' ? 'Computer' : d === 'tablet' ? 'Tablet' : 'Phone')} onClick={() => setDevice(d)} className={`rounded-full p-1.5 ${device === d ? 'bg-foreground text-background' : 'opacity-70'}`}>
                                <Icon className="size-4" />
                            </button>
                        ))}
                    </div>
                    <Button size="sm" variant="ghost" onClick={() => window.confirm(t('Start again from the standard page? Your unsaved changes go.')) && commit(STANDARD)}>
                        {t('Start again')}
                    </Button>
                    <span className="ml-auto flex items-center gap-2">
                        {flash && <span role="status" className="text-xs text-emerald-600 dark:text-emerald-300">{flash}</span>}
                        {dirty && <span className="text-xs opacity-65">{t('Not saved')}</span>}
                        <Link href="/" target="_blank" className="text-xs underline underline-offset-4">
                            {t('Open the live page')}
                        </Link>
                        <Button size="sm" onClick={save} disabled={!dirty || busy}>
                            {t('Save and publish')}
                        </Button>
                    </span>
                </div>
                <InputError message={errors.site} />

                <div className="grid min-h-0 flex-1 gap-3 lg:grid-cols-[17rem_minmax(0,1fr)] xl:grid-cols-[17rem_minmax(0,1fr)_20rem]">
                    {/* The blocks, in order. Drag to rearrange, or use the arrows. */}
                    <aside aria-label={t('Blocks')} className="glass flex min-h-0 flex-col rounded-2xl p-3 max-lg:max-h-72">
                        <ul className="min-h-0 flex-1 space-y-1.5 overflow-y-auto pr-1">
                            {blocks.map((b, i) => (
                                <li
                                    key={b.id}
                                    draggable
                                    onDragStart={() => setDragging(b.id)}
                                    onDragEnd={() => {
                                        setDragging(null);
                                        setOver(null);
                                    }}
                                    onDragOver={(e) => {
                                        e.preventDefault();
                                        setOver(b.id);
                                    }}
                                    onDrop={() => dragging && move(dragging, i)}
                                    className={`flex items-center gap-1 rounded-xl border px-1.5 py-1.5 text-sm ${selected === b.id ? 'border-[#c9a24b] bg-[#c9a24b]/10' : 'border-transparent bg-current/5'} ${over === b.id && dragging !== b.id ? 'ring-2 ring-[#c9a24b]' : ''} ${dragging === b.id ? 'opacity-40' : ''}`}
                                >
                                    <GripVertical aria-hidden className="size-4 shrink-0 cursor-grab opacity-50" />
                                    <button type="button" onClick={() => setSelected(b.id)} className={`min-w-0 flex-1 truncate text-left ${b.enabled ? '' : 'line-through opacity-50'}`}>
                                        {label(b.type)}
                                    </button>
                                    <Button size="icon" variant="ghost" className="size-7" aria-label={t('Move up')} disabled={i === 0} onClick={() => move(b.id, i - 1)}>
                                        <ArrowUp className="size-3.5" />
                                    </Button>
                                    <Button size="icon" variant="ghost" className="size-7" aria-label={t('Move down')} disabled={i === blocks.length - 1} onClick={() => move(b.id, i + 1)}>
                                        <ArrowDown className="size-3.5" />
                                    </Button>
                                    <Button size="icon" variant="ghost" className="size-7" aria-label={b.enabled ? t('Hide') : t('Show')} aria-pressed={!b.enabled} onClick={() => patch(b.id, { enabled: !b.enabled })}>
                                        {b.enabled ? <Eye className="size-3.5" /> : <EyeOff className="size-3.5" />}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <div className="relative mt-3">
                            <Button className="w-full" variant="outline" onClick={() => setAdding((v) => !v)} aria-expanded={adding} disabled={blocks.length >= 30}>
                                <Plus className="size-4" />
                                {t('Add a block')}
                            </Button>
                            {adding && (
                                <ul className="glass absolute right-0 bottom-full left-0 z-20 mb-2 max-h-64 overflow-y-auto rounded-xl p-1.5 text-sm">
                                    {addable.map(([type]) => (
                                        <li key={type}>
                                            <button type="button" onClick={() => add(type)} className="w-full rounded-lg px-3 py-2 text-left hover:bg-current/10">
                                                {label(type)}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </aside>

                    {/* The real home page, drawn from the blocks as they are now. */}
                    <section aria-label={t('Live preview')} className="glass flex min-h-0 flex-col overflow-hidden rounded-2xl max-lg:h-[70svh]">
                        <div ref={wrap} className="relative min-h-0 flex-1 overflow-auto">
                            <div style={{ width: DEVICES[device] * scale, height: '100%', margin: '0 auto' }}>
                                <iframe
                                    ref={frame}
                                    title={t('Live preview')}
                                    src="/?builder=1"
                                    style={{ width: DEVICES[device], height: `${100 / scale}%`, transform: `scale(${scale})`, transformOrigin: 'top left', border: 0, background: '#fff' }}
                                />
                            </div>
                            {!ready && <p className="pointer-events-none absolute inset-x-0 top-6 text-center text-sm opacity-60">{t('Loading the page…')}</p>}
                        </div>
                    </section>

                    {/* The chosen block's settings. */}
                    <aside aria-label={t('Settings')} className="glass min-h-0 overflow-y-auto rounded-2xl p-4 max-xl:col-span-full max-xl:max-h-[28rem]">
                        {chosen ? (
                            <Settings
                                block={chosen}
                                fields={types[chosen.type]?.fields ?? {}}
                                title={label(chosen.type)}
                                onChange={(name, value) => setProp(chosen.id, name, value)}
                                onRemove={() => window.confirm(t('Remove this block?')) && remove(chosen.id)}
                            />
                        ) : (
                            <p className="text-sm opacity-65">{t('Click a block in the page, or in the list, to change it.')}</p>
                        )}
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}

const FIELD_LABELS: Record<string, string> = {
    title: 'Title', text: 'Words', button_label: 'Button text', button_link: 'Button link', count: 'How many products', heading: 'Heading', body: 'Words', image: 'Picture', tone: 'Colour', align: 'Alignment', height: 'Height', size: 'Space',
    p1_title: 'First promise: title', p1_line: 'First promise: line', p2_title: 'Second promise: title', p2_line: 'Second promise: line', p3_title: 'Third promise: title', p3_line: 'Third promise: line',
};
const CHOICES: Record<string, string> = { light: 'Light', dark: 'Dark', gold: 'Gold', left: 'Left', center: 'Centre', sm: 'Small', md: 'Medium', lg: 'Large' };

function Settings({ block, fields, title, onChange, onRemove }: { block: HomeBlock; fields: Record<string, string>; title: string; onChange: (name: string, value: HomeBlock['props'][string]) => void; onRemove: () => void }) {
    const { t } = useI18n();
    const [problem, setProblem] = useState<string | null>(null);

    const upload = async (file: File) => {
        setProblem(null);
        const form = new FormData();
        form.append('image', file);
        const r = await fetch('/admin/site/block-image', { method: 'POST', body: form, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });

        if (!r.ok) return setProblem(t('That picture could not be used. Send a PNG, JPG or WebP up to 4 MB.'));
        onChange('image', (await r.json()).name);
    };

    const Field = ({ name, kind }: { name: string; kind: string }): ReactNode => {
        const v = block.props[name];
        const lbl = t(FIELD_LABELS[name] ?? name);

        if (kind === 'pair' || kind === 'area') {
            const pair = (v as HomePair | undefined) ?? { en: null, bn: null };
            const set = (lang: 'en' | 'bn', text: string) => onChange(name, { ...pair, [lang]: text || null });
            const Box = kind === 'area' ? 'textarea' : 'input';

            return (
                <fieldset className="space-y-1.5">
                    <legend className="text-sm font-medium">{lbl}</legend>
                    {(['en', 'bn'] as const).map((lang) => (
                        <label key={lang} className="block">
                            <span className="sr-only">{`${lbl} (${lang === 'en' ? t('English') : t('Bangla')})`}</span>
                            <Box
                                value={pair[lang] ?? ''}
                                onChange={(e) => set(lang, e.target.value)}
                                maxLength={kind === 'area' ? 400 : 120}
                                rows={kind === 'area' ? 3 : undefined}
                                placeholder={lang === 'en' ? t('English') : t('Bangla')}
                                className="w-full rounded-md border bg-background/70 px-3 py-2 text-sm"
                            />
                        </label>
                    ))}
                </fieldset>
            );
        }

        if (kind === 'url') {
            return (
                <label className="block text-sm font-medium">
                    {lbl}
                    <Input className="mt-1" value={(v as string) ?? ''} onChange={(e) => onChange(name, e.target.value)} placeholder="/market" maxLength={300} />
                </label>
            );
        }

        if (kind === 'image') {
            return (
                <div className="space-y-2">
                    <p className="text-sm font-medium">{lbl}</p>
                    {typeof v === 'string' && <img src={`/site-image/${v}`} alt="" className="h-28 w-full rounded-lg border object-cover" />}
                    <input type="file" accept="image/png,image/jpeg,image/webp" aria-label={lbl} className="block w-full text-sm" onChange={(e) => e.target.files?.[0] && void upload(e.target.files[0])} />
                    {typeof v === 'string' && (
                        <Button size="sm" variant="ghost" onClick={() => onChange('image', undefined)}>
                            {t('Remove the picture')}
                        </Button>
                    )}
                    {problem && <p role="alert" className="text-xs text-rose-500">{problem}</p>}
                </div>
            );
        }

        if (kind.startsWith('int:')) {
            const [, min, max] = kind.split(':');

            return (
                <label className="block text-sm font-medium">
                    {lbl}
                    <Input className="mt-1 w-24" type="number" min={min} max={max} value={(v as number) ?? ''} onChange={(e) => onChange(name, e.target.value === '' ? undefined : Number(e.target.value))} />
                </label>
            );
        }

        if (kind.startsWith('enum:')) {
            const options = kind.slice(5).split('|');

            return (
                <fieldset>
                    <legend className="mb-1 text-sm font-medium">{lbl}</legend>
                    <div className="flex flex-wrap gap-1.5">
                        {options.map((o) => (
                            <button key={o} type="button" aria-pressed={(v ?? options[0]) === o} onClick={() => onChange(name, o)} className={`rounded-full border px-3 py-1 text-sm ${(v ?? options[0]) === o ? 'bg-foreground text-background' : ''}`}>
                                {t(CHOICES[o] ?? o)}
                            </button>
                        ))}
                    </div>
                </fieldset>
            );
        }

        return null;
    };

    return (
        <div className="space-y-5">
            <div className="flex items-center justify-between gap-2">
                <h2 className="classic text-xl font-semibold">{title}</h2>
                <Button size="icon" variant="ghost" aria-label={t('Remove this block')} onClick={onRemove}>
                    <Trash2 className="size-4" />
                </Button>
            </div>
            {Object.keys(fields).length === 0 && <p className="text-sm opacity-65">{t('This block has nothing to change.')}</p>}
            {Object.entries(fields).map(([name, kind]) => (
                <div key={`${block.id}-${name}`}>{Field({ name, kind })}</div>
            ))}
            <p className="text-xs opacity-60">{t('Words left empty use the shop’s own wording.')}</p>
        </div>
    );
}
