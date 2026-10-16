import Pagination from '@/components/admin/pagination';
import CompanyLogo from '@/components/companies/company-logo';
import MedicineRow from '@/components/medicines/medicine-row';
import { type MedicineSummary } from '@/components/medicines/types';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

interface Props {
    company: { id: number; name: string; logo: string | null };
    medicines: { data: MedicineSummary[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
}

export default function CompanyShow({ company, medicines }: Props) {
    const { t, num } = useI18n();

    return (
        <PublicLayout>
            <Head title={company.name} />

            <Link href={route('companies.index')} className="-ml-2 inline-flex items-center gap-1 rounded px-2 py-1 text-sm text-[var(--pt-muted)] hover:text-[var(--pt-ink)]">
                <ChevronLeft aria-hidden className="size-4" />
                {t('All companies')}
            </Link>

            <header className="mt-4 flex items-center gap-4">
                <CompanyLogo name={company.name} logo={company.logo} className="size-20" />
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{company.name}</h1>
                    <p className="text-[var(--pt-muted)]">{t('{n} medicines', { n: num(medicines.total) })}</p>
                </div>
            </header>

            <ul className="mt-8 border-t border-[var(--pt-line)]">
                {medicines.data.map((m) => (
                    <MedicineRow key={m.id} medicine={m} />
                ))}
            </ul>

            <Pagination links={medicines.links} />
        </PublicLayout>
    );
}
