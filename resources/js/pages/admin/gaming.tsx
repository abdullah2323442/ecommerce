import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Order {
    id: number;
    number: string;
    game: string;
    package: string;
    price: number;
    player_id: string;
    zone: string | null;
    player_name: string | null;
    phone: string;
    status: string;
    pay_method: string | null;
    pay_ref: string | null;
    fail_reason: string | null;
    refund: string;
    at: string;
    paid_at: string | null;
    late: boolean;
}

interface Game {
    id: number;
    name: string;
    id_label: string;
    needs_zone: boolean;
    zone_label: string | null;
    is_active: boolean;
    cover: string | null;
    packages: { id: number; name: string; price: number; is_active: boolean }[];
}

interface Props {
    month: string;
    canEdit: boolean;
    games: Game[];
    toCheck: Order[];
    toTopUp: Order[];
    failed: Order[];
    done: Order[];
    sales: { orders: number; sales: number; by_game: { game: string; orders: number; sales: number }[]; failed: number };
    pay: Record<string, string>;
    lateMinutes: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Game top-ups', href: '/admin/gaming' }];

function OrderRow({ o }: { o: Order }) {
    const { t, money, dateTime } = useI18n();
    const [note, setNote] = useState('');
    const act = (action: string, body: Record<string, string> = {}) =>
        router.post(`/admin/gaming/orders/${o.id}/${action}`, body, { preserveScroll: true });

    return (
        <li className="space-y-1 px-4 py-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {o.number} · {o.game} · {o.package} · <span className="tabular">{money(o.price)}</span>
                    {o.late && <span className="ms-2 rounded bg-danger/15 px-1.5 py-0.5 text-xs font-semibold text-danger">{t('Late')}</span>}
                </p>
                <span className="text-muted-foreground text-xs">{dateTime(o.paid_at ?? o.at)}</span>
            </div>
            <p className="tabular">
                {t('Player ID')}: <strong className="select-all">{o.player_id}</strong> {o.zone && `(${o.zone})`}{' '}
                {o.player_name && `· ${o.player_name}`} · {o.phone}
            </p>
            {o.pay_ref && (
                <p className="tabular text-muted-foreground text-xs">
                    {o.pay_method} {o.pay_ref}
                </p>
            )}
            {o.fail_reason && <p className="text-xs text-danger">{o.fail_reason}</p>}

            {o.status === 'claimed' && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" onClick={() => act('accept')}>
                        {t('The money is here')}
                    </Button>
                    <Input
                        aria-label={t('Why not')}
                        className="h-8 w-56"
                        placeholder={t('Why not (the buyer is told)')}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <Button size="sm" variant="outline" disabled={note.trim().length < 3} onClick={() => act('reject', { note })}>
                        {t('Not found')}
                    </Button>
                </div>
            )}
            {o.status === 'paid' && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        aria-label={t('Reference')}
                        className="h-8 w-40"
                        placeholder={t('Reference (optional)')}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <Button size="sm" onClick={() => act('done', { ref: note })}>
                        {t('I topped it up')}
                    </Button>
                    <Button size="sm" variant="destructive" disabled={note.trim().length < 3} onClick={() => act('fail', { note })}>
                        {t('It failed')}
                    </Button>
                    <span className="text-muted-foreground text-xs">{t('To mark it failed, write why in the box.')}</span>
                </div>
            )}
            {o.refund === 'due' && (
                <Button size="sm" onClick={() => act('refunded')}>
                    {t('I refunded the money')}
                </Button>
            )}
        </li>
    );
}

