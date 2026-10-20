import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** The owner's announcement (an update tonight, a closed day), at the top of every page. */
export default function SiteNotice() {
    const { siteNotice, site } = usePage<SharedData>().props;
    const bar = site?.announcement;

    if (!siteNotice && !bar) {
        return null;
    }

    return (
        <>
            {bar && (
                <p role="status" className="border-b border-[var(--pt-line)] bg-[var(--pt-deep)] px-4 py-2 text-center text-sm font-medium text-white">
                    {bar.link ? (
                        <a href={bar.link} className="underline underline-offset-4">
                            {bar.text}
                        </a>
                    ) : (
                        bar.text
                    )}
                </p>
            )}
            {siteNotice && (
                <p role="status" className="border-b border-warning/40 bg-warning/15 px-4 py-2 text-center text-sm font-medium">
                    {siteNotice}
                </p>
            )}
        </>
    );
}
