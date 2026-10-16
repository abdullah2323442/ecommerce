import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface Props {
    title: string;
    intro: string;
    sections: { heading: string; body: string[] }[];
}

const PAGES = [
    { href: '/faq', label: 'Questions and answers' },
    { href: '/return-policy', label: 'Return policy' },
    { href: '/terms', label: 'Terms of service' },
    { href: '/privacy', label: 'Privacy policy' },
];

/** Plain reading pages: terms, privacy, returns, questions. Server-side text, already in the viewer's language. */
export default function Info({ title, intro, sections }: Props) {
    const { t } = useI18n();
    const { url, props } = usePage<SharedData>();
    const brand = props.brand;
    const faq = url.startsWith('/faq');

    return (
        <PublicLayout>
            <Head title={title} />
            <div className="mx-auto max-w-2xl space-y-8">
                <header className="space-y-2">
                    <h1 className="font-display text-3xl font-extrabold">{title}</h1>
                    <p className="text-[var(--pt-muted)]">{intro}</p>
                </header>

                <div className="space-y-8">
                    {sections.map((s) => (
                        <section key={s.heading} className="space-y-3">
                            <h2 className="font-display text-xl font-bold">{s.heading}</h2>
                            {faq ? (
                                <dl className="space-y-3">
                                    {s.body.map((line) => {
                                        const [q, ...a] = line.split(' — ');

                                        return (
                                            <div key={line} className="rounded-2xl border bg-[var(--pt-surface)] p-4">
                                                <dt className="font-semibold">{q}</dt>
                                                <dd className="mt-1 text-[var(--pt-muted)]">{a.join(' — ')}</dd>
                                            </div>
                                        );
                                    })}
                                </dl>
                            ) : (
                                <ul className="list-disc space-y-2 pl-5 leading-relaxed marker:text-[var(--pt-amber)]">
                                    {s.body.map((line) => (
                                        <li key={line}>{line}</li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                </div>

                {(brand.phone || brand.email || brand.address) && (
                    <section className="space-y-1 rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
                        <h2 className="font-display text-lg font-bold">{t('Contact us')}</h2>
                        {brand.address && <p>{brand.address}</p>}
                        {brand.phone && <p>{brand.phone}</p>}
                        {brand.email && <p>{brand.email}</p>}
                    </section>
                )}

                <nav aria-label={t('More information')} className="flex flex-wrap gap-x-5 gap-y-2 border-t pt-4 text-sm font-semibold">
                    {PAGES.filter((p) => !url.startsWith(p.href)).map((p) => (
                        <Link key={p.href} href={p.href} className="underline underline-offset-4">
                            {t(p.label)}
                        </Link>
                    ))}
                </nav>
            </div>
        </PublicLayout>
    );
}
