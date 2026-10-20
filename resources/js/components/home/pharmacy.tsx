import CompanyLogo from '@/components/companies/company-logo';
import { Rosette } from '@/components/home/ornaments';
import { type MedicineSummary } from '@/components/medicines/types';
import { useI18n } from '@/lib/i18n';
import { Link, router } from '@inertiajs/react';
import { ArrowUpRight, Search } from 'lucide-react';
import { useState } from 'react';

export interface PharmacyData {
    featured: MedicineSummary[];
    companies: { id: number; name: string; logo: string | null; count: number }[];
    forms: { slug: string; name: string; count: number }[];
}

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };

/**
 * The pharmacy, given its own dark band because it is a main part of the shop: a search for any medicine by brand or generic name, the forms people
 * browse by, real packs with the printed price, and the companies behind them. Prices are the printed MRP and nothing else is shown about stock.
 */
export default function Pharmacy({
    stats,
    featured,
    companies,
    forms,
}: PharmacyData & { stats: { medicines: number; companies: number; generics: number } }) {
    const { t, num, money } = useI18n();
    const [q, setQ] = useState('');

    return (
        <section
            aria-label={t('Pharmacy')}
            className="reveal art relative isolate mt-28 overflow-hidden rounded-[2rem] bg-[var(--art-night)] p-12 text-[var(--pt-paper)]"
        >
            <Rosette className="absolute -top-48 -left-48 -z-10 size-[34rem] opacity-20" />

            <div className="grid grid-cols-12 gap-12">
                <div className="col-span-5">
                    <h2 style={DISPLAY} className="text-6xl leading-[1.05] font-normal tracking-tight text-balance">
                        {t('Find your medicine')}
                    </h2>
                    <p className="mt-5 max-w-sm text-lg opacity-80">{t('Search by brand or generic name, in English or Bangla.')}</p>

                    <form
                        role="search"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('medicines.index'), q.trim() ? { q: q.trim() } : {});
                        }}
                        className="mt-8 flex h-14 items-center gap-3 rounded-full bg-[var(--pt-paper)] pr-1.5 pl-5 text-[var(--pt-ink)]"
                    >
                        <Search aria-hidden className="size-5 shrink-0 text-[var(--pt-muted)]" strokeWidth={1.6} />
                        <label htmlFor="home-med" className="sr-only">
                            {t('Medicine name')}
                        </label>
                        <input
                            id="home-med"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            maxLength={80}
                            autoComplete="off"
                            placeholder={t('Medicine name')}
                            className="h-full min-w-0 flex-1 bg-transparent outline-none placeholder:text-[var(--pt-muted)]"
                        />
                        <button
                            type="submit"
                            className="art-btn inline-flex h-11 shrink-0 items-center gap-1 rounded-full bg-[var(--art-night)] px-6 text-[var(--pt-paper)]"
                        >
                            {t('Search')}
                            <ArrowUpRight aria-hidden className="size-4" strokeWidth={1.8} />
                        </button>
                    </form>

                    <ul aria-label={t('Browse by type')} className="mt-6 flex flex-wrap gap-2">
                        {forms.map((f) => (
                            <li key={f.slug}>
                                <Link
                                    href={route('forms.show', f.slug)}
                                    className="inline-flex h-9 items-center rounded-full border border-[var(--art-gold-3)] px-4 text-sm capitalize hover:border-[var(--art-gold-2)] hover:text-[var(--art-gold-1)]"
                                >
                                    {f.name}
                                </Link>
                            </li>
                        ))}
                    </ul>

                    <dl className="mt-10 grid grid-cols-3 gap-4 border-t border-[var(--art-gold-3)] pt-6">
                        {(
                            [
                                [stats.medicines, t('All medicines')],
                                [stats.generics, t('generic names')],
                                [stats.companies, t('All companies')],
                            ] as [number, string][]
                        ).map(([n, label]) => (
                            <div key={label} className="flex flex-col">
                                <dt className="order-2 mt-1 text-xs opacity-70">{label}</dt>
                                <dd style={DISPLAY} className="tabular text-3xl leading-none text-[var(--art-gold-2)]">
                                    {num(n)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <p className="mt-6 max-w-sm text-sm opacity-65">
                        {t('Prices shown are the printed MRP.')} {t("Always follow your doctor's prescription.")}
                    </p>
                </div>

                <div className="col-span-7">
                    <ul className="grid grid-cols-3 gap-3">
                        {featured.slice(0, 6).map((m) => (
                            <li key={m.id} className="art-lift">
                                <Link
                                    href={route('medicines.show', m.id)}
                                    className="flex h-full flex-col rounded-[1.5rem] bg-[var(--pt-paper)] p-3 text-[var(--pt-ink)]"
                                >
                                    <span className="art-zoom flex aspect-square items-center justify-center rounded-[1.1rem] bg-white">
                                        {m.image_url && (
                                            <img
                                                src={m.image_url}
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                                className="max-h-full max-w-full object-contain p-2"
                                            />
                                        )}
                                    </span>
                                    <span className="mt-3 block leading-snug font-medium">{m.brand_name}</span>
                                    <span className="line-clamp-1 block text-sm text-[var(--pt-muted)]">
                                        {[m.generic_name, m.strength].filter(Boolean).join(' ')}
                                    </span>
                                    {m.mrp_piece && (
                                        <span className="tabular mt-auto pt-2 text-sm">
                                            {money(Number(m.mrp_piece))} <span className="text-[var(--pt-muted)]">{t('per piece')}</span>
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <Link href={route('medicines.index')} className="art-btn mt-5 inline-flex items-center gap-1 underline underline-offset-4">
                        {t('All medicines')}
                        <ArrowUpRight aria-hidden className="size-4" strokeWidth={1.6} />
                    </Link>
                </div>
            </div>

            <div className="mt-12 border-t border-[var(--art-gold-3)] pt-8">
                <div className="flex items-end justify-between gap-6">
                    <p style={DISPLAY} className="text-3xl">
                        {t('Medicines from {n} companies', { n: num(stats.companies) })}
                    </p>
                    <Link href={route('companies.index')} className="art-btn inline-flex items-center gap-1 underline underline-offset-4">
                        {t('All companies')}
                        <ArrowUpRight aria-hidden className="size-4" strokeWidth={1.6} />
                    </Link>
                </div>
                <ul className="mt-6 grid grid-cols-10 gap-3">
                    {companies.map((c) => (
                        <li key={c.id}>
                            <Link href={route('companies.show', c.id)} aria-label={c.name} className="art-lift block">
                                <CompanyLogo name={c.name} logo={c.logo} className="aspect-square w-full rounded-2xl border-0 bg-white p-1.5" />
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
