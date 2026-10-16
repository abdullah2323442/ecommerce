import Pagination from '@/components/admin/pagination';
import CompanyLogo from '@/components/companies/company-logo';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    companies: { data: { id: number; name: string; logo: string | null; count: number }[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    query: string;
}

export default function CompaniesIndex({ companies, query }: Props) {
    const { t, num } = useI18n();
    const [q, setQ] = useState(query);

    return (
        <PublicLayout>
            <Head title={t('Companies')} />

            <h1 className="font-display text-4xl font-extrabold tracking-tight sm:text-5xl">{t('Companies')}</h1>
            <p className="mt-2 max-w-prose text-[var(--pt-muted)]">{t('{n} pharmaceutical companies. Open one to see everything it makes.', { n: num(companies.total) })}</p>

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
                    className="h-12 w-full rounded-lg border-2 border-[var(--pt-line)] bg-[var(--pt-surface)] px-4 outline-none focus:border-[var(--pt-amber)] focus:ring-4 focus:ring-[var(--pt-amber)]/25"
                />
            </form>

            {companies.data.length === 0 ? (
                <div className="mt-8 max-w-prose">
                    <p className="text-lg font-semibold">{t('No company matches “{term}”.', { term: query })}</p>
                    <p className="mt-1 text-[var(--pt-muted)]">{t('Check the spelling, or clear the search to see all companies.')}</p>
                </div>
            ) : (
                <ul className="mt-8 grid gap-x-6 sm:grid-cols-2 lg:grid-cols-3">
                    {companies.data.map((c) => (
                        <li key={c.id}>
                            <Link
                                href={route('companies.show', c.id)}
                                className="flex items-center gap-3 border-b border-[var(--pt-line)] py-3 outline-none hover:bg-[var(--pt-surface)] focus-visible:ring-2 focus-visible:ring-[var(--pt-amber)] focus-visible:ring-inset"
                            >
                                <CompanyLogo name={c.name} logo={c.logo} />
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">{c.name}</span>
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
