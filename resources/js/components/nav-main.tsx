import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useI18n } from '@/lib/i18n';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

/** One labelled group of sidebar links. Active when the current path is the link or below it. */
export function NavMain({ title, items = [] }: { title: string; items: NavItem[] }) {
    const page = usePage();
    const { t } = useI18n();
    const path = page.url.split('?')[0];

    return (
        <SidebarGroup className="px-2 py-1">
            <SidebarGroupLabel>{t(title)}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => {
                    const active = path === item.url || (item.url !== '/dashboard' && path.startsWith(item.url + '/'));
                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton asChild isActive={active} className="h-9">
                                <Link href={item.url} prefetch aria-current={active ? 'page' : undefined}>
                                    {item.icon && <item.icon />}
                                    <span>{t(item.title)}</span>
                                    {item.badge ? <span className="ml-auto rounded-full bg-amber-500 px-1.5 text-xs font-bold text-black">{item.badge}</span> : null}
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