function GameBox({ g, canEdit }: { g: Game; canEdit: boolean }) {
    const { t, money } = useI18n();
    const [f, setF] = useState({
        name: g.name,
        id_label: g.id_label,
        needs_zone: g.needs_zone,
        zone_label: g.zone_label ?? '',
        is_active: g.is_active,
    });
    const [pk, setPk] = useState({ name: '', price: '' });

    return (
        <li className="space-y-2 px-4 py-3 text-sm">
            {canEdit ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Input aria-label={t('Name')} className="h-8 w-40" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
                    <Input
                        aria-label={t('Label of the ID')}
                        className="h-8 w-32"
                        value={f.id_label}
                        onChange={(e) => setF({ ...f, id_label: e.target.value })}
                    />
                    <label className="flex items-center gap-1 text-xs">
                        <input type="checkbox" checked={f.needs_zone} onChange={(e) => setF({ ...f, needs_zone: e.target.checked })} />{' '}
                        {t('Needs a zone')}
                    </label>
                    <label className="flex items-center gap-1 text-xs">
                        <input type="checkbox" checked={f.is_active} onChange={(e) => setF({ ...f, is_active: e.target.checked })} /> {t('Shown')}
                    </label>
                    <Button size="sm" variant="outline" onClick={() => router.put(`/admin/gaming/games/${g.id}`, f, { preserveScroll: true })}>
                        {t('Save')}
                    </Button>
                </div>
            ) : (
                <p className="font-medium">{g.name}</p>
            )}
            {canEdit && (
                <div className="flex flex-wrap items-center gap-3">
                    {g.cover ? (
                        <img src={g.cover} alt="" className="h-16 w-12 rounded-md border object-cover" />
                    ) : (
                        <span className="text-muted-foreground grid h-16 w-12 place-items-center rounded-md border text-xs">{t('No cover')}</span>
                    )}
                    <label className="text-xs">
                        <span className="sr-only">{t('Upload a cover')}</span>
                        <input
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            aria-label={t('Upload a cover')}
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                if (file)
                                    router.post(`/admin/gaming/games/${g.id}/cover`, { cover: file }, { forceFormData: true, preserveScroll: true });
                            }}
                        />
                    </label>
                    {g.cover && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => router.delete(`/admin/gaming/games/${g.id}/cover`, { preserveScroll: true })}
                        >
                            {t('Remove cover')}
                        </Button>
                    )}
                    <span className="text-muted-foreground text-xs">{t('Use artwork you have the right to use. PNG, JPG or WebP, up to 4 MB.')}</span>
                </div>
            )}

            <ul className="space-y-1">
                {g.packages.map((p) => (
                    <PackageRow key={`${p.id}-${p.price}-${p.is_active}`} p={p} canEdit={canEdit} />
                ))}
            </ul>
            {canEdit && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        aria-label={t('Package')}
                        className="h-8 w-40"
                        placeholder={t('100 Diamonds')}
                        value={pk.name}
                        onChange={(e) => setPk({ ...pk, name: e.target.value })}
                    />
                    <Input
                        aria-label={t('Price')}
                        className="h-8 w-24"
                        inputMode="decimal"
                        placeholder={t('Price')}
                        value={pk.price}
                        onChange={(e) => setPk({ ...pk, price: e.target.value })}
                    />
                    <Button
                        size="sm"
                        disabled={pk.name.trim().length < 2 || Number(pk.price) <= 0}
                        onClick={() =>
                            router.post(`/admin/gaming/games/${g.id}/packages`, pk, {
                                preserveScroll: true,
                                onSuccess: () => setPk({ name: '', price: '' }),
                            })
                        }
                    >
                        {t('Add a package')}
                    </Button>
                </div>
            )}
            <p className="sr-only">{money(0)}</p>
        </li>
    );
}

function PackageRow({ p, canEdit }: { p: Game['packages'][number]; canEdit: boolean }) {
    const { t, money } = useI18n();
    const [f, setF] = useState({ name: p.name, price: String(p.price), is_active: p.is_active });

    return canEdit ? (
        <li className="flex flex-wrap items-center gap-2">
            <Input aria-label={t('Package')} className="h-8 w-40" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
            <Input
                aria-label={t('Price')}
                className="h-8 w-24"
                inputMode="decimal"
                value={f.price}
                onChange={(e) => setF({ ...f, price: e.target.value })}
            />
            <label className="flex items-center gap-1 text-xs">
                <input type="checkbox" checked={f.is_active} onChange={(e) => setF({ ...f, is_active: e.target.checked })} /> {t('Shown')}
            </label>
            <Button size="sm" variant="outline" onClick={() => router.put(`/admin/gaming/packages/${p.id}`, f, { preserveScroll: true })}>
                {t('Save')}
            </Button>
        </li>
    ) : (
        <li className="tabular">
            {p.name} · {money(p.price)}
        </li>
    );
}

