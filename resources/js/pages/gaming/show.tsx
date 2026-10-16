import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    game: { name: string; slug: string; id_label: string; needs_zone: boolean; zone_label: string | null };
    packages: { id: number; name: string; price: number }[];
    me: { phone: string | null } | null;
}

type Check = { status: 'verified' | 'invalid' | 'unavailable'; name?: string } | null;

/** The steps of a top-up (spec ch 18): the ID, the name behind it, a package, then pay. */
export default function GamingShow({ game, packages, me }: Props) {
    const { t, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [playerId, setPlayerId] = useState('');
    const [zone, setZone] = useState('');
    const [check, setCheck] = useState<Check>(null);
    const [checking, setChecking] = useState(false);
    const [pack, setPack] = useState<number | null>(null);
    const [phone, setPhone] = useState(me?.phone ?? '');
    const [confirmed, setConfirmed] = useState(false);

    const verify = async () => {
        setChecking(true);
        setConfirmed(false);
        try {
            const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const res = await fetch(`/gaming/${game.slug}/verify`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, body: JSON.stringify({ player_id: playerId, zone }) });
            setCheck(res.ok ? ((await res.json()) as Check) : { status: 'invalid' });
        } catch {
            setCheck({ status: 'unavailable' });
        } finally {
            setChecking(false);
        }
    };

    const ready = check !== null && check.status !== 'invalid' && pack !== null && confirmed && phone.trim().length >= 10;

    return (
        <PublicLayout>
            <Head title={game.name} />
            <div className="mx-auto max-w-2xl space-y-6">
                <Link href="/gaming" className="text-sm underline underline-offset-4">
                    {t('All games')}
                </Link>
                <h1 className="font-display text-3xl font-extrabold">{game.name}</h1>

                <section className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
                    <h2 className="font-display text-xl font-bold">{t('1. Your ID')}</h2>
                    <div className="flex flex-wrap items-end gap-2">
                        <label className="min-w-48 flex-1 text-sm font-medium">
                            {game.id_label}
                            <Input className="mt-1" inputMode="text" value={playerId} maxLength={60} onChange={(e) => (setPlayerId(e.target.value), setCheck(null))} />
                        </label>
                        {game.needs_zone && (
                            <label className="w-32 text-sm font-medium">
                                {game.zone_label ?? t('Zone')}
                                <Input className="mt-1" value={zone} maxLength={40} onChange={(e) => (setZone(e.target.value), setCheck(null))} />
                            </label>
                        )}
                        <Button type="button" variant="outline" disabled={playerId.trim().length < 4 || checking} onClick={verify}>
                            {checking ? t('Checking...') : t('Check the ID')}
                        </Button>
                    </div>
                    {check?.status === 'verified' && (
                        <p role="status" className="rounded-md bg-emerald-50 p-3 text-sm text-emerald-900">
                            {t('This ID belongs to')} <strong className="text-base">{check.name}</strong>
                        </p>
                    )}
                    {check?.status === 'invalid' && <p role="alert" className="rounded-md bg-red-50 p-3 text-sm text-red-900">{t('This ID does not look right. Check it and try again.')}</p>}
                    {check?.status === 'unavailable' && <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-900">{t('We cannot show the player name yet. Check your ID carefully: a top-up to a wrong ID cannot be taken back.')}</p>}
                </section>

                <section className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
                    <h2 className="font-display text-xl font-bold">{t('2. Pick a package')}</h2>
                    <div className="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label={t('Package')}>
                        {packages.map((p) => (
                            <button key={p.id} type="button" role="radio" aria-checked={pack === p.id} onClick={() => setPack(p.id)} className={`flex items-center justify-between rounded-xl border px-3 py-3 text-start ${pack === p.id ? 'border-[var(--pt-ink)] bg-[var(--pt-sage)]' : ''}`}>
                                <span className="font-semibold">{p.name}</span>
                                <span className="tabular">{money(p.price)}</span>
                            </button>
                        ))}
                    </div>
                </section>

                <section className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
                    <h2 className="font-display text-xl font-bold">{t('3. Confirm and pay')}</h2>
                    <label className="block text-sm font-medium">
                        {t('Your mobile number (for the confirmation)')}
                        <Input className="mt-1" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
                    </label>
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-1" checked={confirmed} disabled={check === null || check.status === 'invalid'} onChange={(e) => setConfirmed(e.target.checked)} />
                        {check?.status === 'verified' ? t('Yes, this is my name. I understand a top-up cannot be taken back.') : t('I have checked the ID. I understand a top-up to a wrong ID cannot be taken back.')}
                    </label>
                    <InputError message={errors.order ?? errors.confirmed ?? errors.contact_phone} />
                    <Button className="h-12 w-full text-base" disabled={!ready} onClick={() => router.post(`/gaming/packages/${pack}/order`, { player_id: playerId, zone, contact_phone: phone, confirmed: confirmed ? 1 : 0 })}>
                        {t('Go to payment')}
                    </Button>
                    <p className="text-xs text-[var(--pt-muted)]">{t('Top-ups are paid online with bKash, Nagad or Rocket. There is no cash on delivery.')}</p>
                </section>
            </div>
        </PublicLayout>
    );
}
