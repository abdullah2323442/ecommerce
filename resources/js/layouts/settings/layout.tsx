import { Separator } from '@/components/ui/separator';
import { useI18n } from '@/lib/i18n';
import { Link, usePage } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

/** The settings pages share a short side menu: profile, password, appearance. */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { t } = useI18n();
    const path = usePage().url;

    const items = [
        { title: t('Profile'), href: '/settings/profile' },
        { title: t('Mobile number'), href: '/settings/phone' },
        { title: t('Password'), href: '/settings/password' },
        { title: t('Two-step sign-in'), href: '/settings/two-factor' },
        { title: t('Connected accounts'), href: '/settings/connected-accounts' },
        { title: t('Appearance'), href: '/settings/appearance' },
    ];

    return (
        <div className="px-4 py-6">
            <div className="mb-8 space-y-0.5">
                <h2 className="text-xl font-semibold tracking-tight">{t('Settings')}</h2>
            </div>

            <div className="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav aria-label={t('Settings')} className="flex flex-col space-y-1">
                        {items.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                prefetch
                                aria-current={path.startsWith(item.href) ? 'page' : undefined}
                                className={`flex min-h-10 items-center rounded-md px-3 text-sm font-medium transition-colors ${path.startsWith(item.href) ? 'bg-muted' : 'hover:bg-muted/60'}`}
                            >
                                {item.title}
                            </Link>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
