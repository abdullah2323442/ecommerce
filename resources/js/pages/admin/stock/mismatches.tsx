import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Report {
    id: number;
    medicine: { id: number; name: string; form: string | null };
    by: string | null;
    found: number;
    system_then: number;
    system_now: number;
    note: string | null;
    at: string;
    clash: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock', href: '/admin/stock' },
    { title: 'Mismatch reports', href: '/admin/stock/mismatches' },
];

export default function Mismatches({ reports }: { reports: Report[] }) {
    const { t, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;

    const act = (id: number, action: 'fix' | 'dismiss') => {
        if (action === 'fix' && !window.confirm(t('Set the stock to the number that was found?'))) return;
        router.post(`/admin/stock/mismatches/${id}`, { action }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Mismatch reports')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Mismatch reports')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Staff counted something different from the system. Fix the number, or dismiss the report.')}</p>
                </div>
                <Flash />
                <InputError message={errors.action} />

                {reports.length === 0 ? (
                    <p className="rounded-md border border-dashed p-10 text-center text-muted-foreground">{t('No open reports.')}</p>
                ) : (
                    <ul className="space-y-3">
                        {reports.map((r) => (
                            <li key={r.id} className="space-y-2 rounded-xl border bg-card p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <Link href={`/admin/stock/medicines/${r.medicine.id}`} className="font-medium hover:underline">
                                            {r.medicine.name}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {r.by} · {dateTime(r.at)}
                                        </p>
                                    </div>
                                    <p className="tabular text-sm">
                                        {t('Found')} <strong>{num(r.found)}</strong> · {t('System says')} <strong>{num(r.system_now)}</strong>
                                        {r.system_now !== r.system_then && <span className="text-muted-foreground"> ({t('then {n}', { n: num(r.system_then) })})</span>}
                                    </p>
                                </div>
                                {r.note && <p className="text-sm">{r.note}</p>}
                                {r.clash && <p className="rounded-md bg-amber-100 px-3 py-2 text-sm text-amber-900">{t('Two reports on this medicine give different numbers. Check before you fix.')}</p>}
                                <div className="flex gap-2">
                                    <Button size="sm" onClick={() => act(r.id, 'fix')}>
                                        {t('Set stock to {n}', { n: num(r.found) })}
                                    </Button>
                                    <Button size="sm" variant="outline" onClick={() => act(r.id, 'dismiss')}>
                                        {t('Dismiss')}
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
