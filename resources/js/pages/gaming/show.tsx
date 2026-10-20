import InputError from '@/components/input-error';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, CircleAlert, CircleCheck, Info, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface Props {
    game: { name: string; slug: string; id_label: string; needs_zone: boolean; zone_label: string | null; cover: string | null };
    packages: { id: number; name: string; price: number }[];
    preselect: number;
    me: { phone: string | null } | null;
}

type Check = { status: 'verified' | 'invalid' | 'unavailable'; name?: string } | null;

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };
const field =
    'mt-1 h-12 w-full rounded-xl border border-[var(--g-line)] bg-[var(--g-panel-2)] px-4 text-[var(--g-text)] outline-none placeholder:text-[var(--g-muted)] focus:border-[var(--g-cyan)]';

/** "1,369 Diamonds" becomes a big number and its unit, so a pack reads at a glance. */
const split = (name: string): [string, string] => {
    const m = name.match(/^([\d,.]+)\s*(.*)$/);

    return m ? [m[1], m[2]] : [name, ''];
};

/**
 * One game's top-up, in the manner of a game store: the game's banner, a player ID box that shows the name behind the ID, a grid of packs with the
 * amount large, and an order panel beside it. Nothing about how a top-up is checked or paid has changed: the ID, the name, a pack, then pay.
 */
