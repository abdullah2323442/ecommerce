import Flash from '@/components/admin/flash';
import Pagination from '@/components/admin/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Customer {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    account_type: string;
    tier: string | null;
    suspended: boolean;
}

interface Props {
    customers: { data: Customer[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filters: { q: string; type: string | null };
    types: string[];
    upgrades: { id: number; name: string; phone: string | null; tier: string | null; next_tier_id: number; next_tier: string; total: number; target: number }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Customers', href: '/admin/customers' }];

export default function CustomersIndex({ customers, filters, types, upgrades }: Props) {
    const { t, num, money } = useI18n();
    const [q, setQ] = useState(filters.q ?? '');
    const [type, setType] = useState(filters.type ?? '');

    const typeLabel: Record<string, string> = { public: t('Public'), paikari: t('Paikari'), wholesaler: t('Wholesale') };

    const apply = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/admin/customers', { q: q || undefined, type: type || undefined }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Customers')} />

            <div className="mx-auto w-full max-w-5xl space-y-5 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Customers')}</h1>
                    <p className="text-sm text-muted-foreground">{customers.total === 1 ? t('1 customer') : t('{n} customers', { n: num(customers.total) })}</p>
                    <Link href="/admin/customers/import" className="text-sm font-semibold underline underline-offset-4">
                        {t('Bring in shops from a file')}
                    </Link>
                </div>

                <Flash />
            {upgrades.length > 0 && (
                <section aria-labelledby="up-h" className="mb-4 space-y-2 rounded-md border border-warning/50 bg-warning/5 p-4">
                    <h2 id="up-h" className="font-medium">
                        {t('Ready for a higher tier')}
                    </h2>
                    <ul className="divide-y">
                        {upgrades.map((u) => (
                            <li key={u.id} className="flex flex-wrap items-center gap-3 py-2">
                                <div className="min-w-48 flex-1">
                                    <Link href={`/admin/customers/${u.id}`} className="font-medium underline-offset-4 hover:underline">
                                        {u.name}
                                    </Link>
                                    <p className="text-sm text-muted-foreground">{t('{total} this month, target {target}', { total: money(u.total), target: money(u.target) })}</p>
                                </div>
                                <Button size="sm" onClick={() => router.patch(`/admin/customers/${u.id}/tier`, { tier_id: u.next_tier_id }, { preserveScroll: true })}>
                                    {t('Upgrade to {tier}', { tier: u.next_tier })}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

                <form onSubmit={apply} role="search" className="flex flex-col gap-2 sm:flex-row">
                    <label htmlFor="q" className="sr-only">
                        {t('Search customers')}
                    </label>
                    <Input id="q" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Name, phone or email')} className="sm:max-w-xs" />
                    <label htmlFor="type" className="sr-only">
                        {t('Account type')}
                    </label>
                    <select
                        id="type"
                        value={type}
                        onChange={(e) => setType(e.target.value)}
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <option value="">{t('All types')}</option>
                        {types.map((ty) => (
                            <option key={ty} value={ty}>
                                {typeLabel[ty] ?? ty}
                            </option>
                        ))}
                    </select>
                    <Button type="submit">{t('Search')}</Button>
                </form>

                {customers.data.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-muted-foreground">{t('No customers match this search.')}</p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b bg-muted/50 text-muted-foreground">
                                <tr>
                                    {['Name', 'Phone', 'Type', 'Tier', 'Status'].map((h) => (
                                        <th key={h} scope="col" className="px-4 py-2.5 font-medium">
                                            {t(h)}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {customers.data.map((c) => (
                                    <tr key={c.id} className="hover:bg-muted/40">
                                        <td className="px-4 py-3">
                                            <Link href={`/admin/customers/${c.id}`} className="font-medium underline-offset-4 hover:underline focus-visible:underline">
                                                {c.name}
                                            </Link>
                                            {c.email && <div className="text-xs text-muted-foreground">{c.email}</div>}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">{c.phone ?? '—'}</td>
                                        <td className="px-4 py-3">{typeLabel[c.account_type] ?? c.account_type}</td>
                                        <td className="px-4 py-3">{c.tier ?? '—'}</td>
                                        <td className="px-4 py-3">{c.suspended ? <Badge variant="destructive">{t('Suspended')}</Badge> : <Badge variant="secondary">{t('Active')}</Badge>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination links={customers.links} />
            </div>
        </AppLayout>
    );
}
