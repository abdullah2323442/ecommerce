import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Item {
    id: number;
    title: string;
    price: number;
    in_stock: boolean;
    unit: string;
    seller: string;
    photo: string | null;
}

export default function Wishlist({ items }: { items: Item[] }) {
    const { t, money } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;

    return (
        <PublicLayout>
            <Head title={t('My wishlist')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <h1 className="text-2xl font-semibold">{t('My wishlist')}</h1>
                {flash && (
                    <p role="status" className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-900">
                        {flash}
                    </p>
                )}
                {items.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('Nothing saved yet. Press "Save to wishlist" on a product you like.')} <Link href="/market" className="underline">{t('Look at the market')}</Link>
                    </p>
                )}
                <ul className="space-y-3">
                    {items.map((i) => (
                        <li key={i.id} className="flex items-center gap-3 rounded-xl border p-3">
                            {i.photo ? <img src={i.photo} alt="" className="size-16 rounded-lg object-cover" /> : <div className="size-16 rounded-lg bg-muted" />}
                            <div className="min-w-0 flex-1">
                                <Link href={`/market/${i.id}`} className="block truncate font-semibold hover:underline">
                                    {i.title}
                                </Link>
                                <p className="text-sm text-muted-foreground">{i.seller}</p>
                                <p className="tabular text-sm font-semibold">
                                    {money(i.price)} / {i.unit} · <span className={i.in_stock ? 'text-emerald-700' : 'text-red-700'}>{i.in_stock ? t('Available') : t('Sold out')}</span>
                                </p>
                            </div>
                            <Button size="sm" variant="ghost" onClick={() => router.post(`/market/${i.id}/wishlist`, {}, { preserveScroll: true })}>
                                {t('Remove')}
                            </Button>
                        </li>
                    ))}
                </ul>
            </div>
        </PublicLayout>
    );
}
