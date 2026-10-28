import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, FilePlus, RefreshCw } from 'lucide-react';
import { useState } from 'react';

interface Line {
    line: number;
    name: string;
    phone: string;
    status: 'new' | 'update' | 'rate' | 'error';
    problems: string[];
    rate: string | null;
}

interface Props {
    columns: string[];
    maxRows: number;
    preview: { lines: Line[]; counts: { lines: number; new: number; updated: number; rates: number; errors: number } } | null;
    token: string | null;
    done: { new: number; updated: number; rates: number; skipped: number } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Customers', href: '/admin/customers' },
    { title: 'Bring in shops', href: '/admin/customers/import' },
];

/** Bringing in the shops a business already has: choose the file, read the preview line by line, then go on. */
export default function CustomerImport({ columns, maxRows, preview, token, done }: Props) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [busy, setBusy] = useState(false);
    const ok = preview ? preview.counts.new + preview.counts.updated : 0;

    const status = (s: Line['status']) =>
        s === 'new' ? (
            <span className="inline-flex items-center gap-1">
                <FilePlus aria-hidden className="size-4" />
                {t('New shop')}
            </span>
        ) : s === 'update' ? (
            <span className="inline-flex items-center gap-1">
                <RefreshCw aria-hidden className="size-4" />
                {t('Update')}
            </span>
        ) : s === 'rate' ? (
            <span className="inline-flex items-center gap-1">
                <CircleCheck aria-hidden className="size-4" />
                {t('Rate')}
            </span>
        ) : (
            <span className="inline-flex items-center gap-1 font-semibold text-destructive">
                <CircleAlert aria-hidden className="size-4" />
                {t('Left out')}
            </span>
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Bring in shops')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <Flash />
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Bring in shops')}</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('Put the shops you already have on the system from one CSV file: their type, tier, area, address and, if you like, their own rates. You will see every line before anything is saved.')}
                    </p>
                </div>

                {done && (
                    <section role="status" className="space-y-1 rounded-xl border bg-card p-4">
                        <h2 className="font-display text-xl font-bold">{t('Done')}</h2>
                        <p className="text-sm">
                            {t('{n} new shops, {m} shops updated, {r} rates saved.', { n: num(done.new), m: num(done.updated), r: num(done.rates) })}
                            {done.skipped > 0 && ` ${t('{n} lines were left out because of a problem.', { n: num(done.skipped) })}`}
                        </p>
                        <Link href="/admin/customers" className="text-sm font-semibold underline underline-offset-4">
                            {t('Customers')}
                        </Link>
                    </section>
                )}

                {!preview && (
                    <section className="grid gap-6 lg:grid-cols-[1fr_20rem]">
                        <form
                            className="space-y-3 rounded-xl border bg-card p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                const file = new FormData(e.currentTarget).get('file');
                                if (!(file instanceof File) || file.size === 0 || busy) return;
                                router.post('/admin/customers/import/preview', { file }, { forceFormData: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
                            }}
                        >
                            <label className="block text-sm font-medium">
                                {t('The CSV file')}
                                <input name="file" type="file" accept=".csv,text/csv,text/plain" className="mt-1 block w-full rounded-md border bg-background p-2 text-sm" />
                            </label>
                            <InputError message={errors.file} />
                            <Button type="submit" disabled={busy} aria-busy={busy}>
                                {busy ? t('Reading the file…') : t('Read the file')}
                            </Button>
                        </form>
                        <aside className="space-y-2 text-sm">
                            <a href="/admin/customers/import/template" className="font-semibold underline underline-offset-4">
                                {t('Download a file to start from')}
                            </a>
                            <p className="text-muted-foreground">{t('The first line names the columns:')}</p>
                            <p className="tabular break-words font-mono text-xs">{columns.join(', ')}</p>
                            <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                                <li>{t('Name and phone are needed. A shop is recognised by its phone number.')}</li>
                                <li>{t('A number already on the system updates that shop. Its name, type and password are never changed.')}</li>
                                <li>{t('For a rate, write the medicine as the brand and strength (add the company if two share one name), then either a percent or a price for one piece, on the shop line or on a line of its own with the same phone.')}</li>
                                <li>{t('Up to {n} lines in one file.', { n: num(maxRows) })}</li>
                            </ul>
                        </aside>
                    </section>
                )}

                {preview && token && (
                    <section className="space-y-4">
                        <div className="rounded-xl border bg-card p-4">
                            <p className="font-display text-4xl font-extrabold tabular">{num(ok)}</p>
                            <p className="text-sm text-muted-foreground">
                                {t('shops ready to go in: {n} new, {m} updated, {r} rates.', { n: num(preview.counts.new), m: num(preview.counts.updated), r: num(preview.counts.rates) })}
                            </p>
                            {preview.counts.errors > 0 && (
                                <p role="alert" className="mt-1 text-sm font-semibold text-destructive">
                                    {t('{n} lines have a problem and will be left out. The reason is on each line.', { n: num(preview.counts.errors) })}
                                </p>
                            )}
                            <InputError message={errors.file} />
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button disabled={ok === 0 || busy} aria-busy={busy} onClick={() => router.post(`/admin/customers/import/${token}`, {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}>
                                    {busy ? t('Saving…') : t('Bring in {n} shops', { n: num(ok) })}
                                </Button>
                                <Button variant="outline" onClick={() => router.get('/admin/customers/import')}>
                                    {t('Choose another file')}
                                </Button>
                            </div>
                        </div>

                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full min-w-[34rem] text-left text-sm">
                                <caption className="sr-only">{t('Every line of the file and what would happen to it')}</caption>
                                <thead className="bg-muted/50 text-xs">
                                    <tr>
                                        <th scope="col" className="px-3 py-2">{t('Line')}</th>
                                        <th scope="col" className="px-3 py-2">{t('Shop')}</th>
                                        <th scope="col" className="px-3 py-2">{t('What happens')}</th>
                                        <th scope="col" className="px-3 py-2">{t('Note')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {preview.lines.map((l) => (
                                        <tr key={l.line}>
                                            <td className="tabular px-3 py-2">{num(l.line)}</td>
                                            <td className="px-3 py-2">
                                                <span className="block font-medium">{l.name}</span>
                                                <span className="tabular text-xs text-muted-foreground">{l.phone}</span>
                                            </td>
                                            <td className="px-3 py-2">{status(l.status)}</td>
                                            <td className="px-3 py-2 text-xs">
                                                {l.rate}
                                                {l.problems.map((p) => (
                                                    <span key={p} className="block text-destructive">
                                                        {p}
                                                    </span>
                                                ))}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
