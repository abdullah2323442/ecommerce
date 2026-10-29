import MedicineCard from '@/components/medicines/medicine-card';
import RequestButton from '@/components/requests/request-button';
import { type MedicineSummary } from '@/components/medicines/types';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { Search } from 'lucide-react';

interface Product {
    id: number;
    title: string;
    price: number;
    photo: string | null;
}

interface Props {
    q: string;
    medicines: MedicineSummary[];
    products: Product[];
    productTotal: number;
}

const GRID = 'grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 xl:grid-cols-5';

/** One search for the whole shop: the pharmacy and the market's products, each in its own group. */
export default function SearchPage({ q, medicines, products, productTotal }: Props) {
    const { t, money, num } = useI18n();
    const searched = q.trim().length >= 2;
    const none = searched && medicines.length === 0 && products.length === 0;

    return (
        <PublicLayout>
            <Head title={t('Search the shop')} />
            <h1 className="font-display text-3xl font-extrabold sm:text-4xl">{t('Search the shop')}</h1>
            <form action="/search" method="get" role="search" className="mt-4 flex h-14 max-w-2xl items-center gap-3 rounded-lg border-2 border-[var(--pt-line)] bg-[var(--pt-surface)] px-4 focus-within:border-[var(--pt-amber)]">
                <Search aria-hidden className="size-5 text-[var(--pt-muted)]" />
                <input name="q" type="search" defaultValue={q} autoFocus autoComplete="off" aria-label={t('Search the shop')} placeholder={t('Medicines, products, brands…')} className="h-full w-full bg-transparent text-lg outline-none" />
            </form>

            {!searched && <p className="mt-6 text-[var(--pt-muted)]">{t('Type at least two letters.')}</p>}
            {none && (
                <div className="mt-8 max-w-prose">
                    <p className="text-lg font-semibold">{t('Nothing matches “{term}”.', { term: q })}</p>
                    <RequestButton key={q.toLowerCase()} query={q} sector="search" className="mt-4" />
                </div>
            )}

            {medicines.length > 0 && (
                <section className="mt-8">
                    <div className="mb-3 flex items-baseline justify-between">
                        <h2 className="font-display text-xl font-bold">{t('Medicines')}</h2>
                        <Link href={`/medicines?q=${encodeURIComponent(q)}`} className="text-sm font-semibold underline underline-offset-4">
                            {t('See all medicines')}
                        </Link>
                    </div>
                    <ul className={GRID}>
                        {medicines.map((m) => (
                            <MedicineCard key={m.id} medicine={m} />
                        ))}
                    </ul>
                </section>
            )}

            {products.length > 0 && (
                <section className="mt-10">
                    <div className="mb-3 flex items-baseline justify-between">
                        <h2 className="font-display text-xl font-bold">
                            {t('Market')} <span className="tabular text-sm font-normal text-[var(--pt-muted)]">{num(productTotal)}</span>
                        </h2>
                        <Link href={`/market?q=${encodeURIComponent(q)}`} className="text-sm font-semibold underline underline-offset-4">
                            {t('See all in the market')}
                        </Link>
                    </div>
                    <ul className={GRID}>
                        {products.map((p) => (
                            <li key={p.id} className="flex">
                                <Link href={`/market/${p.id}`} className="group flex w-full flex-col overflow-hidden rounded-[1.5rem] border border-[var(--pt-line)] bg-[var(--pt-surface)] transition duration-200 hover:-translate-y-0.5 hover:border-[var(--pt-amber)] hover:shadow-[0_12px_32px_-12px_rgba(20,18,15,0.28)] focus-visible:ring-2 focus-visible:ring-[var(--pt-amber)] motion-reduce:transition-none motion-reduce:hover:translate-y-0">
                                    <div className="aspect-[4/3] bg-gradient-to-b from-white to-[var(--pt-sage)]">{p.photo && <img src={p.photo} alt="" loading="lazy" decoding="async" className="size-full object-contain p-4" />}</div>
                                    <div className="flex flex-1 flex-col gap-1 p-3.5">
                                        <h3 className="line-clamp-2 text-[15px] leading-snug font-semibold">{p.title}</h3>
                                        <p className="tabular mt-auto border-t border-dashed border-[var(--pt-line)] pt-3 text-lg font-bold">{money(p.price)}</p>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </PublicLayout>
    );
}
