import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, useForm, usePage } from '@inertiajs/react';

interface Props {
    categories: { key: string; label: string }[];
    application: { status: 'pending' | 'approved' | 'rejected'; business_name: string; review_note: string | null; sent_at: string } | null;
    defaults: { business_name: string | null; phone: string | null };
}

/** Same page for everyone: it never says which account will be opened, so it tells a visitor nothing about the sections behind it. */
export default function Business({ categories, application, defaults }: Props) {
    const { t, dateTime } = useI18n();
    const status = usePage().props.flash as { status?: string } | undefined;
    const form = useForm<{
        business_name: string;
        business_kind: string;
        categories: string[];
        phone: string;
        address: string;
        license_number: string;
        license: File | null;
        second_license: File | null;
        note: string;
    }>({
        business_name: defaults.business_name ?? '',
        business_kind: 'shop',
        categories: ['medicine'],
        phone: defaults.phone ?? '',
        address: '',
        license_number: '',
        license: null,
        second_license: null,
        note: '',
    });
    const waiting = application?.status === 'pending';
    const toggle = (k: string) => form.setData('categories', form.data.categories.includes(k) ? form.data.categories.filter((x) => x !== k) : [...form.data.categories, k]);

    return (
        <PublicLayout>
            <Head title={t('Business account')} />
            <div className="mx-auto max-w-2xl space-y-6">
                <header className="space-y-1">
                    <h1 className="font-display text-3xl font-extrabold">{t('Business account')}</h1>
                    <p className="text-[var(--pt-muted)]">{t('Tell us about your business and send your licence. We check it and reply on this page.')}</p>
                </header>

                {status?.status && <p className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-800">{status.status}</p>}

                {application && (
                    <div role="status" className="space-y-1 rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <p className="font-semibold">
                            {application.status === 'pending' && t('We are checking your application.')}
                            {application.status === 'rejected' && t('We could not open your account.')}
                        </p>
                        <p className="text-sm text-[var(--pt-muted)]">
                            {application.business_name} · {dateTime(application.sent_at)}
                        </p>
                        {application.review_note && <p className="text-sm">{application.review_note}</p>}
                        {application.status === 'rejected' && <p className="text-sm text-[var(--pt-muted)]">{t('You can send a new application below.')}</p>}
                    </div>
                )}

                {!waiting && (
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/business', { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset('license', 'second_license', 'note') });
                        }}
                    >
                        <div>
                            <label htmlFor="bn" className="text-sm font-medium">
                                {t('Business name')}
                            </label>
                            <Input id="bn" className="mt-1" value={form.data.business_name} onChange={(e) => form.setData('business_name', e.target.value)} maxLength={150} />
                            <InputError message={form.errors.business_name} />
                        </div>

                        <fieldset className="space-y-1">
                            <legend className="text-sm font-medium">{t('What is your business?')}</legend>
                            <div className="flex flex-wrap gap-4 text-sm">
                                {[
                                    ['shop', 'A shop'],
                                    ['trader', 'A trading business'],
                                    ['restaurant', 'A restaurant or food business'],
                                ].map(([v, label]) => (
                                    <label key={v} className="flex items-center gap-2">
                                        <input type="radio" name="kind" checked={form.data.business_kind === v} onChange={() => form.setData('business_kind', v)} />
                                        {t(label)}
                                    </label>
                                ))}
                            </div>
                        </fieldset>

                        {form.data.business_kind !== 'restaurant' && (
                        <fieldset className="space-y-1">
                            <legend className="text-sm font-medium">{t('What do you deal in?')}</legend>
                            <div className="flex flex-wrap gap-4 text-sm">
                                {categories.map((c) => (
                                    <label key={c.key} className="flex items-center gap-2">
                                        <input type="checkbox" checked={form.data.categories.includes(c.key)} onChange={() => toggle(c.key)} />
                                        {t(c.label)}
                                    </label>
                                ))}
                            </div>
                            <InputError message={form.errors.categories} />
                        </fieldset>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label htmlFor="ph" className="text-sm font-medium">
                                    {t('Phone')}
                                </label>
                                <Input id="ph" className="mt-1" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                                <InputError message={form.errors.phone} />
                            </div>
                            <div>
                                <label htmlFor="ln" className="text-sm font-medium">
                                    {t('Licence number')}
                                </label>
                                <Input id="ln" className="mt-1" value={form.data.license_number} onChange={(e) => form.setData('license_number', e.target.value)} maxLength={80} />
                                <InputError message={form.errors.license_number} />
                            </div>
                        </div>

                        <div>
                            <label htmlFor="ad" className="text-sm font-medium">
                                {t('Address')}
                            </label>
                            <Input id="ad" className="mt-1" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} maxLength={300} />
                            <InputError message={form.errors.address} />
                        </div>

                        <div className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
                            <p className="text-sm text-[var(--pt-muted)]">{t('Your licence is a private file. Only our staff can open it.')}</p>
                            <div>
                                <label htmlFor="lf" className="text-sm font-medium">
                                    {t('Licence photo or PDF')}
                                </label>
                                <input id="lf" type="file" accept="image/jpeg,image/png,application/pdf" className="mt-1 block text-sm" onChange={(e) => form.setData('license', e.target.files?.[0] ?? null)} />
                                <InputError message={form.errors.license} />
                            </div>
                            <div>
                                <label htmlFor="lf2" className="text-sm font-medium">
                                    {t('Another licence, if you have one')}
                                </label>
                                <input id="lf2" type="file" accept="image/jpeg,image/png,application/pdf" className="mt-1 block text-sm" onChange={(e) => form.setData('second_license', e.target.files?.[0] ?? null)} />
                                <InputError message={form.errors.second_license} />
                            </div>
                        </div>

                        <div>
                            <label htmlFor="nt" className="text-sm font-medium">
                                {t('Note')}
                            </label>
                            <Input id="nt" className="mt-1" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} maxLength={500} />
                        </div>

                        <Button type="submit" className="h-12 w-full text-base" loading={form.processing} disabled={!form.data.license || !form.data.business_name || form.data.categories.length === 0}>
                            {t('Send application')}
                        </Button>
                    </form>
                )}
            </div>
        </PublicLayout>
    );
}
