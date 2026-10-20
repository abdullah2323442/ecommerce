import { useI18n } from '@/lib/i18n';
import { ChevronLeft, ChevronRight, Pause, Play, Star } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface Voice {
    name: string;
    place: string | null;
    rating: number;
    quote: string;
}

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };
const BODY = { fontFamily: "'Noto Serif Bengali', Georgia, serif" };

function Stars({ n, className = 'size-5' }: { n: number; className?: string }) {
    return (
        <span className="flex gap-0.5" role="img" aria-label={`${n} / 5`}>
            {[1, 2, 3, 4, 5].map((i) => (
                <Star
                    key={i}
                    aria-hidden
                    className={`${className} ${i <= n ? 'fill-[var(--art-gold-2)] text-[var(--art-gold-2)]' : 'text-[var(--pt-line)]'}`}
                    strokeWidth={1.5}
                />
            ))}
        </span>
    );
}

const initials = (name: string) =>
    name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w.charAt(0))
        .join('');

/**
 * What buyers say. One quote is held up large, the next three wait beside it and can be chosen, and the whole turns by itself every eight
 * seconds: it stops when you touch it or press Pause, and never turns for anyone who asked their device for less motion. The words come from
 * the shop's own list (Admin > Testimonials); with none, the section is not drawn.
 */
export default function Testimonials({ voices }: { voices: Voice[] }) {
    const { t, num } = useI18n();
    const [at, setAt] = useState(0);
    const [paused, setPaused] = useState(false);
    const [hover, setHover] = useState(false);
    const [still, setStill] = useState(false);

    useEffect(() => {
        const q = window.matchMedia('(prefers-reduced-motion: reduce)');
        setStill(q.matches);
        const on = () => setStill(q.matches);
        q.addEventListener('change', on);

        return () => q.removeEventListener('change', on);
    }, []);

    useEffect(() => {
        if (paused || hover || still || voices.length < 2) return;
        const id = window.setInterval(() => setAt((i) => (i + 1) % voices.length), 8000);

        return () => window.clearInterval(id);
    }, [paused, hover, still, voices.length]);

    if (voices.length === 0) return null;

    const go = (n: number) => setAt((n + voices.length) % voices.length);
    const lead = voices[at % voices.length];
    const next = [1, 2, 3].map((k) => (at + k) % voices.length).filter((i, k, all) => i !== at && all.indexOf(i) === k);
    const average = voices.reduce((n, v) => n + v.rating, 0) / voices.length;

    return (
        <section
            aria-roledescription="carousel"
            aria-label={t('What buyers say')}
            onMouseEnter={() => setHover(true)}
            onMouseLeave={() => setHover(false)}
            onFocus={() => setHover(true)}
            onBlur={() => setHover(false)}
            className="reveal mx-auto mt-28 w-full max-w-6xl px-4 pb-24"
        >
            <div className="flex flex-wrap items-end justify-between gap-6">
                <h2 style={DISPLAY} className="text-6xl leading-[1.05] font-normal tracking-tight text-balance">
                    {t('What buyers say')}
                </h2>
                <div className="flex items-center gap-4">
                    <p style={DISPLAY} className="tabular text-5xl leading-none">
                        {num(Math.round(average * 10) / 10)}
                    </p>
                    <div>
                        <Stars n={Math.round(average)} />
                        <p className="mt-1 text-sm text-[var(--pt-muted)]">{t('from {n} buyers', { n: num(voices.length) })}</p>
                    </div>
                </div>
            </div>

            <div className="mt-10 grid grid-cols-12 gap-10">
                <figure
                    key={at}
                    className="art art-swap relative isolate col-span-7 flex flex-col justify-between overflow-hidden rounded-[2rem] bg-[var(--art-night)] p-12 text-[var(--pt-paper)]"
                >
                    <svg viewBox="0 0 90 56" aria-hidden focusable="false" className="w-20 text-[var(--art-gold-2)]" fill="currentColor">
                        <path d="M6 48 V28 C6 14 14 6 30 2 L33 9 C24 12 20 18 20 24 H32 V48 Z M52 48 V28 C52 14 60 6 76 2 L79 9 C70 12 66 18 66 24 H78 V48 Z" />
                    </svg>
                    <blockquote style={BODY} className="mt-8 text-3xl leading-[1.5] text-balance">
                        {lead.quote}
                    </blockquote>
                    <figcaption className="mt-10 flex items-center justify-between gap-4">
                        <span className="flex items-center gap-4">
                            <span
                                aria-hidden
                                style={DISPLAY}
                                className="grid size-14 shrink-0 place-items-center rounded-full border border-[var(--art-gold-2)] text-xl text-[var(--art-gold-2)]"
                            >
                                {initials(lead.name)}
                            </span>
                            <span>
                                <span className="block text-lg">{lead.name}</span>
                                {lead.place && <span className="block text-sm opacity-70">{lead.place}</span>}
                            </span>
                        </span>
                        <Stars n={lead.rating} />
                    </figcaption>
                </figure>

                <div className="col-span-5 flex flex-col gap-3">
                    {next.map((i) => (
                        <button
                            key={i}
                            type="button"
                            onClick={() => setAt(i)}
                            className="art-lift rounded-[1.5rem] border border-[var(--pt-line)] p-6 text-left outline-none focus-visible:ring-2 focus-visible:ring-[var(--pt-ink)]"
                        >
                            <Stars n={voices[i].rating} className="size-4" />
                            <span className="mt-3 line-clamp-3 block leading-relaxed">{voices[i].quote}</span>
                            <span className="mt-4 block text-sm text-[var(--pt-muted)]">
                                {voices[i].name}
                                {voices[i].place ? `, ${voices[i].place}` : ''}
                            </span>
                        </button>
                    ))}
                    <div className="mt-auto flex items-center gap-2 pt-2">
                        <button
                            type="button"
                            onClick={() => setPaused((p) => !p)}
                            aria-label={paused || still ? t('Play') : t('Pause')}
                            className="grid size-11 place-items-center rounded-full border border-[var(--pt-ink)] hover:bg-[var(--pt-ink)] hover:text-[var(--pt-paper)]"
                        >
                            {paused || still ? <Play aria-hidden className="size-4" /> : <Pause aria-hidden className="size-4" />}
                        </button>
                        <button
                            type="button"
                            onClick={() => go(at - 1)}
                            aria-label={t('Previous')}
                            className="grid size-11 place-items-center rounded-full border border-[var(--pt-ink)] hover:bg-[var(--pt-ink)] hover:text-[var(--pt-paper)]"
                        >
                            <ChevronLeft aria-hidden className="size-5" />
                        </button>
                        <button
                            type="button"
                            onClick={() => go(at + 1)}
                            aria-label={t('Next')}
                            className="grid size-11 place-items-center rounded-full border border-[var(--pt-ink)] hover:bg-[var(--pt-ink)] hover:text-[var(--pt-paper)]"
                        >
                            <ChevronRight aria-hidden className="size-5" />
                        </button>
                        <span className="tabular ml-auto text-sm text-[var(--pt-muted)]">
                            {num((at % voices.length) + 1)} / {num(voices.length)}
                        </span>
                    </div>
                </div>
            </div>
        </section>
    );
}
