import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface Preview {
    unit_cost: number;
    offers_at_loss: number;
    rates_at_loss: number;
    sales_at_loss: number;
    loss: boolean;
}

/** Correct the purchase percent of one lot. Before saving, says what would be sold at a loss, and wants a yes (spec 9). */
export default function LotCost({ lotId, percent }: { lotId: number; percent: number }) {
    const { t, money, num } = useI18n();
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState(String(percent));
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | undefined>();

    if (!open) {
        return (
            <Button size="sm" variant="ghost" onClick={() => setOpen(true)}>
                {t('Correct price')}
            </Button>
        );
    }

    const check = async () => {
        const res = await fetch(`/admin/stock/lots/${lotId}/cost?percent=${encodeURIComponent(value)}`, { headers: { Accept: 'application/json' } });
        setPreview(res.ok ? ((await res.json()) as Preview) : null);
        setError(res.ok ? undefined : t('Enter a percent between 0 and 99.9.'));
    };

    const save = () =>
        router.put(`/admin/stock/lots/${lotId}/cost`, { percent: value, confirm_loss: preview?.loss ?? false }, {
            preserveScroll: true,
            onError: (e) => setError(e.percent),
            onSuccess: () => setOpen(false),
        });

    return (
        <div className="w-56 space-y-1 text-left">
            <label className="text-xs text-muted-foreground" htmlFor={`lot-${lotId}-pct`}>
                {t('% below MRP')}
            </label>
            <Input id={`lot-${lotId}-pct`} className="h-8" type="number" min="0" max="99.9" step="0.001" value={value} onChange={(e) => { setValue(e.target.value); setPreview(null); }} />
            {preview && (
                <p className={`rounded-md px-2 py-1 text-xs ${preview.loss ? 'bg-amber-100 text-amber-900' : 'bg-muted'}`}>
                    {t('Cost per unit')}: {money(preview.unit_cost)}
                    {preview.loss && (
                        <span className="block">
                            {t('At this cost these are at a loss: offers {o}, fixed rates {r}, sales already made {s}.', { o: num(preview.offers_at_loss), r: num(preview.rates_at_loss), s: num(preview.sales_at_loss) })}
                        </span>
                    )}
                </p>
            )}
            <InputError message={error} />
            <div className="flex gap-1">
                {preview ? (
                    <Button size="sm" onClick={save}>
                        {preview.loss ? t('Save anyway') : t('Save')}
                    </Button>
                ) : (
                    <Button size="sm" onClick={check}>
                        {t('Check')}
                    </Button>
                )}
                <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                    {t('Cancel')}
                </Button>
            </div>
        </div>
    );
}
