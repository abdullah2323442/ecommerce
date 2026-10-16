import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

export interface Step {
    unit: string;
    min_qty: string | number;
    kind: 'percent' | 'taka';
    value: string | number;
}

/** The "buy more, pay less" steps of one medicine (spec ch 4). No rows means no discount. */
export default function QuantityStepsEditor({ medicineId, units, steps }: { medicineId: number; units: string[]; steps: Step[] }) {
    const { t } = useI18n();
    const [rows, setRows] = useState<Step[]>(steps.map((s) => ({ ...s, min_qty: String(s.min_qty), value: String(Number(s.value)) })));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const unitName = (u: string) => t(u === 'box' ? 'Box' : u === 'strip' ? 'Strip' : 'Piece');
    const patch = (i: number, change: Partial<Step>) => setRows((all) => all.map((r, n) => (n === i ? { ...r, ...change } : r)));

    const save = () => {
        setBusy(true);
        router.put(`/admin/stock/medicines/${medicineId}/steps`, { steps: rows as unknown as Record<string, string>[] }, {
            preserveScroll: true,
            onError: setErrors,
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <section aria-labelledby="steps-h" className="space-y-3 rounded-xl border bg-card p-4">
            <div>
                <h2 id="steps-h" className="font-display text-xl font-bold">
                    {t('Buy more, pay less')}
                </h2>
                <p className="text-sm text-muted-foreground">{t('The same price for everyone on the public side, but taking more earns a bigger discount. Leave it empty for no discount.')}</p>
            </div>

            {rows.map((r, i) => (
                <div key={i} className="space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <select aria-label={t('Unit')} value={r.unit} onChange={(e) => patch(i, { unit: e.target.value })} className="h-10 rounded-md border bg-background px-2 text-sm">
                            {units.map((u) => (
                                <option key={u} value={u}>
                                    {unitName(u)}
                                </option>
                            ))}
                        </select>
                        <Input aria-label={t('From how many')} className="w-24" type="number" min="2" step="1" value={r.min_qty} onChange={(e) => patch(i, { min_qty: e.target.value })} />
                        <span className="text-sm text-muted-foreground">{t('or more')}</span>
                        <Input aria-label={t('Discount')} className="w-24" type="number" min="0" step="0.01" value={r.value} onChange={(e) => patch(i, { value: e.target.value })} />
                        <select aria-label={t('Kind of discount')} value={r.kind} onChange={(e) => patch(i, { kind: e.target.value as Step['kind'] })} className="h-10 rounded-md border bg-background px-2 text-sm">
                            <option value="percent">{t('percent off')}</option>
                            <option value="taka">{t('taka off each')}</option>
                        </select>
                        <Button type="button" variant="ghost" size="icon" aria-label={t('Remove')} onClick={() => setRows((all) => all.filter((_, n) => n !== i))}>
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                    <InputError message={errors[`steps.${i}.min_qty`] ?? errors[`steps.${i}.value`] ?? errors[`steps.${i}.unit`]} />
                </div>
            ))}

            <div className="flex gap-2">
                <Button type="button" variant="outline" size="sm" onClick={() => setRows((all) => [...all, { unit: units[0] ?? 'box', min_qty: '', kind: 'percent', value: '' }])}>
                    <Plus className="size-4" />
                    {t('Add a step')}
                </Button>
                <Button type="button" size="sm" disabled={busy} onClick={save}>
                    {t('Save')}
                </Button>
            </div>
        </section>
    );
}
