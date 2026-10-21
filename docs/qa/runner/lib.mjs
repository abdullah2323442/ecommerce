// Scenario runner: drives the real site as a real role, records what it saw, and takes screenshots for the report.
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

export const BASE = process.env.QA_BASE ?? 'http://127.0.0.1:8001';
const ROOT = path.resolve(import.meta.dirname, '..');
const SHOTS = path.join(ROOT, 'shots2');
const RESULTS = path.join(ROOT, 'results');
fs.mkdirSync(SHOTS, { recursive: true });
fs.mkdirSync(RESULTS, { recursive: true });

const PASS = 'DevPass-2026!';
export const ACCOUNTS = {
    admin: 'admin@siamsaqua.test',
    staff: 'staff@siamsaqua.test',
    paikari: 'paikari@siamsaqua.test',
    paikari2: 'nila@siamsaqua.test',
    paikari3: 'habib@siamsaqua.test',
    wholesale: 'wholesale@siamsaqua.test',
    visitor: 'visitor@siamsaqua.test',
    vendor: 'demo-crispy-house@aquabazar.test',
    mpo: 'mpo@siamsaqua.test',
    craftsman: 'craftsman@siamsaqua.test',
    guest: null,
};

const rx = (s) => (s instanceof RegExp ? s : new RegExp(String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'));

export async function startBrowser() {
    return chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
}

/** One signed-in browser session per role, kept for the whole run. */
export class Sessions {
    constructor(browser) {
        this.browser = browser;
        this.pages = {};
        this.landing = {};
    }

    /** A throwaway signed-out browser, for tests that create accounts. */
    async fresh() {
        const ctx = await this.browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'en-US' });
        const page = await ctx.newPage();
        page.setDefaultTimeout(12000);
        page.__dialogs = [];
        page.on('dialog', (d) => {
            page.__dialogs.push(d.message());
            d.accept();
        });
        await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
        await page.click('button:text-is("EN")');
        await page.waitForTimeout(1200);
        return page;
    }

    async page(role) {
        if (this.pages[role]) return this.pages[role];
        const ctx = await this.browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'en-US' });
        const page = await ctx.newPage();
        page.setDefaultTimeout(12000);
        page.__dialogs = [];
        page.on('dialog', (d) => {
            page.__dialogs.push(d.message());
            d.accept();
        });
        await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
        await page.click('button:text-is("EN")');
        await page.waitForTimeout(1200);
        if (ACCOUNTS[role]) {
            await page.fill('input[type=email]', ACCOUNTS[role]);
            await page.fill('input[type=password]', PASS);
            await page.click('button[type=submit]');
            await page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 20000 }).catch(() => {});
            await page.waitForLoadState('networkidle');
            if (page.url().includes('/login')) throw new Error(`could not sign in as ${role}`);
            this.landing[role] = new URL(page.url()).pathname;
        }
        return (this.pages[role] = page);
    }

    async close() {
        for (const p of Object.values(this.pages)) await p.context().close();
    }
}

/** The helpers a scenario uses. Every one is forgiving: a miss is recorded, it does not stop the run. */
class T {
    constructor(n, sessions, defaultRole) {
        this.n = n;
        this.sessions = sessions;
        this.role = defaultRole;
        this.page = null;
        this.checks = [];
        this.shots = [];
        this.notes = [];
        this.failed = false;
    }

    async as(role) {
        this.role = role;
        this.page = role === 'newguest' ? await this.sessions.fresh() : await this.sessions.page(role);
        if (role === 'newguest') this.temp = this.page.context();
        return this;
    }

    /** Messages of any "Are you sure?" pop-ups that appeared since the scenario began (they are accepted automatically). */
    dialogs() {
        return this.page.__dialogs ?? [];
    }

    landing(role) {
        return this.sessions.landing[role];
    }

    async phone(on = true) {
        await this.page.setViewportSize(on ? { width: 390, height: 844 } : { width: 1280, height: 800 });
        await this.page.waitForTimeout(500);
    }

