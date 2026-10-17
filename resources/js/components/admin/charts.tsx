import { useId, useRef, useState } from 'react';

/**
 * Small, dependency-free SVG charts for the dashboard. They draw from the numbers they are given, scale to any width, show their values on hover or
 * focus, and never rely on colour alone (every series is also named in the legend and the tooltip).
 */

export interface Series {
    key: string;
    label: string;
    color: string;
}

/** A smooth line through the points (Catmull-Rom turned into Bezier curves), so a month of orders reads as a shape, not a saw. */
function smooth(pts: [number, number][]): string {
    if (pts.length < 2) return '';
    let d = `M${pts[0][0]},${pts[0][1]}`;
    for (let i = 0; i < pts.length - 1; i++) {
        const p0 = pts[i - 1] ?? pts[i];
        const p1 = pts[i];
        const p2 = pts[i + 1];
        const p3 = pts[i + 2] ?? p2;
        d += ` C${p1[0] + (p2[0] - p0[0]) / 6},${p1[1] + (p2[1] - p0[1]) / 6} ${p2[0] - (p3[0] - p1[0]) / 6},${p2[1] - (p3[1] - p1[1]) / 6} ${p2[0]},${p2[1]}`;
    }
    return d;
}

const nice = (max: number): number => {
    if (max <= 0) return 1;
    const mag = 10 ** Math.floor(Math.log10(max));
    const f = max / mag;

    return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * mag;
};

