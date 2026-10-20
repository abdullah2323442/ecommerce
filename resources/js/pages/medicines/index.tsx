import Pagination from '@/components/admin/pagination';
import { type MedicineSummary } from '@/components/medicines/types';
import RequestButton from '@/components/requests/request-button';
import { DISPLAY, FaqList, type Front, type FrontBlock, SectionHead, ServiceStrip } from '@/components/storefront/shared';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, Beaker, Droplet, Eye, Pill, Search, Syringe, Thermometer } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Page {
    data: MedicineSummary[];
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    query: string;
    /** The server's own page of results for a deep link like /medicines?q=napa&page=2; null until a search is made. */
    results: Page | null;
    /** With nothing typed: every medicine, A to Z, one page at a time. */
    browse: Page | null;
    letter: string;
    fresh: MedicineSummary[];
    forms: { slug: string; name: string; count: number }[];
    counts: { brands: number; generics: number };
    /** The page the admin built (Admin > Shop fronts), or null for the plain layout. */
    front: Front | null;
}

/** One medicine as a card: the pack picture (or its first letter, when there is none), what it is, who makes it, and the printed price. */
function MedCard({ m }: { m: MedicineSummary }) {
    const { t, money } = useI18n();
    const flags = [
        m.prescription_required ? t('Prescription') : null,
        m.is_controlled ? t('Controlled') : null,
        m.is_cold_chain ? t('Cold chain') : null,
    ].filter((x): x is string => x !== null);

    return (
        <li>
            <Link
                href={route('medicines.show', m.id)}
                className="art-lift group flex h-full flex-col rounded-2xl border border-[var(--m-line)] bg-white p-3 transition-colors duration-300 hover:border-[var(--m-teal)]"
            >
                <span className="relative grid aspect-[4/3] place-items-center overflow-hidden rounded-xl bg-gradient-to-br from-[var(--m-teal-l)] to-white">
                    {m.image_url ? (
                        <img src={m.image_url} alt="" loading="lazy" decoding="async" className="size-full object-contain p-3" />
                    ) : (
                        <span aria-hidden style={DISPLAY} className="text-6xl leading-none text-[var(--m-teal)]/25">
                            {m.brand_name.charAt(0).toUpperCase()}
                        </span>
                    )}
                    {flags.length > 0 && (
                        <span className="absolute start-2 top-2 flex flex-wrap gap-1">
                            {flags.map((f) => (
                                <span key={f} className="rounded-full bg-[var(--m-teal-d)] px-2 py-0.5 text-[0.65rem] font-semibold text-white">
                                    {f}
                                </span>
                            ))}
                        </span>
                    )}
                </span>
                <span className="mt-3 block text-lg leading-tight font-semibold text-[var(--m-teal)]">{m.brand_name}</span>
                <span className="block text-xs text-[var(--m-muted)] capitalize">{[m.form, m.strength].filter(Boolean).join(', ')}</span>
                {m.generic_name && <span className="mt-1.5 line-clamp-2 block text-sm leading-snug text-[var(--m-ink)]">{m.generic_name}</span>}
                <span className="mt-auto flex items-end justify-between gap-2 pt-3">
                    <span className="line-clamp-1 text-xs text-[var(--m-muted)]">{m.company?.name}</span>
                    <span style={DISPLAY} className="tabular shrink-0 text-xl text-[var(--m-ink)]">
                        {m.mrp_piece ? money(Number(m.mrp_piece)) : t('See pack')}
                    </span>
                </span>
            </Link>
        </li>
    );
}

/** Medicines as a grid of cards, two across on a phone and up to four on a wide screen. */
function MedGrid({ medicines }: { medicines: MedicineSummary[] }) {
    return (
        <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4">
            {medicines.map((m) => (
                <MedCard key={m.id} m={m} />
            ))}
        </ul>
    );
}

/** The icon that suits a form of medicine. */
function formIcon(name: string) {
    const n = name.toLowerCase();

    if (/inject|vial|ampoule|infus/.test(n)) return Syringe;
    if (/eye|ear|nasal/.test(n)) return Eye;
    if (/syrup|suspens|solution|drop|liquid|elixir/.test(n)) return Droplet;
    if (/cream|ointment|gel|lotion|spray/.test(n)) return Beaker;
    if (/supposit|pessar|inhal/.test(n)) return Thermometer;

    return Pill;
}

const DEBOUNCE_MS = 250;

