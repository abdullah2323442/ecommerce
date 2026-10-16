import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';

const TOPIC_LABEL: Record<string, string> = {
    order: 'My order',
    payment: 'Payment',
    delivery: 'Delivery',
    product: 'A product',
    account: 'My account',
    other: 'Something else',
};

interface Props {
    defaults: { name: string | null; phone: string | null };
    topics: string[];
    orderToken: string | null;
    topic: string;
    signedIn: boolean;
}

/** Write to the shop. No account is needed, but a name and a phone number are, as for an order. */
export default function SupportNew({ defaults, topics, orderToken, topic, signedIn }: Props) {
    const { t } = useI18n();
    const form = useForm({ name: defaults.name ?? '', phone: defaults.phone ?? '', topic: orderToken ? 'order' : topic, subject: '', body: '', order_token: orderToken ?? '' });
    const errors = form.errors as Record<string, string>;

    return (
        <PublicLayout>
            <Head title={t('Contact us')} />
            <div className="mx-auto max-w-2xl space-y-6">
                <h1 className="font-display text-3xl font-extrabold">{t('Contact us')}</h1>
                <p className="text-sm text-[var(--pt-muted)]">{t('Write what you need. We answer on a page only you can open, and tell you when we do.')}</p>
                {orderToken && <p className="rounded-xl border px-3 py-2 text-sm">{t('This request is about your order. We will see it.')}</p>}
                <InputError message={errors.support} />

                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/support');
                    }}
                >
                    <fieldset>
                        <legend className="text-sm font-medium">{t('What is it about?')}</legend>
                        <div className="mt-1 flex flex-wrap gap-2">
                            {topics.map((c) => (
                                <label key={c} className={`cursor-pointer rounded-full border px-3 py-1 text-sm ${form.data.topic === c ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)]'}`}>
                                    <input type="radio" name="topic" value={c} checked={form.data.topic === c} onChange={() => form.setData('topic', c)} className="sr-only" />
                                    {t(TOPIC_LABEL[c] ?? 'Something else')}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor="s-name" className="text-sm font-medium">
                                {t('Your name')}
                            </label>
                            <Input id="s-name" className="mt-1" maxLength={120} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <InputError message={errors.name} />
                        </div>
                        <div>
                            <label htmlFor="s-phone" className="text-sm font-medium">
                                {t('Mobile number')}
                            </label>
                            <Input id="s-phone" className="mt-1" inputMode="tel" placeholder="01XXXXXXXXX" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                            <InputError message={errors.phone} />
                        </div>
                    </div>
                    <div>
                        <label htmlFor="s-subject" className="text-sm font-medium">
                            {t('Title')}
                        </label>
                        <Input id="s-subject" className="mt-1" maxLength={120} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                        <InputError message={errors.subject} />
                    </div>
                    <div>
                        <label htmlFor="s-body" className="text-sm font-medium">
                            {t('Tell us what happened')}
                        </label>
                        <textarea id="s-body" rows={6} maxLength={2000} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className="mt-1 w-full rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3" />
                        <InputError message={errors.body} />
                    </div>
                    {!signedIn && <p className="text-xs text-[var(--pt-muted)]">{t('You do not need an account. Keep the page address we show you after sending.')}</p>}
                    <Button type="submit" disabled={form.processing}>
                        {t('Send')}
                    </Button>
                </form>
            </div>
        </PublicLayout>
    );
}
