import Pagination from '@/components/admin/pagination';
import CompanyLogo from '@/components/companies/company-logo';
import SectionHero from '@/components/home/section-hero';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    companies: {
        data: { id: number; name: string; logo: string | null; count: number }[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    query: string;
}

export default function CompaniesIndex({ companies, query }: Props) {
    const { t, num } = useI18n();
    const [q, setQ] = useState(query);

    return (
        <PublicLayout>
            <Head title={t('Companies')} />

            <SectionHero
                image="/images/home/pharmacy"
                title={t('Companies')}
                line={t('{n} pharmaceutical companies. Open one to see everything it makes.', { n: num(companies.total) })}
            />

            <form
                role="search"
                className="mt-6 max-w-md"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.get(route('companies.index'), { q: q || undefined }, { preserveState: true, replace: true });
                }}
            >
                <label htmlFor="company-q" className="sr-only">
                    {t('Company name')}
                </label>
                <input
                    id="company-q"
                    type="search"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder={t('Search companies')}
                    className="h-12 w-full rounded-full border border-[var(--pt-ink)] bg-transparent px-4 outline-none focus:ring-2 focus:ring-[var(--pt-ink)]"
                />
            </form>

            {companies.data.length === 0 ? (
                <div className="mt-8 max-w-prose">
                    <p className="text-lg font-semibold">{t('No company matches “{term}”.', { term: query })}</p>
                    <p className="mt-1 text-[var(--pt-muted)]">{t('Check the spelling, or clear the search to see all companies.')}</p>
                </div>
            ) : (
                <ul className="mt-8 grid gap-px border border-[var(--pt-line)] bg-[var(--pt-line)] sm:grid-cols-2 lg:grid-cols-3">
                    {companies.data.map((c) => (
                        <li key={c.id} className="bg-[var(--pt-paper)]">
                            <Link
                                href={route('companies.show', c.id)}
                                className="flex items-center gap-4 p-4 outline-none hover:bg-[var(--pt-surface)] focus-visible:ring-2 focus-visible:ring-[var(--pt-ink)] focus-visible:ring-inset"
                            >
                                <CompanyLogo name={c.name} logo={c.logo} />
                                <span className="min-w-0">
                                    <span
                                        style={{ fontFamily: "'Cormorant Garamond', 'Hind Siliguri', Georgia, serif" }}
                                        className="block truncate text-xl leading-tight font-medium"
                                    >
                                        {c.name}
                                    </span>
                                    <span className="block text-sm text-[var(--pt-muted)]">{t('{n} medicines', { n: num(c.count) })}</span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            <Pagination links={companies.links} />
        </PublicLayout>
    );
}
