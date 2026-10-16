import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { useEffect, useRef, useState } from 'react';

export interface PickedMedicine {
    id: number;
    brand_name: string;
    strength: string | null;
    form: string | null;
    company: { name: string } | null;
}

interface Props {
    onPick: (medicine: PickedMedicine | null) => void;
    picked: PickedMedicine | null;
}

/** Search-and-pick from the medicine database. Nobody types a free-form medicine name (spec G12). */
export default function MedicinePicker({ onPick, picked }: Props) {
    const { t } = useI18n();
    const [q, setQ] = useState('');
    const [results, setResults] = useState<PickedMedicine[]>([]);
    const [open, setOpen] = useState(false);
    const controller = useRef<AbortController | null>(null);

    // Once a medicine is picked the search text has done its job; clearing it means the picker
    // starts empty (and closed) again after the form is saved or the choice is changed.
    useEffect(() => {
        if (picked) {
            setQ('');
            setOpen(false);
        }
    }, [picked]);

    useEffect(() => {
        const term = q.trim();
        if (picked || term.length < 2) {
            setResults([]);
            return;
        }

        const timer = setTimeout(async () => {
            controller.current?.abort();
            controller.current = new AbortController();
            try {
                const res = await fetch(`/medicines/suggest?q=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.current.signal,
                });
                if (res.ok) setResults(((await res.json()) as { data: PickedMedicine[] }).data.slice(0, 8));
                setOpen(true);
            } catch {
                /* aborted or offline: keep the last results */
            }
        }, 250);

        return () => clearTimeout(timer);
    }, [q, picked]);

    if (picked) {
        return (
            <div className="flex h-9 items-center justify-between gap-2 rounded-md border bg-muted/40 px-3 text-sm">
                <span className="truncate">
                    <strong>{picked.brand_name}</strong> {picked.strength} <span className="text-muted-foreground">{picked.form}</span>
                </span>
                <button type="button" onClick={() => { onPick(null); setQ(''); }} className="text-muted-foreground underline-offset-4 hover:underline">
                    {t('Change')}
                </button>
            </div>
        );
    }

    return (
        <div className="relative">
            <label htmlFor="rate-medicine" className="sr-only">{t('Medicine')}</label>
            <Input
                id="rate-medicine"
                value={q}
                onChange={(e) => { setQ(e.target.value); setOpen(true); }}
                onFocus={() => setOpen(true)}
                onKeyDown={(e) => e.key === 'Escape' && setOpen(false)}
                autoComplete="off"
                placeholder={t('Search a medicine')}
                role="combobox"
                aria-expanded={open && results.length > 0}
                aria-controls="rate-medicine-list"
            />
            {open && results.length > 0 && (
                <ul id="rate-medicine-list" role="listbox" className="absolute z-20 mt-1 max-h-72 w-full min-w-72 overflow-auto rounded-md border bg-popover p-1 shadow-md">
                    {results.map((m) => (
                        <li key={m.id} role="option" aria-selected="false">
                            <button
                                type="button"
                                onClick={() => { onPick(m); setOpen(false); }}
                                className="w-full rounded px-2 py-1.5 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                            >
                                <strong>{m.brand_name}</strong> {m.strength}
                                <span className="block text-xs text-muted-foreground">{[m.form, m.company?.name].filter(Boolean).join(' · ')}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
