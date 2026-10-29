import Flash from '@/components/admin/flash';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';

interface Totals {
    memos: number;
    sales: number;
    average: number;
    profit: number;
}

interface Row {
    id: number;
    name: string;
    strength: string | null;
    company: string | null;
    units: number;
    revenue: number;
    profit: number;
    per_day: number;
    ideal_level: number;
    on_hand: number;
    order: number;
    advice: 'more' | 'less' | null;
}

interface Props {
    month: string;
    totals: Totals;
    previous: Totals;
    lastYear: Totals;
    sectors: { kind: string; channel: string; memos: number; total: number }[];
    departments: { key: string; name: string; orders: number; total: number }[];
    days: {
        day: string;
        memos: number;
        sales: number;
        discount: number;
        credit: number;
        cash: number;
        due: number;
        profit: number;
        returns: number;
    }[];
    ordering: Row[];
    byProfit: Row[];
    slow: Row[];
    notMoving: { name: string; strength: string | null; units: number; value: number }[];
    companies: { company: string | null; units: number; revenue: number; profit: number }[];
    customers: { id: number; name: string | null; phone: string | null; area: string | null; memos: number; total: number; profit: number }[];
    inactive: { name: string | null; phone: string | null; last: string; days: number; total: number; memos: number }[];
    season: { id: number; name: string; strength: string | null; units: number; then_per_day: number; now_per_day: number; on_hand: number; rise: number | null; order: number }[];
    losses: { below_cost: number; expired_not_taken: number };
    range: { from: string; to: string };
    prepared_at: string;
    startDay: number;
    corrected: { count: number; difference: number; items: { number: string | null; replaces: string | null; was: number; now: number; when: string }[] };
    tonight: Totals & { cash_expected: number; open_drafts: number; returns: number; finished: number; low_stock: number; pending_approvals: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reports', href: '/admin/reports' }];

function Change({ now, before }: { now: number; before: number }) {
    const { t, num } = useI18n();
    if (before === 0) return <span className="text-muted-foreground text-xs">{t('no data')}</span>;
    const pct = ((now - before) / before) * 100;

    return (
        <span
            className={`text-xs font-semibold ${pct >= 0 ? 'text-success' : 'text-danger'}`}
        >{`${pct >= 0 ? '▲' : '▼'} ${num(Math.abs(Math.round(pct)))}%`}</span>
    );
}

function Block({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="space-y-2">
            <h2 className="font-display text-lg font-bold">{title}</h2>
            {children}
        </section>
    );
}

const kindName = (k: string) => (k === 'paikari' ? 'Shop' : k === 'wholesale' ? 'Wholesale' : 'Retail');

export default function MonthlyReport(p: Props) {
    const { t, num, money, date, dateTime } = useI18n();
    const name = (r: { name: string | null; strength?: string | null }) => `${r.name ?? ''} ${r.strength ?? ''}`.trim();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Reports')} />
            <div className="mx-auto w-full max-w-6xl space-y-8 p-4">
                <Flash />
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="font-display text-2xl font-extrabold">{t('Monthly report')}</h1>
                    <div className="flex items-center gap-2">
                        <Input
                            type="month"
                            className="w-40"
                            value={p.month}
                            onChange={(e) => router.get('/admin/reports', { month: e.target.value }, { preserveState: false })}
                        />
                        <Button asChild variant="outline">
                            <a href={`/admin/reports/export?month=${p.month}`}>
                                <Download className="size-4" /> {t('Download the sales (Excel)')}
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                    <span>{t('{from} to {to}', { from: date(p.range.from), to: date(p.range.to) })}</span>
                    <span>{t('Prepared {when}', { when: dateTime(p.prepared_at) })}</span>
                    <Button size="sm" variant="outline" onClick={() => router.post(`/admin/reports/prepare?month=${p.month}`, {}, { preserveScroll: true })}>
                        {t('Prepare it again now')}
                    </Button>
                </div>

                {p.corrected.count > 0 && (
                    <section aria-labelledby="corrected-h" className="rounded-md border p-4">
                        <h2 id="corrected-h" className="mb-1 font-medium">
                            {t('Corrected memos')}
                        </h2>
                        <p className="text-muted-foreground mb-2 text-sm">
                            {t('{n} memos were corrected in this period. The old memo is cancelled and the new one counts, so the totals above already use the new amounts.', { n: num(p.corrected.count) })}
                        </p>
                        <ul className="divide-y text-sm">
                            {p.corrected.items.map((c) => (
                                <li key={`${c.number}-${c.when}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-1.5">
                                    <span className="w-24 font-mono text-xs">{c.number}</span>
                                    <span className="text-muted-foreground text-xs">{t('replaces {memo}', { memo: c.replaces ?? '-' })}</span>
                                    <span className="tabular-nums ms-auto">{t('{was} to {now}', { was: money(c.was), now: money(c.now) })}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {(p.losses.below_cost > 0 || p.losses.expired_not_taken > 0) && (
                    <section aria-labelledby="losses-h" className="border-destructive/40 bg-destructive/5 rounded-md border p-4">
                        <h2 id="losses-h" className="text-destructive mb-2 font-medium">
                            {t('Money lost')}
                        </h2>
                        <dl className="grid gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground">{t('Sold below cost this month')}</dt>
                                <dd className="font-medium tabular-nums">{money(p.losses.below_cost)}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">{t('Expired goods the company would not take back')}</dt>
                                <dd className="font-medium tabular-nums">{money(p.losses.expired_not_taken)}</dd>
                            </div>
                        </dl>
                    </section>
                )}

                <section aria-labelledby="tonight-h" className="bg-muted/30 rounded-md border p-4">
                    <h2 id="tonight-h" className="mb-2 font-medium">
                        {t('Tonight’s summary')}
                    </h2>
                    <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        {[
                            [t('Sales today'), money(p.tonight.sales)],
                            [t('Profit today'), money(p.tonight.profit)],
                            [t('Cash in the drawer'), money(p.tonight.cash_expected)],
                            [t('Open drafts'), num(p.tonight.open_drafts)],
                            [t('Finished today'), num(p.tonight.finished)],
                            [t('Low stock'), num(p.tonight.low_stock)],
                            [t('Returns today'), num(p.tonight.returns)],
                            [t('Approvals waiting'), num(p.tonight.pending_approvals)],
                        ].map(([k, v]) => (
                            <div key={k}>
                                <dt className="text-muted-foreground text-xs">{k}</dt>
                                <dd className="text-lg font-bold tabular-nums">{v}</dd>
                            </div>
                        ))}
                    </dl>
                </section>

                <Block title={t('This month against before')}>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="py-1 font-normal" />
                                    <th className="py-1 text-right font-normal">{t('Memos')}</th>
                                    <th className="py-1 text-right font-normal">{t('Sales')}</th>
                                    <th className="py-1 text-right font-normal">{t('Average sale')}</th>
                                    <th className="py-1 text-right font-normal">{t('Profit')}</th>
                                </tr>
                            </thead>
                            <tbody className="tabular-nums">
                                {[
                                    [t('This month'), p.totals, null],
                                    [t('Last month'), p.previous, p.totals],
                                    [t('Same month last year'), p.lastYear, p.totals],
                                ].map(([label, tt, cmp]) => {
                                    const x = tt as Totals;
                                    const c = cmp as Totals | null;
                                    return (
                                        <tr key={label as string} className="border-t">
                                            <td className="py-2 font-medium">{label as string}</td>
                                            <td className="py-2 text-right">{num(x.memos)}</td>
                                            <td className="py-2 text-right">
                                                {money(x.sales)} {c && <Change now={c.sales} before={x.sales} />}
                                            </td>
                                            <td className="py-2 text-right">{money(x.average)}</td>
                                            <td className="py-2 text-right">
                                                {money(x.profit)} {c && <Change now={c.profit} before={x.profit} />}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        {t('Profit counts what came off the stock. A sale that took nothing from stock has no cost to compare.')}
                    </p>
                </Block>

                {/* A report prepared before departments existed has none: it simply has no such block. */}
                {(p.departments ?? []).length > 0 && (
                <Block title={t('By department')}>
                    <ul className="divide-y rounded-md border text-sm">
                        {p.departments.map((d) => (
                            <li key={d.key} className="flex justify-between px-3 py-2">
                                <span>{t(d.name)}</span>
                                <span className="tabular-nums">
                                    {num(d.orders)} · {money(d.total)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Block>
                )}

                {p.sectors.length > 0 && (
                    <Block title={t('By kind of sale')}>
                        <ul className="divide-y rounded-md border text-sm">
                            {p.sectors.map((s) => (
                                <li key={s.kind + s.channel} className="flex justify-between px-3 py-2">
                                    <span>
                                        {t(kindName(s.kind))} · {t(s.channel === 'online' ? 'Online' : 'Counter')}
                                    </span>
                                    <span className="tabular-nums">
                                        {num(s.memos)} · {money(s.total)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Block>
                )}

                <Block title={t('What to order')}>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="py-1 font-normal">{t('Medicine')}</th>
                                    <th className="py-1 text-right font-normal">{t('Sold')}</th>
                                    <th className="py-1 text-right font-normal">{t('Per day')}</th>
                                    <th className="py-1 text-right font-normal">{t('Should have')}</th>
                                    <th className="py-1 text-right font-normal">{t('Have')}</th>
                                    <th className="py-1 text-right font-normal">{t('Order')}</th>
                                    <th className="py-1 font-normal" />
                                </tr>
                            </thead>
                            <tbody className="tabular-nums">
                                {p.ordering.map((r) => (
                                    <tr key={r.id} className="border-t">
                                        <td className="py-1.5">
                                            {r.name} <span className="text-muted-foreground">{r.strength}</span>
                                        </td>
                                        <td className="py-1.5 text-right">{num(r.units)}</td>
                                        <td className="py-1.5 text-right">{num(r.per_day)}</td>
                                        <td className="py-1.5 text-right">{num(r.ideal_level)}</td>
                                        <td className="py-1.5 text-right">{num(r.on_hand)}</td>
                                        <td className="py-1.5 text-right font-semibold">{r.order > 0 ? num(r.order) : ''}</td>
                                        <td className="py-1.5 pl-3 text-xs font-semibold">
                                            {r.advice === 'more' && <span className="text-warning">{t('Take more')}</span>}
                                            {r.advice === 'less' && <span className="text-info">{t('Take less, it is sitting')}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Block>

                <div className="grid gap-6 md:grid-cols-2">
                    <Block title={t('Earns the most')}>
                        <ul className="divide-y rounded-md border text-sm">
                            {p.byProfit.map((r) => (
                                <li key={r.id} className="flex justify-between px-3 py-1.5">
                                    <span>{name(r)}</span>
                                    <span className="tabular-nums">{money(r.profit)}</span>
                                </li>
                            ))}
                        </ul>
                    </Block>
                    <Block title={t('Companies that move most')}>
                        <ul className="divide-y rounded-md border text-sm">
                            {p.companies.map((c, i) => (
                                <li key={i} className="flex justify-between px-3 py-1.5">
                                    <span>{c.company}</span>
                                    <span className="tabular-nums">{money(c.revenue)}</span>
                                </li>
                            ))}
                        </ul>
                    </Block>
                    <Block title={t('Last year this sold a lot at this time')}>
                        {p.season.length === 0 ? (
                            <p className="text-muted-foreground rounded-md border border-dashed p-4 text-center text-sm">{t('Nothing special is coming from last year\'s sales.')}</p>
                        ) : (
                            <ul className="divide-y rounded-md border text-sm">
                                {p.season.map((r) => (
                                    <li key={r.id} className="flex flex-wrap items-baseline justify-between gap-x-3 px-3 py-1.5">
                                        <span>{name(r)}</span>
                                        <span className="text-muted-foreground tabular-nums text-xs">
                                            {t('last year {a} a day, now {b}', { a: num(r.then_per_day), b: num(r.now_per_day) })} · {t('Have')} {num(r.on_hand)}
                                        </span>
                                        {r.order > 0 && <span className="font-semibold tabular-nums">{t('Order')} {num(r.order)}</span>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Block>
                    <Block title={t('Sitting: nothing sold this month')}>
                        <ul className="divide-y rounded-md border text-sm">
                            {p.notMoving.map((r, i) => (
                                <li key={i} className="flex justify-between px-3 py-1.5">
                                    <span>{name(r)}</span>
                                    <span className="tabular-nums">
                                        {num(r.units)} · {money(r.value)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Block>
                    <Block title={t('Best customers')}>
                        <ul className="divide-y rounded-md border text-sm">
                            {p.customers.map((c) => (
                                <li key={c.id} className="flex justify-between gap-3 px-3 py-1.5">
                                    <span>
                                        {c.name} <span className="text-muted-foreground">{c.area}</span>
                                    </span>
                                    <span className="tabular-nums">
                                        {money(c.total)} · {t('we made {amount}', { amount: money(c.profit) })}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Block>
                </div>

                <Block title={t('Shops that have gone quiet')}>
                    {p.inactive.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('Nobody has stopped buying. Everyone bought within the last 30 days.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border text-sm">
                            {p.inactive.map((c, i) => (
                                <li key={i} className="flex flex-wrap justify-between gap-3 px-3 py-1.5">
                                    <span>
                                        {c.name} <span className="text-muted-foreground">{c.phone}</span>
                                    </span>
                                    <span className="tabular-nums">{t('last bought {d} days ago · {amount} so far', { d: num(c.days), amount: money(c.total) })}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Block>

                <Block title={t('Day by day')}>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="py-1 font-normal">{t('Date')}</th>
                                    <th className="py-1 text-right font-normal">{t('Memos')}</th>
                                    <th className="py-1 text-right font-normal">{t('Sales')}</th>
                                    <th className="py-1 text-right font-normal">{t('Cash')}</th>
                                    <th className="py-1 text-right font-normal">{t('Due')}</th>
                                    <th className="py-1 text-right font-normal">{t('Discount')}</th>
                                    <th className="py-1 text-right font-normal">{t('Return product')}</th>
                                    <th className="py-1 text-right font-normal">{t('Returns')}</th>
                                    <th className="py-1 text-right font-normal">{t('Profit')}</th>
                                </tr>
                            </thead>
                            <tbody className="tabular-nums">
                                {p.days.map((d) => (
                                    <tr key={d.day} className="border-t">
                                        <td className="py-1.5">{date(d.day)}</td>
                                        <td className="py-1.5 text-right">{num(d.memos)}</td>
                                        <td className="py-1.5 text-right">{money(d.sales)}</td>
                                        <td className="py-1.5 text-right">{money(d.cash)}</td>
                                        <td className="py-1.5 text-right">{money(d.due)}</td>
                                        <td className="py-1.5 text-right">{money(d.discount)}</td>
                                        <td className="py-1.5 text-right">{money(d.credit)}</td>
                                        <td className="py-1.5 text-right">{money(d.returns)}</td>
                                        <td className="py-1.5 text-right font-semibold">{money(d.profit)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Block>
            </div>
        </AppLayout>
    );
}
