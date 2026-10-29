import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { Check, MessageSquarePlus } from 'lucide-react';
import { useState } from 'react';

type Status = 'idle' | 'sending' | 'done' | 'limited' | 'error' | 'invalid';

function csrf(): string {
    const raw = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return raw ? decodeURIComponent(raw.split('=').slice(1).join('=')) : '';
}

interface Props {
    /** What the visitor searched for. Empty means they say it themselves, in a box beside the button. */
    query?: string;
    /** Which part of the site they were in: medicine, market, food, gaming and so on. The owner sees where the want is. */
    sector?: string;
    className?: string;
}

/**
 * "I was looking for this" (spec ch 13). One click tells Siam what the visitor could not find, in any sector. A note and a photo
 * are there if they want to add them, and out of the way if they do not. When there was no search text, they type what they wanted.
 * Every outcome is shown: sending, thanks, too many requests, a failure they can retry, and a request that is too short.
 */
export default function RequestButton({ query = '', sector = 'medicine', className = '' }: Props) {
    const { t } = useI18n();
    const [status, setStatus] = useState<Status>('idle');
    const [typed, setTyped] = useState('');
    const [more, setMore] = useState(false);
    const [note, setNote] = useState('');
    const [photo, setPhoto] = useState<File | null>(null);
    const what = (query || typed).trim();

    const send = async () => {
        if (what.length < 2) {
            setStatus('invalid');

            return;
        }

        setStatus('sending');

        const body = new FormData();
        body.append('query', what);
        body.append('sector', sector);
        if (note.trim() !== '') body.append('note', note.trim());
        if (photo) body.append('photo', photo);

        try {
            const res = await fetch(route('requests.store'), { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf() }, body });
            setStatus(res.ok ? 'done' : res.status === 429 ? 'limited' : res.status === 422 ? 'invalid' : 'error');
        } catch {
            setStatus('error');
        }
    };

    if (status === 'done') {
        return (
            <p role="status" className={`flex items-center gap-2 text-sm font-semibold text-success ${className}`}>
                <Check aria-hidden className="size-4" />
                {t('Noted. We will try to stock “{query}”.', { query: what })}
            </p>
        );
    }

    return (
        <div className={`space-y-2 ${className}`}>
            {query === '' && (
                <div className="grid max-w-sm gap-1">
                    <label htmlFor={`looking-${sector}`} className="text-sm font-medium">
                        {t('What were you looking for?')}
                    </label>
                    <Input id={`looking-${sector}`} value={typed} onChange={(e) => setTyped(e.target.value)} maxLength={120} autoComplete="off" />
                </div>
            )}

            {more && (
                <div className="grid max-w-sm gap-2">
                    <div className="grid gap-1">
                        <label htmlFor={`note-${sector}`} className="text-sm font-medium">
                            {t('A note (optional)')}
                        </label>
                        <textarea
                            id={`note-${sector}`}
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            maxLength={300}
                            rows={2}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        />
                    </div>
                    <div className="grid gap-1">
                        <label htmlFor={`photo-${sector}`} className="text-sm font-medium">
                            {t('A photo (optional)')}
                        </label>
                        <Input id={`photo-${sector}`} type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                    </div>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-3">
                <Button type="button" variant="secondary" onClick={send} loading={status === 'sending'}>
                    <MessageSquarePlus aria-hidden />
                    {t('I was looking for this')}
                </Button>
                <button type="button" aria-expanded={more} onClick={() => setMore(!more)} className="text-sm underline underline-offset-4">
                    {more ? t('Leave out the note and photo') : t('Add a note or a photo')}
                </button>
            </div>

            {status === 'invalid' && (
                <p role="alert" className="text-sm text-danger">
                    {what.length < 2 ? t('Write what you were looking for, at least two letters.') : t('That photo or note could not be used. Try a smaller photo.')}
                </p>
            )}
            {status === 'limited' && (
                <p role="alert" className="text-sm text-danger">
                    {t('You have sent a few requests already. Please try again in a minute.')}
                </p>
            )}
            {status === 'error' && (
                <p role="alert" className="text-sm text-danger">
                    {t('That did not go through. Check your connection and press the button again.')}
                </p>
            )}
        </div>
    );
}
