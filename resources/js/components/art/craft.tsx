import { useI18n } from '@/lib/i18n';
import { type ReactNode } from 'react';

/**
 * Hand-drawn vector craft: thick ink outlines, flat colour, hard offset shadows and rickshaw-art bunting,
 * in the aqua palette. Everything is plain SVG, so it stays sharp at any size and weighs a few kilobytes.
 * All of it is decorative (the shops are also listed as real text on the page).
 */
const INK = '#14120f';
const CREAM = '#fff6e5';
const SW = 3;

const outline = { stroke: INK, strokeWidth: SW, strokeLinejoin: 'round', strokeLinecap: 'round' } as const;

/** A line of triangular flags, sagging between two posts. */
export function Bunting({ className = '' }: { className?: string }) {
    const colors = ['#c9a24b', '#ffc83d', '#b8864b', '#f5e6bd', '#ff6fa3', '#7b6cf0'];
    const flags = Array.from({ length: 24 }, (_, i) => {
        const x = i * 50 + 25;
        const t = x / 1200;
        const y = (1 - t) ** 2 * 8 + 2 * (1 - t) * t * 50 + t ** 2 * 8;

        return { x, y, c: colors[i % colors.length] };
    });

    return (
        <svg viewBox="0 0 1200 72" preserveAspectRatio="none" aria-hidden className={`block h-10 w-full sm:h-14 ${className}`}>
            <path d="M0 8Q600 50 1200 8" fill="none" {...outline} strokeWidth={2.5} />
            {flags.map(({ x, y, c }) => (
                <path key={x} d={`M${x - 15} ${y}L${x + 15} ${y}L${x} ${y + 32}Z`} fill={c} {...outline} strokeWidth={2.2} />
            ))}
        </svg>
    );
}

/** A four-point sparkle with a white sticker edge. */
export function Sparkle({ className = '', color = '#ffc83d' }: { className?: string; color?: string }) {
    return (
        <svg viewBox="0 0 60 60" aria-hidden className={className}>
            <path d="M30 4C33 20 40 27 56 30 40 33 33 40 30 56 27 40 20 33 4 30 20 27 27 20 30 4Z" fill={color} stroke="#fff" strokeWidth="6" strokeLinejoin="round" paintOrder="stroke" />
            <path d="M30 4C33 20 40 27 56 30 40 33 33 40 30 56 27 40 20 33 4 30 20 27 27 20 30 4Z" fill={color} {...outline} strokeWidth={2.5} />
        </svg>
    );
}

interface Shop {
    key: string;
    en: string;
    bn: string;
    body: string;
    accent: string;
    glass: string;
    h: number;
    open?: boolean;
    props: ReactNode;
}

const stroke2 = { ...outline, strokeWidth: 2.4 } as const;

