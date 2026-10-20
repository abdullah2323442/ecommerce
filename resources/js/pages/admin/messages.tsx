import Flash from '@/components/admin/flash';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    kinds: { key: string; label: string; importance: string; channels: string[] }[];
    channels: string[];
    available: string[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Messages', href: '/admin/messages' }];
const NAME: Record<string, string> = { inbox: 'Inbox', email: 'Email', sms: 'SMS', push: 'Phone notification' };

export default function Messages({ kinds, channels, available }: Props) {
    const { t } = useI18n();
    const [map, setMap] = useState<Record<string, string[]>>(() => Object.fromEntries(kinds.map((k) => [k.key, k.channels])));
    const toggle = (kind: string, channel: string) => setMap({ ...map, [kind]: map[kind].includes(channel) ? map[kind].filter((c) => c !== channel) : [...map[kind], channel] });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Messages')} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Messages')}</h1>
                    <p className="text-muted-foreground text-sm">{t('What a customer is told about their order, and by which way. The inbox is always on. SMS costs money, so it starts off.')}</p>
                </header>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="px-3 py-2 font-medium">{t('Message')}</th>
                                <th className="px-3 py-2 font-medium">{t('How important')}</th>
                                {channels.map((c) => (
                                    <th key={c} className="px-3 py-2 text-center font-medium">
                                        {t(NAME[c] ?? c)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {kinds.map((k) => (
                                <tr key={k.key}>
                                    <td className="px-3 py-2">{t(k.label)}</td>
                                    <td className={`px-3 py-2 ${k.importance === 'urgent' ? 'font-semibold text-danger' : 'text-muted-foreground'}`}>{t(k.importance === 'urgent' ? 'Urgent' : k.importance === 'low' ? 'Low' : 'Normal')}</td>
                                    {channels.map((c) => (
                                        <td key={c} className="px-3 py-2 text-center">
                                            <input
                                                type="checkbox"
                                                aria-label={`${t(k.label)} · ${t(NAME[c] ?? c)}`}
                                                checked={c === 'inbox' || map[k.key].includes(c)}
                                                disabled={c === 'inbox' || !available.includes(c)}
                                                onChange={() => toggle(k.key, c)}
                                            />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">{t('Phone notifications need the phone app, which is not ready yet. Until an SMS service is connected, texts are only written to the log. Email goes only to people who gave a real email address.')}</p>
                <Button onClick={() => router.put('/admin/messages', { channels: map }, { preserveScroll: true })}>{t('Save')}</Button>
            </div>
        </AppLayout>
    );
}
