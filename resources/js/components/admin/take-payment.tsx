import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useI18n } from '@/lib/i18n';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    /** Where the payment is posted. */
    url: string;
    /** The ways money can be paid, in the order they are shown. */
    methods: string[];
    /** The most that may be paid: what is owed. */
    owed: number;
    /** The button: "Take payment" (a customer paying us) or "Record payment" (us paying a company). */
    action: string;
}

const NAMES: Record<string, string> = { cash: 'Cash', bkash: 'bKash', nagad: 'Nagad', rocket: 'Rocket', bank: 'Bank', card: 'Card', cheque: 'Cheque' };

/** The amount, the way it was paid and a note: one panel for both sides of the ledger. A payment fills the oldest memo or the invoice due first. */
export default function TakePayment({ url, methods, owed, action }: Props) {
    const { t, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [amount, setAmount] = useState('');
    const [method, setMethod] = useState(methods[0] ?? 'cash');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const value = Number(amount);
    const ok = amount.trim() !== '' && value > 0 && value <= owed + 0.001;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!ok || busy) return;
        router.post(url, { amount, method, note }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => (setAmount(''), setNote('')) });
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-lg border bg-card p-4" aria-busy={busy}>
            <h2 className="text-lg font-semibold">{action}</h2>

            <div className="flex flex-wrap items-end gap-2">
                <label className="min-w-40 flex-1 text-sm font-medium">
                    {t('Amount')}
                    <Input className="mt-1 text-base" inputMode="decimal" type="number" min="0" step="any" max={owed} value={amount} onChange={(e) => setAmount(e.target.value)} aria-invalid={amount !== '' && !ok} />
                </label>
                <Button type="button" variant="outline" onClick={() => setAmount(String(owed))}>
                    {t('All of it: {amount}', { amount: money(owed) })}
                </Button>
            </div>
            {amount !== '' && value > owed + 0.001 && <p className="text-sm text-destructive">{t('That is more than is owed. The most is {amount}.', { amount: money(owed) })}</p>}

            <div className="space-y-1">
                <p id="pay-way" className="text-sm font-medium">
                    {t('Paid by')}
                </p>
                <ToggleGroup type="single" variant="outline" aria-labelledby="pay-way" value={method} onValueChange={(v) => v && setMethod(v)} className="flex-wrap justify-start">
                    {methods.map((m) => (
                        <ToggleGroupItem key={m} value={m} aria-label={t(NAMES[m] ?? m)}>
                            {t(NAMES[m] ?? m)}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </div>

            <label className="block text-sm font-medium">
                {t('Note (optional)')}
                <Input className="mt-1" maxLength={200} value={note} onChange={(e) => setNote(e.target.value)} />
            </label>

            <InputError message={errors.payment ?? errors.amount ?? errors.method} />
            <Button type="submit" disabled={!ok || busy}>
                {busy ? t('Saving…') : action}
            </Button>
        </form>
    );
}
