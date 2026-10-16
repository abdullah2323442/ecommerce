// UI capture: screenshots, axe and a forbidden-word scan of everything each role's browser receives.
// usage: node capture.mjs <outDir> [--shots] [--widths=390,1440] [--themes=light] [--locales=bn] [--only=guest,admin] [--pages=home,medicines]
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:8000';
const REPO = 'D:/projects/ecommerce';
const args = Object.fromEntries(process.argv.slice(3).map((a) => { const [k, v] = a.replace(/^--/, '').split('='); return [k, v ?? true]; }));
const out = process.argv[2];
const widths = (args.widths || '390,1440').split(',').map(Number);
const themes = (args.themes || 'light').split(',');
const locales = (args.locales || 'bn').split(',');
const only = args.only ? args.only.split(',') : null;
const onlyPages = args.pages ? args.pages.split(',') : null;
fs.mkdirSync(out, { recursive: true });

// Dev-only password, read from the project's own seeder so it never appears in chat or in this script.
const seeder = fs.readFileSync(path.join(REPO, 'database/seeders/DevSeeder.php'), 'utf8');
const PASSWORD = seeder.match(/PASSWORD = '([^']+)'/)[1];

const MED = process.env.MED || '4863';
const CO = process.env.CO || '194';
const CUST = process.env.CUST || '3';

const publicPages = { home: '/', medicines: '/medicines', results: '/medicines?q=napa', empty: '/medicines?q=zzqxv', medicine: `/medicines/${MED}`, companies: '/companies', company: `/companies/${CO}`, form: '/forms/tablet' };
const roles = {
    guest: { email: null, pages: { ...publicPages, login: '/login', register: '/register', forgot: '/forgot-password' }, forbidden: ['paikari', 'wholesale', 'wholesaler', 'mpo', 'craftsman'] },
    public: { email: 'public@siamsaqua.test', pages: { ...publicPages, account: '/dashboard', profile: '/settings/profile', password: '/settings/password' }, forbidden: ['paikari', 'wholesale', 'wholesaler', 'mpo', 'craftsman'] },
    paikari: { email: 'paikari@siamsaqua.test', pages: { home: '/', medicine: `/medicines/${MED}`, account: '/dashboard' }, forbidden: ['wholesale', 'wholesaler', 'mpo', 'craftsman'] },
    wholesaler: { email: 'wholesale@siamsaqua.test', pages: { home: '/', medicine: `/medicines/${MED}`, account: '/dashboard' }, forbidden: ['paikari', 'mpo', 'craftsman'] },
    staff: { email: 'staff@siamsaqua.test', pages: { home: '/', account: '/dashboard', admin_try: '/admin/customers' }, forbidden: ['wholesale', 'wholesaler', 'mpo', 'craftsman', 'profit'] },
    admin: { email: 'admin@siamsaqua.test', pages: { dashboard: '/dashboard', customers: '/admin/customers', customer: `/admin/customers/${CUST}`, tiers: '/admin/tiers', requests: '/admin/requests', uikit: '/admin/ui-kit', home: '/' }, forbidden: [] },
};

const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' });
const report = { base: BASE, at: new Date().toISOString(), roles: {} };

