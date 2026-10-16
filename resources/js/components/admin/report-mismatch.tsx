import MedicinePicker, { type PickedMedicine } from '@/components/admin/medicine-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/** Staff cannot correct stock, but they can say what they found. The admin hears at once (spec 6.7). Shows no stock number. */
export default function ReportMismatch() {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [medicine, setMedicine] = useState<PickedMedicine | null>(null);
    const [found, setFound] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    if (!open) {
        return (
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {t('Report mismatch')}
            </Button>
        );
    }

    const send = (e: React.FormEvent) => {
        e.preventDefault();
        if (!medicine) return;
        setBusy(true);
        router.post(
            '/admin/stock/mismatches',
            { medicine_id: medicine.id, found, note },
            {
                preserveScroll: true,
                onError: (errs) => setErrors(errs),
                onSuccess: () => {
                    setOpen(false);
                    setMedicine(null);
                    setFound('');
                    setNote('');
                    setErrors({});
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <form onSubmit={send} className="w-full max-w-md space-y-2 rounded-md border bg-card p-3">
            <p className="text-sm font-medium">{t('Report mismatch')}</p>
            <p className="text-xs text-muted-foreground">{t('Count what is on the shelf and tell the admin. You cannot change the stock yourself.')}</p>
            <MedicinePicker picked={medicine} onPick={setMedicine} />
            <Input aria-label={t('How many did you find?')} type="number" min="0" step="any" inputMode="decimal" placeholder={t('How many did you find?')} value={found} onChange={(e) => setFound(e.target.value)} />
            <InputError message={errors.found ?? errors.medicine_id} />
            <Input aria-label={t('Note')} maxLength={300} placeholder={t('Note')} value={note} onChange={(e) => setNote(e.target.value)} />
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={busy || !medicine || found === ''}>
                    {t('Send report')}
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(false)}>
                    {t('Cancel')}
                </Button>
            </div>
        </form>
    );
}
