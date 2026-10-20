import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import QRCode from 'qrcode';
import { useEffect, useState } from 'react';

interface Props {
    enabled: boolean;
    setup: { secret: string; uri: string } | null;
    codesLeft: number;
    recoveryCodes: string[] | null;
    required: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Two-step sign-in', href: '/settings/two-factor' }];

/** The QR is drawn here in the browser from the address the server gave; nothing is sent to an outside service. */
function Qr({ uri }: { uri: string }) {
    const { t } = useI18n();
    const [svg, setSvg] = useState('');

    useEffect(() => {
        let alive = true;
        QRCode.toString(uri, { type: 'svg', margin: 1, width: 192 }).then((s) => alive && setSvg(s));

        return () => {
            alive = false;
        };
    }, [uri]);

    return <div role="img" aria-label={t('QR code for your authenticator app')} className="size-48 rounded-md border bg-white p-1" dangerouslySetInnerHTML={{ __html: svg }} />;
}

export default function TwoFactor({ enabled, setup, codesLeft, recoveryCodes, required }: Props) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const post = (path: string, body: Record<string, string> = {}) => router.post(path, body, { preserveScroll: true, onSuccess: () => (setCode(''), setPassword('')) });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Two-step sign-in')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('Two-step sign-in')} description={t('After your password, you also write a 6-digit code from an app on your phone. A stolen password is then not enough.')} />
                    {required && !enabled && <p role="alert" className="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm">{t('The owner asks staff to use two-step sign-in. Turn it on to use the admin panel.')}</p>}

                    {recoveryCodes && (
                        <section role="status" className="space-y-2 rounded-md border border-success/40 bg-success/10 p-4">
                            <h3 className="font-semibold">{t('Your recovery codes')}</h3>
                            <p className="text-sm">{t('Keep these somewhere safe. Each works once if you lose your phone. They are shown only now.')}</p>
                            <ul className="grid grid-cols-2 gap-1 font-mono text-sm">
                                {recoveryCodes.map((c) => (
                                    <li key={c}>{c}</li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {enabled ? (
                        <div className="space-y-6">
                            <p className="rounded-md bg-success/10 p-3 text-sm font-medium text-success">{t('Two-step sign-in is on.')}</p>
                            <p className="text-sm">{t('{n} recovery codes left.', { n: num(codesLeft) })}</p>
                            <div className="grid max-w-sm gap-2">
                                <Label htmlFor="pw">{t('Your password')}</Label>
                                <Input id="pw" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
                                <InputError message={errors.password} />
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" disabled={!password} onClick={() => post('/settings/two-factor/codes', { password })}>
                                        {t('Make new recovery codes')}
                                    </Button>
                                    <Button variant="destructive" disabled={!password} onClick={() => router.delete('/settings/two-factor', { data: { password }, preserveScroll: true, onSuccess: () => setPassword('') })}>
                                        {t('Turn it off')}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    ) : setup ? (
                        <div className="space-y-4">
                            <p className="text-sm">{t('1. In your authenticator app (Google Authenticator, Microsoft Authenticator, Authy), add an account by scanning this code.')}</p>
                            <Qr uri={setup.uri} />
                            <p className="text-sm">
                                {t('Or write this key by hand')}: <code className="rounded bg-muted px-1.5 py-0.5 font-mono tracking-wider">{setup.secret}</code>
                            </p>
                            <p className="text-sm">{t('2. Write the 6-digit code the app shows now.')}</p>
                            <div className="grid max-w-xs gap-2">
                                <Input aria-label={t('Code')} inputMode="numeric" autoComplete="one-time-code" maxLength={7} placeholder="123456" value={code} onChange={(e) => setCode(e.target.value)} />
                                <InputError message={errors.code} />
                                <Button disabled={code.replace(/\s/g, '').length < 6} onClick={() => post('/settings/two-factor/confirm', { code })}>
                                    {t('Turn it on')}
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="grid max-w-sm gap-2">
                            <Label htmlFor="pw0">{t('Your password')}</Label>
                            <Input id="pw0" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
                            <InputError message={errors.password} />
                            <Button disabled={!password} onClick={() => post('/settings/two-factor', { password })}>
                                {t('Set it up')}
                            </Button>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
