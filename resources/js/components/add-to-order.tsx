import InputError from '@/components/input-error';
import OrderExtras, { emptyExtras, type Extras } from '@/components/order-extras';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/** Add to an order that is already sent: a note, a voice note, a photo, the time it is needed by (spec ch 23). */
export default function AddToOrder({ url, sent }: { url: string; sent?: { wanted_time?: string | null; has_voice?: boolean; has_photo?: boolean } }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState('');
    const [extras, setExtras] = useState<Extras>(emptyExtras);
    const [busy, setBusy] = useState(false);

    const already = [sent?.wanted_time && t('Needed by {time}', { time: sent.wanted_time }), sent?.has_voice && t('Voice note sent'), sent?.has_photo && t('Photo sent')].filter(Boolean);

    if (!open) {
        return (
            <div className="space-y-1">
                {already.length > 0 && <p className="text-sm text-[var(--pt-muted)]">{already.join(' · ')}</p>}
                <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                    {t('Add a note, voice note or photo')}
                </Button>
            </div>
        );
    }

    const send = () => {
        setBusy(true);
        router.post(url, { note, ...extras }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setNote('');
                setExtras(emptyExtras);
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="space-y-3 rounded-2xl border p-4">
            <div>
                <label htmlFor="add-note" className="text-sm font-medium">
                    {t('Note')}
                </label>
                <Input id="add-note" className="mt-1" value={note} maxLength={300} onChange={(e) => setNote(e.target.value)} />
                <InputError message={errors.note} />
            </div>
            <OrderExtras value={extras} onChange={setExtras} errors={errors} />
            <InputError message={errors.order} />
            <div className="flex gap-2">
                <Button size="sm" disabled={busy} onClick={send}>
                    {t('Add to my order')}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                    {t('Cancel')}
                </Button>
            </div>
        </div>
    );
}
