import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { Mic, Square, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface Extras {
    voice: File | null;
    photo: File | null;
    wanted_time: string;
}

export const emptyExtras: Extras = { voice: null, photo: null, wanted_time: '' };

/** Record a voice note in the browser. Where recording is not possible the person can pick an audio file instead. */
export function VoiceRecorder({ file, onChange }: { file: File | null; onChange: (file: File | null) => void }) {
    const { t } = useI18n();
    const [recording, setRecording] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const recorder = useRef<MediaRecorder | null>(null);
    const chunks = useRef<Blob[]>([]);
    const canRecord = typeof window !== 'undefined' && 'MediaRecorder' in window && !!navigator.mediaDevices?.getUserMedia;
    const url = file ? URL.createObjectURL(file) : null;

    useEffect(() => () => { if (url) URL.revokeObjectURL(url); }, [url]);

    const start = async () => {
        setError(null);
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const rec = new MediaRecorder(stream);
            chunks.current = [];
            rec.ondataavailable = (e) => e.data.size > 0 && chunks.current.push(e.data);
            rec.onstop = () => {
                stream.getTracks().forEach((track) => track.stop());
                const type = rec.mimeType || 'audio/webm';
                onChange(new File(chunks.current, `voice.${type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm'}`, { type }));
            };
            rec.start();
            recorder.current = rec;
            setRecording(true);
        } catch {
            setError(t('The microphone could not be used. You can choose an audio file instead.'));
        }
    };

    const stop = () => {
        recorder.current?.stop();
        setRecording(false);
    };

    return (
        <div className="space-y-2">
            {file && url ? (
                <div className="flex flex-wrap items-center gap-2">
                    <audio controls src={url} className="h-10 max-w-full" />
                    <Button type="button" variant="ghost" size="icon" aria-label={t('Remove')} onClick={() => onChange(null)}>
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            ) : (
                <div className="flex flex-wrap items-center gap-2">
                    {canRecord &&
                        (recording ? (
                            <Button type="button" variant="destructive" size="sm" onClick={stop}>
                                <Square className="size-4" />
                                {t('Stop recording')}
                            </Button>
                        ) : (
                            <Button type="button" variant="outline" size="sm" onClick={start}>
                                <Mic className="size-4" />
                                {t('Record a voice note')}
                            </Button>
                        ))}
                    <label className="text-sm text-[var(--pt-muted)]">
                        <span className="sr-only">{t('Choose an audio file')}</span>
                        <input type="file" accept="audio/*" onChange={(e) => onChange(e.target.files?.[0] ?? null)} className="max-w-56 text-sm" />
                    </label>
                </div>
            )}
            {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        </div>
    );
}

/**
 * What a buyer can add to an order besides the note (spec ch 23): the time they need it by, a voice note, and a photo for
 * what they cannot explain in words.
 */
export default function OrderExtras({ value, onChange, errors = {} }: { value: Extras; onChange: (next: Extras) => void; errors?: Record<string, string> }) {
    const { t } = useI18n();

    return (
        <div className="space-y-4">
            <div>
                <label htmlFor="wanted-time" className="text-sm font-medium">
                    {t('Needed by (time)')}
                </label>
                <Input id="wanted-time" type="time" className="mt-1 w-40" value={value.wanted_time} onChange={(e) => onChange({ ...value, wanted_time: e.target.value })} />
                {errors.wanted_time && <p role="alert" className="mt-1 text-sm text-red-700">{errors.wanted_time}</p>}
            </div>
            <div>
                <p className="text-sm font-medium">{t('Voice note')}</p>
                <div className="mt-1">
                    <VoiceRecorder file={value.voice} onChange={(voice) => onChange({ ...value, voice })} />
                </div>
                {errors.voice && <p role="alert" className="mt-1 text-sm text-red-700">{errors.voice}</p>}
            </div>
            <div>
                <label htmlFor="order-photo" className="text-sm font-medium">
                    {t('Photo')}
                </label>
                <input id="order-photo" type="file" accept="image/*" className="mt-1 block text-sm" onChange={(e) => onChange({ ...value, photo: e.target.files?.[0] ?? null })} />
                {errors.photo && <p role="alert" className="mt-1 text-sm text-red-700">{errors.photo}</p>}
            </div>
        </div>
    );
}