    skip(reason) {
        this.skipped = reason;
        this.notes.push(reason);
    }

    async go(url) {
        // A page that redirects the visitor elsewhere makes the first request end early. That is the expected outcome, not an error.
        await this.page.goto(url.startsWith('http') ? url : BASE + url, { waitUntil: 'networkidle', timeout: 45000 }).catch((e) => {
            if (!/ERR_ABORTED|interrupted by another navigation/i.test(String(e.message))) throw e;
        });
        await this.page.waitForLoadState('networkidle').catch(() => {});
        await this.page.waitForTimeout(500);
    }

    /** The smallest block of the page that holds both pieces of text: a row, card or panel. */
    async block(containsA, containsB) {
        await this.page.evaluate(([a, b]) => {
            document.querySelectorAll('[data-qa-block]').forEach((e) => e.removeAttribute('data-qa-block'));
            const ok = (e) => e.innerText.includes(a) && e.innerText.includes(b);
            let best = null;
            for (const e of document.querySelectorAll('li,article,section,div,tr')) {
                if (ok(e) && !([...e.children].some(ok)) ) best = best ?? e;
            }
            if (best) best.setAttribute('data-qa-block', '1');
        }, [containsA, containsB]);
        return this.page.locator('[data-qa-block]').first();
    }

    async wait(ms = 800) {
        await this.page.waitForLoadState('networkidle').catch(() => {});
        await this.page.waitForTimeout(ms);
    }

    /** Finds what to click: buttons first, then links, tabs, labelled controls, and plain text last. */
    async target(name) {
        if (typeof name === 'string' && name.startsWith('css=')) return this.page.locator(name.slice(4)).first();
        const r = rx(name);
        const p = this.page;
        for (const loc of [p.getByRole('button', { name: r }), p.getByRole('link', { name: r }), p.getByRole('tab', { name: r }), p.getByRole('menuitem', { name: r }), p.getByLabel(r), p.getByText(r)]) {
            if ((await loc.count().catch(() => 0)) > 0) return loc.first();
        }
        return p.getByText(r).first();
    }

    async click(name, opts = {}) {
        try {
            const el = await this.target(name);
            await el.scrollIntoViewIfNeeded({ timeout: 5000 }).catch(() => {});
            await el.click({ timeout: 8000, ...opts });
            await this.wait(opts.wait ?? 700);
            await this.autoConfirm();
            return true;
        } catch (e) {
            this.checks.push({ ok: false, text: `Could not click "${name}"` });
            this.failed = true;
            return false;
        }
    }

    async fill(name, value) {
        try {
            const p = this.page;
            const r = rx(name);
            const el = typeof name === 'string' && name.startsWith('css=') ? p.locator(name.slice(4)).first() : p.getByLabel(r).or(p.getByPlaceholder(r)).first();
            await el.scrollIntoViewIfNeeded({ timeout: 5000 }).catch(() => {});
            await el.fill(String(value), { timeout: 8000 });
            return true;
        } catch (e) {
            this.checks.push({ ok: false, text: `Could not fill "${name}"` });
            this.failed = true;
            return false;
        }
    }

    async pick(selectorOrLabel, value) {
        try {
            const el = selectorOrLabel.startsWith('css=') ? this.page.locator(selectorOrLabel.slice(4)).first() : this.page.getByLabel(rx(selectorOrLabel)).first();
            if (value instanceof RegExp) {
                const labels = await el.locator('option').allInnerTexts();
                const hit = labels.find((l) => value.test(l));
                if (!hit) throw new Error('no such option');
                value = hit.trim();
            }
            await el.selectOption(typeof value === 'string' ? { label: value } : value);
            await this.wait(500);
            return true;
        } catch (e) {
            this.checks.push({ ok: false, text: `Could not choose "${value}" in "${selectorOrLabel}"` });
            this.failed = true;
            return false;
        }
    }

