import { Bunting } from '@/components/art/craft';
import InstallApp from '@/components/install-app';
import MobileDock, { type DockLink } from '@/components/mobile-dock';
import PwaBanner from '@/components/pwa-banner';
import SiteNotice from '@/components/site-notice';
import TextSize from '@/components/text-size';
import { LanguageToggle, useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Bell, Droplet, Gamepad2, House, MessageCircle, Pill, Search, ShoppingBag, Store, UserRound, UtensilsCrossed } from 'lucide-react';
import { type PropsWithChildren } from 'react';

/**
 * Layout for the public storefront. Kept deliberately small: a glass header with the logo, one search
 * pill, the language switch and the account button; an app-style floating tab bar on phones; and a
 * proper footer. Uses the scoped `.public-theme` tokens so the admin UI keeps its own look.
 */
interface Props {
    /** Let the page run edge to edge (home page). The page then manages its own width. */
    bleed?: boolean;
    /** No drifting particles in the background: a still page (home). */
    calm?: boolean;
}

export default function PublicLayout({ children, bleed = false }: PropsWithChildren<Props>) {
    const { auth, brand, cartCount, canCart, site } = usePage<SharedData>().props;
    const { url } = usePage();
    const { t } = useI18n();

    const account = auth.user ? { href: route('dashboard'), label: t('Account') } : { href: route('login'), label: t('Log in') };

    const here = (...starts: string[]) => starts.some((p) => url === p || url.startsWith(p + '/') || url.startsWith(p + '?'));
    // The department links under the header on a desktop: every kind of shop, one click away. A shop is "on" when its own page is open.
    const sector = new URLSearchParams(url.split('?')[1] ?? '').get('sector');
    const onMarket = url.split('?')[0] === '/market';
    const departmentLinks = [
        { href: route('market.index'), label: t('Market'), on: onMarket && !sector },
        { href: route('market.index', { sector: 'electronics' }), label: t('Electronics'), on: onMarket && sector === 'electronics' },
        { href: route('market.index', { sector: 'clothing' }), label: t('Clothing'), on: onMarket && sector === 'clothing' },
        { href: route('market.index', { sector: 'jewellery' }), label: t('Jewellery'), on: onMarket && sector === 'jewellery' },
        { href: route('market.index', { sector: 'home' }), label: t('Home and living'), on: onMarket && sector === 'home' },
        { href: route('market.index', { sector: 'beauty' }), label: t('Beauty and care'), on: onMarket && sector === 'beauty' },
        { href: route('medicines.index'), label: t('Pharmacy'), on: here('/medicines', '/forms', '/companies') },
        { href: route('food.index'), label: t('Food'), on: here('/food') },
        { href: route('gaming.index'), label: t('Game top-up'), on: here('/gaming') },
        { href: '/custom', label: t('Made to order'), on: here('/custom') },
    ];

    // The phone dock: the places a shopper of the whole shop goes, with the basket where there is one.
    const dock: DockLink[] = [
        { href: '/', label: t('Home'), Icon: House, active: url === '/' || url.startsWith('/?') },
        { href: route('market.index'), label: t('Market'), Icon: Store, active: here('/market') },
        { href: route('medicines.index'), label: t('Medicines'), Icon: Pill, active: here('/medicines', '/forms', '/companies') },
        { href: route('food.index'), label: t('Food'), Icon: UtensilsCrossed, active: here('/food') },
        { href: route('gaming.index'), label: t('Game top-up'), Icon: Gamepad2, active: here('/gaming') },
        canCart
            ? { href: route('market.cart'), label: t('Your market cart'), Icon: ShoppingBag, active: here('/market-cart'), badge: cartCount }
            : { href: route('search'), label: t('Search'), Icon: Search, active: here('/search') },
        { ...account, Icon: UserRound, active: here('/login', '/dashboard', '/account') },
    ];

    return (
        <div className="public-theme relative isolate flex min-h-screen flex-col">
            <Head>
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link
                    href="https://fonts.bunny.net/css?family=hind-siliguri:400,500,600,700|baloo-da-2:600,700,800|cormorant-garamond:500,600|noto-serif-bengali:500,700,800|changa-one:400&display=swap"
                    rel="stylesheet"
                />
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

                    <form
                        action="/search"
                        method="get"
                        role="search"
                        className="mx-auto hidden h-11 w-full max-w-md items-center gap-3 rounded-full border border-[var(--pt-line)] bg-[var(--pt-surface)] px-4 text-[var(--pt-muted)] transition-shadow focus-within:shadow-md hover:shadow-md md:flex"
                    >
                        <Search aria-hidden className="size-4" />
                        <input
                            name="q"
                            type="search"
                            aria-label={t('Search the shop')}
                            placeholder={t('Search the shop')}
                            autoComplete="off"
                            className="h-full w-full bg-transparent text-[var(--pt-ink)] outline-none placeholder:text-[var(--pt-muted)]"
                        />
                    </form>

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
                                    <span className="absolute -top-1 -right-1 flex min-w-5 items-center justify-center rounded-full bg-[var(--pt-amber)] px-1 text-xs font-bold text-black">
                                        {auth.inbox}
                                    </span>
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
                                    <span className="absolute -top-1 -right-1 flex min-w-5 items-center justify-center rounded-full bg-[var(--pt-deep)] px-1 text-xs font-bold text-white">
                                        {cartCount}
                                    </span>
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
                <nav aria-label={t('Departments')} className="hidden border-t border-[var(--pt-line)]/60 md:block">
                    <ul className="mx-auto flex w-full max-w-6xl items-center gap-1 overflow-x-auto px-4 py-1.5 text-sm">
                        {departmentLinks.map(({ href, label, on }) => (
                            <li key={href} className="shrink-0">
                                <Link
                                    href={href}
                                    aria-current={on ? 'page' : undefined}
                                    className={`inline-flex h-9 items-center rounded-full px-4 transition-colors duration-300 ${on ? 'bg-[var(--pt-ink)] text-[var(--pt-paper)]' : 'hover:bg-[var(--pt-line)]/60'}`}
                                >
                                    {label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            </header>

            <main id="main" className={bleed ? 'flex-1' : 'mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:py-12'}>
                {children}
            </main>

            <footer className="relative isolate mt-20 overflow-hidden bg-[var(--pt-deep)] pb-[calc(6.5rem+env(safe-area-inset-bottom))] text-white md:pb-0">
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
                            {site.footer.about ??
                                t('Medicine, food, gaming, made-to-measure and more. The pharmacy is open today. The rest of the market opens soon.')}
                        </p>
                        {Object.keys(site.social).length > 0 && (
                            <ul aria-label={t('Follow us')} className="mt-4 flex flex-wrap gap-3 text-sm text-white/80">
                                {Object.entries(site.social).map(([k, url]) => (
                                    <li key={k}>
                                        <a
                                            href={url}
                                            target="_blank"
                                            rel="noreferrer noopener"
                                            className="underline underline-offset-4 hover:text-white"
                                        >
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
                    href={
                        brand.whatsapp
                            ? `https://wa.me/${brand.whatsapp.replace(/\D/g, '')}`
                            : `https://m.me/${encodeURIComponent(brand.messenger ?? '')}`
                    }
                    target="_blank"
                    rel="noreferrer noopener"
                    aria-label={t('Chat with us')}
                    className="fixed right-4 bottom-[calc(6.5rem+env(safe-area-inset-bottom))] z-30 flex size-12 items-center justify-center rounded-full bg-[var(--pt-deep)] text-white shadow-lg ring-1 ring-white/20 transition-transform hover:scale-105 md:right-6 md:bottom-6"
                >
                    <MessageCircle aria-hidden className="size-6" />
                </a>
            )}

            <MobileDock items={dock} label={t('Main')} more={{ label: t('More'), links: departmentLinks }} />
        </div>
    );
}