export default function Gaming({ month, canEdit, games, toCheck, toTopUp, failed, done, sales, pay, lateMinutes }: Props) {
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [fresh, setFresh] = useState({ name: '', id_label: 'Player ID' });
    const list = (title: string, rows: Order[], empty: string) => (
        <section className="space-y-1">
            <h2 className="font-medium">
                {title} ({num(rows.length)})
            </h2>
            {rows.length === 0 ? (
                <p className="text-muted-foreground rounded-md border border-dashed p-5 text-center text-sm">{empty}</p>
            ) : (
                <ul className="bg-card divide-y rounded-md border">
                    {rows.map((o) => (
                        <OrderRow key={`${o.id}-${o.status}-${o.refund}`} o={o} />
                    ))}
                </ul>
            )}
        </section>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Game top-ups')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-display text-2xl font-extrabold">{t('Game top-ups')}</h1>
                        <p className="text-muted-foreground text-sm">
                            {t(
                                'Online payment only. Until a provider does it for us, check each payment and top up by hand. A paid top-up older than {n} minutes is late.',
                                { n: num(lateMinutes) },
                            )}
                        </p>
                        {Object.keys(pay).length === 0 && (
                            <p className="text-sm font-semibold text-danger">
                                {t('No payment numbers are set. Add them in Settings, under Game top-up.')}
                            </p>
                        )}
                    </div>
                    <label className="text-sm">
                        {t('Month')}
                        <input
                            type="month"
                            value={month}
                            onChange={(e) => router.get('/admin/gaming', { month: e.target.value }, { preserveState: true })}
                            className="bg-background ms-2 h-9 rounded-md border px-2"
                        />
                    </label>
                </div>
                <Flash />
                <InputError message={errors.gaming} />

                <section className="grid gap-3 sm:grid-cols-3">
                    <div className="bg-card rounded-xl border p-3">
                        <p className="text-muted-foreground text-xs">{t('Topped up this month')}</p>
                        <p className="tabular font-display text-xl font-bold">{num(sales.orders)}</p>
                    </div>
                    <div className="bg-card rounded-xl border p-3">
                        <p className="text-muted-foreground text-xs">{t('Sold this month')}</p>
                        <p className="tabular font-display text-xl font-bold">{money(sales.sales)}</p>
                    </div>
                    <div className="bg-card rounded-xl border p-3">
                        <p className="text-muted-foreground text-xs">{t('Failed in all')}</p>
                        <p className="tabular font-display text-xl font-bold">{num(sales.failed)}</p>
                    </div>
                    {sales.by_game.length > 0 && (
                        <ul className="tabular text-sm sm:col-span-3">
                            {sales.by_game.map((g) => (
                                <li key={g.game}>
                                    {g.game}: {num(g.orders)} · {money(g.sales)}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {list(t('Payments to check'), toCheck, t('No payments waiting.'))}
                {list(t('Paid: to top up'), toTopUp, t('Nothing waiting for a top-up.'))}
                {list(t('Failed and refunds'), failed, t('Nothing failed.'))}
                {list(t('Done'), done, t('Nothing done yet.'))}

                <section className="space-y-2">
                    <h2 className="font-medium">{t('Games and packages')}</h2>
                    {games.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-5 text-center text-sm">{t('No games yet.')}</p>
                    ) : (
                        <ul className="bg-card divide-y rounded-md border">
                            {games.map((g) => (
                                <GameBox key={`${g.id}-${g.name}-${g.is_active}`} g={g} canEdit={canEdit} />
                            ))}
                        </ul>
                    )}
                    {canEdit && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Input
                                aria-label={t('Name')}
                                className="h-8 w-48"
                                placeholder={t('New game')}
                                value={fresh.name}
                                onChange={(e) => setFresh({ ...fresh, name: e.target.value })}
                            />
                            <Input
                                aria-label={t('Label of the ID')}
                                className="h-8 w-40"
                                value={fresh.id_label}
                                onChange={(e) => setFresh({ ...fresh, id_label: e.target.value })}
                            />
                            <Button
                                size="sm"
                                disabled={fresh.name.trim().length < 2}
                                onClick={() =>
                                    router.post('/admin/gaming/games', fresh, {
                                        preserveScroll: true,
                                        onSuccess: () => setFresh({ ...fresh, name: '' }),
                                    })
                                }
                            >
                                {t('Add a game')}
                            </Button>
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
