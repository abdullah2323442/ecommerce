import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Ban, CalendarClock, RefreshCw, Handshake, MessageSquareText, HandCoins, PiggyBank, Landmark, LayoutTemplate, LifeBuoy, ArchiveRestore, Archive, CreditCard, Palette, Plug, Bike, Banknote, Star, Library, Ticket, Gamepad2, Carrot, UtensilsCrossed, MessagesSquare, Hammer, Ruler, Tag, Copy, Store, HandHelping, MessageCircleQuestion, MessageSquareWarning, BarChart3, UserSquare, Scale, Truck, MailCheck, Eye, ListChecks, Hourglass, Settings2, NotebookPen, ClipboardCheck, ScrollText, UserCog, ShieldCheck, Undo2, BadgePercent, FileText, Inbox, LayoutGrid, Package, Percent, Pill, SearchX, ShoppingCart, Users, Wallet } from 'lucide-react';
import BrandMark from './shell/brand-mark';

/**
 * Admin navigation. The groups are only drawn when the server says this user may open them
 * (auth.can); the routes themselves are protected on the server either way.
 */
export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;

    const g1: NavItem[] = [
        { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
    ];
    const g2: NavItem[] = [
        ...(auth.can.useCounter ? [{ title: 'Counter', url: '/admin/counter', icon: ShoppingCart }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Online orders', url: '/admin/orders', icon: Inbox }] : []),
        ...(auth.can.handleOrders ? [{ title: 'All orders', url: '/admin/invoices', icon: ListChecks }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Pre-orders', url: '/admin/preorders', icon: Hourglass, badge: auth.preorders }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Prescriptions', url: '/admin/prescriptions', icon: FileText }] : []),
        ...(auth.can.recordReturns ? [{ title: 'Returns', url: '/admin/returns', icon: Undo2 }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Price requests', url: '/admin/quotes', icon: Tag }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Questions', url: '/admin/questions', icon: MessageCircleQuestion, badge: auth.questions }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Online payments', url: '/admin/payments', icon: CreditCard }] : []),
        ...(auth.can.decideApprovals ? [{ title: 'Approvals', url: '/admin/approvals', icon: ShieldCheck, badge: auth.pending }] : []),
    ];
    const g3: NavItem[] = [
        ...(auth.can.makeDeliveries ? [{ title: 'My deliveries', url: '/admin/deliveries', icon: Bike }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Delivery money', url: '/admin/delivery-cash', icon: Banknote }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Delivery ratings', url: '/admin/delivery-ratings', icon: Star }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Couriers', url: '/admin/couriers', icon: Truck }] : []),
        ...(auth.can.manageGoods ? [{ title: 'Weights', url: '/admin/weights', icon: Scale }] : []),
    ];
    const g4: NavItem[] = [
        ...(auth.can.manageCustomers ? [{ title: 'Market', url: '/admin/market', icon: Store, badge: auth.listingsPending }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Market commission', url: '/admin/market/commissions', icon: Percent }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Seller deposits', url: '/admin/market/deposits', icon: PiggyBank }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Commission statements', url: '/admin/market/statements', icon: ScrollText }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Copied products', url: '/admin/market/reports', icon: Copy }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Market complaints', url: '/admin/market/cases', icon: MessageSquareWarning }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Reviews', url: '/admin/reviews', icon: Star }] : []),
    ];
    const g5: NavItem[] = [
        ...(auth.can.manageAccounts ? [{ title: 'Restaurants', url: '/admin/food', icon: UtensilsCrossed }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Ingredient list', url: '/admin/food-ingredients', icon: Carrot }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Game top-ups', url: '/admin/gaming', icon: Gamepad2 }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Made to order', url: '/admin/custom', icon: Ruler }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Craftsmen', url: '/admin/craftsmen', icon: Hammer }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Job chats', url: '/admin/chats', icon: MessagesSquare }] : []),
    ];
    const g6: NavItem[] = [
        ...(auth.can.manageCustomers ? [{ title: 'Product catalog', url: '/admin/catalog', icon: Library }] : []),
        ...(auth.can.manageGoods ? [{ title: 'Stock', url: '/admin/stock', icon: Package }] : []),
        ...(auth.can.manageGoods ? [{ title: 'Company dues', url: '/admin/payables', icon: Landmark }] : []),
        ...(auth.can.manageOffers ? [{ title: 'Offers', url: '/admin/offers', icon: BadgePercent }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Aqua Tokens', url: '/admin/tokens', icon: Ticket }] : []),
    ];
    const g7: NavItem[] = [
        ...(auth.can.manageCustomers ? [{ title: 'Customers', url: '/admin/customers', icon: Users }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Tiers', url: '/admin/tiers', icon: Percent }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Prescription marketplace', url: '/admin/rx-market', icon: FileText }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Services', url: '/admin/services', icon: CalendarClock }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'PharmaTrack', url: '/admin/pharmatrack', icon: RefreshCw }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Bulk texts', url: '/admin/sms', icon: MessageSquareText }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Affiliates', url: '/admin/affiliates', icon: Handshake }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Applications', url: '/admin/applications', icon: ClipboardCheck, badge: auth.applications }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'MPO', url: '/admin/mpo', icon: UserSquare }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Support tickets', url: '/admin/support', icon: LifeBuoy }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Help posts', url: '/admin/help', icon: HandHelping, badge: auth.helpPending }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Requests', url: '/admin/requests', icon: SearchX }] : []),
    ];
    const g8: NavItem[] = [
        ...(auth.can.viewReports ? [{ title: 'End of day', url: '/admin/day', icon: Wallet }] : []),
        ...(auth.can.useCounter && !auth.can.viewReports ? [{ title: 'End of day', url: '/admin/end-of-day', icon: Wallet }] : []),
        ...(auth.can.viewReports ? [{ title: 'Reports', url: '/admin/reports', icon: BarChart3 }] : []),
        ...(auth.can.viewReports ? [{ title: 'Credit ledger', url: '/admin/ledger', icon: HandCoins }] : []),
        ...(auth.can.viewReports ? [{ title: 'VAT report', url: '/admin/reports/vat', icon: FileText }] : []),
        ...(auth.can.viewReports ? [{ title: 'Controlled register', url: '/admin/controlled', icon: NotebookPen }] : []),
        ...(auth.can.viewReports ? [{ title: 'What people look for', url: '/admin/behavior', icon: Eye }] : []),
    ];
    const g9: NavItem[] = [
        ...(auth.can.manageAccounts ? [{ title: 'Site design', url: '/admin/site', icon: Palette }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Shop fronts', url: '/admin/storefront', icon: LayoutTemplate }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Testimonials', url: '/admin/testimonials', icon: MessagesSquare }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Connections', url: '/admin/connections', icon: Plug }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Messages', url: '/admin/messages', icon: MailCheck }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Settings', url: '/admin/settings', icon: Settings2 }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Staff', url: '/admin/staff', icon: UserCog }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Blocked addresses', url: '/admin/blocked-ips', icon: Ban }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Activity', url: '/admin/activity', icon: ScrollText }, { title: 'Archive', url: '/admin/archive', icon: Archive }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Deleted', url: '/admin/deleted', icon: ArchiveRestore }] : []),
    ];
    const groups: [string, NavItem[]][] = [['Overview', g1], ['Sales and orders', g2], ['Delivery', g3], ['Market', g4], ['Food, games and made to order', g5], ['Catalogue and stock', g6], ['People', g7], ['Reports', g8], ['Site and system', g9]];
    const catalog: NavItem[] = [
        { title: 'Home page', url: '/', icon: Eye },
        { title: 'Market', url: '/market', icon: Store },
        { title: 'Medicines', url: '/medicines', icon: Pill },
        { title: 'Companies', url: '/companies', icon: Library },
        { title: 'Food', url: '/food', icon: UtensilsCrossed },
        { title: 'Game top-ups', url: '/gaming', icon: Gamepad2 },
        { title: 'Made to order', url: '/custom', icon: Hammer },
        { title: 'Help posts', url: '/help', icon: LifeBuoy },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <BrandMark />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {groups.map(([label, items]) => (
                    <NavMain key={label} title={label} items={items} />
                ))}
                <NavMain title="Public site" items={catalog} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
