import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, Link, useForm } from '@inertiajs/react';

interface Props {
    business: string;
    logo: string | null;
    grade: string | null;
    tierTask: { tier: string; target: number; gap: number } | null;
    trend: { month: string; total: number; orders: number }[];
    history: { id: number; number: string | null; total: number; when: string | null; terms: string | null }[];
    terms: { moq: number; payments: string[]; categories: string[] };
}

/** The bulk buyer's own page. Only finished months are counted, so nothing here moves while the month is running. */
export default function Account({ business, logo, grade, tierTask, trend, history, terms }: Props) {
    const { t, money, num, dateTime, date } = useI18n();
    const top = Math.max(1, ...trend.map((m) => m.total));
    const monthName = (ym: string) => date(`${ym}-15T12:00:00`, { month: 'short', year: '2-digit' });

    return (
        <PublicLayout>
            <Head title={t('Your account')} />
            <div className="mx-auto max-w-3xl space-y-8">
                <header className="flex flex-wrap items-baseline justify-between gap-3">
                    <div className="flex items-center gap-3">
                        {logo && <img src={logo} alt="" className="size-14 rounded-md border object-contain" />}
                        <div>
                            <h1 className="font-display text-3xl font-extrabold">{business}</h1>
                            <p className="text-[var(--pt-muted)]">{t('Your account')}</p>
                        </div>
                    </div>
                    <div className="flex gap-4">
                        <Link href="/wholesale/sell" className="text-sm font-semibold underline underline-offset-4">
                            {t('Sell')}
                        </Link>
                        <Link href="/wholesale" className="text-sm font-semibold underline underline-offset-4">
                            {t('Order')}
                        </Link>
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <p className="text-sm text-[var(--pt-muted)]">{t('Your grade')}</p>
                        <p className="font-display text-2xl font-extrabold">{grade ?? t('Not set yet')}</p>
                    </div>
                    <div className="rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <p className="text-sm text-[var(--pt-muted)]">{t('Minimum per item')}</p>
                        <p className="font-display text-2xl font-extrabold">{terms.moq > 0 ? num(terms.moq) : t('None')}</p>
                    </div>
                    <div className="rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <p className="text-sm text-[var(--pt-muted)]">{t('You can pay')}</p>
                        <p className="font-semibold">{terms.payments.map((p) => t(p === 'cod' ? 'Pay when it arrives' : 'Pay in advance')).join(' · ') || '—'}</p>
                    </div>
                </section>

                {tierTask && (
                    <p className="rounded-2xl border bg-[var(--pt-surface)] p-4 font-semibold">
                        {tierTask.gap > 0
                            ? t('Buy {amount} more this month and you move up to tier {tier}.', { amount: money(tierTask.gap), tier: tierTask.tier })
                            : t('You have reached the target for tier {tier}. We will check and upgrade you.', { tier: tierTask.tier })}
                    </p>
                )}

                <section aria-labelledby="trend-h" className="space-y-2">
                    <h2 id="trend-h" className="font-display text-xl font-bold">
                        {t('Your buying, month by month')}
                    </h2>
                    <ul className="grid grid-cols-6 items-end gap-2 rounded-2xl border bg-[var(--pt-surface)] p-4" style={{ minHeight: 160 }}>
                        {trend.map((m) => (
                            <li key={m.month} className="flex h-full flex-col items-center justify-end gap-1">
                                <span className="tabular text-xs">{m.total > 0 ? money(m.total) : ''}</span>
                                <span className="w-full rounded-t bg-[var(--pt-amber)]" style={{ height: `${Math.max(2, (m.total / top) * 100)}px` }} aria-hidden />
                                <span className="text-xs text-[var(--pt-muted)]">{monthName(m.month)}</span>
                                <span className="sr-only">{`${money(m.total)}, ${num(m.orders)} ${t('orders')}`}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-xs text-[var(--pt-muted)]">{t('Only finished months are shown.')}</p>
                </section>

                {terms.categories.length > 0 && (
                    <p className="text-sm text-[var(--pt-muted)]">
                        {t('You buy from')}: {terms.categories.map((c) => t(c)).join(', ')}
                    </p>
                )}

                <section aria-labelledby="hist-h" className="space-y-2">
                    <h2 id="hist-h" className="font-display text-xl font-bold">
                        {t('Everything you bought')}
                    </h2>
                    {history.length === 0 ? (
                        <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-[var(--pt-muted)]">{t('No orders yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                            {history.map((h) => (
                                <li key={h.id}>
                                    <Link href={`/memo/${h.id}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                        <span className="min-w-32 flex-1 font-medium">{h.number ?? `#${h.id}`}</span>
                                        <span className="text-sm text-[var(--pt-muted)]">{h.when && dateTime(h.when)}</span>
                                        <span className="tabular w-28 text-right">{money(h.total)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <Branding business={business} hasLogo={logo !== null} />
            </div>
        </PublicLayout>
    );
}

/** The shop's own name and logo: they head every page of this section, so it reads as the shop's own dashboard. */
function Branding({ business, hasLogo }: { business: string; hasLogo: boolean }) {
    const { t } = useI18n();
    const form = useForm<{ business_name: string; logo: File | null; remove_logo: boolean }>({ business_name: business, logo: null, remove_logo: false });

    return (
        <section aria-labelledby="branding-h" className="space-y-3">
            <h2 id="branding-h" className="font-display text-xl font-extrabold">
                {t('Your name and logo')}
            </h2>
            <p className="text-sm text-[var(--pt-muted)]">{t('They show at the top of your pages here, so this feels like your own dashboard.')}</p>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/wholesale/account/branding', { forceFormData: true, preserveScroll: true, onSuccess: () => form.setData('logo', null) });
                }}
                className="grid gap-3 sm:max-w-md"
            >
                <div className="grid gap-1">
                    <label htmlFor="brand-name" className="text-sm font-medium">
                        {t('Business name')}
                    </label>
                    <Input id="brand-name" value={form.data.business_name} onChange={(e) => form.setData('business_name', e.target.value)} maxLength={150} required />
                    <InputError message={form.errors.business_name} />
                </div>
                <div className="grid gap-1">
                    <label htmlFor="brand-logo" className="text-sm font-medium">
                        {t('Logo')}
                    </label>
                    <Input id="brand-logo" type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)} />
                    <InputError message={form.errors.logo} />
                </div>
                {hasLogo && (
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.remove_logo} onChange={(e) => form.setData('remove_logo', e.target.checked)} />
                        {t('Remove my logo')}
                    </label>
                )}
                <div>
                    <Button type="submit" disabled={form.processing}>
                        {t('Save')}
                    </Button>
                </div>
            </form>
        </section>
    );
}
