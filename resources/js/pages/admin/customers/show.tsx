import Flash from '@/components/admin/flash';
import MedicinePicker, { type PickedMedicine } from '@/components/admin/medicine-picker';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Customer {
    id: number;
    area?: string | null;
    name: string;
    phone: string | null;
    email: string | null;
    account_type: string;
    tier_id: number | null;
    business_name: string | null;
    categories: string[];
    moq: number | null;
    pay_cod: boolean;
    pay_advance: boolean;
    suspended_at: string | null;
    suspended_until?: string | null;
    show_monthly_total: boolean;
    late_cancellations: number;
    flagged: boolean;
    created_at: string | null;
}
interface Tier {
    id: number;
    name: string;
    discount_percent: string;
}
interface ManualRate {
    id: number;
    medicine_id: number;
    discount_percent: string | null;
    fixed_price: string | null;
    brand_name: string;
    strength: string | null;
}
interface TierChange {
    id: number;
    from_tier_id: number | null;
    to_tier_id: number | null;
    created_at: string;
}
interface Activity {
    id: number;
    action: string;
    changes: Record<string, unknown> | null;
    created_at: string;
}
interface Login {
    id: number;
    outcome: string;
    ip: string;
    is_suspicious: boolean;
    reason: string | null;
    created_at: string;
}

interface Props {
    customer: Customer;
    tiers: Tier[];
    manualRates: ManualRate[];
    tierHistory: TierChange[];
    activity: Activity[];
    logins: Login[];
    categoryOptions?: { key: string; label: string }[];
    transactions?: { type: string; id: number; when: string; title: string; status: string; amount: number | null; link: string | null; detail: string }[];
    offersTaken?: { name: string; boxes: number; price: number; memo: string | null; status: string }[];
}

function Section({ title, hint, children }: { title: string; hint?: string; children: React.ReactNode }) {
    return (
        <section className="space-y-3 rounded-lg border bg-card p-4">
            <div>
                <h2 className="font-display text-lg font-extrabold">{title}</h2>
                {hint && <p className="text-sm text-muted-foreground">{hint}</p>}
            </div>
            {children}
        </section>
    );
}

function TierSection({ customer, tiers }: { customer: Customer; tiers: Tier[] }) {
    const { t, num } = useI18n();
    const form = useForm({ tier_id: customer.tier_id ? String(customer.tier_id) : '' });

    return (
        <Section title={t('Tier')} hint={t('Sets the default discount. Manual rates below are never changed by a tier move.')}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({ tier_id: d.tier_id === '' ? null : Number(d.tier_id) }));
                    form.patch(`/admin/customers/${customer.id}/tier`, { preserveScroll: true });
                }}
                className="flex flex-wrap items-center gap-2"
            >
                <label htmlFor="tier" className="sr-only">
                    {t('Tier')}
                </label>
                <select
                    id="tier"
                    value={form.data.tier_id}
                    onChange={(e) => form.setData('tier_id', e.target.value)}
                    className="h-9 min-w-44 rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    <option value="">{t('No tier')}</option>
                    {tiers.map((tier) => (
                        <option key={tier.id} value={tier.id}>
                            {tier.name} ({num(Number(tier.discount_percent))}%)
                        </option>
                    ))}
                </select>
                <Button type="submit" disabled={form.processing || form.data.tier_id === (customer.tier_id ? String(customer.tier_id) : '')}>
                    {t('Save tier')}
                </Button>
                <InputError message={form.errors.tier_id} />
            </form>
        </Section>
    );
}

