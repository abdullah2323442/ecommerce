import InstallApp from '@/components/install-app';
import PwaBanner from '@/components/pwa-banner';
import SiteNotice from '@/components/site-notice';
import { Bunting } from '@/components/art/craft';
import TextSize from '@/components/text-size';
import EtherBackground from '@/components/home/ether-background';
import { DustBackdrop } from '@/components/home/three-scenes';
import { LanguageToggle, useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Bell, Building2, Droplet, House, MessageCircle, Search, ShoppingBag, UserRound, type LucideIcon } from 'lucide-react';
import { type PropsWithChildren } from 'react';

/**
 * Layout for the public storefront. Kept deliberately small: a glass header with the logo, one search
 * pill, the language switch and the account button; an app-style floating tab bar on phones; and a
 * proper footer. Uses the scoped `.public-theme` tokens so the admin UI keeps its own look.
 */
interface Props {
    /** Let the page run edge to edge (home page). The page then manages its own width. */
    bleed?: boolean;
}

export default function PublicLayout({ children, bleed = false }: PropsWithChildren<Props>) {
    const { auth, brand, cartCount, canCart, site } = usePage<SharedData>().props;
    const { url } = usePage();
    const { t } = useI18n();

    const account = auth.user ? { href: route('dashboard'), label: t('Account') } : { href: route('login'), label: t('Log in') };

    const tabs: { href: string; label: string; Icon: LucideIcon; active: boolean }[] = [
        { href: '/', label: t('Home'), Icon: House, active: url === '/' || url.startsWith('/?') },
        { href: route('medicines.index'), label: t('Search'), Icon: Search, active: url.startsWith('/medicines') || url.startsWith('/forms') },
        { href: route('companies.index'), label: t('Companies'), Icon: Building2, active: url.startsWith('/companies') },
        { ...account, Icon: UserRound, active: url.startsWith('/login') || url.startsWith('/dashboard') || url.startsWith('/account') },
    ];

    return (
        <div className="public-theme relative isolate flex min-h-screen flex-col pb-[calc(6.5rem+env(safe-area-inset-bottom))] md:pb-0">
            <DustBackdrop />
            <Head>
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link href="https://fonts.bunny.net/css?family=hind-siliguri:400,500,600,700|baloo-da-2:600,700,800&display=swap" rel="stylesheet" />
            </Head>

            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-[var(--pt-surface)] focus:px-3 focus:py-2"
            >
                {t('Skip to content')}
            </a>

            <SiteNotice />
            <header className="sticky top-0 z-30 border-b border-[var(--pt-line)]/70 bg-[var(--pt-paper)]/80 backdrop-blur-xl">
                <div className="mx-auto flex h-16 w-full max-w-6xl items-center gap-3 px-4">
                    <Link href="/" className="font-display flex shrink-0 items-center gap-2.5 text-2xl font-bold">
                        {brand.logo ? (
                            <img src={brand.logo} alt="" className="size-9 rounded-xl object-contain" />
                        ) : (
                            <span
                                aria-hidden
                                className="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-[#c9a24b] to-[#0e0d0c] text-white shadow-md"
                            >
                                <Droplet className="size-4" fill="currentColor" strokeWidth={0} />
                            </span>
                        )}
                        <span className="hidden sm:inline">{brand.name}</span>
                    </Link>

                    <Link
                        href={route('medicines.index')}
                        aria-label={t('Search the shop')}
                        className="mx-auto hidden h-11 w-full max-w-md items-center gap-3 rounded-full border border-[var(--pt-line)] bg-[var(--pt-surface)] px-4 text-[var(--pt-muted)] transition-shadow hover:shadow-md md:flex"
                    >
                        <Search aria-hidden className="size-4" />
                        {t('Search the shop')}
                    </Link>

                    <div className="ml-auto flex items-center gap-2 md:ml-0">
                        <TextSize />
                        <LanguageToggle />
                        {auth.inbox !== null && auth.inbox !== undefined && (
                            <Link
                                href="/inbox"
                                aria-label={t('Your messages')}
                                className="relative flex size-11 items-center justify-center rounded-full border border-[var(--pt-line)] bg-[var(--pt-surface)] transition-shadow hover:shadow-md"
                            >
                                <Bell aria-hidden className="size-5" />
                                {auth.inbox > 0 && (
                                    <span className="absolute -top-1 -right-1 flex min-w-5 items-center justify-center rounded-full bg-[var(--pt-amber)] px-1 text-xs font-bold text-black">{auth.inbox}</span>
                                )}
                            </Link>
                        )}
                        {canCart && (
                            <Link
                                href="/cart"
                                aria-label={t('Your cart')}
                                className="relative flex size-11 items-center justify-center rounded-full border border-[var(--pt-line)] bg-[var(--pt-surface)] transition-shadow hover:shadow-md"
                            >
                                <ShoppingBag aria-hidden className="size-5" />
                                {cartCount > 0 && (
                                    <span className="absolute -top-1 -right-1 flex min-w-5 items-center justify-center rounded-full bg-[var(--pt-deep)] px-1 text-xs font-bold text-white">{cartCount}</span>
                                )}
                            </Link>
                        )}
                        <Link
                            href={account.href}
                            className="hidden h-11 items-center rounded-full bg-[var(--pt-ink)] px-5 font-semibold text-[var(--pt-paper)] transition-opacity hover:opacity-90 md:inline-flex"
                        >
                            {account.label}
                        </Link>
                    </div>
                </div>
            </header>

            <main id="main" className={bleed ? 'flex-1' : 'mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:py-12'}>
                {children}
            </main>

            <footer className="relative isolate mt-20 overflow-hidden bg-[var(--pt-deep)] text-white">
                <EtherBackground variant="footer" />
                <Bunting className="-mt-9 sm:-mt-12" />
                <div className="mx-auto grid w-full max-w-6xl gap-10 px-4 py-14 md:grid-cols-[1.4fr_1fr_1fr]">
                    <div>
                        <p className="font-display flex items-center gap-2.5 text-3xl font-bold">
                            {brand.logo ? (
                                <img src={brand.logo} alt="" className="size-10 rounded-xl bg-white/10 object-contain" />
                            ) : (
                                <span
                                    aria-hidden
                                    className="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-[#c9a24b] to-[#8a6a2b]"
                                >
                                    <Droplet className="size-5" fill="currentColor" strokeWidth={0} />
                                </span>
                            )}
                            {brand.name}
                        </p>
                        {brand.tagline && <p className="mt-2 text-white/80">{brand.tagline}</p>}
                        <p className="mt-4 max-w-sm text-white/70">
                            {site.footer.about ?? t('Medicine, food, gaming, made-to-measure and more. The pharmacy is open today. The rest of the market opens soon.')}
                        </p>
                        {Object.keys(site.social).length > 0 && (
                            <ul aria-label={t('Follow us')} className="mt-4 flex flex-wrap gap-3 text-sm text-white/80">
                                {Object.entries(site.social).map(([k, url]) => (
                                    <li key={k}>
                                        <a href={url} target="_blank" rel="noreferrer noopener" className="underline underline-offset-4 hover:text-white">
                                            {k === 'facebook' ? 'Facebook' : k === 'instagram' ? 'Instagram' : k === 'youtube' ? 'YouTube' : 'TikTok'}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <nav aria-label={t('Explore')}>
                        <p className="font-display text-lg font-bold">{t('Explore')}</p>
                        <ul className="mt-3 space-y-2 text-white/75">
                            <li>
                                <Link href={route('medicines.index')} className="hover:text-white">
                                    {t('Medicines')}
                                </Link>
                            </li>
                            <li>
                                <Link href={route('companies.index')} className="hover:text-white">
                                    {t('Companies')}
                                </Link>
                            </li>
                            <li>
                                <Link href={account.href} className="hover:text-white">
                                    {account.label}
                                </Link>
                            </li>
                            {auth.canApply && (
                                <li>
                                    <Link href="/business" className="hover:text-white">
                                        {t('Business account')}
                                    </Link>
                                </li>
                            )}
                        </ul>
                    </nav>
                    <div>
                        <p className="font-display text-lg font-bold">{t('Good to know')}</p>
                        <p className="mt-3 text-white/70">{t("Prices shown are the printed MRP. Always follow your doctor's prescription.")}</p>
                        <div className="mt-4 sm:hidden">
                            <TextSize force />
                        </div>
                        <ul className="mt-4 space-y-2 text-white/75">
                            {site.footer.links.map((l) => (
                                <li key={l.url + l.label}>
                                    {l.url.startsWith('/') ? (
                                        <Link href={l.url} className="hover:text-white">
                                            {l.translate ? t(l.label) : l.label}
                                        </Link>
                                    ) : (
                                        <a href={l.url} target="_blank" rel="noreferrer noopener" className="hover:text-white">
                                            {l.translate ? t(l.label) : l.label}
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {(brand.address || brand.phone || brand.drug_license) && (
                            <p className="mt-4 space-y-0.5 text-sm text-white/60">
                                {brand.address && <span className="block">{brand.address}</span>}
                                {brand.phone && <span className="block">{brand.phone}</span>}
                                {brand.email && <span className="block">{brand.email}</span>}
                                {brand.hours && <span className="block">{brand.hours}</span>}
                                {brand.drug_license && (
                                    <span className="block">
                                        {t('Drug licence')}: {brand.drug_license}
                                    </span>
                                )}
                            </p>
                        )}
                    </div>
                </div>
                <div className="mx-auto w-full max-w-6xl px-4 pb-6">
                    <InstallApp />
                </div>
                {site.footer.copyright && <p className="mx-auto w-full max-w-6xl px-4 pb-8 text-sm text-white/55">{site.footer.copyright}</p>}
            </footer>
            <PwaBanner />

            {/* A chat button, only when the owner has set a WhatsApp number or a Messenger page. */}
            {(brand.whatsapp || brand.messenger) && (
                <a
                    href={brand.whatsapp ? `https://wa.me/${brand.whatsapp.replace(/\D/g, '')}` : `https://m.me/${encodeURIComponent(brand.messenger ?? '')}`}
                    target="_blank"
                    rel="noreferrer noopener"
                    aria-label={t('Chat with us')}
                    className="fixed right-4 bottom-[calc(6.5rem+env(safe-area-inset-bottom))] z-30 flex size-12 items-center justify-center rounded-full bg-[var(--pt-deep)] text-white shadow-lg ring-1 ring-white/20 transition-transform hover:scale-105 md:right-6 md:bottom-6"
                >
                    <MessageCircle aria-hidden className="size-6" />
                </a>
            )}

            {/* Phone app bar: floating, rounded, the four places people go, under the thumb. */}
            <nav aria-label={t('Main')} className="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-40 md:hidden">
                <ul className="mx-auto grid max-w-md grid-cols-4 rounded-[1.75rem] border border-white/60 bg-[var(--pt-surface)]/85 p-1.5 shadow-[0_18px_40px_-12px_rgba(14,13,12,.45)] backdrop-blur-xl dark:border-white/10">
                    {tabs.map(({ href, label, Icon, active }) => (
                        <li key={href}>
                            <Link
                                href={href}
                                aria-current={active ? 'page' : undefined}
                                className={`flex min-h-14 flex-col items-center justify-center gap-0.5 rounded-[1.35rem] text-xs font-semibold transition-colors outline-none focus-visible:ring-2 focus-visible:ring-[var(--pt-amber)] ${
                                    active ? 'bg-[var(--pt-deep)] text-white' : 'text-[var(--pt-muted)]'
                                }`}
                            >
                                <Icon aria-hidden className="size-5" strokeWidth={active ? 2.2 : 1.8} />
                                {label}
                            </Link>
                        </li>
                    ))}
                </ul>
            </nav>
        </div>
    );
}
