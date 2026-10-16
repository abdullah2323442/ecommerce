import { Sparkle } from '@/components/art/craft';
import EtherBackground from '@/components/home/ether-background';
import HeroSearch from '@/components/home/hero-search';
import { HeroGem } from '@/components/home/three-scenes';
import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { type CSSProperties } from 'react';

/** Real searches that work, shown as shortcuts. Bangla is included because it is a headline feature. */
const examples = ['napa', 'seclo', 'cefixime 200', 'নাপা'];

function Shot({ src, className, style, label }: { src: string; className: string; style?: CSSProperties; label?: string }) {
    return (
        <div
            className={`absolute overflow-hidden rounded-[1.75rem] border-[3px] border-white/70 shadow-[0_30px_60px_-20px_rgba(0,0,0,.6)] ${className}`}
            style={style}
        >
            <img src={src} alt="" width={724} height={543} decoding="async" className="size-full object-cover" />
            {label && (
                <span className="absolute bottom-2.5 left-2.5 rounded-full bg-white/85 px-3 py-0.5 text-sm font-semibold text-[#0e0d0c] backdrop-blur">
                    {label}
                </span>
            )}
        </div>
    );
}

/**
 * Home hero: a deep aqua stage with slow aurora light, the headline and the live search, and a collage
 * of real photography from the shops on the right. Motion is limited to a gentle drift and is off for
 * reduced-motion users.
 */
export default function Hero() {
    const { t } = useI18n();
    const { site } = usePage<SharedData>().props;

    return (
        <section aria-labelledby="hero" className="mx-auto w-full max-w-6xl px-4 pt-4 sm:pt-6">
            <div className="relative isolate overflow-hidden rounded-[2rem] bg-[#0e0d0c] text-white sm:rounded-[2.75rem]">
                {/* aurora light and a fine grid */}
                <div aria-hidden className="aurora-a absolute -top-32 -left-24 -z-10 size-[34rem] rounded-full bg-[#c9a24b]/22 blur-[120px]" />
                <div aria-hidden className="aurora-b absolute -right-20 -bottom-40 -z-10 size-[30rem] rounded-full bg-[#fff3d6]/5 blur-[120px]" />
                <EtherBackground variant="hero" />
                <HeroGem className="absolute -top-10 -right-16 -z-10 hidden h-[30rem] w-[30rem] opacity-90 lg:block" />
                <div
                    aria-hidden
                    className="absolute inset-0 -z-10 bg-[radial-gradient(rgba(255,255,255,.07)_1px,transparent_1px)] [background-size:26px_26px]"
                />

                <div className="grid items-center gap-10 px-6 py-12 sm:px-12 sm:py-16 lg:grid-cols-[1.05fr_0.95fr]">
                    <div>
                        <p className="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-4 py-1.5 text-sm font-medium backdrop-blur">
                            <span aria-hidden className="size-2 rounded-full bg-[#4ade80] shadow-[0_0_0_4px_rgba(74,222,128,.25)]" />
                            {t('Pharmacy open now')}
                        </p>

                        <h1 id="hero" className="font-display mt-6 text-5xl leading-[1.05] font-bold sm:text-6xl lg:text-7xl">
                            {site.home.hero_title ?? (
                                <>
                                    {t('Every shop.')}
                                    <span className="block bg-gradient-to-r from-[#f3d995] via-[#fbe9b8] to-[#ffb199] bg-clip-text text-transparent">
                                        {t('One place.')}
                                    </span>
                                </>
                            )}
                        </h1>
                        <p className="mt-5 max-w-md text-lg text-white/75">
                            {site.home.hero_text ?? t('Medicine, food, gaming, made-to-measure and more. The pharmacy is open today. The rest of the market opens soon.')}
                        </p>

                        <div className="relative z-20 mt-9 max-w-xl">
                            <HeroSearch />
                            <p className="mt-4 flex flex-wrap items-center gap-2 text-sm text-white/70">
                                {t('Try')}
                                {examples.map((q) => (
                                    <Link
                                        key={q}
                                        href={route('medicines.index', { q })}
                                        className="inline-flex min-h-10 items-center rounded-full border border-white/20 bg-white/10 px-4 font-medium text-white backdrop-blur transition-colors hover:bg-white/20"
                                    >
                                        {q}
                                    </Link>
                                ))}
                            </p>
                        </div>
                    </div>

                    {/* photo collage */}
                    <div aria-hidden className="relative mx-auto h-72 w-full max-w-md sm:h-[27rem] sm:max-w-none">
                        <Sparkle className="tilt-b absolute -top-3 left-[8%] z-10 size-12" />
                        <Sparkle color="#b8864b" className="tilt-a absolute right-[-2%] bottom-[44%] z-10 size-9" />
                        <Sparkle color="#f3d995" className="tilt-a absolute bottom-[-2%] left-[56%] z-10 size-10" />
                        <Shot
                            src="/images/home/food-720.webp"
                            className="tilt-a top-0 right-0 h-[62%] w-[72%]"
                            style={{ '--r': '3deg', transform: 'rotate(3deg)' } as CSSProperties}
                            label={t('Food')}
                        />
                        <Shot
                            src="/images/home/gaming-720.webp"
                            className="tilt-b bottom-0 left-0 h-[52%] w-[60%]"
                            style={{ '--r': '-4deg', transform: 'rotate(-4deg)' } as CSSProperties}
                            label={t('Gaming')}
                        />
                        <Shot
                            src="/images/home/bazaar-720.webp"
                            className="tilt-a right-[4%] bottom-[2%] hidden h-[34%] w-[40%] sm:block"
                            style={{ '--r': '5deg', transform: 'rotate(5deg)' } as CSSProperties}
                            label={t('The Market')}
                        />
                    </div>
                </div>
            </div>
        </section>
    );
}