    /** Sensitive changes ask the owner to type their password again. Do it, so the original action finishes. */
    async autoConfirm() {
        if (!this.page.url().includes('/confirm-password')) return false;
        await this.page.fill('input[type=password]', PASS);
        await this.page.getByRole('button', { name: /Confirm password/i }).click();
        await this.wait(1200);
        this.notes.push('The system asked the owner to type the password again before saving. It was typed and the change went through.');
        return true;
    }

    /** Confirm the password up front, as the owner does before changing money settings. It stays confirmed for a few hours. */
    async preConfirm() {
        await this.page.goto(BASE + '/confirm-password', { waitUntil: 'networkidle' });
        if (!this.page.url().includes('/confirm-password')) return;
        await this.page.fill('input[type=password]', PASS);
        await this.page.getByRole('button', { name: /Confirm password/i }).click();
        await this.wait(1200);
    }

    async press(key) {
        await this.page.keyboard.press(key);
        await this.wait(500);
    }

    async text() {
        return (await this.page.innerText('body').catch(() => '')) ?? '';
    }

    /** Assert the page shows some text. `meaning` is the plain-language reason, shown in the report. */
    async see(text, meaning) {
        const body = await this.text();
        const ok = rx(text).test(body);
        this.checks.push({ ok, text: meaning ?? `The page shows "${text}"` });
        if (!ok) this.failed = true;
        return ok;
    }

    async notSee(text, meaning) {
        const body = await this.text();
        const ok = !rx(text).test(body);
        this.checks.push({ ok, text: meaning ?? `The page does not show "${text}"` });
        if (!ok) this.failed = true;
        return ok;
    }

    /** Assert any condition computed by the scenario. */
    check(ok, meaning, ifNot) {
        // `ifNot` says what was seen instead. It is added only when the check fails, so a passing line never contradicts itself.
        this.checks.push({ ok: !!ok, text: ok || !ifNot ? meaning : `${meaning} (${ifNot})` });
        if (!ok) this.failed = true;
        return !!ok;
    }

    note(text) {
        this.notes.push(text);
    }

    async shot(caption, opts = {}) {
        const k = this.shots.length + 1;
        const file = `f${this.n}_${k}.jpg`;
        await this.page.waitForTimeout(250);
        await this.page.screenshot({ path: path.join(SHOTS, file), type: 'jpeg', quality: 72, fullPage: !!opts.full });
        this.shots.push({ file, caption, role: this.role, url: this.page.url().replace(BASE, '') });
    }
}

export async function runFile(file, only, sessions) {
    const mod = await import(pathToFileURL(file).href);
    const list = mod.default.filter((s) => !only.length || only.includes(s.n));
    for (const s of list) {
        const t = new T(s.n, sessions, s.role ?? 'admin');
        for (const pg of Object.values(sessions.pages)) pg.__dialogs = [];
        let error = null;
        try {
            await t.as(t.role);
            await s.run(t);
        } catch (e) {
            error = String(e.message ?? e).split('\n')[0];
            try {
                await t.shot('Where it stopped');
            } catch {}
        }
        if (t.temp) await t.temp.close().catch(() => {});
        const status = error ? 'error' : t.failed ? 'fail' : s.notBuilt ? 'notbuilt' : t.skipped ? 'skipped' : 'pass';
        const out = { n: s.n, status, error, checks: t.checks, shots: t.shots, notes: t.notes, did: s.did ?? null };
        fs.writeFileSync(path.join(RESULTS, `${s.n}.json`), JSON.stringify(out, null, 2));
        const bad = t.checks.filter((c) => !c.ok).map((c) => c.text);
        console.log(`${String(s.n).padStart(3)} ${status.toUpperCase().padEnd(5)} ${t.shots.length} shots${error ? '  ERR ' + error : ''}${bad.length ? '  MISS: ' + bad.join(' | ') : ''}`);
    }
}