/** What sits in each shop window: one simple, readable object per trade. */
const SHOPS: Shop[] = [
    {
        key: 'pharmacy',
        en: 'Pharmacy',
        bn: 'ফার্মেসি',
        body: '#f5e6bd',
        accent: '#c9a24b',
        glass: '#ffe58a',
        h: 172,
        open: true,
        props: (
            <>
                <path d="M-9 -22h18v13h13v18h-13v13h-18v-13h-13v-18h13z" fill="#c9a24b" {...stroke2} />
                <rect x="14" y="2" width="26" height="11" rx="5.5" transform="rotate(-30 27 7)" fill="#fff" {...stroke2} />
            </>
        ),
    },
    {
        key: 'food',
        en: 'Food',
        bn: 'খাবার',
        body: '#ffd1bd',
        accent: '#b8864b',
        glass: '#ffe0d2',
        h: 160,
        props: (
            <>
                <path d="M-22 -2h44v16a14 14 0 0 1-14 14h-16a14 14 0 0 1-14-14z" fill="#ffc83d" {...stroke2} />
                <path d="M-22 4h-8M22 4h8" fill="none" {...stroke2} />
                <path d="M-10 -8q-6-8 0-14M0 -8q-6-8 0-14M10 -8q-6-8 0-14" fill="none" {...stroke2} />
            </>
        ),
    },
    {
        key: 'games',
        en: 'Gaming',
        bn: 'গেমিং',
        body: '#d9ccff',
        accent: '#7b6cf0',
        glass: '#c8b8ff',
        h: 176,
        props: (
            <>
                <path d="M-26 -8h52a10 10 0 0 1 10 10l3 14a8 8 0 0 1-13 6l-6-6h-40l-6 6a8 8 0 0 1-13-6l3-14a10 10 0 0 1 10-10z" fill="#c9a24b" {...stroke2} />
                <path d="M-16 0v10M-21 5h10" fill="none" {...stroke2} />
                <circle cx="14" cy="2" r="3.2" fill="#ffc83d" {...stroke2} />
                <circle cx="22" cy="9" r="3.2" fill="#b8864b" {...stroke2} />
            </>
        ),
    },
    {
        key: 'custom',
        en: 'Custom',
        bn: 'মাপমতো',
        body: '#f0cf9b',
        accent: '#c98a3f',
        glass: '#f7e1bd',
        h: 156,
        props: (
            <>
                <rect x="-18" y="-24" width="36" height="52" rx="3" fill="#b9803a" {...stroke2} />
                <path d="M0 -24v52" fill="none" {...stroke2} />
                <circle cx="-5" cy="2" r="2" fill={CREAM} stroke={INK} strokeWidth="1.6" />
                <circle cx="5" cy="2" r="2" fill={CREAM} stroke={INK} strokeWidth="1.6" />
                <path d="M26 -20l12 40" fill="none" stroke="#ffc83d" strokeWidth="7" />
                <path d="M26 -20l12 40" fill="none" {...stroke2} strokeDasharray="2 5" />
            </>
        ),
    },
    {
        key: 'market',
        en: 'The Market',
        bn: 'বাজার',
        body: '#dff3b0',
        accent: '#6fae3d',
        glass: '#eefbd0',
        h: 170,
        props: (
            <>
                <path d="M-24 0h48l-6 26h-36z" fill="#d9a05b" {...stroke2} />
                <circle cx="-10" cy="-6" r="9" fill="#b8864b" {...stroke2} />
                <circle cx="6" cy="-8" r="9" fill="#ffc83d" {...stroke2} />
                <circle cx="0" cy="-16" r="8" fill="#6fae3d" {...stroke2} />
            </>
        ),
    },
    {
        key: 'help',
        en: 'Help board',
        bn: 'সাহায্য',
        body: '#ffd6e5',
        accent: '#ff6fa3',
        glass: '#ffe9f1',
        h: 162,
        props: (
            <>
                <path d="M-26 -18h52a6 6 0 0 1 6 6v22a6 6 0 0 1-6 6h-30l-12 10v-10h-10a6 6 0 0 1-6-6v-22a6 6 0 0 1 6-6z" fill="#fff" {...stroke2} />
                <path d="M0 14c-14-9-14-20-6-22 4-1 6 2 6 4 0-2 2-5 6-4 8 2 8 13 0 22z" fill="#ff6fa3" {...stroke2} transform="translate(0 -4) scale(.9)" />
            </>
        ),
    },
];

const W = 170;
const GAP = 25;
const GROUND = 226;

