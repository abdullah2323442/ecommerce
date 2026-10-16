import { useId, type CSSProperties } from 'react';

/**
 * 3D-style vector illustrations for every public sector on the street: food, gaming, made-to-measure
 * wood and clothing, the market and the help board. They share the look of the medicine art (gradients,
 * specular highlights, soft ground shadows) so the whole hero reads as one world.
 */
interface ArtProps {
    className?: string;
    style?: CSSProperties;
    color?: string;
    flat?: boolean;
}

function useUid() {
    return useId().replace(/[^a-zA-Z0-9]/g, '');
}

function Ground({ id, cx, cy, rx, ry }: { id: string; cx: number; cy: number; rx: number; ry: number }) {
    return (
        <>
            <defs>
                <filter id={`${id}-blur`} x="-30%" y="-100%" width="160%" height="300%">
                    <feGaussianBlur stdDeviation="3.5" />
                </filter>
            </defs>
            <ellipse cx={cx} cy={cy} rx={rx} ry={ry} fill="#000" opacity="0.26" filter={`url(#${id}-blur)`} />
        </>
    );
}

/** Food: a burger, built layer by layer. */
export function Burger3D({ className, style, flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 160 140" className={className} style={style} aria-hidden>
            <defs>
                <radialGradient id={`${id}-bun`} cx="0.35" cy="0.2" r="0.9">
                    <stop offset="0" stopColor="#f6c273" />
                    <stop offset="0.6" stopColor="#d98a2b" />
                    <stop offset="1" stopColor="#9a5714" />
                </radialGradient>
                <linearGradient id={`${id}-under`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="#d98a2b" />
                    <stop offset="1" stopColor="#8a4d12" />
                </linearGradient>
                <linearGradient id={`${id}-patty`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="#7a4a2e" />
                    <stop offset="1" stopColor="#3a2014" />
                </linearGradient>
            </defs>
            {!flat && <Ground id={id} cx={80} cy={128} rx={62} ry={6} />}
            <path d="M18 100 Q18 120 44 120 H116 Q142 120 142 100 Z" fill={`url(#${id}-under)`} />
            <rect x="12" y="84" width="136" height="18" rx="9" fill={`url(#${id}-patty)`} />
            <rect x="22" y="87" width="60" height="4" rx="2" fill="#fff" opacity="0.18" />
            <path d="M12 78 H148 L136 78 L128 94 L118 78 L58 78 L50 92 L42 78 Z" fill="#f4c430" />
            <path d="M8 72 q10 -9 20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t12 0 V80 H8 Z" fill="#5aa13c" />
            <rect x="14" y="60" width="132" height="9" rx="4.5" fill="#d8472b" />
            <path d="M16 62 Q16 12 80 12 Q144 12 144 62 Z" fill={`url(#${id}-bun)`} />
            {[
                [48, 30, -20],
                [70, 22, 10],
                [96, 28, 30],
                [60, 44, 15],
                [86, 42, -25],
                [114, 44, 20],
                [36, 48, 40],
            ].map(([x, y, r], i) => (
                <ellipse key={i} cx={x} cy={y} rx="4.5" ry="2.4" fill="#fbf1d3" transform={`rotate(${r} ${x} ${y})`} />
            ))}
            <ellipse cx="52" cy="26" rx="22" ry="8" fill="#fff" opacity="0.28" transform="rotate(-18 52 26)" />
        </svg>
    );
}

/** Gaming: a game controller. */
export function Controller3D({ className, style, color = '#4b46a8', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 200 120" className={className} style={style} aria-hidden>
            <defs>
                <linearGradient id={`${id}-body`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="#fff" stopOpacity="0.55" />
                    <stop offset="0.3" stopColor={color} />
                    <stop offset="1" stopColor="#000" stopOpacity="0.55" />
                </linearGradient>
                <radialGradient id={`${id}-stick`} cx="0.35" cy="0.3" r="0.8">
                    <stop offset="0" stopColor="#5a6577" />
                    <stop offset="1" stopColor="#1a2230" />
                </radialGradient>
            </defs>
            {!flat && <Ground id={id} cx={100} cy={112} rx={78} ry={6} />}
            <path d="M42 28 Q20 28 13 60 L5 96 Q3 112 20 112 Q34 112 42 100 L56 82 H144 L158 100 Q166 112 180 112 Q197 112 195 96 L187 60 Q180 28 158 28 Z" fill={color} />
            <path d="M42 28 Q20 28 13 60 L5 96 Q3 112 20 112 Q34 112 42 100 L56 82 H144 L158 100 Q166 112 180 112 Q197 112 195 96 L187 60 Q180 28 158 28 Z" fill={`url(#${id}-body)`} />
            {/* d-pad */}
            <rect x="36" y="48" width="30" height="10" rx="3" fill="#e9eef3" />
            <rect x="46" y="38" width="10" height="30" rx="3" fill="#e9eef3" />
            <rect x="36" y="48" width="30" height="3" rx="1.5" fill="#fff" opacity="0.7" />
            {/* action buttons */}
            {[
                [150, 38, '#43b581'],
                [164, 52, '#d8472b'],
                [136, 52, '#4aa3ff'],
                [150, 66, '#f4c64a'],
            ].map(([x, y, c], i) => (
                <g key={i}>
                    <circle cx={x as number} cy={(y as number) + 2} r="8" fill="#000" opacity="0.25" />
                    <circle cx={x as number} cy={y as number} r="8" fill={c as string} />
                    <circle cx={(x as number) - 2.5} cy={(y as number) - 3} r="2.6" fill="#fff" opacity="0.6" />
                </g>
            ))}
            {/* thumbsticks */}
            <circle cx="78" cy="74" r="12" fill="#10161f" />
            <circle cx="78" cy="72" r="9" fill={`url(#${id}-stick)`} />
            <circle cx="122" cy="74" r="12" fill="#10161f" />
            <circle cx="122" cy="72" r="9" fill={`url(#${id}-stick)`} />
            <rect x="88" y="46" width="10" height="5" rx="2.5" fill="#fff" opacity="0.65" />
            <rect x="102" y="46" width="10" height="5" rx="2.5" fill="#fff" opacity="0.65" />
            <path d="M30 34 Q50 26 100 26 Q150 26 170 34" fill="none" stroke="#fff" strokeOpacity="0.45" strokeWidth="3" strokeLinecap="round" />
        </svg>
    );
}

/** Gaming: a faceted gem, for diamonds and top-ups. */
export function Gem3D({ className, style, color = '#35c2e8', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 100 92" className={className} style={style} aria-hidden>
            <defs>
                <linearGradient id={`${id}-a`} x1="0" x2="1" y1="0" y2="1">
                    <stop offset="0" stopColor="#fff" />
                    <stop offset="1" stopColor={color} />
                </linearGradient>
                <linearGradient id={`${id}-b`} x1="0" x2="1" y1="0" y2="1">
                    <stop offset="0" stopColor={color} />
                    <stop offset="1" stopColor="#1d4fa8" />
                </linearGradient>
            </defs>
            {!flat && <Ground id={id} cx={50} cy={86} rx={34} ry={4} />}
            <polygon points="30,8 70,8 88,32 12,32" fill={`url(#${id}-a)`} />
            <polygon points="12,32 30,8 40,32" fill={color} opacity="0.85" />
            <polygon points="30,8 50,32 40,32" fill="#fff" opacity="0.55" />
            <polygon points="50,32 70,8 60,32" fill="#9be7fa" />
            <polygon points="70,8 88,32 60,32" fill={color} opacity="0.7" />
            <polygon points="12,32 40,32 50,84" fill={`url(#${id}-b)`} />
            <polygon points="40,32 60,32 50,84" fill={color} />
            <polygon points="60,32 88,32 50,84" fill="#1d4fa8" opacity="0.85" />
            <polygon points="36,14 46,14 40,24" fill="#fff" opacity="0.8" />
        </svg>
    );
}

/** Custom orders: a wooden chair, made to measure. */
export function Chair3D({ className, style, color = '#b8742a', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 120 150" className={className} style={style} aria-hidden>
            <defs>
                <linearGradient id={`${id}-wood`} x1="0" x2="1">
                    <stop offset="0" stopColor="#e2ad74" />
                    <stop offset="0.5" stopColor={color} />
                    <stop offset="1" stopColor="#7a4a1e" />
                </linearGradient>
                <linearGradient id={`${id}-top`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="#f0c690" />
                    <stop offset="1" stopColor="#c98a45" />
                </linearGradient>
            </defs>
            {!flat && <Ground id={id} cx={60} cy={142} rx={48} ry={5} />}
            {/* back legs and posts */}
            <rect x="24" y="96" width="8" height="40" rx="2" fill="#7a4a1e" />
            <rect x="88" y="96" width="8" height="40" rx="2" fill="#7a4a1e" />
            <rect x="24" y="6" width="9" height="92" rx="3" fill={`url(#${id}-wood)`} />
            <rect x="87" y="6" width="9" height="92" rx="3" fill={`url(#${id}-wood)`} />
            {[14, 32, 50].map((y) => (
                <rect key={y} x="33" y={y} width="54" height="12" rx="3" fill={`url(#${id}-wood)`} />
            ))}
            {/* seat: top face, then front edge */}
            <polygon points="16,80 104,80 116,100 4,100" fill={`url(#${id}-top)`} />
            <rect x="4" y="100" width="112" height="12" rx="3" fill={`url(#${id}-wood)`} />
            {/* front legs */}
            <rect x="8" y="112" width="11" height="28" rx="3" fill={`url(#${id}-wood)`} />
            <rect x="101" y="112" width="11" height="28" rx="3" fill={`url(#${id}-wood)`} />
            <polygon points="22,84 60,84 54,92 14,92" fill="#fff" opacity="0.28" />
        </svg>
    );
}

/** Custom orders: a tailored shirt. */
export function Shirt3D({ className, style, color = '#1f8a8a', flat }: ArtProps) {
    const id = useUid();
    const outline = 'M42 10 L18 24 L3 54 L24 64 L34 52 L34 122 H106 V52 L116 64 L137 54 L122 24 L98 10 Q86 26 70 26 Q54 26 42 10 Z';

    return (
        <svg viewBox="0 0 140 134" className={className} style={style} aria-hidden>
            <defs>
                <linearGradient id={`${id}-cloth`} x1="0" x2="1" y1="0" y2="1">
                    <stop offset="0" stopColor="#fff" stopOpacity="0.4" />
                    <stop offset="0.45" stopColor={color} />
                    <stop offset="1" stopColor="#000" stopOpacity="0.4" />
                </linearGradient>
            </defs>
            {!flat && <Ground id={id} cx={70} cy={126} rx={46} ry={5} />}
            <path d={outline} fill={color} />
            <path d={outline} fill={`url(#${id}-cloth)`} />
            {/* collar */}
            <path d="M42 10 Q54 28 70 28 Q86 28 98 10 Q86 18 70 18 Q54 18 42 10 Z" fill="#fff" opacity="0.4" />
            <path d="M52 12 Q70 34 88 12" fill="none" stroke="#000" strokeOpacity="0.25" strokeWidth="2" />
            {/* folds and stitching */}
            <path d="M44 66 Q52 90 46 120" fill="none" stroke="#000" strokeOpacity="0.14" strokeWidth="5" strokeLinecap="round" />
            <path d="M92 70 Q86 94 94 120" fill="none" stroke="#fff" strokeOpacity="0.18" strokeWidth="5" strokeLinecap="round" />
            <path d="M34 118 H106" stroke="#fff" strokeOpacity="0.55" strokeWidth="1.4" strokeDasharray="4 3" />
            <path d="M8 54 L24 62" stroke="#fff" strokeOpacity="0.5" strokeWidth="1.4" strokeDasharray="3 3" />
            <path d="M132 54 L116 62" stroke="#fff" strokeOpacity="0.5" strokeWidth="1.4" strokeDasharray="3 3" />
        </svg>
    );
}

/** The market: a shopping bag. */
export function Bag3D({ className, style, color = '#e8a317', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 110 134" className={className} style={style} aria-hidden>
            <defs>
                <linearGradient id={`${id}-front`} x1="0" x2="1">
                    <stop offset="0" stopColor="#fff" stopOpacity="0.4" />
                    <stop offset="0.35" stopColor={color} />
                    <stop offset="1" stopColor="#000" stopOpacity="0.28" />
                </linearGradient>
            </defs>
            {!flat && <Ground id={id} cx={55} cy={126} rx={44} ry={5} />}
            {/* handles */}
            <path d="M34 40 Q34 8 55 8 Q76 8 76 40" fill="none" stroke="#6a4a10" strokeWidth="5" strokeLinecap="round" />
            <path d="M34 40 Q34 12 55 12 Q76 12 76 40" fill="none" stroke="#fff" strokeOpacity="0.35" strokeWidth="1.5" strokeLinecap="round" />
            {/* body: side panel then front */}
            <path d="M80 34 L102 40 L100 118 L84 124 Z" fill={color} />
            <path d="M80 34 L102 40 L100 118 L84 124 Z" fill="#000" opacity="0.25" />
            <path d="M10 34 H84 L86 124 H6 Z" fill={color} />
            <path d="M10 34 H84 L86 124 H6 Z" fill={`url(#${id}-front)`} />
            <rect x="10" y="34" width="74" height="6" fill="#000" opacity="0.14" />
            {/* label */}
            <circle cx="46" cy="80" r="17" fill="#fbf7ee" />
            <path d="M38 80 H54 M46 72 V88" stroke={color} strokeWidth="5" strokeLinecap="round" />
            <rect x="14" y="44" width="8" height="74" rx="4" fill="#fff" opacity="0.3" />
        </svg>
    );
}

/** The help board: two speech bubbles. */
export function Chat3D({ className, style, color = '#4a8a3a', flat }: ArtProps) {
    const id = useUid();

    return (
        <svg viewBox="0 0 140 112" className={className} style={style} aria-hidden>
            <defs>
                <linearGradient id={`${id}-bubble`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stopColor="#fff" stopOpacity="0.5" />
                    <stop offset="0.35" stopColor={color} />
                    <stop offset="1" stopColor="#000" stopOpacity="0.4" />
                </linearGradient>
            </defs>
            {!flat && <Ground id={id} cx={70} cy={104} rx={50} ry={4} />}
            {/* back bubble */}
            <path d="M64 30 Q64 12 84 12 H118 Q136 12 136 30 V50 Q136 66 118 66 H112 L116 82 L94 66 H84 Q64 66 64 50 Z" fill="#fbf7ee" />
            <rect x="76" y="28" width="46" height="5" rx="2.5" fill={color} opacity="0.55" />
            <rect x="76" y="40" width="30" height="5" rx="2.5" fill={color} opacity="0.35" />
            {/* front bubble */}
            <path d="M8 46 Q8 26 30 26 H66 Q86 26 86 46 V68 Q86 88 66 88 H44 L22 104 L26 88 H30 Q8 88 8 68 Z" fill={color} />
            <path d="M8 46 Q8 26 30 26 H66 Q86 26 86 46 V68 Q86 88 66 88 H44 L22 104 L26 88 H30 Q8 88 8 68 Z" fill={`url(#${id}-bubble)`} />
            {[30, 47, 64].map((x) => (
                <circle key={x} cx={x} cy="57" r="5" fill="#fff" />
            ))}
            <path d="M16 38 Q22 32 34 32" fill="none" stroke="#fff" strokeOpacity="0.6" strokeWidth="3" strokeLinecap="round" />
        </svg>
    );
}
