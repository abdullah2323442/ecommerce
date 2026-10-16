import CompanyLogo from '@/components/companies/company-logo';
import FormTiles, { type FormTile } from '@/components/home/form-tiles';
import Hero from '@/components/home/hero';
import JourneyLine from '@/components/home/journey-line';
import MedicineRail from '@/components/home/medicine-rail';
import ShopsBento from '@/components/home/shops-bento';
import { type MedicineSummary } from '@/components/medicines/types';
import RequestButton from '@/components/requests/request-button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Check, Languages, LayoutGrid, Tag } from 'lucide-react';
import { type ReactNode, useState } from 'react';

interface Props {
    stats: { medicines: number; companies: number; generics: number };
    companies: { id: number; name: string; logo: string | null; count: number }[];
    featured: MedicineSummary[];
    forms: FormTile[];
}

function Section({ id, title, hint, action, children }: { id?: string; title: string; hint?: string; action?: ReactNode; children: ReactNode }) {
    return (
        <section id={id} aria-label={title} className="mx-auto w-full max-w-6xl scroll-mt-20 px-4 pt-16 sm:pt-24">
            <div className="mb-7 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="font-display text-3xl leading-tight font-bold sm:text-5xl">{title}</h2>
                    {hint && <p className="mt-2 max-w-xl text-lg text-[var(--pt-muted)]">{hint}</p>}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

/** A photo on one side, the shop's story on the other. Alternates sides down the page. */
function Split({ id, image, title, line, points, flip }: { id: string; image: string; title: string; line: string; points: string[]; flip?: boolean }) {
    const { t } = useI18n();
    const { site } = usePage<SharedData>().props;
    // The owner can hide this section or open it (Admin > Site design).
    const card = site.home.sections.find((x) => x.key === id);

    if (!card) {
        return null;
    }

    return (
        <section aria-label={title} className="mx-auto w-full max-w-6xl px-4 pt-16 sm:pt-24">
            <div className="grid items-center gap-8 overflow-hidden rounded-[2.5rem] border border-[var(--pt-line)] bg-[var(--pt-surface)] p-4 sm:p-6 lg:grid-cols-2 lg:gap-14">
                <div className={`relative overflow-hidden rounded-[2rem] ${flip ? 'lg:order-2' : ''}`}>
                    <img src={`${image}-1280.webp`} srcSet={`${image}-720.webp 720w, ${image}-1280.webp 1280w`} sizes="(min-width: 1024px) 560px, 100vw" alt="" loading="lazy" decoding="async" className="aspect-[4/3] w-full object-cover" />
                    <span className="absolute top-4 left-4 rounded-full bg-white/90 px-4 py-1 text-sm font-semibold text-[#0e0d0c] backdrop-blur">{card.open ? t('Open now') : t('Opening soon')}</span>
                </div>
                <div className="px-2 pb-4 sm:px-6 lg:py-6">
                    <h2 className="font-display text-4xl leading-tight font-bold sm:text-5xl">{title}</h2>
                    <p className="mt-3 max-w-md text-xl text-[var(--pt-muted)]">{line}</p>
                    <ul className="mt-7 space-y-3">
                        {points.map((p) => (
                            <li key={p} className="flex items-center gap-3 text-lg">
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-[var(--pt-sage)] text-[var(--pt-teal)]">
                                    <Check aria-hidden className="size-4" strokeWidth={3} />
                                </span>
                                {t(p)}
                            </li>
                        ))}
                    </ul>
                    {card.open && card.href && (
                        <Link href={card.href} className="mt-7 inline-flex h-12 items-center rounded-full bg-[var(--pt-ink)] px-6 font-semibold text-[var(--pt-paper)] hover:opacity-90">
                            {t('Open')}
                        </Link>
                    )}
                </div>
            </div>
        </section>
    );
}

function Counter() {
    const { t } = useI18n();
    const [q, setQ] = useState('');
    const ready = q.trim().length >= 2;

    return (
        <div className="relative isolate overflow-hidden rounded-[2.5rem] bg-[#0e0d0c] px-6 py-14 text-white sm:px-14 sm:py-20">
            <div aria-hidden className="aurora-b absolute -top-32 -right-24 -z-10 size-[28rem] rounded-full bg-[#c9a24b]/40 blur-[100px]" />
            <div className="grid gap-8 md:grid-cols-5">
                <div className="md:col-span-2">
                    <h2 className="font-display text-4xl leading-tight font-bold text-balance sm:text-5xl">{t('Cannot find it? Ask at the counter.')}</h2>
                    <p className="mt-3 max-w-md text-lg text-white/75">{t('Tell us the medicine you were looking for. Every request is counted, and the ones asked for most are stocked first.')}</p>
                </div>
                <div className="md:col-span-3">
                    <label htmlFor="counter-q" className="block text-sm font-semibold text-white/85">
                        {t('The medicine you were looking for')}
                    </label>
                    <input
                        id="counter-q"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        maxLength={120}
                        autoComplete="off"
                        placeholder={t('Name and strength, for example Insulin glargine 100 IU')}
                        className="mt-2 h-14 w-full max-w-lg rounded-2xl border border-white/20 bg-white/10 px-5 text-lg text-white outline-none backdrop-blur placeholder:text-white/50 focus:border-[#f3d995] focus:ring-4 focus:ring-[#f3d995]/25"
                    />
                    {/* Remount on change so a finished request does not stay "done" for the next medicine. */}
                    {ready ? <RequestButton key={q.trim().toLowerCase()} query={q.trim()} className="mt-3" /> : <p className="mt-3 text-sm text-white/65">{t('Type at least two letters to send a request.')}</p>}
                </div>
            </div>
        </div>
    );
}

export default function Home({ stats, companies, featured, forms }: Props) {
    const { t, num } = useI18n();

    const values = [
        { Icon: Languages, title: t('Everything in Bangla'), line: t('Read, search and shop in Bangla, with English one tap away.') },
        { Icon: Tag, title: t('Honest prices'), line: t('You see the printed price before anything else.') },
        { Icon: LayoutGrid, title: t('One place'), line: t('Many shops under one roof, opened one at a time.') },
    ];

    return (
        <PublicLayout bleed>
            <Head title={t('Every shop. One place.')}>
                <meta name="description" content={t("Siam's Aqua: search {n} medicines by brand or generic name, in English or Bangla, and compare what different brands charge for the same medicine.", { n: num(stats.medicines) })} />
            </Head>

            <Hero />

            <JourneyLine>

            <Section title={t('The shops')} hint={t('The pharmacy is open. The others are being built, one at a time.')}>
                <ShopsBento />
            </Section>

            <section aria-label={t('Why us')} className="mx-auto w-full max-w-6xl px-4 pt-6">
                <ul className="grid gap-4 md:grid-cols-3">
                    {values.map(({ Icon, title, line }) => (
                        <li key={title} className="rounded-[1.75rem] border border-[var(--pt-line)] bg-[var(--pt-surface)] p-7">
                            <span className="flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-[#c9a24b] to-[#0e0d0c] text-white shadow-md">
                                <Icon aria-hidden className="size-6" strokeWidth={1.6} />
                            </span>
                            <p className="mt-5 font-display text-2xl font-bold">{title}</p>
                            <p className="mt-1.5 text-[var(--pt-muted)]">{line}</p>
                        </li>
                    ))}
                </ul>
            </section>

            {featured.length > 0 && (
                <Section
                    id="pharmacy"
                    title={t('Popular medicines')}
                    hint={t('Real packs from the biggest companies.')}
                    action={
                        <Link href={route('medicines.index')} className="inline-flex h-11 items-center rounded-full bg-[var(--pt-ink)] px-5 font-semibold text-[var(--pt-paper)] hover:opacity-90">
                            {t('Search medicines')}
                        </Link>
                    }
                >
                    <MedicineRail medicines={featured} />
                </Section>
            )}

            {forms.length > 0 && (
                <Section title={t('Browse by type')}>
                    <FormTiles forms={forms} />
                </Section>
            )}

            <Split id="food" image="/images/home/food" title={t('Food')} line={t('Fast food from local restaurants, ordered online.')} points={['Menus from local restaurants', 'Order and pay online', 'Follow your order']} />
            <Split id="custom" image="/images/home/custom" title={t("Custom")} line={t('Wood and clothing made to your size.')} points={['Send your measurements', 'Chat about your order', 'See how far it has come']} />
            <Split id="games" image="/images/home/gaming" flip title={t('Gaming')} line={t('Game top-ups and diamonds, paid online.')} points={['Top-ups for popular games', 'Pay online only', 'A receipt for every top-up']} />

            {companies.length > 0 && (
                <Section
                    title={t('All companies')}
                    action={
                        <Link href={route('companies.index')} className="font-semibold underline underline-offset-4">
                            {t('All {n} companies', { n: num(stats.companies) })}
                        </Link>
                    }
                >
                    <ul className="-mx-4 flex snap-x gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:grid sm:grid-cols-3 sm:overflow-visible sm:px-0 lg:grid-cols-4">
                        {companies.map((c) => (
                            <li key={c.id} className="w-60 shrink-0 snap-start sm:w-auto">
                                <Link
                                    href={route('companies.show', c.id)}
                                    className="flex items-center gap-3 rounded-2xl border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3 outline-none transition-[border-color,box-shadow] duration-150 hover:border-[var(--pt-amber)] hover:shadow-lg focus-visible:ring-4 focus-visible:ring-[var(--pt-amber)]/40"
                                >
                                    <CompanyLogo name={c.name} logo={c.logo} className="size-12 rounded-xl border-[var(--pt-line)] bg-white" />
                                    <span className="min-w-0">
                                        <span className="block truncate font-semibold">{c.name.replace(/ (Pharmaceuticals|Laboratories|Pharma|Limited|Ltd\.?|PLC)\b\.?/gi, '').trim()}</span>
                                        <span className="tabular block text-sm text-[var(--pt-muted)]">{t('{n} medicines', { n: num(c.count) })}</span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}

            <section id="request" aria-label={t('Request a medicine')} className="mx-auto mt-16 w-full max-w-6xl scroll-mt-20 px-4 sm:mt-24">
                <Counter />
            </section>
            </JourneyLine>
        </PublicLayout>
    );
}
