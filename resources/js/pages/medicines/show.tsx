import { MedicineFlagsExplained } from '@/components/medicines/medicine-flags';
import MedicineRow, { MedicineThumb } from '@/components/medicines/medicine-row';
import { type MedicineSummary } from '@/components/medicines/types';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import PageHead, { JsonLd } from '@/components/shell/page-head';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { type SharedData } from '@/types';
import MpoOffers, { type MpoOffer } from '@/components/mpo-offers';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ChevronLeft } from 'lucide-react';

interface Props {
    medicine: MedicineSummary;
    alternatives: MedicineSummary[];
    preorderDays?: number | null;
    mpoOffers?: MpoOffer[];
    steps?: { unit: 'box' | 'strip' | 'piece'; min_qty: number; percent: number; price: number }[];
    questions?: { id: number; question: string; answer: string; at: string }[];
    myWaiting?: number;
    together?: MedicineSummary[];
    priceWas?: { was: number; now: number; at: string; unit: 'box' | 'piece' } | null;
}

function PreorderBox({ medicine, days }: { medicine: MedicineSummary; days: number }) {
    const { t } = useI18n();
    const { auth, canCart } = usePage<SharedData>().props;
    const units = ([['box', medicine.packaging_type === 'box' ? t('Box') : t('Pack'), medicine.mrp_box], ['strip', t('Strip'), medicine.mrp_strip], ['piece', t('Piece'), medicine.mrp_piece]] as [string, string, string | null][]).filter(
        ([u, , price]) => price && (medicine.packaging_type === 'box' || u === 'piece'),
    );
    const [unit, setUnit] = useState(units[0]?.[0] ?? 'piece');
    const [qty, setQty] = useState('1');
    const errors = usePage().props.errors as Record<string, string>;

    if (!canCart || units.length === 0) return null;

    return (
        <div className="mt-6 max-w-sm space-y-2 rounded-[1.5rem] border border-dashed border-[var(--pt-line)] p-4">
            <p className="font-semibold">{t('Not on the shelf? Pre-order it.')}</p>
            <p className="text-sm text-[var(--pt-muted)]">{t('You will get it within {n} days of our answer.', { n: days })}</p>
            {auth.user ? (
                <>
                    <div className="flex gap-2">
                        <select aria-label={t('Pack')} value={unit} onChange={(e) => setUnit(e.target.value)} className="h-11 flex-1 rounded-md border border-[var(--pt-line)] bg-[var(--pt-surface)] px-2 text-sm">
                            {units.map(([u, label]) => (
                                <option key={u} value={u}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <Input aria-label={t('Quantity')} className="h-11 w-20" type="number" min="1" step="1" inputMode="numeric" value={qty} onChange={(e) => setQty(e.target.value)} />
                    </div>
                    <Button variant="outline" className="h-11 w-full" disabled={Number(qty) < 1} onClick={() => router.post('/preorders', { medicine_id: medicine.id, unit, quantity: Math.max(1, Math.round(Number(qty))) })}>
                        {t('Pre-order')}
                    </Button>
                    {errors.preorder && <p className="text-sm text-danger">{errors.preorder}</p>}
                </>
            ) : (
                <Link href="/login" className="inline-block text-sm font-semibold underline underline-offset-4">
                    {t('Log in to pre-order')}
                </Link>
            )}
        </div>
    );
}

function AddToCart({ medicine }: { medicine: MedicineSummary }) {
    const { t, money } = useI18n();
    const { canCart } = usePage<SharedData>().props;
    const units = (
        [
            ['box', medicine.packaging_type === 'box' ? t('Box') : t('Pack'), medicine.mrp_box],
            ['strip', t('Strip'), medicine.mrp_strip],
            ['piece', t('Piece'), medicine.mrp_piece],
        ] as [string, string, string | null][]
    ).filter(([u, , price]) => price && (medicine.packaging_type === 'box' || u === 'piece'));
    const [unit, setUnit] = useState(units[0]?.[0] ?? 'piece');
    const [qty, setQty] = useState('1');
    const errors = usePage().props.errors as Record<string, string>;

    if (!canCart || units.length === 0) return null;

    return (
        <div className="mt-6 max-w-sm space-y-2">
            <div className="flex gap-2">
                <label className="sr-only" htmlFor="cart-unit">
                    {t('Pack')}
                </label>
                <select id="cart-unit" value={unit} onChange={(e) => setUnit(e.target.value)} className="h-11 flex-1 rounded-md border border-[var(--pt-line)] bg-[var(--pt-surface)] px-2 text-sm">
                    {units.map(([u, label, price]) => (
                        <option key={u} value={u}>
                            {label} · {money(price!)}
                        </option>
                    ))}
                </select>
                <label className="sr-only" htmlFor="cart-qty">
                    {t('Quantity')}
                </label>
                <Input id="cart-qty" className="h-11 w-20" type="number" min="1" max="1000" step="1" inputMode="numeric" value={qty} onChange={(e) => setQty(e.target.value)} />
            </div>
            <Button
                className="h-11 w-full"
                disabled={Number(qty) < 1}
                onClick={() => router.post('/cart', { medicine_id: medicine.id, unit, quantity: Math.max(1, Math.round(Number(qty))) }, { preserveScroll: true })}
            >
                {t('Add to cart')}
            </Button>
            {errors.cart && <p className="text-sm text-danger">{errors.cart}</p>}
        </div>
    );
}

/** Questions under the product (spec ch 23): signed-in buyers ask, we answer, everyone reads. Nobody's name is shown. */
function QuestionsBox({ medicineId, questions, waiting, signedIn }: { medicineId: number; questions: { id: number; question: string; answer: string; at: string }[]; waiting: number; signedIn: boolean }) {
    const { t, date } = useI18n();
    const [text, setText] = useState('');
    const errors = usePage().props.errors as Record<string, string>;
    const brand = (usePage().props as { brand?: { name?: string } }).brand?.name;

    return (
        <section aria-labelledby="qa-title" className="mt-12 max-w-2xl">
            <h2 id="qa-title" className="font-display text-2xl font-extrabold">
                {t('Questions and answers')}
            </h2>

            {questions.length === 0 ? (
                <p className="mt-2 text-sm text-[var(--pt-muted)]">{t('No question has been answered here yet.')}</p>
            ) : (
                <ul className="mt-3 divide-y divide-[var(--pt-line)] border-y border-[var(--pt-line)]">
                    {questions.map((q) => (
                        <li key={q.id} className="py-3">
                            <p className="font-semibold">{q.question}</p>
                            <p className="mt-1 text-[var(--pt-ink)]">{q.answer}</p>
                            <p className="mt-1 text-xs text-[var(--pt-muted)]">
                                {brand} · {date(q.at)}
                            </p>
                        </li>
                    ))}
                </ul>
            )}

            {signedIn ? (
                <form
                    className="mt-4 space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(`/medicines/${medicineId}/questions`, { question: text }, { preserveScroll: true, onSuccess: () => setText('') });
                    }}
                >
                    <label htmlFor="ask" className="text-sm font-medium">
                        {t('Ask a question about this medicine')}
                    </label>
                    <textarea id="ask" rows={2} maxLength={500} value={text} onChange={(e) => setText(e.target.value)} className="w-full rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3" />
                    {errors.question && <p role="alert" className="text-sm text-danger">{errors.question}</p>}
                    <Button type="submit" disabled={text.trim().length < 8}>
                        {t('Send question')}
                    </Button>
                    {waiting > 0 && <p className="text-sm text-[var(--pt-muted)]">{t('Your question is waiting for an answer.')}</p>}
                </form>
            ) : (
                <p className="mt-4 text-sm text-[var(--pt-muted)]">
                    <Link href={route('login')} className="font-semibold underline underline-offset-4">
                        {t('Log in')}
                    </Link>{' '}
                    {t('to ask a question.')}
                </p>
            )}
        </section>
    );
}

export default function MedicineShow({ medicine, alternatives, preorderDays, mpoOffers = [], steps = [], questions = [], myWaiting = 0, together = [], priceWas = null }: Props) {
    const authUser = usePage<SharedData>().props.auth.user;
    const { t, money, date } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const title = [medicine.brand_name, medicine.strength].filter(Boolean).join(' ');

    const prices = [
        { label: medicine.packaging_type === 'box' ? t('Per box') : t('Per pack'), value: medicine.mrp_box },
        { label: t('Per strip'), value: medicine.mrp_strip },
        { label: medicine.volume ? t('Per {volume}', { volume: medicine.volume }) : t('Per piece'), value: medicine.mrp_piece },
    ].filter((p) => p.value) as { label: string; value: string }[];

    return (
        <PublicLayout>
            <PageHead title={title} description={[title, medicine.generic_name, medicine.company?.name].filter(Boolean).join(' · ')}>
                <JsonLd data={{ '@context': 'https://schema.org', '@type': 'Product', name: title, description: [medicine.generic_name, medicine.form].filter(Boolean).join(' · ') || undefined, brand: medicine.company?.name ? { '@type': 'Brand', name: medicine.company.name } : undefined }} />
            </PageHead>

            <Link href={route('medicines.index')} className="-ml-2 inline-flex items-center gap-1 rounded px-2 py-1 text-sm text-[var(--pt-muted)] hover:text-[var(--pt-ink)]">
                <ChevronLeft aria-hidden className="size-4" />
                {t('All medicines')}
            </Link>

            <article className="mt-4 grid gap-8 sm:grid-cols-[14rem_1fr]">
                <MedicineThumb medicine={medicine} className="aspect-square w-full max-w-56 sm:w-56" />

                <div>
                    <h1 className="font-display text-4xl font-extrabold tracking-tight">
                        {medicine.brand_name} {medicine.strength && <span>{medicine.strength}</span>}
                    </h1>
                    <p className="mt-1 text-[var(--pt-muted)]">{[medicine.form && t(medicine.form), medicine.generic_name].filter(Boolean).join(' · ')}</p>
                    <p className="mt-0.5 text-[var(--pt-muted)]">{medicine.company?.name}</p>
                    <div className="mt-3">
                        <MedicineFlagsExplained medicine={medicine} />
                    </div>

                    {prices.length > 0 && (
                        <dl className="tabular mt-6 max-w-sm divide-y divide-[var(--pt-line)] rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)]">
                            {prices.map((p) => (
                                <div key={p.label} className="flex items-baseline justify-between px-4 py-3">
                                    <dt className="text-[var(--pt-muted)]">{p.label}</dt>
                                    <dd className="font-display text-xl font-bold">{money(p.value)}</dd>
                                </div>
                            ))}
                        </dl>
                    )}
                    <p className="mt-2 text-sm text-[var(--pt-muted)]">{t('Printed retail price (MRP).')}</p>
                    {priceWas && (
                        <p className="mt-1 text-sm text-[var(--pt-muted)]">
                            {priceWas.now > priceWas.was ? t('It was {price} before {date}.', { price: money(priceWas.was), date: date(priceWas.at) }) : t('It was {price} before {date}. The price came down.', { price: money(priceWas.was), date: date(priceWas.at) })}
                        </p>
                    )}
                    {steps.length > 0 && (
                        <section aria-labelledby="steps-h" className="mt-5 max-w-sm rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] p-4">
                            <h2 id="steps-h" className="font-display text-lg font-bold">
                                {t('Buy more, pay less')}
                            </h2>
                            <ul className="tabular mt-2 space-y-1 text-sm">
                                {steps.map((s) => (
                                    <li key={`${s.unit}-${s.min_qty}`} className="flex justify-between gap-3">
                                        <span>{t('{n} or more ({unit})', { n: s.min_qty, unit: t(s.unit === 'box' ? 'Box' : s.unit === 'strip' ? 'Strip' : 'Piece') })}</span>
                                        <span className="font-semibold">
                                            {t('{p}% off', { p: s.percent })} · {money(s.price)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                    <AddToCart medicine={medicine} />
                    {preorderDays ? <PreorderBox medicine={medicine} days={preorderDays} /> : null}
                    <MpoOffers offers={mpoOffers} signedIn={authUser !== null} />
                    {flash && (
                        <p role="status" className="mt-2 max-w-sm text-sm text-success">
                            {flash}{' '}
                            <Link href="/cart" className="font-semibold underline underline-offset-4">
                                {t('Your cart')}
                            </Link>
                        </p>
                    )}
                </div>
            </article>

            {together.length > 0 && (
                <section aria-labelledby="tog-title" className="mt-12">
                    <h2 id="tog-title" className="font-display text-2xl font-extrabold">
                        {t('People who bought this also took')}
                    </h2>
                    <ul className="mt-3 border-t border-[var(--pt-line)]">
                        {together.map((m) => (
                            <MedicineRow key={m.id} medicine={m} />
                        ))}
                    </ul>
                </section>
            )}

            <QuestionsBox medicineId={medicine.id} questions={questions} waiting={myWaiting} signedIn={authUser !== null} />

            {alternatives.length > 0 && (
                <section aria-labelledby="alt-title" className="mt-12">
                    <h2 id="alt-title" className="font-display text-2xl font-extrabold">
                        {t('Same medicine, other brands')}
                    </h2>
                    <p className="mt-1 text-sm text-[var(--pt-muted)]">{t('Same generic, strength and form. Cheapest first.')}</p>
                    <ul className="mt-3 border-t border-[var(--pt-line)]">
                        {alternatives.map((m) => (
                            <MedicineRow key={m.id} medicine={m} />
                        ))}
                    </ul>
                </section>
            )}
        </PublicLayout>
    );
}
