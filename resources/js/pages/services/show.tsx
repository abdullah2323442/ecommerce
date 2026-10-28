import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    offer: { id: number; title: string; description: string | null; minutes: number; price: number; place: 'provider' | 'online' | 'home'; area: string | null; provider: string };
    slots: { id: number; at: string; free: number }[];
    me: { name: string; phone: string | null } | null;
    own: boolean;
}

/** One service: what it is, the free times, and the form to ask for one. */
export default function ServiceShow({ offer, slots, me, own }: Props) {
    const { t, num, money, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [slot, setSlot] = useState<number | null>(null);
    const [f, setF] = useState({ name: me?.name ?? '', phone: me?.phone ?? '', note: '' });
    const [busy, setBusy] = useState(false);
    const place = { provider: t('At the provider'), online: t('Online'), home: t('At your home') }[offer.place];

    return (
        <PublicLayout>
            <Head title={offer.title} />
            <div className="mx-auto max-w-2xl space-y-6">
                <Link href="/services" className="text-sm underline underline-offset-4">
                    {t('All services')}
                </Link>
                <header className="space-y-2">
                    <h1 className="font-display text-4xl font-extrabold leading-tight">{offer.title}</h1>
                    <p className="text-[var(--pt-muted)]">
                        {offer.provider} · {t('{n} minutes', { n: num(offer.minutes) })} · {place}
                        {offer.area && ` · ${offer.area}`}
                    </p>
                    <p className="tabular font-display text-3xl font-bold">{offer.price > 0 ? money(offer.price) : t('Ask the provider')}</p>
                    {offer.price > 0 && <p className="text-xs text-[var(--pt-muted)]">{t('You pay the provider when you meet. Nothing is paid here.')}</p>}
                    {offer.description && <p className="max-w-prose whitespace-pre-line pt-2">{offer.description}</p>}
                </header>

                {own ? (
                    <p className="rounded-2xl border p-4 text-sm">{t('This is your own service. Manage its times and bookings under Sell.')}</p>
                ) : slots.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-8 text-center text-sm text-[var(--pt-muted)]">{t('No free time right now. Please look again later.')}</p>
                ) : (
                    <form
                        className="space-y-4 rounded-[1.5rem] border bg-[var(--pt-surface)] p-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (slot === null || busy) return;
                            router.post(`/services/${offer.id}/book`, { slot_id: slot, ...f }, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });
                        }}
                    >
                        <fieldset className="space-y-2">
                            <legend className="font-display text-xl font-bold">{t('Pick a time')}</legend>
                            <div className="flex flex-wrap gap-2">
                                {slots.map((s) => (
                                    <button
                                        key={s.id}
                                        type="button"
                                        aria-pressed={slot === s.id}
                                        onClick={() => setSlot(s.id)}
                                        className={`min-h-11 rounded-full border px-4 text-sm transition-colors ${slot === s.id ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-paper)]' : 'border-[var(--pt-line)] hover:border-[var(--pt-ink)]'}`}
                                    >
                                        {dateTime(s.at)}
                                        {s.free > 1 && <span className="ml-1 text-xs opacity-75">({t('{n} places', { n: num(s.free) })})</span>}
                                    </button>
                                ))}
                            </div>
                        </fieldset>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-sm font-medium">
                                {t('Your name')}
                                <Input className="mt-1" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
                            </label>
                            <label className="text-sm font-medium">
                                {t('Mobile number')}
                                <Input className="mt-1" inputMode="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
                            </label>
                        </div>
                        <label className="block text-sm font-medium">
                            {t('Anything the provider should know (optional)')}
                            <textarea className="mt-1 min-h-20 w-full rounded-md border bg-background p-3 text-sm" maxLength={300} value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} />
                        </label>
                        <InputError message={errors.book} />
                        <Button type="submit" className="w-full" disabled={slot === null || busy} aria-busy={busy}>
                            {busy ? t('Sending…') : t('Ask for this time')}
                        </Button>
                    </form>
                )}
            </div>
        </PublicLayout>
    );
}
