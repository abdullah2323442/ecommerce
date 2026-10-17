import { useSidebar } from '@/components/ui/sidebar';
import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ClipboardList, LayoutGrid, LifeBuoy, Menu, Store } from 'lucide-react';

/**
 * The office on a phone: a bar at the bottom with the four places staff go most, and Menu for everything else (it opens the full side menu).
 * It shows only on small screens, and only the places this person may open.
 */
export default function AdminMobileNav() {
    const { t } = useI18n();
    const { auth } = usePage<SharedData>().props;
    const { url } = usePage();
    const { toggleSidebar } = useSidebar();

    const items = [
        { href: '/dashboard', label: t('Dashboard'), Icon: LayoutGrid, show: true },
        { href: '/admin/orders', label: t('Orders'), Icon: ClipboardList, show: auth.can.handleOrders },
        { href: '/admin/market', label: t('Market'), Icon: Store, show: auth.can.manageCustomers },
        { href: '/admin/support', label: t('Support'), Icon: LifeBuoy, show: auth.can.handleOrders },
    ].filter((i) => i.show);

    const on = (href: string) => (href === '/dashboard' ? url === '/dashboard' || url.startsWith('/dashboard?') : url.startsWith(href));
    const cell = 'flex min-h-14 flex-1 flex-col items-center justify-center gap-0.5 rounded-2xl text-[11px] font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring';

    return (
        <nav aria-label={t('Main')} className="glass fixed inset-x-3 bottom-[calc(0.6rem+env(safe-area-inset-bottom))] z-40 flex gap-1 rounded-3xl p-1.5 md:hidden">
            {items.map(({ href, label, Icon }) => (
                <Link key={href} href={href} aria-current={on(href) ? 'page' : undefined} className={`${cell} ${on(href) ? 'bg-foreground text-background' : 'text-foreground/75'}`}>
                    <Icon aria-hidden className="size-5" strokeWidth={1.7} />
                    {label}
                </Link>
            ))}
            <button type="button" onClick={toggleSidebar} className={`${cell} text-foreground/75`}>
                <Menu aria-hidden className="size-5" strokeWidth={1.7} />
                {t('Menu')}
            </button>
        </nav>
    );
}
