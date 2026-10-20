import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

interface Card {
    id: number;
    kind: 'end_discount' | 'invoice_percent' | 'line_price';
    value: number;
    status: string;
    applied: boolean;
    staff: string | null;
    invoice_id: number;
    who: string | null;
    item: string | null;
    minutes_left: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Approvals', href: '/admin/approvals' }];

export default function Approvals({ pending, recent, minutes }: { pending: Card[]; recent: Card[]; minutes: number }) {
    const { t, num, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;

    // The admin's phone should see a new request without reloading: check again every few seconds while open.
    useEffect(() => {
        const timer = setInterval(() => router.reload({ only: ['pending', 'recent', 'auth'] }), 8000);
        return () => clearInterval(timer);
    }, []);

    const what = (c: Card) =>
        c.kind === 'line_price'
            ? t('Price {amount} for {item}', { amount: money(c.value), item: c.item ?? '' })
            : c.kind === 'end_discount'
              ? t('{p}% discount at the end', { p: num(c.value) })
              : t('{p}% on the whole invoice', { p: num(c.value) });
    const state = (s: string) => t(s === 'approved' ? 'Approved' : s === 'rejected' ? 'Declined' : 'Timed out');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Approvals')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />
                <InputError message={errors.approval} />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Approvals')}</h1>
                    <p className="text-muted-foreground text-sm">
                        {t(
                            'Staff ask when they go past their limit. One tap, yes or no. A request nobody answers cancels itself after {n} minutes.',
                            { n: num(minutes) },
                        )}
                    </p>
                </header>

                {pending.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">{t('Nothing waiting.')}</p>
                ) : (
                    <ul className="space-y-2">
                        {pending.map((c) => (
                            <li
                                key={c.id}
                                className="flex flex-wrap items-center gap-3 rounded-md border border-warning/50 bg-warning/5 px-4 py-3"
                            >
                                <div className="min-w-48 flex-1">
                                    <p className="font-semibold">{what(c)}</p>
                                    <p className="text-muted-foreground text-sm">
                                        {c.staff} ·{' '}
                                        <Link href={`/admin/counter/${c.invoice_id}`} className="underline underline-offset-4">
                                            {c.who ?? t('Draft')}
                                        </Link>{' '}
                                        · {t('{n} min left', { n: num(c.minutes_left) })}
                                    </p>
                                </div>
                                <Button onClick={() => router.post(`/admin/approvals/${c.id}/approve`, {}, { preserveScroll: true })}>
                                    {t('Yes')}
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => router.post(`/admin/approvals/${c.id}/reject`, {}, { preserveScroll: true })}
                                >
                                    {t('No')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                {recent.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Recently answered')}</h2>
                        <ul className="divide-y rounded-md border text-sm">
                            {recent.map((c) => (
                                <li key={c.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
                                    <span className="min-w-48 flex-1">
                                        {what(c)} · {c.staff}
                                    </span>
                                    <span className={c.status === 'approved' ? 'font-semibold text-success' : 'text-muted-foreground'}>
                                        {state(c.status)}
                                        {c.status === 'approved' && !c.applied && ` · ${t('not applied, the invoice had moved on')}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
