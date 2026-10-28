import InstallApp from '@/components/install-app';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

interface Props {
    name: string;
    waiting: number;
    waiting_label: string;
    entries: { title: string; url: string; badge: number }[];
}

/** The supplier app's home: what is waiting for you, then the screens of your own kind of business. */
export default function Supplier({ name, waiting, waiting_label, entries }: Props) {
    const { t, num } = useI18n();

    return (
        <PublicLayout>
            <Head title={t('Supplier app')} />
            <div className="mx-auto max-w-2xl space-y-6">
                <header className="space-y-1">
                    <p className="text-sm text-[var(--pt-muted)]">{t('Supplier app')}</p>
                    <h1 className="font-display text-3xl font-extrabold">{name}</h1>
                </header>

                <section className="rounded-[1.5rem] border bg-[var(--pt-surface)] p-5">
                    <p className="tabular font-display text-6xl font-extrabold">{num(waiting)}</p>
                    <p className="text-sm text-[var(--pt-muted)]">{t(waiting_label)}</p>
                </section>

                <ul className="divide-y rounded-[1.5rem] border bg-[var(--pt-surface)]">
                    {entries.map((e) => (
                        <li key={e.url}>
                            <Link href={e.url} className="flex min-h-14 items-center justify-between gap-3 px-5 py-3 hover:bg-[var(--pt-line)]/40 focus-visible:bg-[var(--pt-line)]/40 focus-visible:outline-hidden">
                                <span className="font-medium">{t(e.title)}</span>
                                <span className="flex items-center gap-2">
                                    {e.badge > 0 && <span className="tabular rounded-full bg-[var(--pt-deep)] px-2 py-0.5 text-xs font-bold text-white">{num(e.badge)}</span>}
                                    <ChevronRight aria-hidden className="size-4 text-[var(--pt-muted)]" />
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>

                <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <Link href="/" className="font-semibold underline underline-offset-4">
                        {t('Go to the customer app')}
                    </Link>
                    <InstallApp className="border-[var(--pt-ink)] text-[var(--pt-ink)]" />
                </div>
                <p className="text-xs text-[var(--pt-muted)]">{t('One account for both apps. Buy in the customer app and sell here, with the same login.')}</p>
            </div>
        </PublicLayout>
    );
}
