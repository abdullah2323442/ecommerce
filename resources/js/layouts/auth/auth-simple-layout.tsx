import BrandMark from '@/components/shell/brand-mark';
import PageHead from '@/components/shell/page-head';
import { LanguageToggle, useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

interface AuthLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
}

/** Sign in, sign up and password screens: the brand, one card, the language switch. Nothing else to distract. */
export default function AuthSimpleLayout({ children, title = '', description }: AuthLayoutProps) {
    const { t } = useI18n();

    return (
        <div className="flex min-h-svh flex-col bg-surface text-ink">
            <PageHead title={title} />
            <header className="mx-auto flex h-14 w-full max-w-md items-center justify-between gap-3 px-4">
                <Link href={route('home')} className="min-w-0 rounded-control" aria-label={t('Home')}>
                    <BrandMark />
                </Link>
                <LanguageToggle />
            </header>
            <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-4 pt-4 pb-12">
                <div className="rounded-box border border-line bg-raised p-5 sm:p-7">
                    <h1 className="text-xl font-bold">{title}</h1>
                    {description && <p className="mt-1 text-sm text-ink-muted">{description}</p>}
                    <div className="mt-6">{children}</div>
                </div>
            </main>
        </div>
    );
}
