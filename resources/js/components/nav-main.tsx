import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useI18n } from '@/lib/i18n';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useEffect, useState } from 'react';

const KEY = 'aqua-nav-closed';

const closedGroups = (): string[] => {
    try {
        return JSON.parse(localStorage.getItem(KEY) ?? '[]');
    } catch {
        return [];
    }
};

/**
 * One labelled group of sidebar links. The label folds the group away and the choice is remembered on this device; the group holding the page you are
 * on is always open. A group with nothing the person may open is not drawn. When the whole menu shrinks to icons, every icon stays visible.
 */
export function NavMain({ title, items = [] }: { title: string; items: NavItem[] }) {
    const page = usePage();
    const { t } = useI18n();
    const path = page.url.split('?')[0];
    const isActive = (url: string) => path === url || (url !== '/dashboard' && path.startsWith(url + '/'));
    const holdsPage = items.some((i) => isActive(i.url));
    const [closed, setClosed] = useState(false);

    useEffect(() => setClosed(closedGroups().includes(title)), [title]);

    if (items.length === 0) return null;

    const open = holdsPage || !closed;
    const flip = () => {
        const next = !open;
        setClosed(!next);
        try {
            const rest = closedGroups().filter((g) => g !== title);
            localStorage.setItem(KEY, JSON.stringify(next ? rest : [...rest, title]));
        } catch {
            /* kept for this visit only */
        }
    };
    const id = `nav-${title.toLowerCase().replace(/[^a-z]+/g, '-')}`;
    const pending = items.reduce((n, i) => n + (i.badge ?? 0), 0);

    return (
        <SidebarGroup className="px-2 py-1">
            <SidebarGroupLabel asChild>
                <button type="button" onClick={flip} aria-expanded={open} aria-controls={id} className="flex w-full items-center justify-between text-xs font-semibold tracking-wide hover:text-sidebar-foreground">
                    <span>{t(title)}</span>
                    <span className="flex items-center gap-1.5">
                        {!open && pending > 0 && <span className="rounded-full bg-warning px-1.5 text-[10px] font-bold text-black">{pending}</span>}
                        <ChevronDown aria-hidden className={`size-3.5 transition-transform motion-reduce:transition-none ${open ? '' : '-rotate-90'}`} />
                    </span>
                </button>
            </SidebarGroupLabel>
            <SidebarMenu id={id} className={open ? '' : 'hidden group-data-[collapsible=icon]:flex'}>
                {items.map((item) => {
                    const active = isActive(item.url);

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton asChild isActive={active} className="h-9" tooltip={t(item.title)}>
                                <Link href={item.url} prefetch aria-current={active ? 'page' : undefined}>
                                    {item.icon && <item.icon />}
                                    <span>{t(item.title)}</span>
                                    {item.badge ? <span className="ml-auto rounded-full bg-warning px-1.5 text-xs font-bold text-black">{item.badge}</span> : null}
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
