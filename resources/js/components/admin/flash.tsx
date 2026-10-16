import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';

/** Shows the one-shot status message set by the last action (for example "Tier updated."). */
export default function Flash() {
    const { flash } = usePage<SharedData>().props;
    if (!flash?.status) return null;

    return (
        <p role="status" className="flex items-center gap-2 rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-800 dark:text-emerald-300">
            <CheckCircle2 aria-hidden className="size-4 shrink-0" />
            {flash.status}
        </p>
    );
}