export function StackedArea({
    rows,
    series,
    format,
    short,
    dayLabel,
}: {
    rows: ({ day: string } & Record<string, number | string>)[];
    series: Series[];
    format: (n: number) => string;
    short: (n: number) => string;
    dayLabel: (iso: string) => string;
}) {
    const id = useId().replace(/:/g, '');
    const box = useRef<HTMLDivElement>(null);
    const [hover, setHover] = useState<number | null>(null);
    const W = 760;
    const H = 260;
    const pad = { l: 46, r: 12, t: 14, b: 26 };
    const totals = rows.map((r) => series.reduce((s, x) => s + Number(r[x.key] ?? 0), 0));
    const top = nice(Math.max(...totals, 1));
    const x = (i: number) => pad.l + (i / Math.max(1, rows.length - 1)) * (W - pad.l - pad.r);
    const y = (v: number) => pad.t + (1 - v / top) * (H - pad.t - pad.b);

    // Stack from the first series upward.
    const layers = series.map((s, si) => {
        const upper = rows.map((r, i) => series.slice(0, si + 1).reduce((a, k) => a + Number(r[k.key] ?? 0), 0) + 0 * i);
        const lower = rows.map((r) => series.slice(0, si).reduce((a, k) => a + Number(r[k.key] ?? 0), 0));
        const up = upper.map((v, i) => [x(i), y(v)] as [number, number]);
        const lo = lower.map((v, i) => [x(i), y(v)] as [number, number]).reverse();

        return { s, line: smooth(up), area: `${smooth(up)} L${lo[0][0]},${lo[0][1]} ${smooth(lo).replace(/^M[^C]*/, '')} Z` };
    });

    const move = (clientX: number) => {
        const r = box.current?.getBoundingClientRect();
        if (!r || rows.length === 0) return;
        const px = ((clientX - r.left) / r.width) * W;
        setHover(Math.min(rows.length - 1, Math.max(0, Math.round(((px - pad.l) / (W - pad.l - pad.r)) * (rows.length - 1)))));
    };

    const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => f * top);
    const labelAt = [0, Math.floor((rows.length - 1) / 2), rows.length - 1];

    return (
        <div ref={box} className="relative" onPointerMove={(e) => move(e.clientX)} onPointerLeave={() => setHover(null)}>
            <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={series.map((s) => s.label).join(', ')} className="block h-auto w-full touch-pan-y" tabIndex={0} onKeyDown={(e) => {
                if (e.key === 'ArrowRight') setHover((h) => Math.min(rows.length - 1, (h ?? -1) + 1));
                if (e.key === 'ArrowLeft') setHover((h) => Math.max(0, (h ?? rows.length) - 1));
                if (e.key === 'Escape') setHover(null);
            }}>
                <defs>
                    {series.map((s) => (
                        <linearGradient key={s.key} id={`${id}-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor={s.color} stopOpacity="0.55" />
                            <stop offset="100%" stopColor={s.color} stopOpacity="0.06" />
                        </linearGradient>
                    ))}
                </defs>
                {ticks.map((v) => (
                    <g key={v}>
                        <line x1={pad.l} x2={W - pad.r} y1={y(v)} y2={y(v)} stroke="currentColor" strokeOpacity={v === 0 ? 0.28 : 0.1} strokeDasharray={v === 0 ? undefined : '3 5'} />
                        <text x={pad.l - 8} y={y(v) + 4} textAnchor="end" className="fill-current text-[11px] opacity-60">
                            {short(v)}
                        </text>
                    </g>
                ))}
                {layers.map(({ s, area }) => (
                    <path key={s.key} d={area} fill={`url(#${id}-${s.key})`} />
                ))}
                {layers.map(({ s, line }) => (
                    <path key={s.key} d={line} fill="none" stroke={s.color} strokeWidth={1.8} strokeLinecap="round" />
                ))}
                {labelAt.map((i, n) => (
                    <text key={i} x={x(i)} y={H - 6} textAnchor={n === 0 ? 'start' : n === 2 ? 'end' : 'middle'} className="fill-current text-[11px] opacity-60">
                        {rows[i] ? dayLabel(rows[i].day) : ''}
                    </text>
                ))}
                {hover !== null && rows[hover] && (
                    <g>
                        <line x1={x(hover)} x2={x(hover)} y1={pad.t} y2={H - pad.b} stroke="currentColor" strokeOpacity={0.35} />
                        <circle cx={x(hover)} cy={y(totals[hover])} r={4.5} className="fill-background" stroke="currentColor" strokeWidth={1.8} />
                    </g>
                )}
            </svg>
            {hover !== null && rows[hover] && (
                <div
                    role="status"
                    className="glass pointer-events-none absolute top-2 z-10 min-w-40 rounded-xl px-3 py-2 text-xs"
                    style={{ left: `${(x(hover) / W) * 100}%`, transform: `translateX(${hover > rows.length * 0.6 ? 'calc(-100% - 12px)' : '12px'})` }}
                >
                    <p className="font-semibold">{dayLabel(rows[hover].day)}</p>
                    <ul className="mt-1 space-y-0.5">
                        {series.filter((s) => Number(rows[hover][s.key] ?? 0) > 0).map((s) => (
                            <li key={s.key} className="flex items-center justify-between gap-4">
                                <span className="flex items-center gap-1.5">
                                    <span aria-hidden className="size-2 rounded-full" style={{ background: s.color }} />
                                    {s.label}
                                </span>
                                <span className="tabular-nums">{format(Number(rows[hover][s.key]))}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-1 flex justify-between border-t border-current/15 pt-1 font-semibold">
                        <span>{'Σ'}</span>
                        <span className="tabular-nums">{format(totals[hover])}</span>
                    </p>
                </div>
            )}
        </div>
    );
}

export function Donut({ parts, center, sub, format }: { parts: { key: string; label: string; value: number; color: string }[]; center: string; sub: string; format: (n: number) => string }) {
    const total = parts.reduce((s, p) => s + p.value, 0);
    const r = 70;
    const c = 2 * Math.PI * r;
    let acc = 0;

    return (
        <div className="flex flex-col items-center gap-5 sm:flex-row lg:flex-col xl:flex-row">
            <div className="relative size-44 shrink-0">
                <svg viewBox="0 0 200 200" role="img" aria-label={parts.map((p) => `${p.label} ${format(p.value)}`).join(', ')} className="size-full -rotate-90">
                    <circle cx="100" cy="100" r={r} fill="none" stroke="currentColor" strokeOpacity={0.08} strokeWidth={20} />
                    {total > 0 &&
                        parts.filter((p) => p.value > 0).map((p) => {
                            const len = (p.value / total) * c;
                            const el = <circle key={p.key} cx="100" cy="100" r={r} fill="none" stroke={p.color} strokeWidth={20} strokeDasharray={`${Math.max(0, len - 3)} ${c}`} strokeDashoffset={-acc} strokeLinecap="butt" />;
                            acc += len;

                            return el;
                        })}
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
                    <p className="text-xl font-semibold tabular-nums">{center}</p>
                    <p className="text-[11px] opacity-60">{sub}</p>
                </div>
            </div>
            <ul className="w-full space-y-2 text-sm">
                {parts.map((p) => (
                    <li key={p.key} className="flex items-center justify-between gap-3">
                        <span className="flex items-center gap-2">
                            <span aria-hidden className="size-2.5 rounded-full" style={{ background: p.color }} />
                            {p.label}
                        </span>
                        <span className="tabular-nums opacity-80">{total > 0 ? `${Math.round((p.value / total) * 100)}%` : '–'}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function Bars({ rows, format, color }: { rows: { label: string; value: number }[]; format: (n: number) => string; color: string }) {
    const max = Math.max(1, ...rows.map((r) => r.value));

    return (
        <ul className="space-y-3.5">
            {rows.map((r) => (
                <li key={r.label}>
                    <div className="mb-1 flex items-baseline justify-between gap-3 text-sm">
                        <span className="truncate">{r.label}</span>
                        <span className="tabular-nums opacity-80">{format(r.value)}</span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-current/10">
                        <div className="h-full rounded-full" style={{ width: `${Math.max(3, (r.value / max) * 100)}%`, background: `linear-gradient(90deg, ${color}, ${color}aa)` }} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

export function Spark({ values, color }: { values: number[]; color: string }) {
    const id = useId().replace(/:/g, '');
    const W = 120;
    const H = 36;
    const max = Math.max(1, ...values);
    const pts = values.map((v, i) => [(i / Math.max(1, values.length - 1)) * W, H - 3 - (v / max) * (H - 8)] as [number, number]);
    const line = smooth(pts);

    return (
        <svg viewBox={`0 0 ${W} ${H}`} aria-hidden className="h-9 w-28">
            <defs>
                <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={color} stopOpacity="0.4" />
                    <stop offset="100%" stopColor={color} stopOpacity="0" />
                </linearGradient>
            </defs>
            {pts.length > 1 && <path d={`${line} L${W},${H} L0,${H} Z`} fill={`url(#${id})`} />}
            <path d={line} fill="none" stroke={color} strokeWidth={1.8} strokeLinecap="round" />
        </svg>
    );
}

/** Up or down against the thirty days before, in words and in a sign, never in colour alone. */
export function Delta({ now, was, label }: { now: number; was: number; label: string }) {
    if (was <= 0) return <span className="text-xs opacity-60">{label}</span>;
    const pct = ((now - was) / was) * 100;
    const up = pct >= 0;

    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${up ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-300' : 'bg-rose-500/15 text-rose-600 dark:text-rose-300'}`}>
            <span aria-hidden>{up ? '▲' : '▼'}</span>
            {Math.abs(Math.round(pct))}%
        </span>
    );
}
