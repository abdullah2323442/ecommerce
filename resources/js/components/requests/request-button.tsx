import { useI18n } from '@/lib/i18n';
import { Button } from '@/components/ui/button';
import { Check, MessageSquarePlus } from 'lucide-react';
import { useState } from 'react';

type Status = 'idle' | 'sending' | 'done' | 'limited' | 'error';

function csrf(): string {
    const raw = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return raw ? decodeURIComponent(raw.split('=').slice(1).join('=')) : '';
}

interface Props {
    /** What the visitor searched for. */
    query: string;
    className?: string;
}

/**
 * "I was looking for this" (spec ch 13). One click tells Siam what the visitor could not find.
 * All four outcomes are shown: sending, thanks, too many requests, and a failure they can retry.
 */
export default function RequestButton({ query, className = '' }: Props) {
    const { t } = useI18n();
    const [status, setStatus] = useState<Status>('idle');

    const send = async () => {
        setStatus('sending');
        try {
            const res = await fetch(route('requests.store'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
                body: JSON.stringify({ query }),
            });
            setStatus(res.ok ? 'done' : res.status === 429 ? 'limited' : 'error');
        } catch {
            setStatus('error');
        }
    };

    if (status === 'done') {
        return (
            <p role="status" className={`flex items-center gap-2 text-sm font-semibold text-success ${className}`}>
                <Check aria-hidden className="size-4" />
                {t('Noted. We will try to stock “{query}”.', { query })}
            </p>
        );
    }

    return (
        <div className={className}>
            <Button type="button" variant="secondary" onClick={send} loading={status === 'sending'}>
                <MessageSquarePlus aria-hidden />
                {t('I was looking for this')}
            </Button>
            {status === 'limited' && (
                <p role="alert" className="mt-2 text-sm text-danger">
                    {t('You have sent a few requests already. Please try again in a minute.')}
                </p>
            )}
            {status === 'error' && (
                <p role="alert" className="mt-2 text-sm text-danger">
                    {t('That did not go through. Check your connection and press the button again.')}
                </p>
            )}
        </div>
    );
}
