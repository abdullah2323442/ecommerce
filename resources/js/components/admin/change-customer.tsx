import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Found {
    id: number;
    name: string;
    phone: string | null;
    area: string | null;
    kind: string;
}

/** Change who a draft is for, from a search by name, phone or area (spec ch 8). Lines are priced again at the new customer's rate. */
export default function ChangeCustomer({ invoiceId }: { invoiceId: number }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [rows, setRows] = useState<Found[]>([]);

    useEffect(() => {
        if (!open || q.trim().length < 2) return setRows([]);
        const timer = setTimeout(() => fetch(`/admin/counter/customers?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((d) => setRows(d.results)).catch(() => setRows([])), 200);
        return () => clearTimeout(timer);
    }, [q, open]);

    if (!open) {
        return (
            <Button size="sm" variant="ghost" onClick={() => setOpen(true)}>
                {t('Change customer')}
            </Button>
        );
    }

    return (
        <div className="w-64 space-y-1">
            <Input aria-label={t('Search')} className="h-8" autoFocus placeholder={t('Name, phone or area')} value={q} onChange={(e) => setQ(e.target.value)} />
            {rows.length > 0 && (
                <ul className="divide-y rounded-md border bg-background text-sm">
                    {rows.map((r) => (
                        <li key={r.id}>
                            <button type="button" className="w-full px-2 py-1.5 text-left hover:bg-muted" onClick={() => router.put(`/admin/counter/${invoiceId}/customer`, { customer_id: r.id }, { preserveScroll: true, onSuccess: () => setOpen(false) })}>
                                {r.name} <span className="text-muted-foreground">{[r.area, r.phone].filter(Boolean).join(' · ')}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
