import { MedicineThumb } from '@/components/medicines/medicine-row';
import { type MedicineSummary } from '@/components/medicines/types';
import { useMedicinePrice } from '@/components/medicines/use-medicine-price';
import RequestButton from '@/components/requests/request-button';
import { useI18n } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { Loader2, Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

const DEBOUNCE_MS = 180;
const SHOWN = 6;

/**
 * Instant search for the home page: results appear under the box as you type, with photos and prices.
 * Arrow keys move through them, Enter opens one, Escape closes. With nothing highlighted, Enter
 * submits the form normally and lands on the full results page.
 */
export default function HeroSearch() {
    const { t } = useI18n();
    const priceOf = useMedicinePrice();
    const listId = useId();
    const [q, setQ] = useState('');
    const [results, setResults] = useState<MedicineSummary[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const [searched, setSearched] = useState(false);
    const controller = useRef<AbortController | null>(null);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const term = q.trim();
        setActive(-1);

        if (term.length < 2) {
            controller.current?.abort();
            setResults([]);
            setSearched(false);
            setLoading(false);
            return;
        }

        const timer = setTimeout(async () => {
            controller.current?.abort();
            controller.current = new AbortController();
            setLoading(true);
            try {
                const res = await fetch(`${route('medicines.suggest')}?q=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.current.signal,
                });
                if (res.ok) {
                    setResults(((await res.json()) as { data: MedicineSummary[] }).data.slice(0, SHOWN));
                    setSearched(true);
                    setOpen(true);
                }
            } catch {
                /* aborted by a newer keystroke, or offline: keep what is on screen */
            } finally {
                setLoading(false);
            }
        }, DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [q]);

    const onKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Escape') {
            setOpen(false);
            return;
        }
        if (results.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setOpen(true);
            setActive((i) => (i + 1) % results.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => (i <= 0 ? results.length - 1 : i - 1));
        } else if (e.key === 'Enter' && active >= 0) {
            e.preventDefault();
            router.visit(route('medicines.show', results[active].id));
        }
    };

    const term = q.trim();
    const showPanel = open && term.length >= 2 && (loading || searched);

    return (
        <div
            ref={box}
            className="relative"
            onBlur={(e) => {
                if (!box.current?.contains(e.relatedTarget as Node)) setOpen(false);
            }}
        >
            <form action={route('medicines.index')} method="get" role="search">
                <label htmlFor="home-q" className="sr-only">
                    {t('Medicine name')}
                </label>
                <div className="relative">
                    {loading ? (
                        <Loader2 aria-hidden className="absolute top-1/2 left-4 size-5 -translate-y-1/2 animate-spin text-[var(--pt-amber)]" />
                    ) : (
                        <Search aria-hidden className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[var(--pt-muted)]" />
                    )}
                    <input
                        id="home-q"
                        name="q"
                        type="search"
                        required
                        minLength={2}
                        value={q}
                        onChange={(e) => {
                            setQ(e.target.value);
                            setOpen(true);
                        }}
                        onFocus={() => setOpen(true)}
                        onKeyDown={onKeyDown}
                        autoComplete="off"
                        spellCheck={false}
                        enterKeyHint="search"
                        role="combobox"
                        aria-expanded={showPanel && results.length > 0}
                        aria-controls={listId}
                        aria-activedescendant={active >= 0 ? `${listId}-${active}` : undefined}
                        aria-autocomplete="list"
                        placeholder={t('Brand or generic name')}
                        className="h-14 w-full rounded-xl border-2 border-[var(--pt-line)] bg-[var(--pt-surface)] pr-28 pl-12 text-lg shadow-sm transition-shadow outline-none placeholder:text-[var(--pt-muted)]/70 focus:border-[var(--pt-amber)] focus:shadow-lg focus:ring-4 focus:ring-[var(--pt-amber)]/25"
                    />
                    <button
                        type="submit"
                        className="absolute top-2 right-2 h-10 rounded-lg bg-[var(--pt-ink)] px-5 font-semibold text-[var(--pt-paper)] outline-none hover:opacity-90 focus-visible:ring-4 focus-visible:ring-[var(--pt-amber)]/50"
                    >
                        {t('Search')}
                    </button>
                </div>
            </form>

            {showPanel && (
                <div className="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-xl border border-[var(--pt-line)] bg-[var(--pt-surface)] shadow-2xl">
                    {results.length > 0 ? (
                        <>
                            <ul id={listId} role="listbox" aria-label={t('Matching medicines')}>
                                {results.map((m, i) => {
                                    const price = priceOf(m);
                                    return (
                                        <li key={m.id} id={`${listId}-${i}`} role="option" aria-selected={i === active}>
                                            <a
                                                href={route('medicines.show', m.id)}
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    router.visit(route('medicines.show', m.id));
                                                }}
                                                onMouseEnter={() => setActive(i)}
                                                className={`flex items-center gap-3 border-b border-[var(--pt-line)] px-3 py-2.5 outline-none last:border-b-0 ${i === active ? 'bg-[var(--pt-amber)]/15' : ''}`}
                                            >
                                                <MedicineThumb medicine={m} className="size-11" />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate">
                                                        <span className="font-medium">{m.brand_name}</span> <strong>{m.strength}</strong>
                                                    </span>
                                                    <span className="block truncate text-sm text-[var(--pt-muted)]">
                                                        {[m.form && t(m.form), m.company?.name].filter(Boolean).join(' · ')}
                                                    </span>
                                                </span>
                                                {price && (
                                                    <span className="tabular shrink-0 text-right">
                                                        <span className="block font-semibold">{price.amount}</span>
                                                        <span className="block text-xs text-[var(--pt-muted)]">{price.unit}</span>
                                                    </span>
                                                )}
                                            </a>
                                        </li>
                                    );
                                })}
                            </ul>
                            <a
                                href={route('medicines.index', { q: term })}
                                className="block bg-[var(--pt-paper)] px-4 py-3 text-sm font-semibold hover:underline"
                            >
                                {t('See all results for “{term}”', { term })}
                            </a>
                        </>
                    ) : loading ? (
                        <p className="px-4 py-5 text-sm text-[var(--pt-muted)]">{t('Searching…')}</p>
                    ) : (
                        <div className="px-4 py-5 text-sm">
                            <p>
                                <span className="font-semibold">{t('No medicine matches “{term}”.', { term })}</span>{' '}
                                <span className="text-[var(--pt-muted)]">{t('Check the spelling, or try the generic name.')}</span>
                            </p>
                            <RequestButton key={term.toLowerCase()} query={term} className="mt-3" />
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
