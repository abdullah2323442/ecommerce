import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Zone = 'own' | 'district' | 'outside';
type Rates = Record<Zone, { first_kg: string; extra_kg: string }>;

interface CourierRow {
    id: number;
    name: string;
    provider: string | null;
    cod_percent: number;
    is_active: boolean;
    rates: Record<Zone, { first_kg: number; extra_kg: number } | null>;
}

interface Props {
    providers: { key: string; name: string; connected: boolean }[];
    couriers: CourierRow[];
    checked_on: string | null;
    stale: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Couriers', href: '/admin/couriers' }];
const ZONES: { key: Zone; label: string }[] = [
    { key: 'own', label: 'Own area' },
    { key: 'district', label: 'Inside the district' },
    { key: 'outside', label: 'Outside the district' },
];

const toRates = (r: CourierRow['rates']): Rates =>
    Object.fromEntries(ZONES.map(({ key }) => [key, { first_kg: r[key] ? String(r[key]!.first_kg) : '', extra_kg: r[key] ? String(r[key]!.extra_kg) : '' }])) as Rates;

function Editor({ courier, providers }: { courier?: CourierRow; providers: Props['providers'] }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [name, setName] = useState(courier?.name ?? '');
    const [cod, setCod] = useState(String(courier?.cod_percent ?? 0));
    const [active, setActive] = useState(courier?.is_active ?? true);
    const [provider, setProvider] = useState(courier?.provider ?? '');
    const [rates, setRates] = useState<Rates>(courier ? toRates(courier.rates) : toRates({ own: null, district: null, outside: null }));
    const set = (zone: Zone, k: 'first_kg' | 'extra_kg', v: string) => setRates({ ...rates, [zone]: { ...rates[zone], [k]: v } });
    const body = () => ({
        name,
        provider: provider || null,
        cod_percent: cod === '' ? 0 : cod,
        is_active: active,
        rates: Object.fromEntries(ZONES.map(({ key }) => [key, rates[key].first_kg === '' && rates[key].extra_kg === '' ? null : rates[key]])),
    });

    return (
        <li className="space-y-3 px-4 py-4">
            <div className="flex flex-wrap items-end gap-3">
                <label className="text-sm">
                    {t('Courier name')}
                    <Input className="mt-1 h-9 w-52" value={name} onChange={(e) => setName(e.target.value)} maxLength={80} />
                </label>
                <label className="text-sm">
                    {t('Cash on delivery share (%)')}
                    <Input className="mt-1 h-9 w-32" type="number" min="0" max="20" step="any" value={cod} onChange={(e) => setCod(e.target.value)} />
                </label>
                <label className="text-sm">
                    {t('Book parcels through')}
                    <select value={provider} onChange={(e) => setProvider(e.target.value)} className="mt-1 block h-9 rounded-md border bg-background px-2 text-sm">
                        <option value="">{t('Nobody: I send it myself')}</option>
                        {providers.map((p) => (
                            <option key={p.key} value={p.key}>
                                {p.name} {p.connected ? '' : `(${t('not switched on')})`}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex items-center gap-2 pb-2 text-sm">
                    <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />
                    {t('On')}
                </label>
            </div>
            <div className="grid gap-2 sm:grid-cols-3">
                {ZONES.map(({ key, label }) => (
                    <fieldset key={key} className="rounded-md border p-2">
                        <legend className="px-1 text-xs font-medium">{t(label)}</legend>
                        <div className="flex gap-2">
                            <label className="text-xs">
                                {t('First kilo')}
                                <Input className="mt-1 h-8" type="number" min="0" step="any" value={rates[key].first_kg} onChange={(e) => set(key, 'first_kg', e.target.value)} />
                            </label>
                            <label className="text-xs">
                                {t('Each extra kilo')}
                                <Input className="mt-1 h-8" type="number" min="0" step="any" value={rates[key].extra_kg} onChange={(e) => set(key, 'extra_kg', e.target.value)} />
                            </label>
                        </div>
                    </fieldset>
                ))}
            </div>
            <p className="text-muted-foreground text-xs">{t('Leave a zone empty if this courier does not go there.')}</p>
            <InputError message={errors.name ?? errors.cod_percent} />
            <div className="flex gap-2">
                <Button size="sm" disabled={!name} onClick={() => (courier ? router.put(`/admin/couriers/${courier.id}`, body(), { preserveScroll: true }) : router.post('/admin/couriers', body(), { preserveScroll: true }))}>
                    {courier ? t('Save') : t('Add the courier')}
                </Button>
                {courier && (
                    <Button size="sm" variant="ghost" onClick={() => confirm(t('Remove this courier?')) && router.delete(`/admin/couriers/${courier.id}`, { preserveScroll: true })}>
                        {t('Remove')}
                    </Button>
                )}
            </div>
        </li>
    );
}

export default function Couriers({ couriers, checked_on, stale, providers }: Props) {
    const { t, date } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Couriers')} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Couriers')}</h1>
                    <p className="text-muted-foreground text-sm">
                        {t('Add as many couriers as you use and type their charges. The buyer sees them side by side when every product in the order has a weight. Otherwise the flat area charge is used.')}
                    </p>
                </header>

                {couriers.length > 0 && (
                    <div className={`flex flex-wrap items-center gap-3 rounded-md border px-3 py-2 text-sm ${stale ? 'border-danger/50 bg-danger/10' : ''}`}>
                        <span className="flex-1">
                            {stale ? t('Are the courier rates still correct? Nobody has checked for three months.') : t('Rates last checked')} {checked_on && !stale && date(checked_on)}
                        </span>
                        <Button size="sm" variant="outline" onClick={() => router.post('/admin/couriers/confirm', {}, { preserveScroll: true })}>
                            {t('Yes, they are still correct')}
                        </Button>
                    </div>
                )}

                <ul className="divide-y rounded-md border">
                    {couriers.map((c) => (
                        <Editor key={`${c.id}-${JSON.stringify(c.rates)}-${c.name}-${c.cod_percent}-${c.is_active}`} courier={c} providers={providers} />
                    ))}
                </ul>

                <section className="rounded-md border">
                    <h2 className="border-b px-4 py-2 font-medium">{t('Add a courier')}</h2>
                    <ul>
                        <Editor providers={providers} />
                    </ul>
                </section>
            </div>
        </AppLayout>
    );
}
