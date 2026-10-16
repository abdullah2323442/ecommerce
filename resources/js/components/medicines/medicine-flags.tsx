import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/lib/i18n';
import { FileText, ShieldAlert, Snowflake, type LucideIcon } from 'lucide-react';
import { type MedicineSummary } from './types';

interface Flag {
    key: string;
    Icon: LucideIcon;
    label: string;
    explain: string;
    variant: 'warning' | 'info' | 'danger';
}

/** Only flags the database actually sets. Never shown from a guess. */
export function useMedicineFlags() {
    const { t } = useI18n();

    return (m: MedicineSummary): Flag[] =>
        [
            m.prescription_required && { key: 'rx', Icon: FileText, label: t('Prescription required'), explain: t('Sold only against a doctor’s prescription.'), variant: 'warning' as const },
            m.is_cold_chain && { key: 'cold', Icon: Snowflake, label: t('Keep refrigerated'), explain: t('Must be kept cold, in a refrigerator.'), variant: 'info' as const },
            m.is_controlled && { key: 'controlled', Icon: ShieldAlert, label: t('Controlled medicine'), explain: t('The law limits how this medicine is sold.'), variant: 'danger' as const },
        ].filter(Boolean) as Flag[];
}

/** Compact badges for rows and cards. */
export default function MedicineFlags({ medicine, className = '' }: { medicine: MedicineSummary; className?: string }) {
    const flagsOf = useMedicineFlags();
    const flags = flagsOf(medicine);

    if (flags.length === 0) return null;

    return (
        <ul className={`flex flex-wrap gap-1.5 ${className}`}>
            {flags.map(({ key, Icon, label, variant }) => (
                <li key={key}>
                    <Badge variant={variant}>
                        <Icon aria-hidden />
                        {label}
                    </Badge>
                </li>
            ))}
        </ul>
    );
}

/** Badges with a plain-language line each, for the detail page. */
export function MedicineFlagsExplained({ medicine }: { medicine: MedicineSummary }) {
    const flagsOf = useMedicineFlags();
    const flags = flagsOf(medicine);

    if (flags.length === 0) return null;

    return (
        <ul className="space-y-2">
            {flags.map(({ key, Icon, label, explain, variant }) => (
                <li key={key} className="flex items-start gap-3">
                    <Badge variant={variant} className="mt-0.5">
                        <Icon aria-hidden />
                        {label}
                    </Badge>
                    <span className="text-sm text-ink-muted">{explain}</span>
                </li>
            ))}
        </ul>
    );
}
