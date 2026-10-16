import Flash from '@/components/admin/flash';
import CustomChat, { type ChatData } from '@/components/custom-chat';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Props {
    order: { id: number; number: string; status: string; kind: 'wood' | 'clothing'; buyer: string; craftsman: string | null; price: number | null };
    chat: ChatData;
}

/** One job's chat, as we read it: real names beside each message. We can answer, and send an offer. */
export default function CustomChatPage({ order, chat }: Props) {
    const { t, money } = useI18n();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Job chats', href: '/admin/chats' },
        { title: order.number, href: `/admin/chats/${order.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={order.number} />
            <div className="mx-auto w-full max-w-3xl space-y-4 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">
                        {order.number} · {order.kind === 'wood' ? t('Wood') : t('Clothing')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {order.buyer} · {order.craftsman ?? t('Nobody has the job yet')} · {order.status}
                        {order.price !== null && ` · ${money(order.price)}`}
                    </p>
                </div>
                <Flash />
                <CustomChat chat={chat} postUrl={`/admin/chats/${order.id}`} fileUrl={(id) => `/admin/chats/file/${id}`} offerUrl={`/admin/chats/${order.id}/offer`} />
            </div>
        </AppLayout>
    );
}
