import InputError from '@/components/input-error';
import { VoiceRecorder } from '@/components/order-extras';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { blankPerson, PersonBox, type Person } from '@/pages/custom/new';
import { router, usePage } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface ChatMessage {
    id: number;
    mine: boolean;
    role: 'buyer' | 'maker' | 'staff';
    who: string;
    kind: 'text' | 'photo' | 'voice' | 'measurements' | 'offer';
    body: string | null;
    has_file: boolean;
    meta: Record<string, unknown> | null;
    at: string;
    slow: boolean;
}

export interface ChatData {
    enabled: boolean;
    role: 'buyer' | 'maker' | 'staff';
    messages: ChatMessage[];
    measurements: { id: number; items: MeasureItem[]; at: string } | null;
    closed: boolean;
    can_measure: boolean;
    can_offer: boolean;
}

interface MeasureItem {
    label?: string;
    garment?: string;
    quantity?: number;
    unit: string;
    values: Record<string, number>;
}

export interface MeasureProps {
    kind: 'wood' | 'clothing';
    profiles: { id: number; name: string; kind: 'men' | 'women' | 'wood'; unit: string; values: Record<string, number>; measured_on: string; stale: boolean; months: number }[];
    fields: Record<string, Record<string, string>>;
    units: string[];
}

const label = (k: string) => k.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

function Items({ items }: { items: MeasureItem[] }) {
    const { t, num } = useI18n();

    return (
        <ul className="space-y-1">
            {items.map((it, i) => (
                <li key={i} className="tabular">
                    <strong>{it.label || it.garment || t('Size')}</strong>
                    {it.quantity && it.quantity > 1 ? ` × ${num(it.quantity)}` : ''}:{' '}
                    {Object.entries(it.values)
                        .map(([k, v]) => `${t(label(k))} ${num(v)}`)
                        .join(', ')}{' '}
                    ({t(it.unit === 'inch' ? 'Inch' : it.unit === 'foot' ? 'Foot' : 'Centimetre')})
                </li>
            ))}
        </ul>
    );
}

