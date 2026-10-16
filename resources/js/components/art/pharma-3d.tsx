import { useId, type CSSProperties } from 'react';

/**
 * 3D-style vector illustrations of medicine, drawn with gradients, specular highlights, rim light
 * and soft ground shadows so they read as solid objects. Pure SVG: they scale to any size, cost a
 * few hundred bytes each, and take any colour.
 */
interface ArtProps {
    className?: string;
    style?: CSSProperties;
    /** Main colour. Capsules also take `colorB` for the second half. */
    color?: string;
    colorB?: string;
    /** Skip the soft shadow under the object, for use inside tiles that draw their own. */
    flat?: boolean;
}

function useUid() {
    return useId().replace(/[^a-zA-Z0-9]/g, '');
}

function Shadow({ id, cx, cy, rx, ry }: { id: string; cx: number; cy: number; rx: number; ry: number }) {
    return (
        <>
            <defs>
                <filter id={`${id}-blur`} x="-30%" y="-100%" width="160%" height="300%">
                    <feGaussianBlur stdDeviation="3.5" />
                </filter>
            </defs>
            <ellipse cx={cx} cy={cy} rx={rx} ry={ry} fill="#000" opacity="0.28" filter={`url(#${id}-blur)`} />
        </>
    );
}

export function Capsule3D({ className, style, color = '#e8a317', colorB = '#10223a', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 200 100" className={className} style={style} aria-hidden>
            <defs>
                <clipPath id={`${id}-body`}>
                    <rect x="12" y="14" width="176" height="62" rx="31" />
                </clipPath>
                <linearGradient id={`${id}-shade`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stopColor="#fff" stopOpacity="0.5" />
                    <stop offset="0.38" stopColor="#fff" stopOpacity="0" />
                    <stop offset="0.72" stopColor="#000" stopOpacity="0.12" />
                    <stop offset="1" stopColor="#000" stopOpacity="0.42" />
                </linearGradient>
                <linearGradient id={`${id}-seam`} x1="0" x2="1">
                    <stop offset="0" stopColor="#000" stopOpacity="0" />
                    <stop offset="0.5" stopColor="#000" stopOpacity="0.35" />
                    <stop offset="1" stopColor="#000" stopOpacity="0" />
                </linearGradient>
            </defs>
            {!flat && <Shadow id={id} cx={100} cy={86} rx={74} ry={6} />}
            <g clipPath={`url(#${id}-body)`}>
                <rect x="12" y="14" width="88" height="62" fill={color} />
                <rect x="100" y="14" width="88" height="62" fill={colorB} />
                <rect x="12" y="14" width="176" height="62" fill={`url(#${id}-shade)`} />
                <rect x="92" y="14" width="16" height="62" fill={`url(#${id}-seam)`} />
                {/* bounce light along the bottom edge */}
                <rect x="12" y="66" width="176" height="10" fill="#fff" opacity="0.1" />
            </g>
            {/* specular highlights */}
            <rect x="30" y="21" width="62" height="8" rx="4" fill="#fff" opacity="0.62" />
            <rect x="112" y="21" width="42" height="7" rx="3.5" fill="#fff" opacity="0.38" />
            <circle cx="170" cy="30" r="3" fill="#fff" opacity="0.5" />
        </svg>
    );
}

export function Tablet3D({ className, style, color = '#f4f1ea', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 120 100" className={className} style={style} aria-hidden>
            <defs>
                <radialGradient id={`${id}-face`} cx="0.35" cy="0.28" r="0.85">
                    <stop offset="0" stopColor="#fff" />
                    <stop offset="0.55" stopColor={color} />
                    <stop offset="1" stopColor="#000" stopOpacity="0.3" stopOpacity-hack="" />
                </radialGradient>
                <linearGradient id={`${id}-side`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor={color} />
                    <stop offset="1" stopColor="#000" stopOpacity="0.5" />
                </linearGradient>
            </defs>
            {!flat && <Shadow id={id} cx={60} cy={82} rx={46} ry={7} />}
            {/* thickness */}
            <ellipse cx="60" cy="62" rx="50" ry="26" fill={`url(#${id}-side)`} />
            <rect x="10" y="46" width="100" height="16" fill={`url(#${id}-side)`} />
            {/* top face */}
            <ellipse cx="60" cy="46" rx="50" ry="26" fill={`url(#${id}-face)`} />
            <ellipse cx="60" cy="46" rx="43" ry="21" fill="none" stroke="#fff" strokeOpacity="0.6" strokeWidth="1.5" />
            {/* score line: a dark groove with a light lip */}
            <path d="M18 48 L102 44" stroke="#000" strokeOpacity="0.28" strokeWidth="2.4" strokeLinecap="round" />
            <path d="M18 50.4 L102 46.4" stroke="#fff" strokeOpacity="0.7" strokeWidth="1.2" strokeLinecap="round" />
            <ellipse cx="38" cy="33" rx="16" ry="5" fill="#fff" opacity="0.5" transform="rotate(-12 38 33)" />
        </svg>
    );
}

export function Bottle3D({ className, style, color = '#b8742a', colorB = '#10223a', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 100 150" className={className} style={style} aria-hidden>
            <defs>
                <linearGradient id={`${id}-glass`} x1="0" x2="1">
                    <stop offset="0" stopColor="#000" stopOpacity="0.5" />
                    <stop offset="0.25" stopColor="#000" stopOpacity="0" />
                    <stop offset="0.6" stopColor="#fff" stopOpacity="0.18" />
                    <stop offset="1" stopColor="#000" stopOpacity="0.55" />
                </linearGradient>
                <linearGradient id={`${id}-cap`} x1="0" x2="1">
                    <stop offset="0" stopColor={colorB} />
                    <stop offset="0.35" stopColor="#fff" stopOpacity="0.35" />
                    <stop offset="1" stopColor={colorB} />
                </linearGradient>
            </defs>
            {!flat && <Shadow id={id} cx={50} cy={140} rx={34} ry={5} />}
            {/* neck and body */}
            <rect x="36" y="22" width="28" height="22" fill={color} />
            <rect x="14" y="40" width="72" height="98" rx="16" fill={color} />
            <rect x="14" y="40" width="72" height="98" rx="16" fill={`url(#${id}-glass)`} />
            {/* label */}
            <rect x="20" y="70" width="60" height="44" rx="4" fill="#fbf7ee" />
            <rect x="20" y="70" width="60" height="9" rx="4" fill={colorB} opacity="0.9" />
            <path d="M50 86v20M40 96h20" stroke={color} strokeWidth="4" strokeLinecap="round" />
            {/* cap with ridges */}
            <rect x="32" y="6" width="36" height="22" rx="5" fill={`url(#${id}-cap)`} />
            {[0, 1, 2, 3, 4].map((i) => (
                <rect key={i} x={36 + i * 6} y="9" width="2" height="16" fill="#000" opacity="0.22" />
            ))}
            {/* glass highlight */}
            <rect x="22" y="48" width="7" height="82" rx="3.5" fill="#fff" opacity="0.45" />
        </svg>
    );
}

export function Syringe3D({ className, style, color = '#4b46a8', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 220 80" className={className} style={style} aria-hidden>
            <defs>
                <linearGradient id={`${id}-barrel`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="#fff" stopOpacity="0.9" />
                    <stop offset="0.45" stopColor="#dfeaf0" stopOpacity="0.55" />
                    <stop offset="1" stopColor="#7d93a3" stopOpacity="0.7" />
                </linearGradient>
                <linearGradient id={`${id}-fluid`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor={color} stopOpacity="0.85" />
                    <stop offset="1" stopColor={color} />
                </linearGradient>
            </defs>
            {!flat && <Shadow id={id} cx={110} cy={74} rx={86} ry={4} />}
            {/* needle */}
            <rect x="4" y="38" width="44" height="3.5" rx="1.75" fill="#aeb9c3" />
            <rect x="4" y="38" width="44" height="1.4" rx="0.7" fill="#fff" opacity="0.8" />
            {/* barrel with fluid */}
            <rect x="46" y="22" width="120" height="36" rx="6" fill={`url(#${id}-barrel)`} stroke="#6e8394" strokeOpacity="0.5" />
            <rect x="52" y="27" width="74" height="26" rx="4" fill={`url(#${id}-fluid)`} />
            {[0, 1, 2, 3, 4, 5, 6].map((i) => (
                <rect key={i} x={132 + i * 5} y="22" width="1.6" height={i % 2 ? 8 : 12} fill="#3b4b59" opacity="0.7" />
            ))}
            {/* flange, plunger and thumb rest */}
            <rect x="160" y="14" width="8" height="52" rx="3" fill="#cdd7de" />
            <rect x="168" y="36" width="34" height="8" rx="3" fill="#aeb9c3" />
            <rect x="202" y="24" width="10" height="32" rx="4" fill="#cdd7de" />
            <rect x="52" y="29" width="104" height="5" rx="2.5" fill="#fff" opacity="0.55" />
        </svg>
    );
}

export function Tube3D({ className, style, color = '#4a8a3a', colorB = '#fbf7ee', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 220 90" className={className} style={style} aria-hidden>
            <defs>
                <linearGradient id={`${id}-body`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="#fff" stopOpacity="0.55" />
                    <stop offset="0.35" stopColor="#fff" stopOpacity="0" />
                    <stop offset="1" stopColor="#000" stopOpacity="0.4" />
                </linearGradient>
            </defs>
            {!flat && <Shadow id={id} cx={112} cy={82} rx={84} ry={5} />}
            {/* cap */}
            <rect x="6" y="28" width="38" height="34" rx="8" fill={colorB} />
            <rect x="6" y="28" width="38" height="34" rx="8" fill={`url(#${id}-body)`} />
            {/* tapering body */}
            <path d="M44 32 L184 18 Q198 17 198 30 L198 60 Q198 73 184 72 L44 58 Z" fill={color} />
            <path d="M44 32 L184 18 Q198 17 198 30 L198 60 Q198 73 184 72 L44 58 Z" fill={`url(#${id}-body)`} />
            {/* band and label mark */}
            <rect x="90" y="25" width="34" height="40" fill={colorB} opacity="0.92" />
            <path d="M107 36v18M98 45h18" stroke={color} strokeWidth="4" strokeLinecap="round" />
            {/* crimped end */}
            <rect x="198" y="22" width="14" height="46" rx="2" fill={color} />
            <rect x="198" y="22" width="14" height="46" rx="2" fill={`url(#${id}-body)`} />
            {[0, 1, 2, 3].map((i) => (
                <rect key={i} x={199 + i * 3} y="24" width="1.2" height="42" fill="#000" opacity="0.28" />
            ))}
            <rect x="50" y="34" width="132" height="5" rx="2.5" fill="#fff" opacity="0.5" />
        </svg>
    );
}

/** Pick the right illustration for a medicine form (the same words the database uses). */
export function PharmaArt({ form, className, flat }: { form: string | null; className?: string; flat?: boolean }) {
    const f = (form ?? '').toLowerCase();

    if (f.includes('tablet')) return <Tablet3D className={className} flat={flat} color={f.includes('chew') ? '#f6d6a0' : '#f4f1ea'} />;
    if (f.includes('injection')) return <Syringe3D className={className} flat={flat} />;
    if (f.includes('drop')) return <Bottle3D className={className} flat={flat} color="#2b6cb0" />;
    if (['cream', 'ointment', 'gel', 'lotion'].some((w) => f.includes(w))) return <Tube3D className={className} flat={flat} />;
    if (['syrup', 'suspension', 'solution', 'elixir'].some((w) => f.includes(w))) return <Bottle3D className={className} flat={flat} />;

    return <Capsule3D className={className} flat={flat} />;
}
