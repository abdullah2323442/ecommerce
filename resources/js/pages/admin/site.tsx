import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Pair = { en: string | null; bn: string | null };
type Link = { label: string; label_bn: string | null; url: string; enabled: boolean };
type Section = { key: string; enabled: boolean; status: 'open' | 'soon' };

interface Groups {
    brand: { name: string | null; name_bn: string | null; tagline: Pair; theme_color: string | null };
    contact: { phone: string | null; email: string | null; address: string | null; whatsapp: string | null; messenger: string | null; drug_license: string | null; hours: Pair };
    social: { facebook: string | null; instagram: string | null; youtube: string | null; tiktok: string | null };
    footer: { about: Pair; copyright: Pair; links: Link[] };
    home: { hero_title: Pair; hero_text: Pair; sections: Section[] };
    announcement: { enabled: boolean; text: Pair; link: string | null };
    seo: { description: Pair };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Site design', href: '/admin/site' }];

function Box({ title, hint, children, onSave }: { title: string; hint?: string; children: React.ReactNode; onSave: () => void }) {
    const { t } = useI18n();

    return (
        <section className="space-y-3 rounded-lg border p-4">
            <div>
                <h2 className="font-semibold">{title}</h2>
                {hint && <p className="text-sm text-muted-foreground">{hint}</p>}
            </div>
            {children}
            <Button size="sm" onClick={onSave}>
                {t('Save')}
            </Button>
        </section>
    );
}

function Text({ label, value, onChange, max = 200, area }: { label: string; value: string | null; onChange: (v: string) => void; max?: number; area?: boolean }) {
    return (
        <label className="block text-sm font-medium">
            {label}
            {area ? (
                <textarea rows={2} maxLength={max} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className="mt-1 w-full rounded-md border bg-background p-2 text-sm" />
            ) : (
                <Input className="mt-1" maxLength={max} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
            )}
        </label>
    );
}

function Both({ label, value, onChange, max = 200, area }: { label: string; value: Pair; onChange: (v: Pair) => void; max?: number; area?: boolean }) {
    const { t } = useI18n();

    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <Text label={`${label} (${t('English')})`} value={value.en} onChange={(en) => onChange({ ...value, en })} max={max} area={area} />
            <Text label={`${label} (${t('Bangla')})`} value={value.bn} onChange={(bn) => onChange({ ...value, bn })} max={max} area={area} />
        </div>
    );
}

function Picture({ which, label, has }: { which: 'logo' | 'favicon'; label: string; has: boolean }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <div className="space-y-1 text-sm">
            <p className="font-medium">{label}</p>
            <input type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => e.target.files?.[0] && router.post(`/admin/site/image/${which}`, { image: e.target.files[0] }, { forceFormData: true, preserveScroll: true })} />
            <InputError message={errors.image} />
            {has && (
                <div className="flex items-center gap-3">
                    <img src={`/brand/${which}?r=${Date.now()}`} alt="" className="size-12 rounded border object-contain" />
                    <Button size="sm" variant="ghost" onClick={() => router.delete(`/admin/site/image/${which}`, { preserveScroll: true })}>
                        {t('Remove')}
                    </Button>
                </div>
            )}
        </div>
    );
}

