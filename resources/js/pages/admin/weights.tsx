import Flash from '@/components/admin/flash';
import Pagination from '@/components/admin/pagination';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    name: string;
    company: string | null;
    packaging: string | null;
    weight_g: number | null;
    fixed_in: number | null;
    fixed_out: number | null;
}

interface Props {
    rows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filters: { q: string; missing: boolean };
    without: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Weights', href: '/admin/weights' }];

function Line({ r }: { r: Row }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ weight_g: r.weight_g === null ? '' : String(r.weight_g), fixed_in: r.fixed_in === null ? '' : String(r.fixed_in), fixed_out: r.fixed_out === null ? '' : String(r.fixed_out) });
    const dirty = f.weight_g !== (r.weight_g === null ? '' : String(r.weight_g)) || f.fixed_in !== (r.fixed_in === null ? '' : String(r.fixed_in)) || f.fixed_out !== (r.fixed_out === null ? '' : String(r.fixed_out));
    const num = (v: string) => (v === '' ? null : v);

    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm">
            <span className="min-w-48 flex-1">
                <span className="font-medium">{r.name}</span> <span className="text-muted-foreground">{r.company}</span>
            </span>
            <Input aria-label={t('Weight in grams')} className="h-8 w-24" type="number" min="1" step="1" placeholder={t('grams')} value={f.weight_g} onChange={(e) => setF({ ...f, weight_g: e.target.value })} />
            <Input aria-label={t('Charge inside')} className="h-8 w-24" type="number" min="0" step="any" placeholder={t('inside')} value={f.fixed_in} onChange={(e) => setF({ ...f, fixed_in: e.target.value })} />
            <Input aria-label={t('Charge outside')} className="h-8 w-24" type="number" min="0" step="any" placeholder={t('outside')} value={f.fixed_out} onChange={(e) => setF({ ...f, fixed_out: e.target.value })} />
            <Button size="sm" variant={dirty ? 'default' : 'ghost'} disabled={!dirty} onClick={() => router.put(`/admin/weights/${r.id}`, { weight_g: num(f.weight_g), fixed_in: num(f.fixed_in), fixed_out: num(f.fixed_out) }, { preserveScroll: true })}>
                {t('Save')}
            </Button>
            <InputError message={errors.fixed_out ?? errors.weight_g} />
        </li>
    );
}

export default function Weights({ rows, filters, without }: Props) {
    const { t, num } = useI18n();
    const [q, setQ] = useState(filters.q);
    const go = (next: { q?: string; missing?: boolean }) => router.get('/admin/weights', { q: next.q ?? q, missing: (next.missing ?? filters.missing) ? 1 : undefined }, { preserveState: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Weights')} />
            <div className="mx-auto w-full max-w-5xl space-y-4 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Weights and delivery charges')}</h1>
                    <p className="text-muted-foreground text-sm">
                        {t('Weight is in grams for one stock unit (a box, or a piece for loose items). A charge here is mandatory for that product and beats every other delivery charge.')}
                    </p>
                </header>

                <form
                    className="flex flex-wrap items-center gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        go({});
                    }}
                >
                    <Input aria-label={t('Search')} className="h-9 w-64" placeholder={t('Medicine or company')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <Button type="submit" variant="outline" className="h-9">
                        {t('Search')}
                    </Button>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={filters.missing} onChange={(e) => go({ missing: e.target.checked })} />
                        {t('Only those with no weight')} ({num(without)})
                    </label>
                </form>

                {rows.data.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center text-sm">{t('Nothing found.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border">
                        {rows.data.map((r) => (
                            <Line key={`${r.id}-${r.weight_g}-${r.fixed_in}-${r.fixed_out}`} r={r} />
                        ))}
                    </ul>
                )}
                <Pagination links={rows.links} />
            </div>
        </AppLayout>
    );
}
