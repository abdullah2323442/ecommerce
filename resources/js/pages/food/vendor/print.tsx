import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { Head } from '@inertiajs/react';

interface Props {
    kind: 'memo' | 'kitchen';
    order: {
        number: string;
        mode: 'delivery' | 'eat_there';
        ship_to: { name?: string; phone?: string; area?: string; address?: string };
        eat_time: string | null;
        note: string | null;
        lines: { name: string; price: number; quantity: number; note: string | null; ingredients: { base: { name: string; price: number }; items: { name: string; price: number }[] } | null }[];
        subtotal: number;
        delivery_charge: number;
        total: number;
        placed_at: string;
        advance_paid: number;
    };
    restaurant: { name: string; phone: string | null; address: string | null };
    brand: string;
}

/** The memo is in the restaurant's name, ours small at the bottom. The kitchen ticket is the other way round: the details large (spec ch 17). */
export default function Print({ kind, order, restaurant, brand }: Props) {
    const { t, money, num, dateTime } = useI18n();

    return (
        <div className="mx-auto max-w-md bg-white p-6 text-black print:p-0">
            <Head title={order.number} />
            <div className="mb-4 print:hidden">
                <Button onClick={() => window.print()}>{t('Print')}</Button>
            </div>

            {kind === 'memo' ? (
                <article className="space-y-3 text-sm">
                    <header className="text-center">
                        <h1 className="text-2xl font-extrabold">{restaurant.name}</h1>
                        {restaurant.address && <p>{restaurant.address}</p>}
                        {restaurant.phone && <p>{restaurant.phone}</p>}
                    </header>
                    <p>
                        {order.number} · {dateTime(order.placed_at)}
                    </p>
                    <table className="tabular w-full">
                        <tbody>
                            {order.lines.map((l, i) => (
                                <tr key={i} className="border-b align-top">
                                    <td className="py-1">
                                        {num(l.quantity)} × {l.name}
                                        {l.ingredients && (
                                            <span className="block text-xs">
                                                {/* every ingredient on its own line with its price (spec ch 17) */}
                                                <span className="flex justify-between">
                                                    <span>{l.ingredients.base.name}</span>
                                                    <span>{money(l.ingredients.base.price)}</span>
                                                </span>
                                                {l.ingredients.items.map((x, k) => (
                                                    <span key={k} className="flex justify-between">
                                                        <span>+ {x.name}</span>
                                                        <span>{money(x.price)}</span>
                                                    </span>
                                                ))}
                                            </span>
                                        )}
                                        {l.note && <span className="block text-xs">{l.note}</span>}
                                    </td>
                                    <td className="py-1 text-right">{money(l.price * l.quantity)}</td>
                                </tr>
                            ))}
                            {order.mode === 'delivery' && (
                                <tr>
                                    <td className="py-1">{t('Delivery charge')}</td>
                                    <td className="py-1 text-right">{money(order.delivery_charge)}</td>
                                </tr>
                            )}
                            <tr className="border-t-2 font-bold">
                                <td className="py-1">{t('Total')}</td>
                                <td className="py-1 text-right">{money(order.total)}</td>
                            </tr>
                            {order.advance_paid > 0 && (
                                <tr>
                                    <td className="py-1">{t('Advance received')}</td>
                                    <td className="py-1 text-right">{money(order.advance_paid)}</td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                    <p>{order.mode === 'delivery' ? `${order.ship_to.name}, ${order.ship_to.address}, ${order.ship_to.area}` : t("I'll eat there")}</p>
                    <footer className="pt-6 text-center text-[10px] text-gray-500">{brand}</footer>
                </article>
            ) : (
                <article className="space-y-3">
                    <h1 className="text-4xl font-extrabold">{order.number}</h1>
                    <p className="text-2xl font-bold">{order.mode === 'delivery' ? t('Home delivery') : `${t("I'll eat there")} ${order.eat_time ?? ''}`}</p>
                    <ul className="space-y-3">
                        {order.lines.map((l, i) => (
                            <li key={i} className="border-b-2 pb-2">
                                <p className="text-3xl font-extrabold">
                                    {num(l.quantity)} × {l.name}
                                </p>
                                {l.ingredients && <p className="text-2xl font-bold">{l.ingredients.items.length > 0 ? l.ingredients.items.map((x) => x.name).join(' · ') : t('Plain')}</p>}
                                {l.note && <p className="text-2xl font-bold underline">{l.note}</p>}
                            </li>
                        ))}
                    </ul>
                    {order.note && <p className="text-2xl font-bold">{order.note}</p>}
                </article>
            )}
        </div>
    );
}