export default function MedicinesIndex({ query, results: page, browse, letter, front }: Props) {
    const { t, num, money } = useI18n();
    const [q, setQ] = useState(query);
    const [results, setResults] = useState<MedicineSummary[]>(page?.data ?? []);
    /** Page links belong to the server's results only; live results from typing replace them. */
    const [links, setLinks] = useState(page?.links ?? []);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [searched, setSearched] = useState(query.trim().length >= 2);
    const controller = useRef<AbortController | null>(null);
    /** The brand (company) chosen to narrow the matches. Null shows all of them. */
    const [brand, setBrand] = useState<number | null>(null);
    const brands = Object.values(
        results.reduce<Record<number, { id: number; name: string; n: number }>>((all, m) => {
            if (m.company) all[m.company.id] = { id: m.company.id, name: m.company.name, n: (all[m.company.id]?.n ?? 0) + 1 };
            return all;
        }, {}),
    ).sort((a, b) => b.n - a.n || a.name.localeCompare(b.name));
    const chosen = brand !== null && brands.some((b) => b.id === brand) ? brand : null;
    const shown = chosen === null ? results : results.filter((m) => m.company?.id === chosen);

    useEffect(() => {
        const term = q.trim();

        if (term.length < 2) {
            controller.current?.abort();
            setResults([]);
            setLinks([]);
            setSearched(false);
            setLoading(false);
            return;
        }

        const timer = setTimeout(async () => {
            controller.current?.abort();
            controller.current = new AbortController();
            setLoading(true);
            setFailed(false);

            try {
                const res = await fetch(`${route('medicines.suggest')}?q=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.current.signal,
                });
                if (!res.ok) throw new Error(String(res.status));
                const json = (await res.json()) as { data: MedicineSummary[] };
                setResults(json.data);
                setLinks([]);
                setSearched(true);
                window.history.replaceState(null, '', `${route('medicines.index')}?q=${encodeURIComponent(term)}`);
            } catch (error) {
                if ((error as Error).name !== 'AbortError') setFailed(true);
            } finally {
                setLoading(false);
            }
        }, DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [q]);

    const term = q.trim();
    const searching = searched || term.length >= 2;
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

    // With no sections built, the page is just its banner and the list.
    const blocks: FrontBlock[] = front?.blocks ?? [
        {
            id: 0,
            kind: 'hero',
            title: t('Find a medicine'),
            text: t('Search by brand or generic name, in English or Bangla. Small spelling mistakes are fine.'),
            button: t('Search'),
        },
        { id: -1, kind: 'browse', title: t('All medicines, A to Z'), text: null, button: null },
    ];

    const hero = (b: FrontBlock) => {
        const counts = b.counts as { brands: number; generics: number } | undefined;

        return (
            <section
                key={b.id}
                aria-labelledby="search-title"
                className="relative isolate -mx-4 overflow-hidden bg-[var(--m-teal-d)] text-white sm:mx-0 sm:rounded-3xl"
            >
                {b.image && (
                    <img
                        src={b.image}
                        alt=""
                        fetchPriority="high"
                        decoding="async"
                        className="absolute inset-y-0 right-0 -z-20 h-full w-full object-cover sm:w-3/5"
                    />
                )}
                <span
                    aria-hidden
                    className="absolute inset-0 -z-10 bg-gradient-to-r from-[var(--m-teal-d)] via-[var(--m-teal-d)]/90 to-[var(--m-teal-d)]/30"
                />
                <div className="px-6 py-12 sm:px-12 sm:py-20">
                    <h1 id="search-title" style={DISPLAY} className="art-rise max-w-xl text-5xl leading-[1.02] text-balance sm:text-7xl">
                        {b.title}
                    </h1>
                    {b.text && <p className="art-rise art-d1 mt-5 max-w-lg text-lg text-white/80">{b.text}</p>}

                    <form
                        role="search"
                        className="art-rise art-d2 mt-8 flex max-w-2xl overflow-hidden rounded-2xl bg-white text-[var(--m-ink)] shadow-[0_24px_60px_-20px_rgba(0,0,0,0.5)]"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('medicines.index'), { q: term }, { preserveState: true, replace: true });
                        }}
                    >
                        <label htmlFor="medicine-q" className="sr-only">
                            {t('Medicine name')}
                        </label>
                        <div className="relative flex-1">
                            <Search
                                aria-hidden
                                className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[var(--m-muted)]"
                            />
                            <input
                                id="medicine-q"
                                type="search"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                autoFocus
                                autoComplete="off"
                                spellCheck={false}
                                enterKeyHint="search"
                                placeholder={t('Brand or generic name')}
                                className="h-16 w-full bg-transparent pr-4 pl-12 text-lg outline-none placeholder:text-[var(--m-muted)]/70"
                            />
                        </div>
                        <button type="submit" className="h-16 shrink-0 bg-[var(--m-teal)] px-7 font-semibold text-white hover:bg-[var(--m-teal-d)]">
                            {b.button ?? t('Search')}
                        </button>
                    </form>

                    {counts && (
                        <p className="tabular mt-5 text-sm text-white/70">
                            {t('{a} brands and {b} generic names', { a: num(counts.brands), b: num(counts.generics) })}
                        </p>
                    )}
                </div>
            </section>
        );
    };

    const list = (b: FrontBlock) => (
        <section key={b.id} aria-live="polite" aria-busy={loading} className="space-y-4">
            {!searching && b.title && <SectionHead title={b.title} />}
            {!searching && (
                <nav aria-label={t('Brands by first letter')} className="-mx-4 sm:mx-0">
                    <ul className="no-scrollbar flex gap-1.5 overflow-x-auto px-4 pb-1 sm:flex-wrap sm:overflow-visible sm:px-0">
                        <li className="shrink-0">
                            <Link
                                href={route('medicines.index')}
                                aria-current={letter === '' ? 'page' : undefined}
                                className={`grid h-10 min-w-10 place-items-center rounded-full border px-4 text-sm font-semibold transition-colors duration-300 ${letter === '' ? 'border-[var(--m-teal-d)] bg-[var(--m-teal-d)] text-white' : 'border-[var(--m-line)] bg-white text-[var(--m-ink)] hover:border-[var(--m-teal)]'}`}
                            >
                                {t('All')}
                            </Link>
                        </li>
                        {letters.map((l) => (
                            <li key={l} className="shrink-0">
                                <Link
                                    href={route('medicines.index', { letter: l })}
                                    aria-current={letter === l ? 'page' : undefined}
                                    className={`grid size-10 place-items-center rounded-full border text-sm font-semibold transition-colors duration-300 ${letter === l ? 'border-[var(--m-teal-d)] bg-[var(--m-teal-d)] text-white' : 'border-[var(--m-line)] bg-white text-[var(--m-ink)] hover:border-[var(--m-teal)]'}`}
                                >
                                    {l}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            )}

            {failed && (
                <p role="alert" className="rounded-md border border-[var(--pt-danger)] px-4 py-3 text-[var(--pt-danger)]">
                    {t('Search is not responding. Check your connection and try again.')}
                </p>
            )}

            {!failed && searched && brands.length > 1 && (
                <div role="group" aria-label={t('Brand')} className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        aria-pressed={chosen === null}
                        onClick={() => setBrand(null)}
                        className={`rounded-full border px-3 py-1 text-sm ${chosen === null ? 'border-[var(--m-teal)] bg-[var(--m-teal)] text-white' : 'border-[var(--m-line)] bg-white'}`}
                    >
                        {t('All brands')}
                    </button>
                    {brands.map((br) => (
                        <button
                            key={br.id}
                            type="button"
                            aria-pressed={chosen === br.id}
                            onClick={() => setBrand(br.id)}
                            className={`rounded-full border px-3 py-1 text-sm ${chosen === br.id ? 'border-[var(--m-teal)] bg-[var(--m-teal)] text-white' : 'border-[var(--m-line)] bg-white'}`}
                        >
                            {br.name} <span className="tabular opacity-70">{num(br.n)}</span>
                        </button>
                    ))}
                </div>
            )}

            {!failed && searched && !loading && results.length > 0 && (
                <p className="text-sm text-[var(--pt-muted)]">
                    {results.length >= 30
                        ? t('Showing the 30 closest matches. Add a strength to narrow it down.')
                        : results.length === 1
                          ? t('1 match')
                          : t('{n} matches', { n: num(results.length) })}
                </p>
            )}

            {loading && results.length === 0 && (
                <ul aria-hidden className="space-y-3">
                    {[0, 1, 2, 3].map((i) => (
                        <li key={i} className="h-20 animate-pulse rounded-md bg-[var(--pt-line)]/60" />
                    ))}
                </ul>
            )}

            {results.length > 0 && <MedGrid medicines={shown} />}
            <Pagination links={links} />

            {!failed && searched && !loading && results.length === 0 && (
                <div className="max-w-prose py-6">
                    <p className="text-lg font-semibold">{t('No medicine matches “{term}”.', { term })}</p>
                    <p className="mt-1 text-[var(--pt-muted)]">
                        {t('Check the spelling, or search by the generic name instead, for example “paracetamol”.')}
                    </p>
                    <RequestButton key={term.toLowerCase()} query={term} className="mt-4" />
                </div>
            )}

            {!searching && browse && (
                <>
                    <p className="text-sm text-[var(--pt-muted)]">
                        {letter
                            ? t('Brands starting with {l}: {n}', { l: letter, n: num(browse.total) })
                            : t('All medicines, A to Z: {n}', { n: num(browse.total) })}
                    </p>
                    <MedGrid medicines={browse.data} />
                    <Pagination links={browse.links} />
                </>
            )}

            {!searching && !browse && (
                <p className="max-w-prose text-[var(--pt-muted)]">{t('Type at least two letters. Try “napa”, “seclo” or “cefixime 200”.')}</p>
            )}
        </section>
    );

    const section = (b: FrontBlock) => {
        switch (b.kind) {
            case 'hero':
                return hero(b);
            case 'browse':
                return list(b);
            case 'service':
                return <ServiceStrip key={b.id} items={b.items ?? []} label={t('Why use this')} />;
            case 'faq':
                return <FaqList key={b.id} title={b.title} items={b.items ?? []} />;
            case 'forms': {
                const forms = (b.forms as { slug: string; name: string; count: number }[]) ?? [];

                return (
                    <section key={b.id} aria-label={b.title ?? undefined} className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-16">
                        <SectionHead title={b.title} text={b.text} />
                        <ul className="grid grid-cols-2 gap-3">
                            {forms.map((f) => {
                                const Icon = formIcon(f.name);

                                return (
                                    <li key={f.slug}>
                                        <Link
                                            href={route('forms.show', f.slug)}
                                            className="art-lift group flex h-full flex-col justify-between gap-6 rounded-2xl border border-[var(--m-line)] bg-white p-4 hover:border-[var(--m-teal)]"
                                        >
                                            <Icon aria-hidden className="size-7 text-[var(--m-teal)]" strokeWidth={1.5} />
                                            <span>
                                                <span className="block font-semibold capitalize">{f.name}</span>
                                                <span className="tabular text-sm text-[var(--m-muted)]">
                                                    {t('{n} medicines', { n: num(f.count) })}
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                );
            }
            case 'fresh': {
                const meds = (b.medicines as MedicineSummary[]) ?? [];

                return (
                    <section key={b.id} aria-label={b.title ?? undefined}>
                        <SectionHead title={b.title} />
                        <div className="mt-6">
                            <MedGrid medicines={meds} />
                        </div>
                    </section>
                );
            }
            case 'companies': {
                const companies = (b.companies as { id: number; name: string; logo: string | null; count: number }[]) ?? [];

                return (
                    <section key={b.id} aria-label={b.title ?? undefined}>
                        <div className="flex items-end justify-between gap-4">
                            <SectionHead title={b.title} />
                            <Link
                                href={route('companies.index')}
                                className="inline-flex min-h-10 shrink-0 items-center gap-1 text-sm underline underline-offset-4"
                            >
                                {t('All companies')}
                                <ArrowUpRight aria-hidden className="size-4" strokeWidth={1.6} />
                            </Link>
                        </div>
                        <ul className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {companies.map((c) => (
                                <li key={c.id}>
                                    <Link
                                        href={route('companies.show', c.id)}
                                        className="art-lift flex h-full items-center gap-3 rounded-2xl border border-[var(--m-line)] bg-white p-3 hover:border-[var(--m-teal)]"
                                    >
                                        <span className="grid size-12 shrink-0 place-items-center overflow-hidden rounded-xl bg-[var(--m-teal-l)] text-lg font-semibold text-[var(--m-teal)]">
                                            {c.logo ? (
                                                <img src={c.logo} alt="" loading="lazy" decoding="async" className="size-full object-contain p-1" />
                                            ) : (
                                                c.name.charAt(0)
                                            )}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="line-clamp-2 block leading-snug font-semibold">{c.name}</span>
                                            <span className="tabular text-xs text-[var(--m-muted)]">{t('{n} medicines', { n: num(c.count) })}</span>
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                );
            }
            default:
                return null;
        }
    };

    return (
        <PublicLayout>
            <Head title={t('Find a medicine')} />
            <div className="med mx-auto max-w-5xl space-y-16">
                {blocks.map((b) => (searching && b.kind !== 'hero' && b.kind !== 'browse' ? null : section(b)))}
            </div>
        </PublicLayout>
    );
}
