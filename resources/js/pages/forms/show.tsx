import Pagination from '@/components/admin/pagination';
import MedicineRow from '@/components/medicines/medicine-row';
import { type MedicineSummary } from '@/components/medicines/types';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

interface Props {
    form: { name: string; slug: string };
    medicines: { data: MedicineSummary[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    forms: { slug: string; name: string; count: number }[];
}

export default function FormShow({ form, medicines, forms }: Props) {
    const { t, num } = useI18n();
    const name = t(form.name);

    return (
        <PublicLayout>
            <Head title={t('{form} medicines', { form: name })} />

            <Link href="/" className="-ml-2 inline-flex items-center gap-1 rounded px-2 py-1 text-sm text-[var(--pt-muted)] hover:text-[var(--pt-ink)]">
                <ChevronLeft aria-hidden className="size-4" />
                {t('Home')}
            </Link>

            <h1 className="mt-4 font-display text-4xl font-extrabold tracking-tight capitalize sm:text-5xl">{name}</h1>
            <p className="mt-1 text-[var(--pt-muted)]">{t('{n} medicines, A to Z.', { n: num(medicines.total) })}</p>

            <nav aria-label={t('Other forms')} className="-mx-4 mt-5 flex gap-2 overflow-x-auto px-4 pb-2 sm:mx-0 sm:flex-wrap sm:px-0">
                {forms.map((f) => (
                    <Link
                        key={f.slug}
                        href={route('forms.show', f.slug)}
                        aria-current={f.slug === form.slug ? 'page' : undefined}
                        className={`shrink-0 rounded-full border px-3.5 py-1.5 text-sm font-medium capitalize ${
                            f.slug === form.slug ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-paper)]' : 'border-[var(--pt-line)] bg-[var(--pt-surface)] hover:border-[var(--pt-amber)]'
                        }`}
                    >
                        {t(f.name)}
                    </Link>
                ))}
            </nav>

            <ul className="mt-6 border-t border-[var(--pt-line)]">
                {medicines.data.map((m) => (
                    <MedicineRow key={m.id} medicine={m} />
                ))}
            </ul>

            <Pagination links={medicines.links} />
        </PublicLayout>
    );
}
