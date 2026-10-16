import { useState } from 'react';

interface Props {
    name: string;
    logo: string | null;
    className?: string;
}

/** Company mark on a white tile (logos are drawn for white), with the initial as the fallback. */
export default function CompanyLogo({ name, logo, className = 'size-14' }: Props) {
    const [failed, setFailed] = useState(false);

    if (!logo || failed) {
        return (
            <div
                aria-hidden
                className={`${className} flex shrink-0 items-center justify-center rounded-lg border border-[var(--pt-line)] bg-[var(--pt-paper)] text-lg font-semibold text-[var(--pt-muted)]`}
            >
                {name.trim().charAt(0).toUpperCase()}
            </div>
        );
    }

    return (
        <div className={`${className} flex shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--pt-line)] bg-white p-1.5`}>
            <img src={logo} alt="" loading="lazy" decoding="async" referrerPolicy="no-referrer" onError={() => setFailed(true)} className="max-h-full max-w-full object-contain" />
        </div>
    );
}
