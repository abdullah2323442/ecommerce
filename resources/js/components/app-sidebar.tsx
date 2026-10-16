import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { LifeBuoy, ArchiveRestore, CreditCard, Palette, Plug, Bike, Banknote, Star, Library, Ticket, Gamepad2, Carrot, UtensilsCrossed, MessagesSquare, Hammer, Ruler, Tag, Copy, Store, HandHelping, MessageCircleQuestion, MessageSquareWarning, BarChart3, UserSquare, Scale, Truck, MailCheck, Eye, ListChecks, Hourglass, Settings2, NotebookPen, ClipboardCheck, ScrollText, UserCog, ShieldCheck, Undo2, BadgePercent, FileText, Inbox, LayoutGrid, Package, Percent, Pill, SearchX, ShoppingCart, Users, Wallet } from 'lucide-react';
import BrandMark from './shell/brand-mark';

/**
 * Admin navigation. The groups are only drawn when the server says this user may open them
 * (auth.can); the routes themselves are protected on the server either way.
 */
export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;

    const work: NavItem[] = [
        { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
        ...(auth.can.manageCustomers
            ? [
                  { title: 'Customers', url: '/admin/customers', icon: Users },
                  { title: 'Tiers', url: '/admin/tiers', icon: Percent },
                  { title: 'Requests', url: '/admin/requests', icon: SearchX },
              ]
            : []),
        ...(auth.can.manageCustomers ? [{ title: 'Market', url: '/admin/market', icon: Store, badge: auth.listingsPending }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Market commission', url: '/admin/market/commissions', icon: Percent }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Commission statements', url: '/admin/market/statements', icon: ScrollText }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Copied products', url: '/admin/market/reports', icon: Copy }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Market complaints', url: '/admin/market/cases', icon: MessageSquareWarning }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Help posts', url: '/admin/help', icon: HandHelping, badge: auth.helpPending }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Applications', url: '/admin/applications', icon: ClipboardCheck, badge: auth.applications }] : []),
        ...(auth.can.recordReturns ? [{ title: 'Returns', url: '/admin/returns', icon: Undo2 }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Prescriptions', url: '/admin/prescriptions', icon: FileText }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Game top-ups', url: '/admin/gaming', icon: Gamepad2 }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Ingredient list', url: '/admin/food-ingredients', icon: Carrot }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Restaurants', url: '/admin/food', icon: UtensilsCrossed }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Job chats', url: '/admin/chats', icon: MessagesSquare }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Craftsmen', url: '/admin/craftsmen', icon: Hammer }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Made to order', url: '/admin/custom', icon: Ruler }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Price requests', url: '/admin/quotes', icon: Tag }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Site design', url: '/admin/site', icon: Palette }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Connections', url: '/admin/connections', icon: Plug }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Aqua Tokens', url: '/admin/tokens', icon: Ticket }] : []),
        ...(auth.can.makeDeliveries ? [{ title: 'My deliveries', url: '/admin/deliveries', icon: Bike }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Support tickets', url: '/admin/support', icon: LifeBuoy }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Online payments', url: '/admin/payments', icon: CreditCard }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Delivery money', url: '/admin/delivery-cash', icon: Banknote }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Delivery ratings', url: '/admin/delivery-ratings', icon: Star }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Product catalog', url: '/admin/catalog', icon: Library }] : []),
        ...(auth.can.manageCustomers ? [{ title: 'Reviews', url: '/admin/reviews', icon: Star }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Pre-orders', url: '/admin/preorders', icon: Hourglass, badge: auth.preorders }] : []),
        ...(auth.can.handleOrders ? [{ title: 'All orders', url: '/admin/invoices', icon: ListChecks }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Online orders', url: '/admin/orders', icon: Inbox }] : []),
        ...(auth.can.handleOrders ? [{ title: 'Questions', url: '/admin/questions', icon: MessageCircleQuestion, badge: auth.questions }] : []),
        ...(auth.can.useCounter ? [{ title: 'Counter', url: '/admin/counter', icon: ShoppingCart }] : []),
        ...(auth.can.viewReports ? [{ title: 'End of day', url: '/admin/day', icon: Wallet }] : []),
        ...(auth.can.useCounter && !auth.can.viewReports ? [{ title: 'End of day', url: '/admin/end-of-day', icon: Wallet }] : []),
        ...(auth.can.viewReports ? [{ title: 'VAT report', url: '/admin/reports/vat', icon: FileText }] : []),
        ...(auth.can.viewReports ? [{ title: 'Controlled register', url: '/admin/controlled', icon: NotebookPen }] : []),
        ...(auth.can.viewReports ? [{ title: 'What people look for', url: '/admin/behavior', icon: Eye }] : []),
        ...(auth.can.viewReports ? [{ title: 'Reports', url: '/admin/reports', icon: BarChart3 }] : []),
        ...(auth.can.decideApprovals ? [{ title: 'Approvals', url: '/admin/approvals', icon: ShieldCheck, badge: auth.pending }] : []),
        ...(auth.can.manageOffers ? [{ title: 'Offers', url: '/admin/offers', icon: BadgePercent }] : []),
        ...(auth.can.manageGoods ? [{ title: 'Stock', url: '/admin/stock', icon: Package }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'MPO', url: '/admin/mpo', icon: UserSquare }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Couriers', url: '/admin/couriers', icon: Truck }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Deleted', url: '/admin/deleted', icon: ArchiveRestore }] : []),
        ...(auth.can.manageGoods ? [{ title: 'Weights', url: '/admin/weights', icon: Scale }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Messages', url: '/admin/messages', icon: MailCheck }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Settings', url: '/admin/settings', icon: Settings2 }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Staff', url: '/admin/staff', icon: UserCog }] : []),
        ...(auth.can.manageAccounts ? [{ title: 'Activity', url: '/admin/activity', icon: ScrollText }] : []),
    ];
    const catalog: NavItem[] = [{ title: 'Medicines', url: '/medicines', icon: Pill }];

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
                <NavMain title="Work" items={work} />
                <NavMain title="Catalog" items={catalog} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