function Shopfront({ shop, x, label, soon }: { shop: Shop; x: number; label: string; soon: string }) {
    const top = GROUND - shop.h;
    const stripes = 6;
    const sw = (W - 8) / stripes;
    const winY = top + 80;

    return (
        <g transform={`translate(${x} 0)`}>
            {/* hard shadow, then the building */}
            <rect x="7" y={top + 7} width={W} height={shop.h} rx="6" fill={INK} />
            <rect x="0" y={top} width={W} height={shop.h} rx="6" fill={shop.body} {...outline} />
            {/* roof edge */}
            <rect x="-6" y={top - 9} width={W + 12} height="12" rx="4" fill={shop.accent} {...outline} />

            {/* signboard */}
            <rect x="14" y={top + 12} width={W - 28} height="32" rx="9" fill={CREAM} {...outline} />
            <text x={W / 2} y={top + 35} textAnchor="middle" fontSize="19" fontWeight="800" fill={INK} style={{ fontFamily: "'Baloo Da 2','Hind Siliguri',sans-serif" }}>
                {label}
            </text>

            {/* scalloped awning */}
            {Array.from({ length: stripes }).map((_, i) => (
                <path key={i} d={`M${4 + i * sw} ${top + 50}h${sw}v14a${sw / 2} ${sw / 2} 0 0 1 ${-sw} 0z`} fill={i % 2 === 0 ? shop.accent : CREAM} {...outline} strokeWidth={2.4} />
            ))}

            {/* window */}
            <rect x="14" y={winY} width="86" height="62" rx="6" fill={shop.glass} {...outline} />
            <g transform={`translate(${57} ${winY + 32})`}>{shop.props}</g>
            {shop.open ? (
                <path d={`M20 ${winY + 8}l22 -0 -22 22z`} fill="#fff" opacity=".55" />
            ) : (
                <g>
                    <rect x="14" y={winY} width="86" height="36" rx="6" fill="#b8c4c8" {...outline} />
                    {[10, 20, 30].map((d) => (
                        <path key={d} d={`M16 ${winY + d}h82`} stroke={INK} strokeWidth="1.6" />
                    ))}
                </g>
            )}

            {/* door */}
            <rect x="110" y={GROUND - 74} width="46" height="74" rx="5" fill={shop.open ? '#8a6a2b' : '#8f9ea3'} {...outline} />
            <circle cx="147" cy={GROUND - 36} r="3" fill="#ffc83d" stroke={INK} strokeWidth="1.8" />
            {shop.open ? (
                <>
                    <rect x="117" y={GROUND - 64} width="32" height="20" rx="4" fill="#fff" {...outline} strokeWidth={2.2} />
                    <text x="133" y={GROUND - 49} textAnchor="middle" fontSize="12" fontWeight="800" fill="#8a6a2b" style={{ fontFamily: "'Baloo Da 2',sans-serif" }}>
                        {label === shop.bn ? 'খোলা' : 'OPEN'}
                    </text>
                </>
            ) : (
                <g transform={`rotate(-6 133 ${GROUND - 50})`}>
                    <rect x="114" y={GROUND - 62} width="38" height="22" rx="4" fill={CREAM} {...outline} strokeWidth={2.2} />
                    <text x="133" y={GROUND - 47} textAnchor="middle" fontSize="13" fontWeight="800" fill={INK} style={{ fontFamily: "'Baloo Da 2','Hind Siliguri',sans-serif" }}>
                        {soon}
                    </text>
                </g>
            )}
        </g>
    );
}

/** One street, six shops: the pharmacy lit up and open, the rest shuttered until they open. */
export function ShopStreet({ className = '' }: { className?: string }) {
    const { t, isBn } = useI18n();
    const total = SHOPS.length * W + (SHOPS.length - 1) * GAP;
    const start = (1200 - total) / 2;

    return (
        <svg viewBox="0 0 1200 250" aria-hidden className={`block h-auto w-full ${className}`}>
            {/* soft hills and clouds behind the shops */}
            <path d="M0 170Q150 110 320 160T640 150T960 160T1200 130V226H0Z" fill="#cfeae3" opacity=".7" />
            <path d="M0 190Q200 150 420 185T820 180T1200 175V226H0Z" fill="#b7ddd3" opacity=".6" />
            {[
                [150, 40, 1],
                [560, 28, 0.8],
                [860, 52, 1.1],
            ].map(([cx, cy, k]) => (
                <g key={cx} transform={`translate(${cx} ${cy}) scale(${k})`}>
                    <path d="M-34 12a14 14 0 0 1 4-27 20 20 0 0 1 38-4 16 16 0 0 1 22 14 11 11 0 0 1-2 17z" fill="#fff" {...outline} strokeWidth={2.4} />
                </g>
            ))}

            {/* sun */}
            <circle cx="1080" cy="40" r="26" fill="#ffc83d" {...outline} />
            <path d="M1030 40h-14M1080 -2v-10M1130 40h14M1045 5l-9-9M1115 5l9-9" stroke={INK} strokeWidth="3" strokeLinecap="round" />

            {SHOPS.map((shop, i) => {
                const x = start + i * (W + GAP);

                return <Shopfront key={shop.key} shop={shop} x={x} label={isBn ? shop.bn : shop.en} soon={t('Soon')} />;
            })}

            {/* lamp posts in the gaps */}
            {SHOPS.slice(0, -1).map((s, i) => {
                const x = start + i * (W + GAP) + W + GAP / 2 + 3;

                return (
                    <g key={`lamp-${s.key}`}>
                        <path d={`M${x} ${GROUND}V${GROUND - 120}`} stroke={INK} strokeWidth="4" strokeLinecap="round" />
                        <circle cx={x} cy={GROUND - 124} r="7" fill="#ffc83d" {...outline} strokeWidth={2.4} />
                    </g>
                );
            })}

            {/* pavement */}
            <rect x="0" y={GROUND} width="1200" height="24" fill="#d7e3df" stroke={INK} strokeWidth={SW} />
            <path d={`M0 ${GROUND + 12}h1200`} stroke={INK} strokeWidth="1.5" strokeDasharray="14 12" opacity=".5" />
        </svg>
    );
}
