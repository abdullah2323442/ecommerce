import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Button } from '@/components/ui/button';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Pack {
    n: number;
    token: string;
    number: string;
    title: string;
    variant: string | null;
    status: string;
    seller: string;
    tracking: string | null;
    preorder: boolean;
    delivery_charge: number;
    total: number;
    pay_by: string;
    paid: boolean;
}

/** The orders of one checkout, as packages (spec ch 13): each has its own seller, its own tracking and its own page. */
export default function Packages({ packages, total }: { packages: Pack[]; total: number }) {
    const { t, money, num } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const state = (s: string) => t(s === 'placed' ? 'Sent' : s === 'accepted' ? 'Accepted' : s === 'shipped' ? 'On the way' : s === 'delivered' ? 'Done' : s === 'not_received' ? 'Not received' : 'Cancelled');

    return (
        <PublicLayout>
            <Head title={t('Your packages')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <h1 className="font-display text-3xl font-extrabold">{t('Your packages')}</h1>
                {flash && (
                    <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
                        {flash}
                    </p>
                )}
                <p className="text-sm text-[var(--pt-muted)]">{t('Each package comes from its own seller and is tracked on its own. Keep this page link to come back to all of them.')}</p>
                <ul className="space-y-3">
                    {packages.map((p) => (
                        <li key={p.token} className="rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                            <Link href={`/market/orders/${p.token}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 hover:underline">
                                <span className="min-w-40 flex-1">
                                    <span className="block text-xs text-[var(--pt-muted)]">
                                        {t('Package {n}', { n: num(p.n) })} · {p.seller}
                                    </span>
                                    <span className="font-medium">
                                        {p.title}
                                        {p.variant && ` · ${p.variant}`}
                                    </span>
                                </span>
                                <span className="text-sm font-semibold">{p.preorder ? t('Pre-order') : state(p.status)}</span>
                                <span className="tabular w-28 text-right">{money(p.total)}</span>
                            </Link>
                            {p.pay_by === 'online' && !p.paid && p.status === 'placed' && (
                                <div className="mt-2 flex flex-wrap items-center gap-3">
                                    <span className="text-sm text-[var(--pt-muted)]">{t('Waiting for your payment. The seller sees the order after you pay.')}</span>
                                    <Button size="sm" onClick={() => router.post(`/market/orders/${p.token}/pay`, {})}>
                                        {t('Pay {amount} online', { amount: money(p.total) })}
                                    </Button>
                                </div>
                            )}
                            {p.tracking && (
                                <p className="mt-1 text-sm">
                                    {t('Tracking')}: {p.tracking}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
                <p className="flex justify-between text-lg font-bold">
                    <span>{t('Total')}</span>
                    <span className="tabular">{money(total)}</span>
                </p>
            </div>
        </PublicLayout>
    );
}
