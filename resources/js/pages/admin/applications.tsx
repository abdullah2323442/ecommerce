import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    status: string;
    business_name: string;
    kind: string;
    categories: string[];
    person: string | null;
    email: string | null;
    phone: string;
    address: string;
    license_number: string | null;
    license_name: string | null;
    second_name: string | null;
    note: string | null;
    review_note: string | null;
    waiting_days: number;
    sent_at: string;
}

interface Props {
    pending: Row[];
    done: Row[];
    tiers: { id: number; name: string }[];
    categories: { key: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Applications', href: '/admin/applications' }];

function Review({ a, tiers, categories }: { a: Row; tiers: Props['tiers']; categories: Props['categories'] }) {
    const { t, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [type, setType] = useState(a.kind === 'trader' ? 'wholesaler' : 'paikari');
    const [tier, setTier] = useState('');
    const [cats, setCats] = useState<string[]>(a.categories);
    const [moq, setMoq] = useState('');
    const [cod, setCod] = useState(true);
    const [advance, setAdvance] = useState(true);
    const [reason, setReason] = useState('');
    const [area, setArea] = useState('');
    const [ckind, setCkind] = useState('percent');
    const [cvalue, setCvalue] = useState('10');
    const bulk = type === 'wholesaler';
    const restaurant = a.kind === 'restaurant';

    return (
        <li className="space-y-3 px-4 py-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p className="font-semibold">{a.business_name}</p>
                    <p className="text-muted-foreground text-sm">
                        {[a.person, a.email, a.phone].filter(Boolean).join(' · ')}
                    </p>
                    <p className="text-muted-foreground text-sm">{a.address}</p>
                </div>
                <p className={`text-sm font-medium ${a.waiting_days >= 3 ? 'text-danger' : ''}`}>
                    {dateTime(a.sent_at)} · {t('waiting {n} days', { n: num(a.waiting_days) })}
                </p>
            </div>
            <p className="text-sm">
                {t(a.kind === 'trader' ? 'A trading business' : a.kind === 'restaurant' ? 'A restaurant or food business' : 'A shop')} · {a.categories.map((c) => t(categories.find((x) => x.key === c)?.label ?? c)).join(', ')}
                {a.license_number && ` · ${t('Licence')} ${a.license_number}`}
            </p>
            {a.note && <p className="text-muted-foreground text-sm">“{a.note}”</p>}
            <div className="flex flex-wrap gap-3 text-sm">
                <a className="font-semibold underline underline-offset-4" href={`/admin/applications/${a.id}/file/first`} target="_blank" rel="noreferrer">
                    {t(a.categories.includes('medicine') ? 'Open trade licence' : 'Open licence')} {a.license_name && `(${a.license_name})`}
                </a>
                {a.second_name && (
                    <a className="font-semibold underline underline-offset-4" href={`/admin/applications/${a.id}/file/second`} target="_blank" rel="noreferrer">
                        {t(a.categories.includes('medicine') ? 'Open drug licence' : 'Open second licence')} ({a.second_name})
                    </a>
                )}
            </div>

            <div className="grid gap-2 rounded-md border p-3 sm:grid-cols-2">
                {!restaurant && (
                <>
                <label className="text-sm">
                    {t('Open as')}
                    <select value={type} onChange={(e) => setType(e.target.value)} className="bg-background mt-1 block h-9 w-full rounded-md border px-2 text-sm">
                        <option value="paikari">{t('Shop buyer')}</option>
                        <option value="wholesaler">{t('Bulk buyer')}</option>
                    </select>
                </label>
                <label className="text-sm">
                    {t('Tier')}
                    <select value={tier} onChange={(e) => setTier(e.target.value)} className="bg-background mt-1 block h-9 w-full rounded-md border px-2 text-sm">
                        <option value="">{t('No tier yet')}</option>
                        {tiers.map((x) => (
                            <option key={x.id} value={x.id}>
                                {x.name}
                            </option>
                        ))}
                    </select>
                </label>
                </>
                )}
                {!restaurant && bulk && (
                    <>
                        <fieldset className="sm:col-span-2">
                            <legend className="text-sm">{t('Categories they can see')}</legend>
                            <div className="mt-1 flex flex-wrap gap-4 text-sm">
                                {categories.map((c) => (
                                    <label key={c.key} className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={cats.includes(c.key)}
                                            onChange={() => setCats(cats.includes(c.key) ? cats.filter((x) => x !== c.key) : [...cats, c.key])}
                                        />
                                        {t(c.label)}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <label className="text-sm">
                            {t('Minimum per item')}
                            <Input className="mt-1 h-9" type="number" min="0" step="any" value={moq} onChange={(e) => setMoq(e.target.value)} placeholder={t('Shop default')} />
                        </label>
                        <fieldset>
                            <legend className="text-sm">{t('Can pay')}</legend>
                            <div className="mt-2 flex gap-4 text-sm">
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={cod} onChange={(e) => setCod(e.target.checked)} />
                                    {t('Pay when it arrives')}
                                </label>
                                <label className="flex items-center gap-2">
                                    <input type="checkbox" checked={advance} onChange={(e) => setAdvance(e.target.checked)} />
                                    {t('Pay in advance')}
                                </label>
                            </div>
                        </fieldset>
                    </>
                )}
                {restaurant && (
                    <>
                        <label className="text-sm">
                            {t('Area the restaurant is in')}
                            <Input className="mt-1 h-9" value={area} onChange={(e) => setArea(e.target.value)} maxLength={80} />
                            <InputError message={errors.area} />
                        </label>
                        <label className="text-sm">
                            {t('Our commission')}
                            <span className="mt-1 flex gap-2">
                                <select value={ckind} onChange={(e) => setCkind(e.target.value)} className="bg-background h-9 rounded-md border px-2 text-sm">
                                    <option value="percent">%</option>
                                    <option value="taka">{t('Taka')}</option>
                                </select>
                                <Input className="h-9" type="number" min="0" step="any" value={cvalue} onChange={(e) => setCvalue(e.target.value)} />
                            </span>
                        </label>
                        <InputError message={errors.application} />
                    </>
                )}
                <div className="sm:col-span-2">
                    <Button
                        disabled={restaurant ? area.trim() === '' || cvalue === '' : bulk && (!cod && !advance || cats.length === 0)}
                        onClick={() =>
                            router.post(`/admin/applications/${a.id}/approve`, restaurant ? { area, commission_kind: ckind, commission_value: cvalue } : {
                                account_type: type,
                                tier_id: tier || null,
                                categories: bulk ? cats : undefined,
                                moq: bulk && moq !== '' ? moq : undefined,
                                pay_cod: bulk ? cod : undefined,
                                pay_advance: bulk ? advance : undefined,
                            }, { preserveScroll: true })
                        }
                    >
                        {t('Open the account')}
                    </Button>
                </div>
            </div>

            <div className="flex flex-wrap items-start gap-2">
                <div>
                    <Input aria-label={t('Reason')} className="h-9 w-72" placeholder={t('Reason, the applicant will read it')} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} />
                    <InputError message={errors.reason} />
                </div>
                <Button variant="outline" size="sm" className="h-9" onClick={() => router.post(`/admin/applications/${a.id}/reject`, { reason }, { preserveScroll: true })}>
                    {t('Decline')}
                </Button>
            </div>
        </li>
    );
}

export default function Applications({ pending, done, tiers, categories }: Props) {
    const { t, num, dateTime } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Applications')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Applications')}</h1>
                    <p className="text-muted-foreground text-sm">{t('Check the licence, then open the right account or say why not. Every look at a licence is logged.')}</p>
                </header>

                <section className="space-y-2">
                    <h2 className="font-medium">
                        {t('Waiting')} ({num(pending.length)})
                    </h2>
                    {pending.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">{t('No applications waiting.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {pending.map((a) => (
                                <Review key={a.id} a={a} tiers={tiers} categories={categories} />
                            ))}
                        </ul>
                    )}
                </section>

                {done.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Recently decided')}</h2>
                        <ul className="divide-y rounded-md border text-sm">
                            {done.map((a) => (
                                <li key={a.id} className="flex flex-wrap items-baseline gap-x-4 px-3 py-2">
                                    <span className="min-w-40 font-medium">{a.business_name}</span>
                                    <span className={a.status === 'approved' ? 'text-success' : 'text-danger'}>{t(a.status === 'approved' ? 'Opened' : 'Declined')}</span>
                                    {a.review_note && <span className="text-muted-foreground">{a.review_note}</span>}
                                    <span className="text-muted-foreground ml-auto text-xs">{dateTime(a.sent_at)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
