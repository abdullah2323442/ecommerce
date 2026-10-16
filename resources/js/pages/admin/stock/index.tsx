import Flash from '@/components/admin/flash';
import Pagination from '@/components/admin/pagination';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { PackagePlus } from 'lucide-react';

interface Row {
    id: number;
    brand_name: string;
    strength: string | null;
    form: string | null;
    packaging_type: 'box' | 'pcs';
    company: string | null;
    units: string;
    value: string;
    min_cost: string;
    max_cost: string;
    lots: number;
    small_old: number;
    fragmented: boolean;
}

interface Props {
    rows: { data: Row[]; total: number; links: { url: string | null; label: string; active: boolean }[] };
    query: string;
    stockValue: number;
    masterSync: boolean;
    openMismatches: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Stock', href: '/admin/stock' }];

export default function StockIndex({ rows, query, stockValue, masterSync, openMismatches }: Props) {
    const { t, num, money } = useI18n();
    const search = useForm({ q: query });
    const sync = useForm({ on: !masterSync, confirm: false });

    const toggleSync = () => {
        if (!masterSync && !window.confirm(t('Make sure all goods are entered first. Then Master Stock Sync will take paikari and retail sales out of stock too. Turn it on?'))) return;
        router.post('/admin/stock/master-sync', { on: !masterSync, confirm: true }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Stock')} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Stock')}</h1>
                        <p className="text-sm text-muted-foreground">{t("Siam's pharmacy. Purchase prices are shown here only and never on any public page.")}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button asChild variant="outline">
                            <Link href="/admin/stock/mismatches">
                                {t('Mismatch reports')}
                                {openMismatches > 0 && <span className="tabular ml-1 rounded-full bg-amber-500 px-1.5 text-xs text-white">{num(openMismatches)}</span>}
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/admin/stock/reports">{t('Reports')}</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/admin/stock/counts">{t('Stock count')}</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/admin/stock/expiry">{t('Expiry')}</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/admin/stock/sources">{t('Sources and rates')}</Link>
                        </Button>
                        <Button asChild>
                            <Link href="/admin/stock/receive">
                                <PackagePlus aria-hidden />
                                {t('Enter purchase')}
                            </Link>
                        </Button>
                    </div>
                </div>

                <Flash />
                <InputError message={(sync.errors as Record<string, string>).on} />

                <dl className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-xl border bg-card p-4">
                        <dt className="text-sm text-muted-foreground">{t('Stock value at purchase cost')}</dt>
                        <dd className="tabular mt-1 font-display text-3xl font-extrabold">{money(stockValue)}</dd>
                    </div>
                    <div className="rounded-xl border bg-card p-4">
                        <dt className="text-sm text-muted-foreground">{t('Master Stock Sync')}</dt>
                        <dd className="mt-1 flex flex-wrap items-center justify-between gap-3">
                            <span className="font-display text-xl font-bold">{masterSync ? t('On') : t('Off')}</span>
                            <Button variant={masterSync ? 'outline' : 'default'} size="sm" onClick={toggleSync}>
                                {masterSync ? t('Turn off') : t('Turn on')}
                            </Button>
                        </dd>
                        <p className="mt-2 text-sm text-muted-foreground">{masterSync ? t('Every sale now takes stock out.') : t('Only offer orders take stock out for now.')}</p>
                    </div>
                </dl>

                <form
                    role="search"
                    onSubmit={(e) => {
                        e.preventDefault();
                        search.get('/admin/stock', { preserveState: true });
                    }}
                    className="flex gap-2"
                >
                    <label htmlFor="stock-q" className="sr-only">
                        {t('Medicine name')}
                    </label>
                    <Input id="stock-q" value={search.data.q} onChange={(e) => search.setData('q', e.target.value)} placeholder={t('Brand name')} className="max-w-sm" />
                    <Button type="submit" variant="secondary">
                        {t('Search')}
                    </Button>
                </form>

                {rows.total === 0 ? (
                    <p className="rounded-md border border-dashed p-10 text-center text-muted-foreground">{query ? t('No stocked medicine matches that name.') : t('No stock yet. Enter the first purchase.')}</p>
                ) : (
                    <div className="overflow-x-auto rounded-md border bg-card">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">{t('Medicine')}</th>
                                    <th className="px-4 py-2.5 text-right font-medium">{t('On hand')}</th>
                                    <th className="px-4 py-2.5 text-right font-medium">{t('Cost per unit')}</th>
                                    <th className="px-4 py-2.5 text-right font-medium">{t('Value')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.data.map((r) => (
                                    <tr key={r.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-2.5">
                                            <Link href={`/admin/stock/medicines/${r.id}`} className="font-medium hover:underline">
                                                {r.brand_name} <strong>{r.strength}</strong>
                                            </Link>
                                            <p className="text-xs text-muted-foreground">{[r.company, r.form && t(r.form)].filter(Boolean).join(' · ')}</p>
                                            {r.fragmented && (
                                                <p className="text-xs text-amber-700">{r.small_old > 0 ? t('{n} lots, {s} small and old', { n: num(r.lots), s: num(r.small_old) }) : t('{n} lots', { n: num(r.lots) })}</p>
                                            )}
                                        </td>
                                        <td className="tabular px-4 py-2.5 text-right">
                                            {num(Number(r.units))} <span className="text-xs text-muted-foreground">{r.packaging_type === 'box' ? t('boxes') : t('pieces')}</span>
                                        </td>
                                        <td className="tabular px-4 py-2.5 text-right">{Number(r.min_cost) === Number(r.max_cost) ? money(r.min_cost) : `${money(r.min_cost)} – ${money(r.max_cost)}`}</td>
                                        <td className="tabular px-4 py-2.5 text-right font-medium">{money(r.value)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination links={rows.links} />
            </div>
        </AppLayout>
    );
}
