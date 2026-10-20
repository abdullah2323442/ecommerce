import Flash from '@/components/admin/flash';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Star } from 'lucide-react';

interface Row {
    id: number;
    name: string;
    place: string | null;
    quote: string;
    quote_bn: string | null;
    rating: number;
    is_active: boolean;
    sort: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Testimonials', href: '/admin/testimonials' }];

/** Five stars you press to set the rating: buttons, not a drop-down, so it works by touch and keyboard. */
function Stars({ value, onChange }: { value: number; onChange: (n: number) => void }) {
    const { t } = useI18n();

    return (
        <div role="group" aria-label={t('Stars')} className="flex gap-1">
            {[1, 2, 3, 4, 5].map((n) => (
                <button
                    key={n}
                    type="button"
                    aria-pressed={n <= value}
                    aria-label={`${n}`}
                    onClick={() => onChange(n)}
                    className="focus-visible:ring-ring rounded p-0.5 outline-none focus-visible:ring-2"
                >
                    <Star
                        aria-hidden
                        className={`size-5 ${n <= value ? 'fill-[var(--art-gold-2)] text-[var(--art-gold-2)]' : 'text-muted-foreground'}`}
                    />
                </button>
            ))}
        </div>
    );
}

function Editor({ row, onDone }: { row?: Row; onDone?: () => void }) {
    const { t } = useI18n();
    const form = useForm({
        name: row?.name ?? '',
        place: row?.place ?? '',
        quote: row?.quote ?? '',
        quote_bn: row?.quote_bn ?? '',
        rating: row?.rating ?? 5,
        is_active: row?.is_active ?? true,
        sort: row?.sort ?? 0,
    });

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                if (!row) form.reset();
                onDone?.();
            },
        };
        if (row) form.put(`/admin/testimonials/${row.id}`, opts);
        else form.post('/admin/testimonials', opts);
    };

    return (
        <form onSubmit={save} className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm font-medium">
                    {t('Name')}
                    <Input className="mt-1" maxLength={80} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                    {form.errors.name && <span className="text-xs text-danger">{form.errors.name}</span>}
                </label>
                <label className="text-sm font-medium">
                    {t('Where from')}
                    <Input
                        className="mt-1"
                        maxLength={80}
                        placeholder={t('For example: Mirpur, Dhaka')}
                        value={form.data.place}
                        onChange={(e) => form.setData('place', e.target.value)}
                    />
                </label>
            </div>
            <label className="block text-sm font-medium">
                {t('What they said')}
                <textarea
                    className="mt-1 min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                    maxLength={400}
                    value={form.data.quote}
                    onChange={(e) => form.setData('quote', e.target.value)}
                    required
                />
                {form.errors.quote && <span className="text-xs text-danger">{form.errors.quote}</span>}
            </label>
            <label className="block text-sm font-medium">
                {t('The same in Bangla')}
                <textarea
                    className="mt-1 min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                    maxLength={600}
                    value={form.data.quote_bn}
                    onChange={(e) => form.setData('quote_bn', e.target.value)}
                />
            </label>
            <div className="flex flex-wrap items-center gap-4">
                <Stars value={form.data.rating} onChange={(n) => form.setData('rating', n)} />
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                    {t('Show on the home page')}
                </label>
                <label className="flex items-center gap-2 text-sm">
                    {t('Order')}
                    <Input
                        type="number"
                        min="0"
                        max="1000"
                        className="w-20"
                        value={form.data.sort}
                        onChange={(e) => form.setData('sort', Number(e.target.value))}
                    />
                </label>
                <Button type="submit" disabled={form.processing} className="ml-auto">
                    {row ? t('Save') : t('Add')}
                </Button>
            </div>
        </form>
    );
}

export default function Testimonials({ testimonials }: { testimonials: Row[] }) {
    const { t } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Testimonials')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />
                <div>
                    <h1 className="text-2xl font-bold">{t('Testimonials')}</h1>
                    <p className="text-muted-foreground text-sm">
                        {t('What buyers say, shown near the bottom of the home page. Use real words from real buyers, with their permission.')}
                    </p>
                </div>

                <section className="bg-card rounded-md border p-4">
                    <h2 className="mb-3 font-semibold">{t('Add one')}</h2>
                    <Editor />
                </section>

                {testimonials.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">{t('Nothing here yet.')}</p>
                ) : (
                    <ul className="space-y-3">
                        {testimonials.map((r) => (
                            <li key={r.id} className={`bg-card rounded-md border p-4 ${r.is_active ? '' : 'opacity-60'}`}>
                                <Editor row={r} />
                                <div className="mt-2 text-right">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            if (window.confirm(t('Remove this testimonial?')))
                                                router.delete(`/admin/testimonials/${r.id}`, { preserveScroll: true });
                                        }}
                                    >
                                        {t('Remove')}
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
