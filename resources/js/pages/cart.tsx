import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ShoppingBag, Trash2 } from 'lucide-react';

interface Line {
    key: string;
    id: number;
    name: string;
    strength: string | null;
    company: string | null;
    unit: 'piece' | 'strip' | 'box';
    quantity: number;
    unit_price: number;
    total: number;
    mrp: number;
    step: { percent: number; next: { needs: number; percent: number } | null };
}

interface Props {
    lines: Line[];
    subtotal: number;
    freeOver: number;
}

export const unitName = (u: string) => (u === 'box' ? 'Box' : u === 'strip' ? 'Strip' : 'Piece');

export default function Cart({ lines, subtotal, freeOver }: Props) {
    const { t, money, num } = useI18n();
    const page = usePage();
    const errors = page.props.errors as Record<string, string>;
    const status = (page.props.flash as { status?: string } | undefined)?.status;

    return (
        <PublicLayout>
            <Head title={t('Your cart')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <h1 className="font-display text-3xl font-extrabold">{t('Your cart')}</h1>
                {status && <p className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{status}</p>}
                <InputError message={errors.cart} />

                {lines.length === 0 ? (
                    <div className="rounded-2xl border border-dashed p-10 text-center">
                        <ShoppingBag aria-hidden className="mx-auto size-10 text-[var(--pt-muted)]" />
                        <p className="mt-3 font-semibold">{t('Your cart is empty.')}</p>
                        <div className="mt-3 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm font-semibold">
                            <Link href="/medicines" className="underline underline-offset-4">
                                {t('Medicines')}
                            </Link>
                            <Link href="/market" className="underline underline-offset-4">
                                {t('Market')}
                            </Link>
                            <Link href="/food" className="underline underline-offset-4">
                                {t('Food')}
                            </Link>
                        </div>
                    </div>
                ) : (
                    <>
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                            {lines.map((l) => (
                                <li key={l.key} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                    <div className="min-w-48 flex-1">
                                        <Link href={`/medicines/${l.id}`} className="font-semibold hover:underline">
                                            {l.name} <span className="text-sm font-normal text-[var(--pt-muted)]">{l.strength}</span>
                                        </Link>
                                        <p className="text-sm text-[var(--pt-muted)]">
                                            {l.company} · {t(unitName(l.unit))} · {money(l.unit_price)}
                                        </p>
                                        {l.step.percent > 0 && <p className="text-sm font-medium text-success">{t('You are getting {p}% off', { p: l.step.percent })}</p>}
                                        {l.step.next && (
                                            <p className="text-sm text-[var(--pt-muted)]">{t('Take {n} more for {p}% off each', { n: l.step.next.needs, p: l.step.next.percent })}</p>
                                        )}
                                    </div>
                                    <Input
                                        aria-label={t('Quantity')}
                                        className="w-20"
                                        type="number"
                                        min="1"
                                        max="1000"
                                        step="1"
                                        defaultValue={l.quantity}
                                        onBlur={(e) => Number(e.target.value) >= 1 && Number(e.target.value) !== l.quantity && router.put(`/cart/${l.key}`, { quantity: Math.round(Number(e.target.value)) }, { preserveScroll: true })}
                                    />
                                    <p className="tabular w-24 text-right font-semibold">{money(l.total)}</p>
                                    <Button variant="ghost" size="icon" aria-label={t('Remove')} onClick={() => router.delete(`/cart/${l.key}`, { preserveScroll: true })}>
                                        <Trash2 className="size-4" />
                                    </Button>
                                </li>
                            ))}
                        </ul>

                        <div className="space-y-1 rounded-2xl border bg-[var(--pt-surface)] p-4">
                            <p className="flex justify-between font-display text-lg font-bold">
                                <span>
                                    {t('Items')} ({num(lines.length)})
                                </span>
                                <span className="tabular">{money(subtotal)}</span>
                            </p>
                            <p className="text-sm text-[var(--pt-muted)]">
                                {t('Delivery charge is added at the next step.')}
                                {freeOver > 0 && ` ${subtotal >= freeOver ? t('Delivery is free on this order.') : t('Add {amount} more for free delivery.', { amount: money(freeOver - subtotal) })}`}
                            </p>
                        </div>

                        <Link
                            href="/checkout"
                            className="flex h-12 w-full items-center justify-center rounded-full bg-[var(--pt-deep)] text-base font-semibold text-white transition-opacity hover:opacity-90"
                        >
                            {t('Go to checkout')}
                        </Link>
                    </>
                )}
            </div>
        </PublicLayout>
    );
}
