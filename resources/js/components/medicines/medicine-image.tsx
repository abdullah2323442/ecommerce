import { cn } from '@/lib/utils';
import { useState } from 'react';
import { FormIcon } from './form-icon';
import { type MedicineSummary } from './types';

interface Props {
    medicine: MedicineSummary;
    /** `thumb` for list rows (form glyph fallback), `tile` for cards and the detail page (name set in type). */
    variant?: 'thumb' | 'tile';
    className?: string;
    /** Above the fold: load eagerly. */
    priority?: boolean;
}

/**
 * Pack photo on a neutral square, contained (never cropped), with fixed dimensions so nothing shifts
 * when it loads. With no photo, or with photos switched off (config/catalog.php), a typographic tile.
 */
export default function MedicineImage({ medicine, variant = 'thumb', className, priority = false }: Props) {
    const [failed, setFailed] = useState(false);
    const size = variant === 'tile' ? 480 : 96;

    if (!medicine.image_url || failed) {
        return (
            <div aria-hidden className={cn('flex aspect-square shrink-0 items-center justify-center overflow-hidden rounded-control border border-line bg-sunken text-ink-muted', className)}>
                {variant === 'tile' ? (
                    <span className="flex flex-col items-center gap-2 px-3 text-center">
                        <FormIcon form={medicine.form} className="size-8 text-accent" />
                        <span className="line-clamp-2 text-base leading-snug font-semibold text-ink">{medicine.brand_name}</span>
                        {medicine.strength && <span className="text-sm font-bold text-ink">{medicine.strength}</span>}
                    </span>
                ) : (
                    <FormIcon form={medicine.form} className="size-1/2 max-h-7 max-w-7" />
                )}
            </div>
        );
    }

    return (
        <div className={cn('relative aspect-square shrink-0 overflow-hidden rounded-control border border-line bg-white', className)}>
            <img
                src={medicine.image_url}
                alt=""
                width={size}
                height={size}
                loading={priority ? 'eager' : 'lazy'}
                fetchPriority={priority ? 'high' : 'auto'}
                decoding="async"
                referrerPolicy="no-referrer"
                onError={() => setFailed(true)}
                className="size-full object-contain p-[6%]"
            />
        </div>
    );
}
