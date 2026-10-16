import { Bag3D, Burger3D, Controller3D } from '@/components/art/sectors-3d';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Props {
    account: { name: string; type: string; tier: string | null };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Account', href: '/dashboard' }];

export default function Account({ account }: Props) {
    const { t } = useI18n();

    const types: Record<string, string> = {
        public: t('Public customer'),
        paikari: t('Paikari customer'),
        wholesaler: t('Wholesale customer'),
        mpo: t('MPO'),
        food_vendor: t('Food vendor'),
        craftsman: t('Craftsman'),
        staff: t('Staff'),
        admin: t('Admin'),
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Account')} />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4 md:p-6">
                <header>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Hello, {name}', { name: account.name })}</h1>
                    <p className="text-sm text-muted-foreground">
                        {types[account.type] ?? account.type}
                        {account.tier ? ` · ${t('Tier {tier}', { tier: account.tier })}` : ''}
                    </p>
                </header>

                <nav aria-label={t('Your orders')} className="flex flex-wrap gap-4 text-sm font-semibold">
                    <Link href="/my-orders" className="underline underline-offset-4">
                        {t('Your orders')}
                    </Link>
                    <Link href="/inbox" className="underline underline-offset-4">
                        {t('Your messages')}
                    </Link>
                    <Link href="/support" className="underline underline-offset-4">
                        {t('My requests')}
                    </Link>
                    <Link href="/preorders" className="underline underline-offset-4">
                        {t('Your pre-orders')}
                    </Link>
                    <Link href="/history" className="underline underline-offset-4">
                        {t('All your orders')}
                    </Link>
                    <Link href="/market-cart" className="underline underline-offset-4">
                        {t('Your market cart')}
                    </Link>
                    <Link href="/wishlist" className="underline underline-offset-4">
                        {t('My wishlist')}
                    </Link>
                    <Link href="/cart" className="underline underline-offset-4">
                        {t('Your cart')}
                    </Link>
                </nav>

                <section className="relative overflow-hidden rounded-2xl border bg-card p-5">
                    <div aria-hidden className="pointer-events-none absolute -right-2 -bottom-2 flex w-44 items-end gap-1 opacity-90">
                        <Burger3D className="float-a w-1/3" flat />
                        <Controller3D className="float-b w-1/2" flat />
                        <Bag3D className="float-c w-1/3" flat />
                    </div>
                    <h2 className="font-display text-xl font-extrabold">{t('Look up a medicine')}</h2>
                    <p className="mt-1 max-w-sm text-sm text-muted-foreground">{t('Search by brand or generic name, in English or Bangla, and see the printed price.')}</p>
                    <Link href="/medicines" className="relative mt-3 inline-block rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">
                        {t('Search medicines')}
                    </Link>
                </section>
            </div>
        </AppLayout>
    );
}
