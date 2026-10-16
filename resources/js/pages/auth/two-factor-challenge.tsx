import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { useI18n } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

/** The second step of signing in: the code from the phone, or one recovery code when the phone is lost. */
export default function TwoFactorChallenge() {
    const { t } = useI18n();
    const [recovery, setRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({ code: '', recovery_code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('two-factor.challenge.store'), { onFinish: () => reset() });
    };

    return (
        <AuthLayout
            title={t('Two-step sign-in')}
            description={recovery ? t('Write one of your recovery codes.') : t('Open your authenticator app and write the 6-digit code.')}
        >
            <Head title={t('Two-step sign-in')} />
            <form className="flex flex-col gap-6" onSubmit={submit}>
                {recovery ? (
                    <div className="grid gap-2">
                        <Label htmlFor="recovery_code">{t('Recovery code')}</Label>
                        <Input id="recovery_code" autoFocus autoComplete="one-time-code" placeholder="abc123-def456" value={data.recovery_code} onChange={(e) => setData('recovery_code', e.target.value)} />
                        <InputError message={errors.recovery_code} />
                    </div>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="code">{t('Code')}</Label>
                        <Input id="code" autoFocus inputMode="numeric" autoComplete="one-time-code" maxLength={7} placeholder="123456" className="text-center text-2xl tracking-[0.4em]" value={data.code} onChange={(e) => setData('code', e.target.value)} />
                        <InputError message={errors.code} />
                    </div>
                )}
                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {t('Continue')}
                </Button>
                <button
                    type="button"
                    className="text-sm underline underline-offset-4"
                    onClick={() => {
                        setRecovery(!recovery);
                        reset();
                    }}
                >
                    {recovery ? t('Use the code from my phone') : t('I lost my phone: use a recovery code')}
                </button>
            </form>
        </AuthLayout>
    );
}
