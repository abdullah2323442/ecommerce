import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User | null;
    pending?: number;
    applications?: number;
    questions?: number;
    helpPending?: number;
    listingsPending?: number;
    sellerOrders?: number;
    inbox?: number | null;
    preorders?: number;
    canApply?: boolean;
    can: {
        manageCustomers: boolean;
        makeDeliveries: boolean;
        manageGoods: boolean;
        useCounter: boolean;
        viewReports: boolean;
        manageOffers: boolean;
        approveReturns: boolean;
        decideApprovals: boolean;
        manageAccounts: boolean;
        handleOrders: boolean;
        recordReturns: boolean;
    };
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    badge?: number;
}

export interface Brand {
    name: string;
    tagline?: string | null;
    favicon?: string | null;
    hours?: string | null;
    logo: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    whatsapp: string | null;
    messenger: string | null;
    drug_license: string | null;
}

export interface SiteShared {
    social: Partial<Record<'facebook' | 'instagram' | 'youtube' | 'tiktok', string>>;
    footer: { about: string | null; copyright: string | null; links: { label: string; translate: boolean; url: string }[] };
    home: { hero_title: string | null; hero_text: string | null; sections: { key: string; open: boolean; href: string | null }[] };
    announcement: { text: string; link: string | null } | null;
    seo: { description: string | null };
}

export interface SharedData {
    brand: Brand;
    site: SiteShared;
    socialProviders: { key: string; name: string }[];
    auth: Auth;
    flash: { status: string | null };
    cartCount: number;
    siteNotice: string | null;
    sellerBrand: string | null;
    canCart: boolean;
    locale: 'bn' | 'en';
    translations: Record<string, string>;
    [key: string]: unknown;
}

/** The signed-in user as the shell sees it. Deliberately small: no account type, no tier (decided on the server). */
export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
}
