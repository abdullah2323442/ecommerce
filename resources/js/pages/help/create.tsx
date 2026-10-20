import InputError from '@/components/input-error';
import { categoryLabel, HelpNotice } from '@/components/help-bits';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, useForm } from '@inertiajs/react';

export default function HelpCreate({ categories, refusal }: { categories: string[]; refusal: string | null }) {
    const { t } = useI18n();
    const form = useForm({ category: categories[0] ?? 'worker', title: '', body: '', contact: '' });
    const errors = form.errors as Record<string, string>;

    return (
        <PublicLayout>
            <Head title={t('Post something')} />
            <div className="mx-auto max-w-2xl space-y-6">
                <Link href="/help" className="text-sm underline underline-offset-4">
                    {t('Back to the Help board')}
                </Link>
                <h1 className="font-display text-3xl font-extrabold">{t('Post something')}</h1>
                <HelpNotice />
                <p className="text-sm text-[var(--pt-muted)]">{t('We read every post before it is shown. Photos are not allowed.')}</p>
                {refusal && <p role="alert" className="rounded-[1.5rem] border border-warning/40 bg-warning/10 px-4 py-3 text-sm">{refusal}</p>}
                <InputError message={errors.post} />

                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/help');
                    }}
                >
                    <fieldset>
                        <legend className="text-sm font-medium">{t('What is it about?')}</legend>
                        <div className="mt-1 flex flex-wrap gap-2">
                            {categories.map((c) => (
                                <label key={c} className={`cursor-pointer rounded-full border px-3 py-1 text-sm ${form.data.category === c ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)]'}`}>
                                    <input type="radio" name="category" value={c} checked={form.data.category === c} onChange={() => form.setData('category', c)} className="sr-only" />
                                    {t(categoryLabel(c))}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.category} />
                    </fieldset>
                    <div>
                        <label htmlFor="h-title" className="text-sm font-medium">
                            {t('Title')}
                        </label>
                        <Input id="h-title" className="mt-1" maxLength={120} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        <InputError message={errors.title} />
                    </div>
                    <div>
                        <label htmlFor="h-body" className="text-sm font-medium">
                            {t('Details: what you need, and what you can pay if it is a job')}
                        </label>
                        <textarea id="h-body" rows={6} maxLength={2000} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className="mt-1 w-full rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3" />
                        <InputError message={errors.body} />
                    </div>
                    <div>
                        <label htmlFor="h-contact" className="text-sm font-medium">
                            {t('How should people reach you?')}
                        </label>
                        <Input id="h-contact" className="mt-1" maxLength={120} value={form.data.contact} onChange={(e) => form.setData('contact', e.target.value)} />
                        <p className="mt-1 text-xs text-[var(--pt-muted)]">{t('Only people who are logged in see this.')}</p>
                        <InputError message={errors.contact} />
                    </div>
                    <Button type="submit" disabled={form.processing || !!refusal}>
                        {t('Send for approval')}
                    </Button>
                </form>
            </div>
        </PublicLayout>
    );
}
