import { DiamondBand, Rosette } from '@/components/home/ornaments';
import { type ReactNode } from 'react';

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };

/**
 * The top of a public section, in the home page's own language: warm night, the title in Changa One, a run of jamdani diamonds, and the section's
 * photograph in a gold-framed arch with an alpona rosette behind it. Give `image` for a photo in public/images/home, or `src` for any picture.
 */
export default function SectionHero({
    image,
    src,
    title,
    line,
    children,
    id,
}: {
    image?: string;
    src?: string | null;
    title: string;
    line?: string;
    children?: ReactNode;
    id?: string;
}) {
    const label = id ?? 'section-title';

    return (
        <section
            aria-labelledby={label}
            className="art relative isolate -mx-4 mb-8 overflow-hidden bg-[var(--art-night)] text-[var(--pt-paper)] sm:mx-0 sm:rounded-[2rem]"
        >
            <div className="grid items-center gap-8 px-6 py-10 sm:grid-cols-12 sm:px-12 sm:py-14">
                <div className="sm:col-span-7">
                    <h1 id={label} style={DISPLAY} className="art-rise text-4xl leading-[1.05] font-normal tracking-tight text-balance sm:text-6xl">
                        {title}
                    </h1>
                    <DiamondBand className="mt-5 max-w-40" />
                    {line && <p className="art-rise art-d1 mt-5 max-w-lg text-[15px] leading-relaxed opacity-80 sm:text-lg">{line}</p>}
                    {children && <div className="art-rise art-d2 mt-6 flex flex-wrap gap-3">{children}</div>}
                </div>
                {(image || src) && (
                    <div className="relative mx-auto hidden w-full max-w-60 sm:col-span-5 sm:block sm:max-w-xs">
                        <Rosette className="absolute top-1/2 left-1/2 -z-10 w-[150%] max-w-none -translate-x-1/2 -translate-y-1/2 opacity-80" />
                        <div className="art-float art-d3 rounded-t-full border border-[var(--art-gold-2)] bg-[var(--art-night)] p-1.5">
                            <img
                                src={src ?? `${image}-720.webp`}
                                alt=""
                                fetchPriority="high"
                                decoding="async"
                                className="aspect-[4/5] w-full rounded-t-full object-cover"
                            />
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
