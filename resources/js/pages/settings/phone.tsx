import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';

interface Props {
    phone: string | null;
    verified: boolean;
    pending: { phone: string; minutes: number } | null;
    linked: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Mobile number', href: '/settings/phone' }];

export default function Phone({ phone, verified, pending, linked }: Props) {
    const { t, num } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const ask = useForm({ phone: phone ?? '' });
    const check = useForm({ code: '' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Mobile number')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('Mobile number')} description={t('Verify your number. Orders you made before with this number then appear in your account.')} />
                    {flash && (
                        <p role="status" className="rounded-md bg-success/10 p-3 text-sm">
                            {flash}
                        </p>
                    )}
                    {linked != null && linked > 0 && <p className="rounded-md border p-3 text-sm font-medium">{t('{n} earlier orders are now in your account.', { n: num(linked) })}</p>}

                    {verified && phone && (
                        <p className="text-sm">
                            {t('Verified number')}: <strong className="tabular">{phone}</strong>
                        </p>
                    )}

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            ask.post('/settings/phone', { preserveScroll: true });
                        }}
                        className="space-y-2"
                    >
                        <label htmlFor="ph-phone" className="text-sm font-medium">
                            {verified ? t('Change your number') : t('Your mobile number')}
                        </label>
                        <div className="flex flex-wrap gap-2">
                            <Input id="ph-phone" inputMode="tel" className="max-w-60" placeholder="01XXXXXXXXX" value={ask.data.phone} onChange={(e) => ask.setData('phone', e.target.value)} />
                            <Button type="submit" disabled={ask.processing}>
                                {t('Send me a code')}
                            </Button>
                        </div>
                        <InputError message={ask.errors.phone} />
                    </form>

                    {pending && (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                check.post('/settings/phone/verify', { preserveScroll: true, onSuccess: () => check.reset() });
                            }}
                            className="space-y-2"
                        >
                            <label htmlFor="ph-code" className="text-sm font-medium">
                                {t('The code we sent to {phone}', { phone: pending.phone })}
                            </label>
                            <div className="flex flex-wrap gap-2">
                                <Input id="ph-code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} className="max-w-40 tabular tracking-widest" value={check.data.code} onChange={(e) => check.setData('code', e.target.value)} />
                                <Button type="submit" disabled={check.processing}>
                                    {t('Verify')}
                                </Button>
                            </div>
                            <p className="text-xs text-muted-foreground">{t('The code works for about {n} more minutes.', { n: num(pending.minutes) })}</p>
                            <InputError message={check.errors.code} />
                        </form>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
