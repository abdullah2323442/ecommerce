import { type PickedMedicine } from '@/components/admin/medicine-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { useEffect, useState } from 'react';

interface Props {
    /** Called once with every medicine the buyer ticked, so the purchase gets one line each. */
    onAdd: (medicines: PickedMedicine[]) => void;
}

const get = (url: string) => fetch(url, { headers: { Accept: 'application/json' } }).then((r) => r.json());

/** Open a company, tick the medicines that came in the box, add them all as lines in one go (spec ch 6: pick from a company's list). Not a form: it sits inside the purchase form. */
export default function CompanyAdder({ onAdd }: Props) {
    const { t } = useI18n();
    const [q, setQ] = useState('');
    const [companies, setCompanies] = useState<{ id: number; name: string }[]>([]);
    const [company, setCompany] = useState<{ id: number; name: string } | null>(null);
    const [filter, setFilter] = useState('');
    const [medicines, setMedicines] = useState<PickedMedicine[]>([]);
    const [ticked, setTicked] = useState<Record<number, boolean>>({});

    useEffect(() => {
        if (company || q.trim().length < 2) {
            setCompanies([]);
            return;
        }
        const timer = setTimeout(() => get(`/admin/stock/companies?q=${encodeURIComponent(q.trim())}`).then((d) => setCompanies(d.results)).catch(() => setCompanies([])), 200);
        return () => clearTimeout(timer);
    }, [q, company]);

    useEffect(() => {
        if (!company) return;
        const timer = setTimeout(() => get(`/admin/stock/companies/${company.id}/medicines?q=${encodeURIComponent(filter.trim())}`).then((d) => setMedicines(d.results)).catch(() => setMedicines([])), 200);
        return () => clearTimeout(timer);
    }, [company, filter]);

    const chosen = medicines.filter((m) => ticked[m.id]);

    const add = () => {
        onAdd(chosen);
        setTicked({});
    };

    const close = () => {
        setCompany(null);
        setQ('');
        setFilter('');
        setMedicines([]);
        setTicked({});
    };

    return (
        <div className="mt-4 space-y-2 border-t pt-4">
            <p className="text-sm font-medium">{t('Add many from one company')}</p>
            {!company ? (
                <div className="relative max-w-sm">
                    <Input aria-label={t('Search a company')} autoComplete="off" placeholder={t('Search a company')} value={q} onChange={(e) => setQ(e.target.value)} />
                    {companies.length > 0 && (
                        <ul className="bg-popover absolute z-10 mt-1 w-full rounded-md border shadow-md">
                            {companies.map((c) => (
                                <li key={c.id}>
                                    <button type="button" className="hover:bg-muted w-full px-3 py-2 text-left text-sm" onClick={() => setCompany(c)}>
                                        {c.name}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : (
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <strong>{company.name}</strong>
                        <Button type="button" size="sm" variant="ghost" onClick={close}>
                            {t('Change')}
                        </Button>
                        <Input aria-label={t('Narrow the list')} className="h-8 max-w-48" placeholder={t('Narrow the list')} value={filter} onChange={(e) => setFilter(e.target.value)} />
                    </div>
                    {medicines.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('No medicine matches.')}</p>
                    ) : (
                        <ul className="grid max-h-56 gap-1 overflow-auto rounded-md border p-2 sm:grid-cols-2">
                            {medicines.map((m) => (
                                <li key={m.id}>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={!!ticked[m.id]} onChange={(e) => setTicked({ ...ticked, [m.id]: e.target.checked })} />
                                        <span>
                                            <strong>{m.brand_name}</strong> {m.strength} <span className="text-muted-foreground">{m.form}</span>
                                        </span>
                                    </label>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Button type="button" size="sm" disabled={chosen.length === 0} onClick={add}>
                        {t('Add the ticked medicines')}
                    </Button>
                </div>
            )}
        </div>
    );
}
