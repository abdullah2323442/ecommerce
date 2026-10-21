import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

interface Block {
    id: number;
    ip: string;
    reason: string | null;
    by: string | null;
    since: string | null;
    until: string | null;
    active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Blocked addresses', href: '/admin/blocked-ips' }];

export default function BlockedIps({ blocks, yourIp }: { blocks: Block[]; yourIp: string }) {
    const { t, dateTime } = useI18n();
    const form = useForm({ ip: '', reason: '', days: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/blocked-ips', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Blocked addresses')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Blocked addresses')}</h1>
                    <p className="text-sm text-muted-foreground">{t('A blocked address cannot open any page of the site. Use it for an address that is attacking or abusing the site.')}</p>
                </div>

                <Flash />

                <form onSubmit={submit} className="space-y-3 rounded-md border bg-card p-4">
                    <h2 className="font-display text-lg font-extrabold">{t('Block an address')}</h2>
                    <div className="flex flex-wrap items-start gap-2">
                        <div>
                            <label htmlFor="bip-ip" className="sr-only">
                                {t('Address')}
                            </label>
                            <Input id="bip-ip" placeholder={t('For example 203.0.113.7')} value={form.data.ip} onChange={(e) => form.setData('ip', e.target.value)} className="w-52" />
                            <InputError message={form.errors.ip} />
                        </div>
                        <div>
                            <label htmlFor="bip-reason" className="sr-only">
                                {t('Reason')}
                            </label>
                            <Input id="bip-reason" placeholder={t('Reason (optional)')} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className="w-64" />
                        </div>
                        <div>
                            <label htmlFor="bip-days" className="sr-only">
                                {t('For how many days')}
                            </label>
                            <Input id="bip-days" type="number" min="1" max="365" placeholder={t('Days (empty: until you lift it)')} value={form.data.days} onChange={(e) => form.setData('days', e.target.value)} className="w-56" />
                            <InputError message={form.errors.days} />
                        </div>
                        <Button type="submit" variant="destructive" disabled={form.processing || form.data.ip.trim() === ''}>
                            {t('Block')}
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">{t('The address you are using now is {ip}. It cannot be blocked.', { ip: yourIp })}</p>
                </form>

                {blocks.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-muted-foreground">{t('No address is blocked.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border bg-card">
                        {blocks.map((b) => (
                            <li key={b.id} className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                                <div className="min-w-40 flex-1">
                                    <p className="font-mono font-medium">{b.ip}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {b.reason ?? t('No reason written')}
                                        {b.by && ` · ${b.by}`}
                                    </p>
                                </div>
                                <p className="text-sm tabular-nums">
                                    {!b.active ? t('Ended {date}', { date: dateTime(b.until!) }) : b.until ? t('Until {date}', { date: dateTime(b.until) }) : t('Until you lift it')}
                                </p>
                                <Button variant="outline" size="sm" onClick={() => router.delete(`/admin/blocked-ips/${b.id}`, { preserveScroll: true })}>
                                    {t('Unblock')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