export default function Site({ groups, images }: { groups: Groups; images: { logo: boolean; favicon: boolean } }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [brand, setBrand] = useState(groups.brand);
    const [contact, setContact] = useState(groups.contact);
    const [social, setSocial] = useState(groups.social);
    const [footer, setFooter] = useState(groups.footer);
    const [home, setHome] = useState(groups.home);
    const [bar, setBar] = useState(groups.announcement);
    const [seo, setSeo] = useState(groups.seo);
    const save = (group: string, data: object) => router.put(`/admin/site/${group}`, data as Record<string, never>, { preserveScroll: true });
    const sectionName = (k: string) => t(k === 'pharmacy' ? 'Pharmacy' : k === 'food' ? 'Food' : k === 'games' ? 'Gaming' : k === 'custom' ? 'Made to order' : k === 'market' ? 'The Market' : 'Help board');
    const move = (i: number, d: number) => {
        const s = [...home.sections];
        const j = i + d;
        if (j < 0 || j >= s.length) return;
        [s[i], s[j]] = [s[j], s[i]];
        setHome({ ...home, sections: s });
    };
    const setLink = (i: number, patch: Partial<Link>) => setFooter({ ...footer, links: footer.links.map((l, n) => (n === i ? { ...l, ...patch } : l)) });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Site design')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                <div>
                    <h1 className="text-2xl font-bold">{t('Site design')}</h1>
                    <p className="text-sm text-muted-foreground">{t('The name, logo, words and links of your site. What you save shows on the site at once. Leave a Bangla box empty to show the English words.')}</p>
                </div>
                <InputError message={errors.site} />

                <a href="/admin/site/builder" className="glass flex flex-wrap items-center justify-between gap-3 rounded-2xl p-4 hover:opacity-90">
                    <span>
                        <span className="classic block text-xl font-semibold">{t('Home page builder')}</span>
                        <span className="text-sm opacity-70">{t('Drag blocks to rearrange the home page, change their words and pictures, and watch the real page change as you go.')}</span>
                    </span>
                    <span className="rounded-full bg-foreground px-4 py-2 text-sm font-medium text-background">{t('Open the builder')}</span>
                </a>

                <Box title={t('Name and logo')} onSave={() => save('brand', brand)}>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Text label={t('Shop name')} value={brand.name} onChange={(name) => setBrand({ ...brand, name })} max={80} />
                        <Text label={t('Shop name in Bangla')} value={brand.name_bn} onChange={(name_bn) => setBrand({ ...brand, name_bn })} max={80} />
                    </div>
                    <Both label={t('Tagline')} value={brand.tagline} onChange={(tagline) => setBrand({ ...brand, tagline })} max={120} />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Picture which="logo" label={t('Logo (PNG, JPG or WebP, up to 2 MB)')} has={images.logo} />
                        <Text label={t('App colour, like #0f766e')} value={brand.theme_color} onChange={(theme_color) => setBrand({ ...brand, theme_color })} max={7} />
                        <Picture which="favicon" label={t('Small icon for the browser tab')} has={images.favicon} />
                    </div>
                </Box>

                <Box title={t('Contact')} hint={t('Each line shows in the footer only when it is filled in.')} onSave={() => save('contact', contact)}>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Text label={t('Phone')} value={contact.phone} onChange={(phone) => setContact({ ...contact, phone })} max={30} />
                        <Text label={t('Email')} value={contact.email} onChange={(email) => setContact({ ...contact, email })} max={120} />
                        <Text label={t('Address')} value={contact.address} onChange={(address) => setContact({ ...contact, address })} max={250} />
                        <Text label={t('WhatsApp number, with country code (8801…)')} value={contact.whatsapp} onChange={(whatsapp) => setContact({ ...contact, whatsapp })} max={20} />
                        <Text label={t('Messenger page name')} value={contact.messenger} onChange={(messenger) => setContact({ ...contact, messenger })} max={80} />
                        <Text label={t('Drug licence number')} value={contact.drug_license} onChange={(drug_license) => setContact({ ...contact, drug_license })} max={60} />
                    </div>
                    <Both label={t('Opening hours')} value={contact.hours} onChange={(hours) => setContact({ ...contact, hours })} max={160} />
                </Box>

                <Box title={t('Home page')} hint={t('The big words at the top, and which shops show, in what order, and whether each is open or opening soon.')} onSave={() => save('home', home)}>
                    <Both label={t('Big heading (empty: the standard one)')} value={home.hero_title} onChange={(hero_title) => setHome({ ...home, hero_title })} max={80} />
                    <Both label={t('Line under it')} value={home.hero_text} onChange={(hero_text) => setHome({ ...home, hero_text })} max={300} area />
                    <ul className="divide-y rounded-md border text-sm">
                        {home.sections.map((s, i) => (
                            <li key={s.key} className="flex flex-wrap items-center gap-3 px-3 py-2">
                                <span className="min-w-32 flex-1 font-medium">{sectionName(s.key)}</span>
                                <label className="flex items-center gap-1.5">
                                    <input type="checkbox" checked={s.enabled} onChange={(e) => setHome({ ...home, sections: home.sections.map((x, n) => (n === i ? { ...x, enabled: e.target.checked } : x)) })} />
                                    {t('Show')}
                                </label>
                                <select aria-label={t('Status')} value={s.status} onChange={(e) => setHome({ ...home, sections: home.sections.map((x, n) => (n === i ? { ...x, status: e.target.value as 'open' | 'soon' } : x)) })} className="h-8 rounded-md border bg-background px-2">
                                    <option value="open">{t('Open now')}</option>
                                    <option value="soon">{t('Opening soon')}</option>
                                </select>
                                <Button size="sm" variant="ghost" disabled={i === 0} onClick={() => move(i, -1)} aria-label={t('Move up')}>
                                    ↑
                                </Button>
                                <Button size="sm" variant="ghost" disabled={i === home.sections.length - 1} onClick={() => move(i, 1)} aria-label={t('Move down')}>
                                    ↓
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Box>

                <Box title={t('Notice bar')} hint={t('A slim bar above every page: a closed day, an offer.')} onSave={() => save('announcement', bar)}>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={bar.enabled} onChange={(e) => setBar({ ...bar, enabled: e.target.checked })} />
                        {t('Show the bar')}
                    </label>
                    <Both label={t('Words')} value={bar.text} onChange={(text) => setBar({ ...bar, text })} max={200} />
                    <Text label={t('Link (optional, starts with / or https://)')} value={bar.link} onChange={(link) => setBar({ ...bar, link })} max={300} />
                </Box>

                <Box title={t('Footer')} onSave={() => save('footer', footer)}>
                    <Both label={t('A few words about you')} value={footer.about} onChange={(about) => setFooter({ ...footer, about })} max={400} area />
                    <Both label={t('Line at the very bottom')} value={footer.copyright} onChange={(copyright) => setFooter({ ...footer, copyright })} max={160} />
                    <div className="space-y-2">
                        <p className="text-sm font-medium">{t('Links in the footer')}</p>
                        {footer.links.map((l, i) => (
                            <div key={i} className="grid items-center gap-2 sm:grid-cols-[1fr_1fr_1.4fr_auto_auto]">
                                <Input aria-label={t('Words')} placeholder={t('Words')} value={l.label} onChange={(e) => setLink(i, { label: e.target.value })} />
                                <Input aria-label={t('Words in Bangla')} placeholder={t('Words in Bangla')} value={l.label_bn ?? ''} onChange={(e) => setLink(i, { label_bn: e.target.value })} />
                                <Input aria-label={t('Link')} placeholder="/page or https://…" value={l.url} onChange={(e) => setLink(i, { url: e.target.value })} />
                                <label className="flex items-center gap-1.5 text-sm">
                                    <input type="checkbox" checked={l.enabled} onChange={(e) => setLink(i, { enabled: e.target.checked })} />
                                    {t('Show')}
                                </label>
                                <Button size="sm" variant="ghost" onClick={() => setFooter({ ...footer, links: footer.links.filter((_, n) => n !== i) })}>
                                    {t('Remove')}
                                </Button>
                            </div>
                        ))}
                        <Button size="sm" variant="outline" onClick={() => setFooter({ ...footer, links: [...footer.links, { label: '', label_bn: null, url: '', enabled: true }] })}>
                            {t('Add a link')}
                        </Button>
                    </div>
                </Box>

                <Box title={t('Social pages')} hint={t('Full addresses starting with https://. Empty ones do not show.')} onSave={() => save('social', social)}>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {(['facebook', 'instagram', 'youtube', 'tiktok'] as const).map((k) => (
                            <Text key={k} label={k === 'facebook' ? 'Facebook' : k === 'instagram' ? 'Instagram' : k === 'youtube' ? 'YouTube' : 'TikTok'} value={social[k]} onChange={(v) => setSocial({ ...social, [k]: v })} max={300} />
                        ))}
                    </div>
                </Box>

                <Box title={t('For search engines')} hint={t('The short description people see under your site in Google.')} onSave={() => save('seo', seo)}>
                    <Both label={t('Description')} value={seo.description} onChange={(description) => setSeo({ ...seo, description })} max={300} area />
                </Box>
            </div>
        </AppLayout>
    );
}
