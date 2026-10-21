import MedicinePicker, { type PickedMedicine } from '@/components/admin/medicine-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export interface SellingRatesProps {
    companies: { id: number; name: string }[];
    companyRates: { id: number; name: string; percent: string }[];
    medicineRates: { id: number; brand_name: string; strength: string | null; percent: string | null; fixed_price: string | null }[];
}

/** Customer discounts set for a whole company or for one medicine. They sit between a customer's own rate and the tier. */
export default function SellingRates({ companies, companyRates, medicineRates }: SellingRatesProps) {
    const { t } = useI18n();

    return (
        <section aria-labelledby="selling-h" className="space-y-4">
            <div>
                <h2 id="selling-h" className="font-display text-xl font-extrabold">
                    {t('Rates by company and by medicine')}
                </h2>
                <p className="text-sm text-muted-foreground">{t('A rate for one medicine beats the company rate. Both beat the tier. A shop with its own rate keeps it.')}</p>
            </div>
            <CompanyRate companies={companies} rates={companyRates} />
            <MedicineRate rates={medicineRates} />
        </section>
    );
}

function CompanyRate({ companies, rates }: { companies: SellingRatesProps['companies']; rates: SellingRatesProps['companyRates'] }) {
    const { t, num } = useI18n();
    const [q, setQ] = useState('');
    const [picked, setPicked] = useState<{ id: number; name: string } | null>(null);
    const form = useForm<{ percent: string; force_loss: boolean }>({ percent: '', force_loss: false });
    const matches = useMemo(() => (q.trim().length < 2 || picked ? [] : companies.filter((c) => c.name.toLowerCase().includes(q.trim().toLowerCase())).slice(0, 8)), [q, picked, companies]);
    const errors = form.errors as Record<string, string>;

    const save = (force = false) => {
        if (!picked) return;
        form.transform((d) => ({ ...d, force_loss: force }));
        form.put(`/admin/selling-rates/company/${picked.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setPicked(null);
                setQ('');
            },
        });
    };

    return (
        <div className="space-y-3 rounded-md border bg-card p-4">
            <h3 className="font-medium">{t('A company')}</h3>
            {rates.length > 0 && (
                <ul className="divide-y rounded-md border text-sm">
                    {rates.map((r) => (
                        <li key={r.id} className="flex items-center gap-3 px-3 py-2">
                            <span className="flex-1 font-medium">{r.name}</span>
                            <span className="tabular-nums">{t('{n}% off', { n: num(Number(r.percent)) })}</span>
                            <Button variant="ghost" size="sm" onClick={() => router.put(`/admin/selling-rates/company/${r.id}`, { percent: '' }, { preserveScroll: true })}>
                                {t('Remove')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap items-start gap-2">
                <div className="relative">
                    <label htmlFor="sr-company" className="sr-only">
                        {t('Company name')}
                    </label>
                    <Input
                        id="sr-company"
                        autoComplete="off"
                        placeholder={t('Search a company')}
                        value={picked ? picked.name : q}
                        onChange={(e) => {
                            setPicked(null);
                            setQ(e.target.value);
                        }}
                        className="w-64"
                    />
                    {matches.length > 0 && (
                        <ul className="absolute z-10 mt-1 w-64 divide-y rounded-md border bg-popover text-sm shadow">
                            {matches.map((c) => (
                                <li key={c.id}>
                                    <button type="button" className="w-full px-3 py-2 text-left hover:bg-muted" onClick={() => setPicked(c)}>
                                        {c.name}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div>
                    <label htmlFor="sr-company-pct" className="sr-only">
                        {t('Percent off')}
                    </label>
                    <Input id="sr-company-pct" type="number" step="0.01" min="0" max="100" placeholder={t('Percent off')} value={form.data.percent} onChange={(e) => form.setData('percent', e.target.value)} className="w-32" />
                </div>
                <Button type="button" disabled={!picked || form.data.percent === '' || form.processing} onClick={() => save(false)}>
                    {t('Save company rate')}
                </Button>
            </div>
            <InputError message={errors.percent} />
            {errors.loss && (
                <div role="alert" className="space-y-2 text-sm">
                    <p className="text-destructive">{errors.loss}</p>
                    <Button type="button" variant="destructive" size="sm" onClick={() => save(true)}>
                        {t('Sell at a loss anyway')}
                    </Button>
                </div>
            )}
        </div>
    );
}

function MedicineRate({ rates }: { rates: SellingRatesProps['medicineRates'] }) {
    const { t, num, money } = useI18n();
    const [medicine, setMedicine] = useState<PickedMedicine | null>(null);
    const [mode, setMode] = useState<'percent' | 'price'>('percent');
    const form = useForm<{ percent: string; fixed_price: string; force_loss: boolean }>({ percent: '', fixed_price: '', force_loss: false });
    const errors = form.errors as Record<string, string>;

    const save = (force = false) => {
        if (!medicine) return;
        form.transform((d) => ({ percent: mode === 'percent' ? d.percent : '', fixed_price: mode === 'price' ? d.fixed_price : '', force_loss: force }));
        form.put(`/admin/selling-rates/medicine/${medicine.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setMedicine(null);
            },
        });
    };

    return (
        <div className="space-y-3 rounded-md border bg-card p-4">
            <h3 className="font-medium">{t('One medicine')}</h3>
            {rates.length > 0 && (
                <ul className="divide-y rounded-md border text-sm">
                    {rates.map((r) => (
                        <li key={r.id} className="flex items-center gap-3 px-3 py-2">
                            <span className="flex-1 font-medium">
                                {r.brand_name} {r.strength}
                            </span>
                            <span className="tabular-nums">{r.percent !== null ? t('{n}% off', { n: num(Number(r.percent)) }) : t('{price} a piece', { price: money(Number(r.fixed_price ?? 0)) })}</span>
                            <Button variant="ghost" size="sm" onClick={() => router.put(`/admin/selling-rates/medicine/${r.id}`, { percent: '', fixed_price: '' }, { preserveScroll: true })}>
                                {t('Remove')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                <MedicinePicker picked={medicine} onPick={setMedicine} />
                <div className="flex rounded-md border p-0.5" role="group" aria-label={t('Rate type')}>
                    {(['percent', 'price'] as const).map((m) => (
                        <button key={m} type="button" aria-pressed={mode === m} onClick={() => setMode(m)} className={`rounded px-3 text-sm ${mode === m ? 'bg-foreground text-background' : 'text-muted-foreground'}`}>
                            {m === 'percent' ? t('Percent off') : t('Taka price per piece')}
                        </button>
                    ))}
                </div>
                <div>
                    <label htmlFor="sr-med-value" className="sr-only">
                        {mode === 'percent' ? t('Percent off') : t('Price in taka for one piece')}
                    </label>
                    <Input
                        id="sr-med-value"
                        type="number"
                        step="0.01"
                        min="0"
                        max={mode === 'percent' ? 100 : undefined}
                        placeholder={mode === 'percent' ? '25' : '90.00'}
                        value={mode === 'percent' ? form.data.percent : form.data.fixed_price}
                        onChange={(e) => form.setData(mode === 'percent' ? 'percent' : 'fixed_price', e.target.value)}
                        className="w-32"
                    />
                </div>
            </div>
            <Button type="button" disabled={!medicine || (mode === 'percent' ? form.data.percent === '' : form.data.fixed_price === '') || form.processing} onClick={() => save(false)}>
                {t('Save medicine rate')}
            </Button>
            <InputError message={errors.percent || errors.fixed_price} />
            {errors.loss && (
                <div role="alert" className="space-y-2 text-sm">
                    <p className="text-destructive">{errors.loss}</p>
                    <Button type="button" variant="destructive" size="sm" onClick={() => save(true)}>
                        {t('Sell at a loss anyway')}
                    </Button>
                </div>
            )}
        </div>
    );
}
