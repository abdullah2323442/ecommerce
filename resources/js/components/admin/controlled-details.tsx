import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

export interface ControlledItem {
    line_id: number;
    name: string;
    quantity: number;
    unit: string;
    details: { buyer_name: string; buyer_phone: string | null; buyer_address: string | null; prescription_no: string; doctor_name: string } | null;
}

interface Defaults {
    buyer_name: string | null;
    buyer_phone: string | null;
}

function Row({ item, defaults, locked }: { item: ControlledItem; defaults: Defaults | null; locked: boolean }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [open, setOpen] = useState(item.details === null);
    const [f, setF] = useState({
        buyer_name: item.details?.buyer_name ?? defaults?.buyer_name ?? '',
        buyer_phone: item.details?.buyer_phone ?? defaults?.buyer_phone ?? '',
        buyer_address: item.details?.buyer_address ?? '',
        prescription_no: item.details?.prescription_no ?? '',
        doctor_name: item.details?.doctor_name ?? '',
    });
    const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });
    const ready = f.buyer_name && f.prescription_no && f.doctor_name && (f.buyer_phone || f.buyer_address);

    return (
        <li className="space-y-2 px-3 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {item.name} <span className="text-muted-foreground text-sm">· {num(item.quantity)} {t(item.unit === 'box' ? 'Box' : item.unit === 'strip' ? 'Strip' : 'Piece')}</span>
                </p>
                {item.details && !open && (
                    <span className="flex items-center gap-2 text-sm">
                        <span className="text-emerald-700">{t('In the register')}</span>
                        {!locked && (
                            <Button size="sm" variant="ghost" onClick={() => setOpen(true)}>
                                {t('Change')}
                            </Button>
                        )}
                    </span>
                )}
            </div>
            {item.details && !open && (
                <p className="text-muted-foreground text-sm">
                    {[item.details.buyer_name, item.details.buyer_phone, item.details.buyer_address].filter(Boolean).join(' · ')} — {t('Prescription')} {item.details.prescription_no}, {item.details.doctor_name}
                </p>
            )}
            {open && !locked && (
                <div className="grid gap-2 sm:grid-cols-2">
                    <Input aria-label={t('Buyer name')} placeholder={t('Buyer name')} value={f.buyer_name} onChange={set('buyer_name')} maxLength={120} />
                    <Input aria-label={t('Buyer phone')} placeholder={t('Buyer phone')} inputMode="tel" value={f.buyer_phone} onChange={set('buyer_phone')} maxLength={30} />
                    <Input aria-label={t('Buyer address')} placeholder={t('Buyer address')} value={f.buyer_address} onChange={set('buyer_address')} maxLength={250} className="sm:col-span-2" />
                    <Input aria-label={t('Prescription number')} placeholder={t('Prescription number')} value={f.prescription_no} onChange={set('prescription_no')} maxLength={60} />
                    <Input aria-label={t('Doctor name')} placeholder={t('Doctor name')} value={f.doctor_name} onChange={set('doctor_name')} maxLength={120} />
                    <div className="flex items-center gap-2 sm:col-span-2">
                        <Button
                            size="sm"
                            disabled={!ready}
                            onClick={() => router.put(`/admin/controlled/${item.line_id}`, f, { preserveScroll: true, onSuccess: () => setOpen(false) })}
                        >
                            {t('Save in the register')}
                        </Button>
                        {item.details && (
                            <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                                {t('Cancel')}
                            </Button>
                        )}
                        <InputError message={errors.controlled} />
                    </div>
                </div>
            )}
        </li>
    );
}

/** The register entry for each controlled medicine on a sale. A sale cannot be finished without it (spec ch 27). */
export default function ControlledDetails({ items, defaults, final = false }: { items: ControlledItem[]; defaults: Defaults | null; final?: boolean }) {
    const { t } = useI18n();
    const can = usePage<SharedData>().props.auth.can;

    if (items.length === 0) return null;

    return (
        <section aria-labelledby="ctl-h" className="space-y-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 print:hidden">
            <div>
                <h2 id="ctl-h" className="font-display text-lg font-bold">
                    {t('Controlled medicine')}
                </h2>
                <p className="text-muted-foreground text-sm">{t('The law needs a record of who bought it. The sale cannot be finished without it.')}</p>
            </div>
            <ul className="divide-y rounded-md border bg-background">
                {items.map((i) => (
                    <Row key={`${i.line_id}-${i.details ? 'x' : 'n'}`} item={i} defaults={defaults} locked={final && !can.viewReports} />
                ))}
            </ul>
        </section>
    );
}
