import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Bangla / English (spec ch 28). English is the source text and the key, so a missing translation
 * falls back to readable English instead of a code. Product, company and generic names are data and
 * are never passed through `t` (spec: names are never auto-translated).
 */
export function useI18n() {
    const { locale, translations } = usePage<SharedData>().props;
    const dictionary = translations as Record<string, string>;
    const isBn = locale === 'bn';
    const tag = isBn ? 'bn-BD' : 'en-US';

    const t = (text: string, vars?: Record<string, string | number>): string => {
        let out = dictionary[text] ?? text;
        if (vars) {
            for (const [key, value] of Object.entries(vars)) out = out.split(`{${key}}`).join(String(value));
        }
        return out;
    };

    const numberFormat = new Intl.NumberFormat(tag);
    const moneyFormat = new Intl.NumberFormat(tag, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return {
        t,
        locale,
        isBn,
        /** 25933 -> 25,933 or ২৫,৯৩৩ (lakh grouping in Bangla) */
        num: (n: number) => numberFormat.format(n),
        /** 3.7 -> 3.7 or ৩.৭ */
        decimal: (n: number, digits = 1) => new Intl.NumberFormat(tag, { maximumFractionDigits: digits }).format(n),
        /** 15 -> ৳15.00 or ৳১৫.০০ */
        money: (value: string | number) => `৳${moneyFormat.format(Number(value))}`,
        date: (iso: string, options: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' }) => new Date(iso).toLocaleDateString(isBn ? 'bn-BD' : 'en-GB', options),
        dateTime: (iso: string) => new Date(iso).toLocaleString(isBn ? 'bn-BD' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }),
    };
}

/** Bangla | English switch. Remembers the choice in a cookie and keeps the page where it is. */
export function LanguageToggle({ className = '' }: { className?: string }) {
    const { locale } = useI18n();

    useEffect(() => {
        document.documentElement.lang = locale;
    }, [locale]);

    const choose = (next: 'bn' | 'en') => {
        if (next !== locale) router.post(route('locale'), { locale: next }, { preserveScroll: true, preserveState: true });
    };

    return (
        <div role="group" aria-label="Language" className={`inline-flex rounded-full border border-[var(--pt-line,var(--border))] p-0.5 text-sm font-semibold ${className}`}>
            {(
                [
                    ['bn', 'বাংলা'],
                    ['en', 'EN'],
                ] as const
            ).map(([code, label]) => (
                <button
                    key={code}
                    type="button"
                    lang={code}
                    aria-pressed={locale === code}
                    onClick={() => choose(code)}
                    className={`min-h-8 rounded-full px-3 outline-none transition-colors focus-visible:ring-2 focus-visible:ring-[#e8a317] ${
                        locale === code ? 'bg-[#10223a] text-[#fbf7ee] dark:bg-[#f0b43a] dark:text-[#1b1300]' : 'text-[var(--pt-muted,var(--muted-foreground))] hover:text-[var(--pt-ink,var(--foreground))]'
                    }`}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}
