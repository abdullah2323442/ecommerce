import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * The platform's name and logo, both from config/brand.php (never typed in a component).
 * Until Siam supplies a logo (open question 4) the name is set in type, with no invented mark.
 */
export default function BrandMark({ className = '' }: { className?: string }) {
    const { brand } = usePage<SharedData>().props;

    return (
        <span className={`inline-flex min-w-0 items-center gap-2 ${className}`}>
            {brand.logo && <img src={brand.logo} alt="" width={28} height={28} className="size-7 shrink-0 rounded-control object-contain" />}
            <span className="truncate text-lg font-bold tracking-tight text-ink">{brand.name}</span>
        </span>
    );
}
