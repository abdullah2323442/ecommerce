import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Download, Printer } from 'lucide-react';
import { useState } from 'react';

export interface Entry {
    id: number;
    line_id: number;
    when: string;
    memo: string;
    medicine: string;
    quantity: number;
    unit: string;
    buyer_name: string;
    buyer_phone: string | null;
    buyer_address: string | null;
    prescription_no: string;
    doctor_name: string;
    staff: string | null;
    cancelled: boolean;
}

interface Props {
    entries: Entry[];
    filters: { from: string | null; to: string | null; medicine: number | null };
    medicines: { id: number; name: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Controlled register', href: '/admin/controlled' }];

function EditRow({ e, onDone }: { e: Entry; onDone: () => void }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ buyer_name: e.buyer_name, buyer_phone: e.buyer_phone ?? '', buyer_address: e.buyer_address ?? '', prescription_no: e.prescription_no, doctor_name: e.doctor_name });
    const set = (k: keyof typeof f) => (ev: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: ev.target.value });

    return (
        <div className="grid gap-2 py-2 sm:grid-cols-2">
            <Input aria-label={t('Buyer name')} value={f.buyer_name} onChange={set('buyer_name')} />
            <Input aria-label={t('Buyer phone')} value={f.buyer_phone} onChange={set('buyer_phone')} />
            <Input aria-label={t('Buyer address')} value={f.buyer_address} onChange={set('buyer_address')} className="sm:col-span-2" />
            <Input aria-label={t('Prescription number')} value={f.prescription_no} onChange={set('prescription_no')} />
            <Input aria-label={t('Doctor name')} value={f.doctor_name} onChange={set('doctor_name')} />
            <div className="flex items-center gap-2 sm:col-span-2">
                <Button size="sm" onClick={() => router.put(`/admin/controlled/${e.line_id}`, f, { preserveScroll: true, onSuccess: onDone })}>
                    {t('Save')}
                </Button>
                <Button size="sm" variant="ghost" onClick={onDone}>
                    {t('Cancel')}
                </Button>
                <InputError message={errors.controlled} />
            </div>
            <p className="text-muted-foreground text-xs sm:col-span-2">{t('Every change is logged with what it was before.')}</p>
        </div>
    );
}

export default function Controlled({ entries, filters, medicines }: Props) {
    const { t, num, dateTime } = useI18n();
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [medicine, setMedicine] = useState(String(filters.medicine ?? ''));
    const [editing, setEditing] = useState<number | null>(null);
    const query = () => {
        const q = new URLSearchParams();
        if (from) q.set('from', from);
        if (to) q.set('to', to);
        if (medicine) q.set('medicine', medicine);
        return q.toString();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Controlled register')} />
            <div className="mx-auto w-full max-w-6xl space-y-4 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Controlled register')}</h1>
                    <p className="text-muted-foreground text-sm">{t('Every sale of a controlled medicine, for the drug-licence inspector. Changes are logged.')}</p>
                </header>

                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(ev) => {
                        ev.preventDefault();
                        router.get('/admin/controlled', Object.fromEntries(new URLSearchParams(query())), { preserveState: true });
                    }}
                >
                    <label className="text-sm">
                        {t('From')}
                        <Input className="mt-1 h-9" type="date" value={from} onChange={(ev) => setFrom(ev.target.value)} />
                    </label>
                    <label className="text-sm">
                        {t('To')}
                        <Input className="mt-1 h-9" type="date" value={to} onChange={(ev) => setTo(ev.target.value)} />
                    </label>
                    <label className="text-sm">
                        {t('Medicine')}
                        <select value={medicine} onChange={(ev) => setMedicine(ev.target.value)} className="bg-background mt-1 block h-9 rounded-md border px-2 text-sm">
                            <option value="">{t('All')}</option>
                            {medicines.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button type="submit" variant="outline" className="h-9">
                        {t('Show')}
                    </Button>
                    <a href={`/admin/controlled/print?${query()}`} target="_blank" rel="noreferrer" className="inline-flex h-9 items-center gap-1 text-sm font-semibold underline underline-offset-4">
                        <Printer className="size-4" /> {t('Print')}
                    </a>
                    <a href={`/admin/controlled/export?${query()}`} className="inline-flex h-9 items-center gap-1 text-sm font-semibold underline underline-offset-4">
                        <Download className="size-4" /> {t('Download CSV')}
                    </a>
                </form>

                {entries.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center text-sm">{t('No controlled sales in this period.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border text-sm">
                        {entries.map((e) => (
                            <li key={e.id} className={`px-3 py-2 ${e.cancelled ? 'opacity-60' : ''}`}>
                                <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                                    <span className="text-muted-foreground w-36 text-xs">{dateTime(e.when)}</span>
                                    <span className="w-24 font-mono text-xs">{e.memo}</span>
                                    <span className="min-w-40 flex-1 font-medium">
                                        {e.medicine} <span className="text-muted-foreground font-normal">· {num(e.quantity)} {t(e.unit === 'box' ? 'Box' : e.unit === 'strip' ? 'Strip' : 'Piece')}</span>
                                    </span>
                                    <span>{e.buyer_name}</span>
                                    <span className="text-muted-foreground">{[e.buyer_phone, e.buyer_address].filter(Boolean).join(' · ')}</span>
                                    <span>
                                        {t('Prescription')} {e.prescription_no}
                                    </span>
                                    <span>{e.doctor_name}</span>
                                    <span className="text-muted-foreground">{e.staff}</span>
                                    {e.cancelled && <span className="rounded bg-danger/15 px-1.5 text-xs font-semibold">{t('Memo cancelled')}</span>}
                                    {!e.cancelled && (
                                        <Button size="sm" variant="ghost" onClick={() => setEditing(editing === e.id ? null : e.id)}>
                                            {t('Change')}
                                        </Button>
                                    )}
                                </div>
                                {editing === e.id && <EditRow e={e} onDone={() => setEditing(null)} />}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
