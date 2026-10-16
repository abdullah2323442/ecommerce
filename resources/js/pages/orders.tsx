import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/react';

interface Props {
    orders: { token: string; number: string | null; status: string; total: number; placed_at: string | null }[];
    buyAgain: boolean;
}

export default function Orders({ orders, buyAgain }: Props) {
    const { t, money, dateTime } = useI18n();
    const state = (s: string) => t(s === 'placed' ? 'Being checked' : s === 'verified' ? 'Ready' : s === 'final' ? 'Done' : 'Cancelled');

    return (
        <PublicLayout>
            <Head title={t('Your orders')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('Your orders')}</h1>
                    <Link href="/my-orders/returns" className="text-sm font-semibold underline underline-offset-4">
                        {t('Returns')}
                    </Link>
                </div>
                {orders.length === 0 ? (
                    <div className="rounded-2xl border border-dashed p-10 text-center">
                        <p className="font-semibold">{t('No orders yet.')}</p>
                        <Link href="/medicines" className="mt-3 inline-block text-sm font-semibold underline underline-offset-4">
                            {t('Search medicines')}
                        </Link>
                    </div>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {orders.map((o) => (
                            <li key={o.token}>
                                <Link href={`/orders/${o.token}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                    <span className="min-w-32 flex-1 font-medium">{o.number ?? t('Your order')}</span>
                                    <span className="text-sm text-[var(--pt-muted)]">{o.placed_at && dateTime(o.placed_at)}</span>
                                    <span className="text-sm font-semibold">{state(o.status)}</span>
                                    <span className="tabular w-24 text-right">{money(o.total)}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <section aria-labelledby="again-h" className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
                    <div>
                        <h2 id="again-h" className="font-semibold">
                            {t('Buy again reminder')}
                        </h2>
                        <p className="text-[var(--pt-muted)]">{buyAgain ? t('If you buy the same medicine about every month, we remind you when it is time.') : t('Reminders are off.')}</p>
                    </div>
                    <Button size="sm" variant="outline" onClick={() => router.put('/my-orders/buy-again', { on: !buyAgain }, { preserveScroll: true })}>
                        {buyAgain ? t('Turn off') : t('Turn on')}
                    </Button>
                </section>
            </div>
        </PublicLayout>
    );
}
