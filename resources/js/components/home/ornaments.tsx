import { useId } from 'react';

/**
 * Vector ornament for the home page, drawn in the manner of an alpona, the rice-paste floor art of Bengal: petals laid in rings around a centre.
 * Gold gradients and a soft shadow give it body, and the two petal rings turn slowly against each other. It is decoration only, so it is hidden from assistive technology.
 */
export function Rosette({ className = '' }: { className?: string }) {
    const id = useId().replace(/:/g, '');
    const stop = (v: string) => ({ stopColor: `var(${v})` });
    const petal = 'M200 14 C 236 70 238 128 200 178 C 162 128 164 70 200 14 Z';
    const small = 'M200 70 C 222 104 222 138 200 168 C 178 138 178 104 200 70 Z';

    return (
        <svg viewBox="0 0 400 400" aria-hidden focusable="false" className={className}>
            <defs>
                <linearGradient id={`${id}a`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" style={stop('--art-gold-1')} />
                    <stop offset="0.55" style={stop('--art-gold-2')} />
                    <stop offset="1" style={stop('--art-gold-3')} />
                </linearGradient>
                <linearGradient id={`${id}b`} x1="1" y1="0" x2="0" y2="1">
                    <stop offset="0" style={stop('--art-gold-2')} />
                    <stop offset="1" style={stop('--art-gold-3')} />
                </linearGradient>
                <radialGradient id={`${id}c`} cx="0.4" cy="0.35" r="0.8">
                    <stop offset="0" style={stop('--art-gold-1')} />
                    <stop offset="1" style={stop('--art-gold-3')} />
                </radialGradient>
                <filter id={`${id}s`} x="-20%" y="-20%" width="140%" height="140%">
                    <feDropShadow dx="0" dy="6" stdDeviation="5" floodColor="black" floodOpacity="0.45" />
                </filter>
            </defs>

            <g filter={`url(#${id}s)`}>
                <circle cx="200" cy="200" r="190" fill="none" stroke={`url(#${id}a)`} strokeWidth="2" />
                <circle cx="200" cy="200" r="182" fill="none" stroke={`url(#${id}b)`} strokeWidth="1" strokeDasharray="2 6" />
                <g className="art-spin">
                    {Array.from({ length: 16 }, (_, i) => (
                        <path
                            key={`o${i}`}
                            d={petal}
                            transform={`rotate(${i * 22.5} 200 200)`}
                            fill={`url(#${id}a)`}
                            stroke="var(--art-night)"
                            strokeWidth="1.5"
                        />
                    ))}
                </g>
                <g className="art-spin-rev">
                    {Array.from({ length: 16 }, (_, i) => (
                        <path
                            key={`m${i}`}
                            d={small}
                            transform={`rotate(${i * 22.5 + 11.25} 200 200)`}
                            fill={`url(#${id}b)`}
                            stroke="var(--art-night)"
                            strokeWidth="1.5"
                        />
                    ))}
                </g>
                <circle cx="200" cy="200" r="46" fill="var(--art-night)" />
                <circle cx="200" cy="200" r="40" fill={`url(#${id}c)`} />
                {Array.from({ length: 12 }, (_, i) => (
                    <circle key={`d${i}`} cx="200" cy="168" r="3.5" transform={`rotate(${i * 30} 200 200)`} fill="var(--art-night)" />
                ))}
                <circle cx="200" cy="200" r="12" fill="var(--art-night)" />
            </g>
        </svg>
    );
}

/** A band of nested diamonds, after the woven border of a jamdani sari. Repeats to any width. */
export function DiamondBand({ className = '' }: { className?: string }) {
    const id = useId().replace(/:/g, '');

    return (
        <svg aria-hidden focusable="false" className={className} width="100%" height="28">
            <defs>
                <pattern id={id} width="28" height="28" patternUnits="userSpaceOnUse">
                    <path d="M14 2 L26 14 L14 26 L2 14 Z" fill="none" stroke="var(--art-gold-2)" strokeWidth="1.2" />
                    <path d="M14 8 L20 14 L14 20 L8 14 Z" fill="var(--art-gold-2)" />
                </pattern>
            </defs>
            <rect width="100%" height="28" fill={`url(#${id})`} />
        </svg>
    );
}

/** Shared gold for the three scenes below: gradients for line, body and glow, a night ground with a jamdani border, and a halo behind the subject. */
function SceneDefs({ id }: { id: string }) {
    const stop = (v: string, o?: number) => ({ stopColor: `var(${v})`, stopOpacity: o });

    return (
        <defs>
            <linearGradient id={`${id}l`} x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" style={stop('--art-gold-1')} />
                <stop offset="1" style={stop('--art-gold-2')} />
            </linearGradient>
            <linearGradient id={`${id}f`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" style={stop('--art-gold-1')} />
                <stop offset="0.5" style={stop('--art-gold-2')} />
                <stop offset="1" style={stop('--art-gold-3')} />
            </linearGradient>
            <linearGradient id={`${id}d`} x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" style={stop('--art-gold-3')} />
                <stop offset="1" style={stop('--art-gold-2')} />
            </linearGradient>
            <radialGradient id={`${id}h`} cx="0.5" cy="0.5" r="0.5">
                <stop offset="0" style={stop('--art-gold-2', 0.5)} />
                <stop offset="0.7" style={stop('--art-gold-3', 0.18)} />
                <stop offset="1" style={stop('--art-gold-3', 0)} />
            </radialGradient>
            <pattern id={`${id}p`} width="20" height="20" patternUnits="userSpaceOnUse">
                <path d="M10 2 L18 10 L10 18 L2 10 Z" fill="none" stroke="var(--art-gold-3)" strokeWidth="1.2" />
                <path d="M10 7 L13 10 L10 13 L7 10 Z" fill="var(--art-gold-2)" />
            </pattern>
        </defs>
    );
}

/** The halo, the dashed rings and the ground every scene stands on. */
function Stage({ id, ground = 186 }: { id: string; ground?: number }) {
    return (
        <g>
            <circle cx="160" cy="100" r="92" fill={`url(#${id}h)`} />
            <circle cx="160" cy="100" r="86" fill="none" stroke="var(--art-gold-3)" strokeWidth="1" strokeDasharray="3 7" />
            <circle cx="160" cy="100" r="70" fill="none" stroke="var(--art-gold-3)" strokeWidth="1" strokeDasharray="1 5" />
            <rect x="0" y={ground} width="320" height={220 - ground} fill="var(--art-night)" />
            <rect x="0" y={ground} width="320" height="20" fill={`url(#${id}p)`} opacity="0.85" />
            <path d={`M0 ${ground} H320`} stroke={`url(#${id}d)`} strokeWidth="2" />
        </g>
    );
}

const flower = (cx: number, cy: number, r: number, fill: string) => (
    <g>
        {Array.from({ length: 6 }, (_, i) => (
            <ellipse
                key={i}
                cx={cx}
                cy={cy - r}
                rx={r * 0.45}
                ry={r * 0.8}
                transform={`rotate(${i * 60} ${cx} ${cy})`}
                fill={fill}
                stroke="var(--art-night)"
                strokeWidth="0.8"
            />
        ))}
        <circle cx={cx} cy={cy} r={r * 0.4} fill="var(--art-night)" />
        <circle cx={cx} cy={cy} r={r * 0.2} fill="var(--art-gold-1)" />
    </g>
);

/** Choose: a bazaar stall. A striped awning with a scalloped hem, two lamps, pots, a basket heaped with fruit, a sack of grain. */
export function BazaarArt({ className = '' }: { className?: string }) {
    const id = useId().replace(/:/g, '');
    const line = `url(#${id}l)`;
    const fill = `url(#${id}f)`;
    const w = 28;
    const x0 = 66;

    return (
        <svg
            viewBox="0 0 320 220"
            aria-hidden
            focusable="false"
            className={className}
            fill="none"
            stroke={line}
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <SceneDefs id={id} />
            <Stage id={id} />
            {/* roof, finial and awning */}
            <path d="M78 46 H242 L254 72 H66 Z" fill={fill} />
            <path d="M160 46 V34" />
            <circle cx="160" cy="30" r="5" fill={fill} />
            {Array.from({ length: 7 }, (_, i) => {
                const a = x0 + i * w;

                return (
                    <path key={i} d={`M${a} 72 H${a + w} V94 A${w / 2} ${w / 2} 0 0 1 ${a} 94 Z`} fill={i % 2 === 0 ? fill : 'var(--art-night)'} />
                );
            })}
            {/* posts */}
            <rect x="72" y="100" width="6" height="86" fill={fill} stroke="none" />
            <rect x="242" y="100" width="6" height="86" fill={fill} stroke="none" />
            {/* lamps */}
            {[112, 208].map((x) => (
                <g key={x}>
                    <path d={`M${x} 98 V110`} />
                    <circle cx={x} cy="124" r="15" fill={`url(#${id}h)`} stroke="none" />
                    <path d={`M${x - 7} 110 H${x + 7} L${x + 9} 126 Q${x} 134 ${x - 9} 126 Z`} fill={fill} />
                </g>
            ))}
            {/* counter */}
            <rect x="58" y="146" width="204" height="40" rx="4" fill="var(--art-night-2)" />
            <rect x="58" y="146" width="204" height="9" rx="3" fill={fill} />
            {[88, 128, 168, 208, 238].map((x) => (
                <path key={x} d={`M${x} 160 l7 7 l-7 7 l-7 -7 Z`} strokeWidth="1.5" />
            ))}
            {/* pots */}
            <path d="M82 146 C 68 140 68 118 82 114 C 96 118 96 140 82 146 Z" fill={fill} />
            <path d="M77 114 H87 M79 111 H85" />
            <path d="M108 146 C 98 142 98 128 104 124 H112 C 118 128 118 142 108 146 Z" fill={fill} />
            {/* the basket, heaped with fruit */}
            {[146, 158, 170, 152, 164].map((x, i) => (
                <circle key={x} cx={x} cy={i < 3 ? 116 : 108} r="8" fill={fill} />
            ))}
            <path d="M138 146 L144 120 H182 L188 146 Z" fill="var(--art-night-2)" />
            <path d="M141 133 H185 M139 140 H187 M152 120 L150 146 M163 120 V146 M174 120 L176 146" strokeWidth="1.2" />
            {/* a sack of grain, tied at the neck */}
            <path d="M206 146 C 196 140 198 120 208 114 L218 114 C 228 120 230 140 220 146 Z" fill={fill} />
            <path d="M206 114 C 208 108 218 108 220 114" />
            <path d="M210 124 h1 M216 130 h1 M212 136 h1" strokeWidth="3" />
            {/* a few coins, scattered */}
            <circle cx="236" cy="142" r="3" fill={fill} />
            <circle cx="244" cy="144" r="3" fill={fill} />
        </svg>
    );
}

/** Order: a parcel in three tones, tied with twine and a bow, a tag on a string, and a running stitch round it like a nakshi kantha border. */
export function ParcelArt({ className = '' }: { className?: string }) {
    const id = useId().replace(/:/g, '');
    const line = `url(#${id}l)`;
    const fill = `url(#${id}f)`;

    return (
        <svg
            viewBox="0 0 320 220"
            aria-hidden
            focusable="false"
            className={className}
            fill="none"
            stroke={line}
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <SceneDefs id={id} />
            <Stage id={id} />
            <rect x="14" y="12" width="292" height="196" rx="14" strokeDasharray="7 8" strokeWidth="1.5" opacity="0.7" />
            {/* the box: top, left, right */}
            <path d="M160 52 L236 90 L160 128 L84 90 Z" fill={`url(#${id}l)`} stroke="var(--art-night)" />
            <path d="M84 90 L160 128 V188 L84 150 Z" fill="var(--art-gold-3)" stroke="var(--art-night)" />
            <path d="M160 128 L236 90 V150 L160 188 Z" fill="var(--art-gold-2)" stroke="var(--art-night)" />
            {/* twine over the top and down both sides */}
            <path d="M122 71 L198 109 V169" stroke="var(--art-night)" strokeWidth="10" />
            <path d="M198 71 L122 109 V169" stroke="var(--art-night)" strokeWidth="10" />
            <path d="M122 71 L198 109 V169" stroke="var(--art-gold-1)" strokeWidth="5" />
            <path d="M198 71 L122 109 V169" stroke="var(--art-gold-1)" strokeWidth="5" />
            {/* bow */}
            <path d="M160 90 C 128 56 106 74 124 88 C 134 94 152 92 160 90 Z" fill={fill} stroke="var(--art-night)" />
            <path d="M160 90 C 192 56 214 74 196 88 C 186 94 168 92 160 90 Z" fill={fill} stroke="var(--art-night)" />
            <circle cx="160" cy="90" r="6" fill="var(--art-gold-1)" stroke="var(--art-night)" />
            {/* the tag on its string */}
            <path d="M162 94 C 170 124 200 134 208 152" strokeWidth="1.5" />
            <path d="M200 148 L218 144 L224 162 L206 168 Z" fill={fill} stroke="var(--art-night)" />
            <path d="M211 154 l4 4 l-4 4 l-4 -4 Z" fill="var(--art-night)" stroke="none" />
            {/* sparkles */}
            {[
                [48, 52, 8],
                [276, 70, 6],
                [262, 168, 8],
                [40, 150, 5],
            ].map(([x, y, r]) => (
                <path
                    key={`${x}${y}`}
                    d={`M${x} ${y - r} L${x + r * 0.28} ${y - r * 0.28} L${x + r} ${y} L${x + r * 0.28} ${y + r * 0.28} L${x} ${y + r} L${x - r * 0.28} ${y + r * 0.28} L${x - r} ${y} L${x - r * 0.28} ${y - r * 0.28} Z`}
                    fill={fill}
                    stroke="none"
                />
            ))}
        </svg>
    );
}

/** Receive: the Dhaka rickshaw. A painted hood in fanned bands, flowers on the side panel, a parcel on the seat, a flag, and wheels that turn. */
export function RickshawArt({ className = '' }: { className?: string }) {
    const id = useId().replace(/:/g, '');
    const line = `url(#${id}l)`;
    const fill = `url(#${id}f)`;
    const wheel = (cx: number, cy: number, r: number) => (
        <g>
            <circle cx={cx} cy={cy} r={r} stroke="var(--art-night)" strokeWidth="6" />
            <g className="art-wheel">
                <circle cx={cx} cy={cy} r={r} strokeWidth="3" />
                <circle cx={cx} cy={cy} r={r - 6} strokeWidth="1" />
                {Array.from({ length: 16 }, (_, i) => (
                    <path key={i} d={`M${cx} ${cy - 6} V${cy - r + 6}`} transform={`rotate(${(i * 360) / 16} ${cx} ${cy})`} strokeWidth="1" />
                ))}
                <circle cx={cx} cy={cy} r="6" fill={fill} />
            </g>
        </g>
    );

    return (
        <svg
            viewBox="0 0 320 220"
            aria-hidden
            focusable="false"
            className={className}
            fill="none"
            stroke={line}
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <SceneDefs id={id} />
            <Stage id={id} ground={190} />
            {/* speed lines */}
            <path d="M16 120 H46 M10 136 H40 M22 152 H44" strokeWidth="1.5" opacity="0.6" />
            {wheel(92, 152, 38)}
            {wheel(246, 160, 30)}
            {/* frame and fork */}
            <path d="M92 152 H176 L200 160" strokeWidth="3" />
            <path d="M246 160 L224 112 H214" strokeWidth="3" />
            <path d="M214 112 L228 98 H242" strokeWidth="3" />
            <circle cx="246" cy="96" r="5" fill={fill} />
            {/* the puller's saddle */}
            <path d="M190 128 Q204 120 218 128 Z" fill={fill} />
            <path d="M204 128 V152" />
            {/* the passenger's seat and its painted side panel */}
            <path d="M68 84 V122 H168 V108" />
            <rect x="70" y="122" width="100" height="26" rx="4" fill="var(--art-night-2)" />
            <rect x="70" y="122" width="100" height="8" rx="3" fill={fill} />
            {flower(96, 140, 8, fill)}
            {flower(120, 140, 8, fill)}
            {flower(144, 140, 8, fill)}
            {/* a parcel on the seat */}
            <path d="M92 122 V104 H126 V122 Z" fill={fill} />
            <path d="M109 104 V122 M92 113 H126" stroke="var(--art-night)" strokeWidth="2" />
            {/* the hood: three nested arcs, the painted rainbow bands a Dhaka rickshaw is known for */}
            <path d="M62 120 C 56 52 130 36 186 98 L186 120 Z" fill="var(--art-night-2)" stroke="none" />
            {[1, 0.84, 0.68].map((k, i) => (
                <path
                    key={k}
                    d="M62 120 C 56 52 130 36 186 98"
                    transform={`translate(124 120) scale(${k}) translate(-124 -120)`}
                    stroke={i % 2 === 0 ? fill : 'var(--art-gold-3)'}
                    strokeWidth={i === 0 ? 7 : 6}
                />
            ))}
            <path d="M62 120 H186" strokeWidth="3" />
            {/* the flag */}
            <path d="M118 36 V14 L142 21 L118 28" fill={fill} />
        </svg>
    );
}