for (const [role, cfg] of Object.entries(roles)) {
    if (only && !only.includes(role)) continue;
    const r = (report.roles[role] = { leaks: [], pages: {} });

    for (const locale of locales) {
        for (const theme of themes) {
            const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: theme });
            await ctx.addInitScript((t) => { try { localStorage.setItem('appearance', t); } catch {} }, theme);
            const page = await ctx.newPage();
            const bodies = [];
            page.on('response', async (res) => {
                const ct = res.headers()['content-type'] || '';
                if (!/html|json|javascript|css/.test(ct) && !res.url().endsWith('.js')) return;
                try { bodies.push({ url: res.url(), text: await res.text() }); } catch {}
            });

            if (cfg.email) {
                await page.goto(`${BASE}/login`);
                await page.fill('input[type=email]', cfg.email);
                await page.fill('input[type=password]', PASSWORD);
                await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15000 }).catch(() => {}), page.click('button[type=submit]')]);
            }
            // Language: use the app's own switch so the cookie is set the real way.
            await page.goto(`${BASE}/`);
            const want = locale === 'en' ? 'EN' : 'বাংলা';
            const btn = page.locator(`button[lang=${locale}]`).first();
            if ((await btn.count()) && (await btn.getAttribute('aria-pressed')) !== 'true') { await btn.click(); await page.waitForTimeout(800); }

            for (const [name, url] of Object.entries(cfg.pages)) {
                if (onlyPages && !onlyPages.includes(name)) continue;
                const key = `${name}`;
                const resp = await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' }).catch((e) => ({ status: () => 'ERR ' + e.message }));
                const finalPath = new URL(page.url()).pathname;
                const entry = (r.pages[key] ??= { url, final: finalPath, status: typeof resp?.status === 'function' ? resp.status() : null });

                // Inertia props as JSON, so props are scanned even when they only live in the data-page attribute.
                const props = await page.evaluate(() => document.getElementById('app')?.dataset.page ?? '');
                bodies.push({ url: `${url} [data-page]`, text: props });

                if (locale === locales[0] && theme === themes[0]) {
                    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze().catch(() => null);
                    if (axe) entry.axe = axe.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, sample: v.nodes[0]?.target?.join(' ') }));
                    const cls = await page.evaluate(() => new Promise((res) => { let c = 0; new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) c += e.value; }).observe({ type: 'layout-shift', buffered: true }); setTimeout(() => res(c), 500); }));
                    entry.cls = Math.round(cls * 1000) / 1000;
                    entry.hscroll = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
                }

                if (args.shots) {
                    for (const w of widths) {
                        await page.setViewportSize({ width: w, height: w < 768 ? 844 : 900 });
                        await page.waitForTimeout(250);
                        const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
                        if (over > 0) (entry.overflow ??= {})[`${w}-${locale}-${theme}`] = over;
                        await page.screenshot({ path: path.join(out, `${role}-${name}-${w}-${locale}-${theme}.png`), fullPage: true });
                    }
                    await page.setViewportSize({ width: 1440, height: 900 });
                }
            }

            for (const b of bodies) {
                const lower = b.text.toLowerCase();
                for (const word of cfg.forbidden) {
                    const re = new RegExp(`\\b${word}\\b`, 'g');
                    let m; let n = 0;
                    while ((m = re.exec(lower)) && n < 3) {
                        n++;
                        r.leaks.push({ word, url: b.url.replace(BASE, ''), context: b.text.slice(Math.max(0, m.index - 60), m.index + 60).replace(/\s+/g, ' ') });
                    }
                }
            }
            await ctx.close();
        }
    }
    // De-duplicate leaks by word+url.
    const seen = new Set();
    r.leaks = r.leaks.filter((l) => { const k = l.word + l.url + l.context; if (seen.has(k)) return false; seen.add(k); return true; });
}

await browser.close();
fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
for (const [role, r] of Object.entries(report.roles)) {
    const axeCount = Object.values(r.pages).reduce((s, p) => s + (p.axe || []).filter((v) => ['serious', 'critical'].includes(v.impact)).length, 0);
    console.log(`${role}: leaks=${r.leaks.length} serious/critical axe rule hits=${axeCount}`);
    for (const l of r.leaks.slice(0, 12)) console.log(`   LEAK ${l.word} @ ${l.url}: …${l.context}…`);
    for (const [n, p] of Object.entries(r.pages)) console.log(`   ${n} ${p.url} -> ${p.final} ${p.status} cls=${p.cls} hscroll=${p.hscroll} ${JSON.stringify(p.overflow || {})} axe=${(p.axe || []).map((v) => `${v.id}(${v.impact},${v.nodes})`).join(' ')}`);
}
