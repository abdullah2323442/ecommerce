import Pagination from '@/components/admin/pagination';
import MedicineCard from '@/components/medicines/medicine-card';
import { type MedicineSummary } from '@/components/medicines/types';
import RequestButton from '@/components/requests/request-button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
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
}

const DEBOUNCE_MS = 250;

export default function MedicinesIndex({ query, results: page, browse }: Props) {
    const { t, num } = useI18n();
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

    return (
        <PublicLayout>
            <Head title={t('Find a medicine')} />

            <section aria-labelledby="search-title">
                <h1 id="search-title" className="font-display text-4xl font-extrabold tracking-tight sm:text-5xl">
                    {t('Find a medicine')}
                </h1>
                <p className="mt-2 max-w-prose text-[var(--pt-muted)]">{t('Search by brand or generic name, in English or Bangla. Small spelling mistakes are fine.')}</p>

                <form
                    role="search"
                    className="mt-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(route('medicines.index'), { q: term }, { preserveState: true, replace: true });
                    }}
                >
                    <label htmlFor="medicine-q" className="sr-only">
                        {t('Medicine name')}
                    </label>
                    <div className="relative">
                        <Search aria-hidden className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[var(--pt-muted)]" />
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
                            className="h-14 w-full rounded-lg border-2 border-[var(--pt-line)] bg-[var(--pt-surface)] pr-4 pl-12 text-lg outline-none placeholder:text-[var(--pt-muted)]/70 focus:border-[var(--pt-amber)] focus:ring-4 focus:ring-[var(--pt-amber)]/25"
                        />
                    </div>
                </form>
            </section>

            <section aria-live="polite" aria-busy={loading} className="mt-8">
                {failed && (
                    <p role="alert" className="rounded-md border border-[var(--pt-danger)] px-4 py-3 text-[var(--pt-danger)]">
                        {t('Search is not responding. Check your connection and try again.')}
                    </p>
                )}

                {!failed && searched && brands.length > 1 && (
                    <div role="group" aria-label={t('Brand')} className="mb-3 flex flex-wrap gap-2">
                        <button type="button" aria-pressed={chosen === null} onClick={() => setBrand(null)} className={`rounded-full border px-3 py-1 text-sm ${chosen === null ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)]'}`}>
                            {t('All brands')}
                        </button>
                        {brands.map((b) => (
                            <button key={b.id} type="button" aria-pressed={chosen === b.id} onClick={() => setBrand(b.id)} className={`rounded-full border px-3 py-1 text-sm ${chosen === b.id ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)]'}`}>
                                {b.name} <span className="tabular opacity-70">{num(b.n)}</span>
                            </button>
                        ))}
                    </div>
                )}

                {!failed && searched && !loading && results.length > 0 && (
                    <p className="mb-1 text-sm text-[var(--pt-muted)]">
                        {results.length >= 30 ? t('Showing the 30 closest matches. Add a strength to narrow it down.') : results.length === 1 ? t('1 match') : t('{n} matches', { n: num(results.length) })}
                    </p>
                )}

                {loading && results.length === 0 && (
                    <ul aria-hidden className="space-y-3">
                        {[0, 1, 2, 3].map((i) => (
                            <li key={i} className="h-20 animate-pulse rounded-md bg-[var(--pt-line)]/60" />
                        ))}
                    </ul>
                )}

                {results.length > 0 && (
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 xl:grid-cols-5">
                        {shown.map((m) => (
                            <MedicineCard key={m.id} medicine={m} />
                        ))}
                    </ul>
                )}

                <Pagination links={links} />

                {!failed && searched && !loading && results.length === 0 && (
                    <div className="max-w-prose py-6">
                        <p className="text-lg font-semibold">{t('No medicine matches “{term}”.', { term })}</p>
                        <p className="mt-1 text-[var(--pt-muted)]">{t('Check the spelling, or search by the generic name instead, for example “paracetamol”.')}</p>
                        <RequestButton key={term.toLowerCase()} query={term} className="mt-4" />
                    </div>
                )}

                {!searched && term.length < 2 && browse && (
                    <>
                        <p className="mb-1 text-sm text-[var(--pt-muted)]">{t('All medicines, A to Z: {n}', { n: num(browse.total) })}</p>
                        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 xl:grid-cols-5">
                            {browse.data.map((m) => (
                                <MedicineCard key={m.id} medicine={m} />
                            ))}
                        </ul>
                        <Pagination links={browse.links} />
                    </>
                )}

                {!searched && term.length < 2 && !browse && <p className="max-w-prose text-[var(--pt-muted)]">{t('Type at least two letters. Try “napa”, “seclo” or “cefixime 200”.')}</p>}
            </section>
        </PublicLayout>
    );
}