/** The measurement form inside the chat: no typing, just the fields (spec ch 23). */
function MeasureForm({ measure, url, onDone }: { measure: MeasureProps; url: string; onDone: () => void }) {
    const { t } = useI18n();
    const [unit, setUnit] = useState('inch');
    const [dims, setDims] = useState<Record<string, string>>({});
    const [people, setPeople] = useState<Person[]>([blankPerson()]);
    const wood = measure.kind === 'wood';

    const send = () => {
        const items = wood ? [{ unit, values: dims }] : people.map((p) => (p.profile_id ? { profile_id: p.profile_id, garment: p.garment, quantity: p.quantity } : { kind: p.kind, unit: p.unit, garment: p.garment, quantity: p.quantity, values: p.values }));
        router.post(url, { items }, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <div className="space-y-3 rounded-xl border bg-background p-3">
            {wood ? (
                <>
                    <label className="text-sm font-medium">
                        {t('Unit')}
                        <select value={unit} onChange={(e) => setUnit(e.target.value)} className="ms-2 h-8 rounded-md border bg-background px-2 text-sm">
                            {measure.units.map((u) => (
                                <option key={u} value={u}>
                                    {t(u === 'inch' ? 'Inch' : u === 'foot' ? 'Foot' : 'Centimetre')}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {Object.entries(measure.fields.wood).map(([k, l]) => (
                            <label key={k} className="text-sm">
                                {t(l)}
                                <Input className="mt-1" inputMode="decimal" value={dims[k] ?? ''} onChange={(e) => setDims({ ...dims, [k]: e.target.value })} />
                            </label>
                        ))}
                    </div>
                </>
            ) : (
                <>
                    {people.map((p, i) => (
                        <PersonBox key={i} person={p} index={i} profiles={measure.profiles} fields={measure.fields} units={measure.units} onChange={(np) => setPeople(people.map((x, j) => (j === i ? np : x)))} onRemove={people.length > 1 ? () => setPeople(people.filter((_, j) => j !== i)) : undefined} />
                    ))}
                    <Button type="button" size="sm" variant="outline" onClick={() => setPeople([...people, blankPerson()])}>
                        {t('Add another person')}
                    </Button>
                </>
            )}
            <div className="flex gap-2">
                <Button size="sm" onClick={send}>
                    {t('Send these measurements')}
                </Button>
                <Button size="sm" variant="ghost" onClick={onDone}>
                    {t('Close')}
                </Button>
            </div>
        </div>
    );
}

/** The same chat for the buyer, the maker and us. The buyer sees our name, the maker sees "the buyer", we see who is who. */
export default function CustomChat({
    chat,
    postUrl,
    fileUrl,
    measure,
    measureUrl,
    answerUrl,
    offerUrl,
}: {
    chat: ChatData;
    postUrl: string;
    fileUrl: (id: number) => string;
    measure?: MeasureProps | null;
    measureUrl?: string;
    answerUrl?: (id: number) => string;
    offerUrl?: string;
}) {
    const { t, money, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [text, setText] = useState('');
    const [photo, setPhoto] = useState<File | null>(null);
    const [voice, setVoice] = useState<File | null>(null);
    const [measuring, setMeasuring] = useState(false);
    const [offering, setOffering] = useState(false);
    const [offer, setOffer] = useState({ price: '', days: '10', advance: '', note: '' });
    const [checked, setChecked] = useState(false);
    // Kept here, because the chat asks for itself again every few seconds and that would wipe the page's own error.
    const [problem, setProblem] = useState<string | null>(null);
    const end = useRef<HTMLDivElement>(null);
    const last = chat.messages[chat.messages.length - 1]?.id ?? 0;

    // New messages arrive by asking for the chat again, not for the whole page.
    useEffect(() => {
        const id = window.setInterval(() => document.visibilityState === 'visible' && router.reload({ only: ['chat'] }), 8000);

        return () => window.clearInterval(id);
    }, []);

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'nearest' });
    }, [last]);

    if (!chat.enabled) {
        return null;
    }

    const send = () => {
        setProblem(null);
        router.post(postUrl, { text, photo, voice }, { forceFormData: true, preserveScroll: true, onSuccess: () => (setText(''), setPhoto(null), setVoice(null)), onError: (e) => setProblem(Object.values(e)[0] ?? null) });
    };

    const card = (m: ChatMessage) => {
        const meta = m.meta ?? {};

        if (m.kind === 'measurements') {
            return (
                <div id={`measure-${m.id}`} className="space-y-1 rounded-xl border-2 border-[var(--pt-ink)] p-3 text-sm">
                    <p className="font-semibold">{t('Measurements')}</p>
                    <Items items={(meta.items as MeasureItem[]) ?? []} />
                </div>
            );
        }

        const state = String(meta.state ?? '');

        return (
            <div className="space-y-1 rounded-xl border-2 border-[var(--pt-ink)] p-3 text-sm">
                <p className="font-semibold">{t('Our offer')}</p>
                <p className="tabular font-display text-xl font-bold">{money(Number(meta.total ?? 0))}</p>
                <p>{t('It takes about {n} days.', { n: num(Number(meta.days ?? 0)) })}</p>
                {meta.advance_percent !== null && meta.advance_percent !== undefined && <p>{t('Advance ({n}%)', { n: num(Number(meta.advance_percent)) })}</p>}
                {meta.note ? <p>{String(meta.note)}</p> : null}
                {state === 'pending' && chat.role === 'buyer' && answerUrl ? (
                    <div className="space-y-2 pt-1">
                        <label className="flex items-start gap-2">
                            <input type="checkbox" className="mt-1" checked={checked} onChange={(e) => setChecked(e.target.checked)} /> {t('I have checked the measurements and I agree to the price.')}
                        </label>
                        <div className="flex gap-2">
                            <Button size="sm" disabled={!checked} onClick={() => router.post(answerUrl(m.id), { answer: 'accept', checked: 1 }, { preserveScroll: true })}>
                                {t('Accept')}
                            </Button>
                            <Button size="sm" variant="outline" onClick={() => router.post(answerUrl(m.id), { answer: 'decline' }, { preserveScroll: true })}>
                                {t('Decline')}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <p className="font-semibold">{t(state === 'accepted' ? 'Accepted' : state === 'declined' ? 'Declined' : state === 'superseded' ? 'Replaced by a newer offer' : 'Waiting for the buyer')}</p>
                )}
            </div>
        );
    };

    return (
        <section aria-labelledby="chat-h" className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="chat-h" className="font-display text-xl font-bold">
                    {t('Chat')}
                </h2>
                {chat.measurements && (
                    <button type="button" className="text-sm font-semibold underline underline-offset-4" onClick={() => document.getElementById(`measure-${chat.measurements?.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })}>
                        {t('View measurements')}
                    </button>
                )}
            </div>
            <p className="text-xs text-[var(--pt-muted)]">{t('Phone numbers and links cannot be sent here. We can read this chat.')}</p>

            <div className="max-h-[28rem] space-y-2 overflow-y-auto rounded-xl bg-muted/40 p-3" role="log" aria-live="polite">
                {chat.messages.length === 0 && <p className="py-6 text-center text-sm text-[var(--pt-muted)]">{t('No messages yet.')}</p>}
                {chat.messages.map((m) => (
                    <div key={m.id} className={`flex ${m.mine ? 'justify-end' : 'justify-start'}`}>
                        <div className={`max-w-[85%] space-y-1 rounded-2xl px-3 py-2 text-sm ${m.mine ? 'bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border bg-background'}`}>
                            <p className="text-xs opacity-70">
                                {m.who} · {dateTime(m.at)}
                                {m.slow && ` · ${t('Not answered in time')}`}
                            </p>
                            {m.kind === 'text' && <p className="whitespace-pre-line">{m.body}</p>}
                            {m.kind === 'photo' && m.has_file && (
                                <a href={fileUrl(m.id)} target="_blank" rel="noreferrer">
                                    <img src={fileUrl(m.id)} alt={t('Photo')} className="max-h-56 rounded-lg" />
                                </a>
                            )}
                            {m.kind === 'voice' && m.has_file && <audio controls src={fileUrl(m.id)} className="h-10 max-w-full" />}
                            {(m.kind === 'measurements' || m.kind === 'offer') && <div className="text-[var(--pt-ink)]">{card(m)}</div>}
                        </div>
                    </div>
                ))}
                <div ref={end} />
            </div>

            {chat.closed ? (
                <p className="text-sm text-[var(--pt-muted)]">{t('This job is closed. You can still read the chat.')}</p>
            ) : (
                <div className="space-y-2">
                    <InputError message={problem ?? errors.chat ?? errors.order ?? errors.job ?? errors.text} />
                    <div className="flex flex-wrap gap-2">
                        {chat.can_measure && measure && (
                            <Button type="button" size="sm" variant="outline" onClick={() => setMeasuring(!measuring)}>
                                {t('Send measurements')}
                            </Button>
                        )}
                        {chat.can_offer && (
                            <Button type="button" size="sm" variant="outline" onClick={() => setOffering(!offering)}>
                                {t('Send offer')}
                            </Button>
                        )}
                    </div>
                    {measuring && measure && measureUrl && <MeasureForm measure={measure} url={measureUrl} onDone={() => setMeasuring(false)} />}
                    {offering && offerUrl && (
                        <div className="flex flex-wrap items-end gap-2 rounded-xl border bg-background p-3 text-sm">
                            {(
                                [
                                    ['price', 'Total price'],
                                    ['days', 'Days'],
                                    ['advance', 'Advance %'],
                                ] as const
                            ).map(([k, l]) => (
                                <label key={k} className="text-xs">
                                    {t(l)}
                                    <Input className="mt-1 h-8 w-24" inputMode="decimal" value={offer[k]} onChange={(e) => setOffer({ ...offer, [k]: e.target.value })} />
                                </label>
                            ))}
                            <label className="text-xs">
                                {t('Note')}
                                <Input className="mt-1 h-8 w-48" value={offer.note} onChange={(e) => setOffer({ ...offer, note: e.target.value })} />
                            </label>
                            <Button size="sm" disabled={Number(offer.price) <= 0} onClick={() => router.post(offerUrl, offer, { preserveScroll: true, onSuccess: () => setOffering(false) })}>
                                {t('Send the offer')}
                            </Button>
                        </div>
                    )}
                    <textarea aria-label={t('Message')} value={text} maxLength={1000} onChange={(e) => (setText(e.target.value), setProblem(null))} rows={2} placeholder={t('Write a message')} className="block w-full rounded-md border bg-background p-2 text-sm" />
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="inline-flex cursor-pointer items-center gap-1 text-sm">
                            <Camera className="size-4" />
                            <span>{photo ? photo.name : t('Photo')}</span>
                            <input type="file" accept="image/*" className="sr-only" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                        </label>
                        <VoiceRecorder file={voice} onChange={setVoice} />
                        <Button className="ms-auto" disabled={text.trim() === '' && !photo && !voice} onClick={send}>
                            {t('Send')}
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}