export default function GamingShow({ game, packages, preselect, me }: Props) {
    const { t, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [playerId, setPlayerId] = useState('');
    const [zone, setZone] = useState('');
    const [check, setCheck] = useState<Check>(null);
    const [checking, setChecking] = useState(false);
    const [pack, setPack] = useState<number | null>(packages.some((p) => p.id === preselect) ? preselect : null);
    const [phone, setPhone] = useState(me?.phone ?? '');
    const [confirmed, setConfirmed] = useState(false);

    const verify = async () => {
        setChecking(true);
        setConfirmed(false);
        try {
            const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
            const res = await fetch(`/gaming/${game.slug}/verify`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
                body: JSON.stringify({ player_id: playerId, zone }),
            });
            setCheck(res.ok ? ((await res.json()) as Check) : { status: 'invalid' });
        } catch {
            setCheck({ status: 'unavailable' });
        } finally {
            setChecking(false);
        }
    };

    const chosen = packages.find((p) => p.id === pack) ?? null;
    const ready = check !== null && check.status !== 'invalid' && pack !== null && confirmed && phone.trim().length >= 10;

    return (
        <PublicLayout>
            <Head title={game.name} />
            <div className="game -mx-4 px-5 py-8 sm:mx-0 sm:rounded-[2rem] sm:px-10 sm:py-10">
                <div className="mx-auto max-w-5xl">
                    <Link
                        href="/gaming"
                        className="art-btn inline-flex h-10 items-center rounded-full border border-[var(--g-line)] px-5 text-sm hover:border-[var(--g-cyan)]"
                    >
                        {t('All games')}
                    </Link>

                    <header className="relative isolate mt-5 overflow-hidden rounded-[1.75rem] border border-[var(--g-line)] bg-[var(--g-panel)]">
                        {game.cover && (
                            <img src={game.cover} alt="" decoding="async" className="absolute inset-0 -z-20 size-full object-cover opacity-70" />
                        )}
                        <span
                            aria-hidden
                            className="absolute inset-0 -z-10 bg-gradient-to-r from-[var(--g-bg)] via-[var(--g-bg)]/70 to-transparent"
                        />
                        <div className="px-6 py-10 sm:px-10 sm:py-14">
                            <h1 style={DISPLAY} className="text-4xl leading-none font-normal tracking-tight sm:text-6xl">
                                {game.name}
                            </h1>
                            <p className="mt-3 flex items-center gap-2 text-sm text-[var(--g-muted)]">
                                <Info aria-hidden className="size-4" strokeWidth={1.6} />
                                {t('Enter your ID, pick a pack, then pay online.')}
                            </p>
                        </div>
                    </header>

                    <div className="mt-6 grid gap-6 lg:grid-cols-12">
                        <div className="space-y-6 lg:col-span-8">
                            <section className="rounded-[1.5rem] border border-[var(--g-line)] bg-[var(--g-panel)] p-6">
                                <h2 style={DISPLAY} className="text-2xl">
                                    {t('1. Your ID')}
                                </h2>
                                <div className="mt-4 flex flex-wrap items-end gap-3">
                                    <label className="min-w-48 flex-1 text-sm">
                                        {game.id_label}
                                        <input
                                            className={field}
                                            inputMode="text"
                                            value={playerId}
                                            maxLength={60}
                                            onChange={(e) => (setPlayerId(e.target.value), setCheck(null))}
                                        />
                                    </label>
                                    {game.needs_zone && (
                                        <label className="w-32 text-sm">
                                            {game.zone_label ?? t('Zone')}
                                            <input
                                                className={field}
                                                value={zone}
                                                maxLength={40}
                                                onChange={(e) => (setZone(e.target.value), setCheck(null))}
                                            />
                                        </label>
                                    )}
                                    <button
                                        type="button"
                                        disabled={playerId.trim().length < 4 || checking}
                                        onClick={verify}
                                        className="art-btn h-12 shrink-0 rounded-full bg-[var(--g-cyan)] px-6 font-medium text-[var(--g-bg)] disabled:opacity-40"
                                    >
                                        {checking ? t('Checking...') : t('Check the ID')}
                                    </button>
                                </div>
                                {check?.status === 'verified' && (
                                    <p
                                        role="status"
                                        className="mt-4 flex items-center gap-2 rounded-xl border border-[var(--g-cyan)] bg-[var(--g-panel-2)] p-3 text-sm"
                                    >
                                        <CircleCheck aria-hidden className="size-5 shrink-0 text-[var(--g-cyan)]" strokeWidth={1.8} />
                                        <span>
                                            {t('This ID belongs to')} <strong className="text-base">{check.name}</strong>
                                        </span>
                                    </p>
                                )}
                                {check?.status === 'invalid' && (
                                    <p
                                        role="alert"
                                        className="mt-4 flex items-center gap-2 rounded-xl border border-[var(--g-violet)] bg-[var(--g-panel-2)] p-3 text-sm"
                                    >
                                        <CircleAlert aria-hidden className="size-5 shrink-0 text-[var(--g-violet)]" strokeWidth={1.8} />
                                        {t('This ID does not look right. Check it and try again.')}
                                    </p>
                                )}
                                {check?.status === 'unavailable' && (
                                    <p className="mt-4 flex items-center gap-2 rounded-xl border border-[var(--g-line)] bg-[var(--g-panel-2)] p-3 text-sm">
                                        <Info aria-hidden className="size-5 shrink-0" strokeWidth={1.8} />
                                        {t(
                                            'We cannot show the player name yet. Check your ID carefully: a top-up to a wrong ID cannot be taken back.',
                                        )}
                                    </p>
                                )}
                            </section>

                            <section className="rounded-[1.5rem] border border-[var(--g-line)] bg-[var(--g-panel)] p-6">
                                <h2 style={DISPLAY} className="text-2xl">
                                    {t('2. Pick a package')}
                                </h2>
                                <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3" role="radiogroup" aria-label={t('Package')}>
                                    {packages.map((p) => {
                                        const [amount, unit] = split(p.name);
                                        const on = pack === p.id;

                                        return (
                                            <button
                                                key={p.id}
                                                type="button"
                                                role="radio"
                                                aria-checked={on}
                                                onClick={() => setPack(p.id)}
                                                className={`art-lift relative flex flex-col items-start rounded-[1.25rem] border p-4 text-start transition-colors duration-300 ${on ? 'border-[var(--g-cyan)] bg-[var(--g-panel-2)] shadow-[0_0_0_1px_var(--g-cyan)]' : 'border-[var(--g-line)] bg-[var(--g-panel)] hover:border-[var(--g-muted)]'}`}
                                            >
                                                {on && (
                                                    <span
                                                        aria-hidden
                                                        className="absolute top-3 right-3 grid size-6 place-items-center rounded-full bg-[var(--g-cyan)] text-[var(--g-bg)]"
                                                    >
                                                        <Check className="size-4" strokeWidth={3} />
                                                    </span>
                                                )}
                                                <span style={DISPLAY} className="tabular text-4xl leading-none">
                                                    {amount}
                                                </span>
                                                {unit && <span className="mt-1 text-sm text-[var(--g-muted)]">{unit}</span>}
                                                <span className="tabular mt-3 text-[var(--g-cyan)]">{money(p.price)}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </section>
                        </div>

                        <aside className="lg:col-span-4">
                            <section className="rounded-[1.5rem] border border-[var(--g-line)] bg-[var(--g-panel)] p-6 lg:sticky lg:top-24">
                                <h2 style={DISPLAY} className="text-2xl">
                                    {t('3. Confirm and pay')}
                                </h2>
                                <dl className="mt-4 space-y-2 border-b border-[var(--g-line)] pb-4 text-sm">
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-[var(--g-muted)]">{t('Game')}</dt>
                                        <dd>{game.name}</dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-[var(--g-muted)]">{t('Package')}</dt>
                                        <dd>{chosen ? chosen.name : t('Not chosen yet')}</dd>
                                    </div>
                                    <div className="flex justify-between gap-3 text-lg">
                                        <dt>{t('Total')}</dt>
                                        <dd style={DISPLAY} className="tabular text-[var(--g-cyan)]">
                                            {chosen ? money(chosen.price) : money(0)}
                                        </dd>
                                    </div>
                                </dl>
                                <label className="mt-4 block text-sm">
                                    {t('Your mobile number (for the confirmation)')}
                                    <input className={field} inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
                                </label>
                                <label className="mt-4 flex items-start gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-1 accent-[var(--g-cyan)]"
                                        checked={confirmed}
                                        disabled={check === null || check.status === 'invalid'}
                                        onChange={(e) => setConfirmed(e.target.checked)}
                                    />
                                    {check?.status === 'verified'
                                        ? t('Yes, this is my name. I understand a top-up cannot be taken back.')
                                        : t('I have checked the ID. I understand a top-up to a wrong ID cannot be taken back.')}
                                </label>
                                <InputError message={errors.order ?? errors.confirmed ?? errors.contact_phone} />
                                <button
                                    type="button"
                                    disabled={!ready}
                                    onClick={() =>
                                        router.post(`/gaming/packages/${pack}/order`, {
                                            player_id: playerId,
                                            zone,
                                            contact_phone: phone,
                                            confirmed: confirmed ? 1 : 0,
                                        })
                                    }
                                    className="art-btn mt-5 h-14 w-full rounded-full bg-[var(--g-cyan)] text-lg font-medium text-[var(--g-bg)] disabled:opacity-40"
                                >
                                    {t('Go to payment')}
                                </button>
                                <p className="mt-4 flex items-start gap-2 text-xs text-[var(--g-muted)]">
                                    <ShieldCheck aria-hidden className="mt-0.5 size-4 shrink-0" strokeWidth={1.6} />
                                    {t('Top-ups are paid online with bKash, Nagad or Rocket. There is no cash on delivery.')}
                                </p>
                            </section>
                        </aside>
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