function TradeSection({ customer, options }: { customer: Customer; options: { key: string; label: string }[] }) {
    const { t } = useI18n();
    const form = useForm({
        account_type: customer.account_type,
        business_name: customer.business_name ?? '',
        categories: customer.categories,
        moq: customer.moq === null ? '' : String(customer.moq),
        pay_cod: customer.pay_cod,
        pay_advance: customer.pay_advance,
    });
    const bulk = form.data.account_type === 'wholesaler';
    const errors = form.errors as Record<string, string>;

    return (
        <Section title={t('Account type and terms')} hint={t('A bulk buyer sees only the categories you give them, and has their own minimum and ways to pay.')}>
            <form
                className="space-y-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((d) => ({ ...d, moq: d.moq === '' ? null : d.moq }));
                    form.put(`/admin/customers/${customer.id}/trade`, { preserveScroll: true });
                }}
            >
                <div className="flex flex-wrap items-center gap-2">
                    <label htmlFor="atype" className="sr-only">
                        {t('Account type')}
                    </label>
                    <select
                        id="atype"
                        value={form.data.account_type}
                        onChange={(e) => form.setData('account_type', e.target.value)}
                        className="h-9 min-w-44 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="public">{t('Public')}</option>
                        <option value="paikari">{t('Shop buyer')}</option>
                        <option value="wholesaler">{t('Bulk buyer')}</option>
                    </select>
                    <Input aria-label={t('Business name')} className="h-9 w-64" placeholder={t('Business name')} value={form.data.business_name} onChange={(e) => form.setData('business_name', e.target.value)} />
                </div>
                {bulk && (
                    <>
                        <fieldset>
                            <legend className="text-sm">{t('Categories they can see')}</legend>
                            <div className="mt-1 flex flex-wrap gap-4 text-sm">
                                {options.map((c) => (
                                    <label key={c.key} className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={form.data.categories.includes(c.key)}
                                            onChange={() =>
                                                form.setData('categories', form.data.categories.includes(c.key) ? form.data.categories.filter((x) => x !== c.key) : [...form.data.categories, c.key])
                                            }
                                        />
                                        {t(c.label)}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <div className="flex flex-wrap items-end gap-4 text-sm">
                            <label>
                                {t('Minimum per item')}
                                <Input className="mt-1 h-9 w-32" type="number" min="0" step="any" placeholder={t('Shop default')} value={form.data.moq} onChange={(e) => form.setData('moq', e.target.value)} />
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={form.data.pay_cod} onChange={(e) => form.setData('pay_cod', e.target.checked)} />
                                {t('Pay when it arrives')}
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={form.data.pay_advance} onChange={(e) => form.setData('pay_advance', e.target.checked)} />
                                {t('Pay in advance')}
                            </label>
                        </div>
                        <InputError message={errors.pay_cod} />
                    </>
                )}
                <Button type="submit" disabled={form.processing}>
                    {t('Save')}
                </Button>
            </form>
        </Section>
    );
}

function RatesSection({ customer, rates }: { customer: Customer; rates: ManualRate[] }) {
    const { t, num, money } = useI18n();
    const [medicine, setMedicine] = useState<PickedMedicine | null>(null);
    const [mode, setMode] = useState<'percent' | 'price'>('percent');
    const form = useForm({ medicine_id: '', discount_percent: '', fixed_price: '' });
    const [forceLoss, setForceLoss] = useState(false);
    const lossError = (form.errors as Record<string, string>).loss;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            medicine_id: medicine?.id,
            discount_percent: mode === 'percent' ? d.discount_percent : null,
            fixed_price: mode === 'price' ? d.fixed_price : null,
            force_loss: forceLoss,
        }));
        form.put(`/admin/customers/${customer.id}/rates`, {
            preserveScroll: true,
            onSuccess: () => {
                setMedicine(null);
                setForceLoss(false);
                form.reset();
            },
        });
    };

    const remove = (rate: ManualRate) => {
        if (window.confirm(t('Remove the manual rate for {name}?', { name: rate.brand_name }))) {
            router.delete(`/admin/customers/${customer.id}/rates/${rate.id}`, { preserveScroll: true });
        }
    };

    return (
        <Section title={t('Manual rates')} hint={t('A price set by hand for this customer on one medicine. Highest priority; overrides tier, company and medicine rates.')}>
            {rates.length === 0 ? (
                <p className="text-sm text-muted-foreground">{t('No manual rates. This customer pays their tier rate.')}</p>
            ) : (
                <ul className="divide-y rounded-md border">
                    {rates.map((r) => (
                        <li key={r.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span>
                                <strong>{r.brand_name}</strong> {r.strength}
                            </span>
                            <span className="ml-auto font-semibold tabular-nums">{r.discount_percent !== null ? t('{n}% off', { n: num(Number(r.discount_percent)) }) : money(r.fixed_price ?? 0)}</span>
                            <Button variant="ghost" size="sm" onClick={() => remove(r)} aria-label={t('Remove manual rate for {name}', { name: r.brand_name })}>
                                {t('Remove')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            <form onSubmit={submit} className="space-y-2 border-t pt-3">
                <p className="text-sm font-medium">{t('Add or change a rate')}</p>
                <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                    <MedicinePicker picked={medicine} onPick={setMedicine} />
                    <div className="flex rounded-md border p-0.5" role="group" aria-label={t('Rate type')}>
                        {(['percent', 'price'] as const).map((m) => (
                            <button key={m} type="button" aria-pressed={mode === m} onClick={() => setMode(m)} className={`rounded px-3 text-sm ${mode === m ? 'bg-foreground text-background' : 'text-muted-foreground'}`}>
                                {m === 'percent' ? t('Percent off') : t('Taka price per piece')}
                            </button>
                        ))}
                    </div>
                    <div>
                        <label htmlFor="rate-value" className="sr-only">
                            {mode === 'percent' ? t('Percent off') : t('Price in taka for one piece')}
                        </label>
                        <Input
                            id="rate-value"
                            type="number"
                            step="0.01"
                            min="0"
                            max={mode === 'percent' ? 100 : undefined}
                            placeholder={mode === 'percent' ? '25' : '90.00'}
                            value={mode === 'percent' ? form.data.discount_percent : form.data.fixed_price}
                            onChange={(e) => form.setData(mode === 'percent' ? 'discount_percent' : 'fixed_price', e.target.value)}
                            className="w-32"
                        />
                    </div>
                </div>
                <InputError message={form.errors.medicine_id || form.errors.discount_percent || form.errors.fixed_price} />
                {lossError && (
                    <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
                        <p className="font-medium text-destructive">{lossError}</p>
                        <label className="mt-2 flex items-center gap-2">
                            <input type="checkbox" checked={forceLoss} onChange={(e) => setForceLoss(e.target.checked)} />
                            {t('Sell at a loss anyway. It will be flagged in the reports.')}
                        </label>
                    </div>
                )}
                <Button type="submit" disabled={form.processing || !medicine}>
                    {t('Save rate')}
                </Button>
            </form>
        </Section>
    );
}

function StatusSection({ customer }: { customer: Customer }) {
    const { t, dateTime } = useI18n();
    const form = useForm({ reason: '', days: '' });
    // A suspension for a set number of days lifts itself when the time is up.
    const suspended = customer.suspended_at !== null && (!customer.suspended_until || new Date(customer.suspended_until) > new Date());

    if (suspended) {
        return (
            <Section title={t('Account status')} hint={customer.suspended_until ? t('Suspended since {date}, until {until}.', { date: dateTime(customer.suspended_at!), until: dateTime(customer.suspended_until) }) : t('Suspended since {date}.', { date: dateTime(customer.suspended_at!) })}>
                <Button variant="outline" onClick={() => router.delete(`/admin/customers/${customer.id}/suspend`, { preserveScroll: true })}>
                    {t('Reactivate account')}
                </Button>
            </Section>
        );
    }

    return (
        <Section title={t('Account status')} hint={t('Suspending stops this customer using the platform. Their invoices and history stay.')}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/admin/customers/${customer.id}/suspend`, { preserveScroll: true, onSuccess: () => form.reset() });
                }}
                className="flex flex-wrap items-start gap-2"
            >
                <div>
                    <label htmlFor="reason" className="sr-only">
                        {t('Reason')}
                    </label>
                    <Input id="reason" placeholder={t('Reason (required)')} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className="w-72" />
                    <InputError message={form.errors.reason} />
                </div>
                <div>
                    <label htmlFor="suspend-days" className="sr-only">
                        {t('For how many days')}
                    </label>
                    <Input id="suspend-days" type="number" min="1" max="365" placeholder={t('Days (empty: until you reopen it)')} value={form.data.days} onChange={(e) => form.setData('days', e.target.value)} className="w-56" />
                    <InputError message={form.errors.days} />
                </div>
                <Button type="submit" variant="destructive" disabled={form.processing || form.data.reason.trim() === ''}>
                    {t('Suspend account')}
                </Button>
            </form>
        </Section>
    );
}

function Transactions({ items }: { items: NonNullable<Props['transactions']> }) {
    const { t, money, dateTime } = useI18n();
    const kind = (x: string) => t(x === 'memo' ? 'Memo' : x === 'return' ? 'Return' : x === 'preorder' ? 'Pre-order' : 'Credit');

    return (
        <Section title={t('All transactions')} hint={t('Orders, returns, pre-orders and credit together, newest first.')}>
            {items.length === 0 ? (
                <p className="text-sm text-muted-foreground">{t('Nothing recorded yet.')}</p>
            ) : (
                <ul className="divide-y rounded-md border text-sm">
                    {items.map((x) => (
                        <li key={`${x.type}-${x.id}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2">
                            <span className="w-20 text-xs font-semibold">{kind(x.type)}</span>
                            <span className="w-36 text-xs text-muted-foreground">{dateTime(x.when)}</span>
                            {x.link ? (
                                <Link href={x.link} className="min-w-28 flex-1 underline underline-offset-2">
                                    {x.title}
                                </Link>
                            ) : (
                                <span className="min-w-28 flex-1">{x.title}</span>
                            )}
                            <span className="w-28 text-xs text-muted-foreground">{x.detail}</span>
                            <span className="w-24 text-xs">{x.status}</span>
                            <span className="tabular w-24 text-right">{x.amount === null ? '' : money(x.amount)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

export default function CustomerShow({ customer, tiers, manualRates, tierHistory, activity, logins, offersTaken, categoryOptions = [], transactions = [] }: Props) {
    const { t, dateTime, money } = useI18n();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Customers', href: '/admin/customers' },
        { title: customer.name, href: `/admin/customers/${customer.id}` },
    ];
    const tierName = (id: number | null) => tiers.find((x) => x.id === id)?.name ?? t('No tier');

    const types: Record<string, string> = { public: t('Public'), paikari: t('Paikari'), wholesaler: t('Wholesale') };

    const actionLabel: Record<string, string> = {
        'customer.tier_changed': t('Tier changed'),
        'customer.suspended': t('Account suspended'),
        'customer.reactivated': t('Account reactivated'),
        'customer.manual_rate_set': t('Manual rate saved'),
        'customer.manual_rate_removed': t('Manual rate removed'),
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={customer.name} />

            <div className="mx-auto w-full max-w-3xl space-y-5 p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h1 className="font-display text-3xl font-extrabold tracking-tight">{customer.name}</h1>
                        <p className="text-sm text-muted-foreground tabular-nums">{[customer.phone, customer.email].filter(Boolean).join(' · ') || t('No contact details')}</p>
                    </div>
                    <div className="flex gap-2">
                        <Badge variant="outline">{types[customer.account_type] ?? customer.account_type}</Badge>
                        {customer.suspended_at ? <Badge variant="destructive">{t('Suspended')}</Badge> : <Badge variant="secondary">{t('Active')}</Badge>}
                    </div>
                </div>

                <Flash />
                {customer.flagged && (
                    <p role="alert" className="rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm font-semibold">
                        {t('Flagged: {n} orders cancelled after they were checked, in the last 90 days.', { n: customer.late_cancellations })}
                    </p>
                )}

                <TierSection customer={customer} tiers={tiers} />
                <TradeSection customer={customer} options={categoryOptions} />
                <RatesSection customer={customer} rates={manualRates} />
                {customer.account_type === 'paikari' && (
                    <Section title={t('Monthly total')} hint={t('Show this customer their own total for the month on their order page.')}>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={customer.show_monthly_total} onChange={(e) => router.put(`/admin/customers/${customer.id}/monthly-total`, { show: e.target.checked }, { preserveScroll: true })} />
                            {t('Show the monthly total')}
                        </label>
                    </Section>
                )}
                {offersTaken && offersTaken.length > 0 && (
                    <Section title={t('Offers taken')} hint={t('Offers given at the counter or ordered online show here.')}>
                        <ul className="divide-y text-sm">
                            {offersTaken.map((o, i) => (
                                <li key={i} className="flex flex-wrap justify-between gap-2 py-1.5">
                                    <span>
                                        {o.name} · {o.boxes} {t('Box')}
                                    </span>
                                    <span className="tabular-nums">{money(o.price)} {o.memo && `· ${o.memo}`}</span>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}
                <StatusSection customer={customer} />

                <Transactions items={transactions} />

                <Section title={t('History')} hint={t('Most recent first.')}>
                    {tierHistory.length === 0 && activity.length === 0 && logins.length === 0 && <p className="text-sm text-muted-foreground">{t('Nothing recorded yet.')}</p>}

                    {tierHistory.length > 0 && (
                        <div>
                            <h3 className="mb-1 text-sm font-medium">{t('Tier changes')}</h3>
                            <ul className="space-y-1 text-sm">
                                {tierHistory.map((h) => (
                                    <li key={h.id}>
                                        {tierName(h.from_tier_id)} → {tierName(h.to_tier_id)} <span className="text-muted-foreground">· {dateTime(h.created_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {activity.length > 0 && (
                        <div>
                            <h3 className="mb-1 text-sm font-medium">{t('Actions')}</h3>
                            <ul className="space-y-1 text-sm">
                                {activity.map((a) => (
                                    <li key={a.id}>
                                        {actionLabel[a.action] ?? a.action} <span className="text-muted-foreground">· {dateTime(a.created_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {logins.length > 0 && (
                        <div>
                            <h3 className="mb-1 text-sm font-medium">{t('Recent logins')}</h3>
                            <ul className="space-y-1 text-sm">
                                {logins.map((l) => (
                                    <li key={l.id}>
                                        {l.outcome === 'success' ? t('Signed in from {ip}', { ip: l.ip }) : t('Failed attempt from {ip}', { ip: l.ip })}
                                        {l.is_suspicious && (
                                            <Badge variant="destructive" className="ml-2">
                                                {t('Suspicious')}
                                            </Badge>
                                        )}
                                        <span className="text-muted-foreground"> · {dateTime(l.created_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
