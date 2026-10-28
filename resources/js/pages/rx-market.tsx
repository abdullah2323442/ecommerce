import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    me: { name: string; phone: string | null; area: string | null } | null;
    joined: boolean;
}

/** The prescription marketplace, not open yet: what it will be, and a way to say you want it. */
export default function RxMarket({ me, joined }: Props) {
    const { t } = useI18n();
    const page = usePage();
    const errors = page.props.errors as Record<string, string>;
    const flash = (page.props.flash as { status?: string } | undefined)?.status;
    const [f, setF] = useState({ name: me?.name ?? '', phone: me?.phone ?? '', area: me?.area ?? '' });
    const [busy, setBusy] = useState(false);
    const steps = [
        { title: t('Send your prescription'), text: t('Take a photo and send it once.') },
        { title: t('Pharmacies answer'), text: t('Pharmacies near you reply with a price for it.') },
        { title: t('You choose'), text: t('Pick the one you like, and order from them.') },
    ];

    return (
        <PublicLayout>
            <Head title={t('Prescription marketplace')} />
            <div className="mx-auto max-w-2xl space-y-8">
                <header className="space-y-2">
                    <p className="text-sm font-semibold text-[var(--pt-muted)]">{t('Coming later')}</p>
                    <h1 className="font-display text-4xl font-extrabold leading-tight">{t('Send a prescription, let pharmacies answer')}</h1>
                    <p className="max-w-prose text-[var(--pt-muted)]">{t('Soon many pharmacies will be connected here. You will send your prescription and order from whichever one you like.')}</p>
                </header>

                <ol className="grid gap-3 sm:grid-cols-3">
                    {steps.map((s, i) => (
                        <li key={s.title} className="rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                            <p className="font-display text-lg font-bold">
                                {i + 1}. {s.title}
                            </p>
                            <p className="text-sm text-[var(--pt-muted)]">{s.text}</p>
                        </li>
                    ))}
                </ol>

                <section className="space-y-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-5">
                    <h2 className="font-display text-xl font-bold">{t('Tell me when it opens')}</h2>
                    {flash && (
                        <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
                            {flash}
                        </p>
                    )}
                    {joined ? (
                        <p className="text-sm">{t('You are on the list. We will tell you when it opens.')}</p>
                    ) : (
                        <form
                            className="space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post('/prescription-market/interest', f, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
                            }}
                        >
                            <div className="grid gap-3 sm:grid-cols-3">
                                <label className="text-sm font-medium">
                                    {t('Your name (optional)')}
                                    <Input className="mt-1" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
                                </label>
                                <label className="text-sm font-medium">
                                    {t('Mobile number')}
                                    <Input className="mt-1" inputMode="tel" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
                                </label>
                                <label className="text-sm font-medium">
                                    {t('Your area (optional)')}
                                    <Input className="mt-1" value={f.area} onChange={(e) => setF({ ...f, area: e.target.value })} />
                                </label>
                            </div>
                            <InputError message={errors.phone} />
                            <Button type="submit" disabled={busy || f.phone.trim().length < 11} aria-busy={busy}>
                                {busy ? t('Saving…') : t('Tell me when it opens')}
                            </Button>
                        </form>
                    )}
                </section>
            </div>
        </PublicLayout>
    );
}
